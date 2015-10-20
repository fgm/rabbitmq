<?php

/**
 * @file
 * Drush plugin for RabbitMQ.
 */

use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\rabbitmq\Connection;
use Drupal\rabbitmq\Queue\Queue;
use Drupal\rabbitmq\Queue\QueueFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Implements hook_drush_command().
 */
function rabbitmq_drush_command() {
  $commands['rabbitmq-worker'] = [
    'arguments' => [
      'worker' => 'The name of the queue to process',
    ],
    'options' => [
      'memory_limit' => 'Set the max amount of memory the worker should occupy before exiting. Given in megabytes.',
      'max_iterations' => 'Number of iterations to process before exiting. If not present, exit criteria will not evaluate the amount of iterations processed.',
    ],
    'description' => 'Connect to RabbitMQ and wait for jobs to do',
  ];

  $commands['rabbitmq-queue-info'] = [
    'arguments' => [
      'queue_name' => 'The name of the queue to get information from',
    ],
    'description' => 'Return information about a queue',
    // @see https://github.com/drush-ops/drush/issues/1667
    // 'required-arguments' => TRUE,
  ];

  $commands['rabbitmq-test-producer'] = [
    'description' => 'Run the test producer from https://www.rabbitmq.com/tutorials/tutorial-one-php.html',
  ];

  $commands['rabbitmq-test-consumer'] = [
    'description' => 'Run the test consumer from https://www.rabbitmq.com/tutorials/tutorial-one-php.html',
  ];

  return $commands;
}

/**
 * Command callback for rabbitmq-test-producer.
 */
function drush_rabbitmq_test_producer() {
  $connection = new AMQPStreamConnection(
    Connection::DEFAULT_HOST,
    Connection::DEFAULT_PORT,
    Connection::DEFAULT_USER,
    Connection::DEFAULT_PASS);
  $channel = $connection->channel();
  $routing_key = $queue_name = 'hello';
  $channel->queue_declare($queue_name, FALSE, FALSE, FALSE, FALSE);
  $message = new AMQPMessage('Hello World!');
  $channel->basic_publish($message, '', $routing_key);
  echo " [x] Sent 'Hello World!'\n";
  $channel->close();
  $connection->close();
}

/**
 * Command callback for rabbitmq-test-consumer.
 */
function drush_rabbitmq_test_consumer() {
  $connection = new AMQPStreamConnection(
    Connection::DEFAULT_HOST,
    Connection::DEFAULT_PORT,
    Connection::DEFAULT_USER,
    Connection::DEFAULT_PASS);
  $channel = $connection->channel();
  $queue_name = 'hello';
  $channel->queue_declare($queue_name, FALSE, FALSE, FALSE, FALSE);
  echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";

  $callback = function($msg) {
    echo " [x] Received ", $msg->body, "\n";
  };

  $channel->basic_consume($queue_name, '', FALSE, TRUE, FALSE, FALSE, $callback);

  while (count($channel->callbacks)) {
    $channel->wait();
  }
  $channel->close();
  $connection->close();
}

/**
 * Command callback for rabbitmq-queue-info.
 */
function drush_rabbitmq_queue_info($queue_name = NULL) {
  if (empty($queue_name)) {
    return;
  }

  /* @var \Drupal\Core\Queue\QueueFactory $queue_factory */
  $queue_factory = \Drupal::service('queue');

  $queue = $queue_factory->get($queue_name);
  $count = $queue->numberOfItems();
  echo Yaml::dump([$queue_name => $count]);
}

/**
 * Command callback: Create a queue worker.
 */
function drush_rabbitmq_worker($queue_name) {
  /* @var \Drupal\Core\Queue\QueueWorkerManager $worker_manager */
  $worker_manager = \Drupal::service('plugin.manager.queue_worker');

  $workers = $worker_manager->getDefinitions();

  if (!isset($workers[$queue_name])) {
    return drush_set_error('rabbitmq', t('No known worker for queue @queue', [
      '@queue' => $queue_name,
    ]));
  }

  // Before we start listening for messages, make sure the callback
  // worker is callable.
  $worker = $worker_manager->createInstance($queue_name);
  if (!($worker instanceof QueueWorkerInterface)) {
    return drush_set_error("Worker for queue does not implement the worker interface.");
  }

  /* @var \Drupal\Core\Queue\QueueFactory $queue_factory */
  $queue_factory = \Drupal::service('queue');
  /* @var \Drupal\rabbitmq\Queue\queue $queue */
  $queue = $queue_factory->get($queue_name);
  var_dump($queue);
  return;

  /** @var \Psr\Log\LoggerInterface $logger */
  $logger = \Drupal::service('logger.channel.rabbitmq');
  $callback = function ($msg) use ($worker, $queue_name, $logger) {
    $logger->info('Received queued message: :id', [
      ':id' => $msg->delivery_info['delivery_tag'],
    ]);

    try {
      // Build the item to pass to the queue worker.
      $item = (object) [
        'id' => $msg->delivery_info['delivery_tag'],
        'data' => unserialize($msg->body),
      ];

      // Call the queue worker.
      $worker->processItem($item->data);

      // Remove the item from the queue.
      $msg->delivery_info['channel']->basic_ack($item->id);
      $logger->info('Item :id acknowledged from @queue', [
        ':id' => $item->id,
        '@queue' => $queue_name,
      ]);
    }
    catch (\Exception $e) {
      watchdog_exception('rabbitmq', $e);
      $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
    }
  };
  $queue->getChannel()->basic_qos(NULL, 1, NULL);
  $queue->getChannel()->basic_consume($queue_name, '', FALSE, FALSE, FALSE, FALSE, $callback);

  $logger->info("RabbitMQ worker ready to receive messages", [], WATCHDOG_INFO);

  // Begin listening for messages to process.
  while (count($queue->getChannel()->callbacks)) {
    $queue->getChannel()->wait();

    // Evaluate if worker should exit.
    // If the --memory_limit option is set, check the memory usage and
    // exit if the limit has been exceeded or met.
    if ($memory_limit = drush_get_option('memory_limit', FALSE)) {
      $memory_usage = memory_get_peak_usage() / 1024 / 1024;
      if ($memory_usage >= $memory_limit) {
        $logger->notice('RabbitMQ worker has reached or exceeded set memory limit of @amountMB and will now exit.', [
          '@amount' => $memory_limit,
        ]);
        break;
      }
    }

    // If max_iterations is set, don't allow loop to continue longer than
    // max_iterations.
    if ($max_iterations = drush_get_option('max_iterations', FALSE)) {
      $iteration = isset($iteration) ? $iteration : 0;
      $iteration++;
      if ($max_iterations <= $iteration) {
        $logger->notice('Rabbit worker has reached max number of iterations. Exiting.');
        break;
      }
    }
  }
}
