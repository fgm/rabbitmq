<?php

/**
 * @file
 * Drush plugin for RabbitMQ.
 */

use Drupal\rabbitmq\ConnectionFactory;
use Drupal\rabbitmq\Consumer;
// Avoid possible conflict with the builtin \[InvalidArgument]Exception.
use Drupal\rabbitmq\Exception\Exception as RabbitMqException;
use Drupal\rabbitmq\Exception\InvalidArgumentException as RabbitMqInvalidArgumentException;
use Drupal\rabbitmq\Exception\InvalidWorkerException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Implements hook_drush_command().
 *
 * @return array
 *   An array of Drush command definitions.
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
 *
 * @param string $queueName
 *   The name of the queue/ID of the queue worker plugin to wait on.
 *
 * @return bool
 *   Did the command succeed ?
 */
function drush_rabbitmq_worker($queueName) {
  /** @var \Drupal\rabbitmq\Consumer $consumer */
  $consumer = (new Drupal())->service('rabbitmq.consumer');

  // Service might be called from a non-Drush environment, so drush_get_option()
  // may not be available to it.
  $consumer->setOptionGetter(function (string $name) {
    return (int) drush_get_option($name, Consumer::OPTIONS[$name]);
  });

  $queueArgs = ['@name' => $queueName];

  drupal_register_shutdown_function(function () use ($consumer, $queueName) {
    $consumer->shutdownQueue($queueName);
  });

  try {
    $consumer->logStart();
    $consumer->consume($queueName);
  }
  catch (InvalidWorkerException $e) {
    return drush_set_error(dt("Worker for queue @name does not implement the worker interface.", $queueArgs));
  }
  catch (RabbitMqInvalidArgumentException $e) {
    return drush_set_error($e->getMessage());
  }
  catch (RabbitMqException $e) {
    return drush_set_error(dt("Could not obtain channel for queue.", $queueArgs));
  }

  return TRUE;
}
