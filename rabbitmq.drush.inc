<?php

/**
 * @file
 * Drush plugin for RabbitMQ.
 */

use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\rabbitmq\ConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Implements hook_drush_command().
 */
function rabbitmq_drush_command() {
  $file = preg_replace('/(inc|php)$/', 'yml', __FILE__);
  $config = (new Yaml())->parse(file_get_contents($file));
  $items = $config['commands'];
  return $items;
}

/**
 * Command callback for rabbitmq-test-producer.
 */
function drush_rabbitmq_test_producer() {
  $connection = new AMQPStreamConnection(
    ConnectionFactory::DEFAULT_HOST,
    ConnectionFactory::DEFAULT_PORT,
    ConnectionFactory::DEFAULT_USER,
    ConnectionFactory::DEFAULT_PASS
  );
  $channel = $connection->channel();
  $routingKey = $queueName = 'hello';
  $channel->queue_declare($queueName, FALSE, FALSE, FALSE, FALSE);
  $message = new AMQPMessage('Hello World!');
  $channel->basic_publish($message, '', $routingKey);
  echo " [x] Sent 'Hello World!'\n";
  $channel->close();
  $connection->close();
}

/**
 * Command callback for rabbitmq-test-consumer.
 */
function drush_rabbitmq_test_consumer() {
  $connection = new AMQPStreamConnection(
    ConnectionFactory::DEFAULT_HOST,
    ConnectionFactory::DEFAULT_PORT,
    ConnectionFactory::DEFAULT_USER,
    ConnectionFactory::DEFAULT_PASS
  );
  $channel = $connection->channel();
  $queueName = 'hello';
  $channel->queue_declare($queueName, FALSE, FALSE, FALSE, FALSE);
  echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";

  $callback = function ($msg) {
    echo " [x] Received ", $msg->body, "\n";
  };

  $channel->basic_consume($queueName, '', FALSE, TRUE, FALSE, FALSE, $callback);

  while (count($channel->callbacks)) {
    $channel->wait();
  }
  $channel->close();
  $connection->close();
}

/**
 * Command callback for rabbitmq-queue-info.
 */
function drush_rabbitmq_queue_info($queueName = NULL) {
  if (empty($queueName)) {
    return;
  }

  /* @var \Drupal\Core\Queue\QueueFactory $queueFactory */
  $queueFactory = (new Drupal())->service('queue');

  $queue = $queueFactory->get($queueName);
  $count = $queue->numberOfItems();
  echo (new Yaml())->dump([$queueName => $count]);
}

/**
 * Command callback: Create a queue worker.
 */
function drush_rabbitmq_worker($queueName) {
  $container = (new Drupal())->getContainer();

  /* @var \Drupal\Core\Queue\QueueWorkerManager $workerManager */
  $workerManager = $container->get('plugin.manager.queue_worker');

  $workers = $workerManager->getDefinitions();

  if (!isset($workers[$queueName])) {
    return drush_set_error('rabbitmq', t('No known worker for queue @queue', [
      '@queue' => $queueName,
    ]));
  }
  $maxIterations = intval(drush_get_option('max_iterations', 0));
  $memoryLimit = intval(drush_get_option('memory_limit', -1));
  $timeout = intval(drush_get_option('rabbitmq_timeout', 120));

  /** @var \Psr\Log\LoggerInterface $logger */
  $logger = $container->get('logger.channel.rabbitmq-drush');
  $continueListening = TRUE;

  if ($maxIterations > 0) {
    $readyMessage = "RabbitMQ worker ready to receive up to @count messages.";
    $readyArgs = ['@count' => $maxIterations];
  }
  else {
    $readyMessage = "RabbitMQ worker ready to receive an unlimited number of messages.";
    $readyArgs = [];
  }
  $logger->info($readyMessage, $readyArgs, WATCHDOG_INFO);

  while ($continueListening) {
    try {
      // Before we start listening for messages, make sure the callback
      // worker is callable.
      $worker = $workerManager->createInstance($queueName);
      if (!($worker instanceof QueueWorkerInterface)) {
        return drush_set_error("Worker for queue does not implement the worker interface.");
      }

      /* @var \Drupal\Core\Queue\QueueFactory $queueFactory */
      $queueFactory = $container->get('queue');
      /* @var \Drupal\rabbitmq\Queue\queue $queue */
      $queue = $queueFactory->get($queueName);
      assert('$queue instanceof \Drupal\rabbitmq\Queue\Queue');

      $callback = function (AMQPMessage $msg) use ($worker, $queueName, $logger) {
        $logger->info('(Drush) Received queued message: @id', [
          '@id' => $msg->delivery_info['delivery_tag'],
        ]);

        try {
          // Build the item to pass to the queue worker.
          $item = (object) [
            'id' => $msg->delivery_info['delivery_tag'],
            'data' => json_decode($msg->body),
          ];

          // Call the queue worker.
          $worker->processItem($item->data);

          // Remove the item from the queue.
          $msg->delivery_info['channel']->basic_ack($item->id);
          $logger->info('(Drush) Item @id acknowledged from @queue', [
            '@id' => $item->id,
            '@queue' => $queueName,
          ]);
        }
        catch (\Exception $e) {
          watchdog_exception('rabbitmq', $e);
          $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
        }
      };

      $queue->getChannel()->basic_qos(NULL, 1, NULL);
      $queue->getChannel()
        ->basic_consume($queueName, '', FALSE, FALSE, FALSE, FALSE, $callback);

      // Begin listening for messages to process.
      $iteration = 0;
      while (count($queue->getChannel()->callbacks)) {
        $queue->getChannel()->wait(NULL, FALSE, $timeout);

        // Evaluate whether worker should exit.
        // If the --memory_limit option is set, check the memory usage
        // and exit if the limit has been exceeded or met.
        if ($memoryLimit > 0) {
          $memoryUsage = memory_get_peak_usage() / 1024 / 1024;
          if ($memoryUsage >= $memoryLimit) {
            $logger->notice('RabbitMQ worker has reached or exceeded set memory limit of @limitMB and will now exit.', [
              '@limit' => $memoryLimit,
            ]);
            break;
          }
        }

        // If max_iterations is set, don't allow loop to continue longer than
        // max_iterations.
        $iteration++;
        if ($maxIterations > 0) {
          if ($maxIterations <= $iteration) {
            $logger->notice('RabbitMQ worker has reached max number of iterations: @count. Exiting.', $readyArgs);
            break;
          }
        }
      }
      $continueListening = FALSE;
    }
    catch (AMQPTimeoutException $e) {
      $continueListening = TRUE;
      unset($worker, $queue);
    }
    catch (\Exception $e) {
      throw new \Exception($e);
    }
  }
}
