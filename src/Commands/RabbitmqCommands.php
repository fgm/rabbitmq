<?php

namespace Drupal\rabbitmq\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

/**
 * Implementation of the Drush commands for RabbitMQ.
 */
class RabbitmqCommands extends DrushCommands {

  const WORKER_DEFAULTS = ['memory_limit' => NULL, 'max_iterations' => NULL];

  /**
   * The rabbitmq.queue_info service.
   *
   * @var \Drupal\rabbitmq\Commands\QueueInfo
   */
  protected $queueInfo;

  /**
   * The SF3 YAML component.
   *
   * @var \Symfony\Component\Yaml\Yaml
   */
  protected $yaml;

  /**
   * RabbitmqCommands constructor.
   *
   * @param \Drupal\rabbitmq\Commands\QueueInfo $queueInfo
   *   The rabbitmq.queue_info service.
   */
  public function __construct(
    QueueInfo $queueInfo
  ) {
    $this->queueInfo = $queueInfo;
    $this->yaml = new Yaml();
  }

  /**
   * Connect to RabbitMQ and wait for jobs to do.
   *
   * @param string $worker
   *   The name of the queue to process, also the name of the worker plugin.
   * @param mixed $options
   *   The command options.
   *
   * @command rabbitmq-worker
   * @option memory_limit Set the max amount of memory the worker should occupy before exiting. Given in megabytes.
   * @option max_iterations Number of iterations to process before exiting. If not present, exit criteria will not evaluate the amount of iterations processed.
   * @aliases rqwk
   */
  public function worker($worker, $options = self::WORKER_DEFAULTS) {

  }

  /**
   * Return information about a queue.
   *
   * @param string $queue_name
   *   The name of the queue to get information from.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\PropertyList|null
   *   The command result.
   *
   * @command rabbitmq-queue-info
   * @aliases rqqi
   * @field-labels
   *   queue-name: Queue name
   *   count: Items count
   */
  public function queueInfo($queue_name = NULL) {
    $count = $this->queueInfo->get($queue_name);

    return new PropertyList(['queue-name' => $queue_name, 'count' => $count]);
  }

  /**
   * Run the RabbitMQ tutorial test producer.
   *
   * @see https://www.rabbitmq.com/tutorials/tutorial-one-php.html
   *
   * @command rabbitmq-test-producer
   * @aliases rqtp
   */
  public function testProducer() {

  }

  /**
   * Run the RabbitMQ tutorial test consumer.
   *
   * @see https://www.rabbitmq.com/tutorials/tutorial-one-php.html
   *
   * @command rabbitmq-test-consumer
   * @aliases rqtc
   */
  public function testConsumer() {

  }

}
