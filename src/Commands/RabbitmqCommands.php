<?php

namespace Drupal\rabbitmq\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Drupal\rabbitmq\ConnectionFactory;
use Drupal\rabbitmq\Consumer;
use Drupal\rabbitmq\Service\QueueInfo;
use Drupal\rabbitmq\Service\Worker;
use Drush\Commands\DrushCommands;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Implementation of the Drush commands for RabbitMQ.
 */
class RabbitmqCommands extends DrushCommands {

  const WORKER_DEFAULTS = [
    Consumer::OPTION_MEMORY_LIMIT => NULL,
    Consumer::OPTION_MAX_ITERATIONS => NULL,
    Consumer::OPTION_TIMEOUT => NULL,
  ];

  /**
   * The rabbitmq.queue_info service.
   *
   * @var \Drupal\rabbitmq\Service\QueueInfo
   */
  protected $queueInfo;

  /**
   * The rabbitmq.worker service.
   *
   * @var \Drupal\rabbitmq\Service\Worker
   */
  protected $worker;

  /**
   * RabbitmqCommands constructor.
   *
   * @param \Drupal\rabbitmq\Service\QueueInfo $queueInfo
   *   The rabbitmq.queue_info service.
   * @param \Drupal\rabbitmq\Service\Worker $worker
   *   The rabbitmq.worker service.
   */
  public function __construct(
    QueueInfo $queueInfo,
    Worker $worker
  ) {
    $this->queueInfo = $queueInfo;
    $this->worker = $worker;
  }

  /**
   * Connect to RabbitMQ and wait for jobs to do.
   *
   * @param string $queueName
   *   The name of the queue to process, also the name of the worker plugin.
   * @param mixed $options
   *   The command options.
   *
   * @return int
   *   Exit code.
   *
   * @command rabbitmq-worker
   * @option Consumer::OPTION_MEMORY_LIMIT
   *   Set the max amount of memory the worker should occupy before exiting.
   *   Given in megabytes.
   * @option Consumer::OPTION_MAX_ITERATIONS
   *   Number of iterations to process before exiting.If not present, exit
   *   criteria will not evaluate the amount of iterations processed.
   * @option Consumer::OPTION_TIMEOUT
   *   Timeout to limit time worker should keep waiting messages from RabbitMQ.
   * @aliases rqwk
   */
  public function worker($queueName, $options = self::WORKER_DEFAULTS) {
    $this->worker->consume($queueName, $options);
  }

  /**
   * Return information about a queue.
   *
   * @param string $queueName
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
  public function queueInfo($queueName = NULL) {
    $count = $this->queueInfo->count($queueName);

    return new PropertyList(['queue-name' => $queueName, 'count' => $count]);
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
    $this->writeln(" [x] Sent 'Hello World!'");
    $channel->close();
    $connection->close();
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
