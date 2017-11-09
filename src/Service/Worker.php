<?php

namespace Drupal\rabbitmq\Service;

use Drupal\rabbitmq\Consumer;

/**
 * Class QueueInfo provides the rabbitmq.worker service.
 */
class Worker {

  /**
   * The rabbitmq.consumer service.
   *
   * @var \Drupal\rabbitmq\Consumer
   */
  protected $consumer;

  /**
   * Worker constructor.
   *
   * @param \Drupal\rabbitmq\Consumer $consumer
   *   The rabbitmq.consumer service.
   */
  public function __construct(Consumer $consumer) {
    $this->consumer = $consumer;
  }

  /**
   * Return the number of elements in a queue.
   *
   * @param string $queueName
   *   The name of the queue.
   * @param array $options
   *   Consumer options.
   */
  public function consume(string $queueName, array $options) {
    $this->consumer->setOptionGetter(function (string $name) use ($options) {
      return (int) $options[$name];
    });

    drupal_register_shutdown_function(function () use ($queueName) {
      $this->consumer->shutdownQueue($queueName);
    });

    $this->consumer->logStart();
    $this->consumer->consumeQueueApi($queueName);
  }
}
