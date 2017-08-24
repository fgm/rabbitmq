<?php

namespace Drupal\rabbitmq\Queue;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\rabbitmq\ConnectionFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;

/**
 * Class QueueBase.
 */
abstract class QueueBase {

  const LOGGER_CHANNEL = 'rabbitmq';

  const MODULE = 'rabbitmq';

  /**
   * Object that holds a channel to RabbitMQ.
   *
   * @var \PhpAmqpLib\Channel\AMQPChannel
   */
  protected $channel;

  /**
   * The RabbitMQ connection service.
   *
   * @var \PhpAmqpLib\Connection\AbstractConnection
   */
  protected $connection;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $modules;

  /**
   * The name of the queue.
   *
   * @var string
   */
  protected $name;

  /**
   * The queue options.
   *
   * @var array
   */
  protected $options;

  /**
   * A queue array: [writer, item count, consumer count].
   *
   * @var array|null
   */
  protected $queue;

  /**
   * Constructor.
   *
   * @param string $name
   *   The name of the queue to work with: an arbitrary string.
   * @param \Drupal\rabbitmq\ConnectionFactory $connectionFactory
   *   The RabbitMQ connection factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $modules
   *   The module handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ImmutableConfig $moduleConfig
   *   The config.factory service.
   */
  public function __construct(
    string $name,
    ConnectionFactory $connectionFactory,
    ModuleHandlerInterface $modules,
    LoggerInterface $logger,
    ImmutableConfig $moduleConfig
  ) {
    // Check our active storage to find the the queue config.
    $queues = $moduleConfig->get('queues');

    $this->options = ['name' => $name];
    if (isset($queues[$name])) {
      $this->options += $queues[$name];
    }

    $this->name = $name;
    $this->connection = $connectionFactory->getConnection();
    $this->logger = $logger;
    $this->modules = $modules;
    // Declare any exchanges required if configured.
    $exchanges = $moduleConfig->get('exchanges');
    if ($exchanges) {
      foreach ($exchanges as $exchangeName => $exchange) {
        $this->connection->channel()->exchange_declare(
          $exchangeName,
          $exchange['type'] ?? 'direct',
          $exchange['passive'] ?? FALSE,
          $exchange['durable'] ?? TRUE,
          $exchange['auto_delete'] ?? FALSE,
          $exchange['internal'] ?? FALSE,
          $exchange['nowait'] ?? FALSE
        );
      }
    }
  }

  /**
   * Obtain an initialized channel to the queue.
   *
   * @return \PhpAmqpLib\Channel\AMQPChannel
   *   The queue channel.
   */
  public function getChannel() {
    if (!$this->channel) {
      $this->channel = $this->connection
        ->getConnection()
        ->channel();

      // Initialize a queue on the channel.
      $this->getQueue($this->channel);
    }

    return $this->channel;
  }

  /**
   * Declare a queue and obtain information about the queue.
   *
   * @param \PhpAmqpLib\Channel\AMQPChannel $channel
   *   The queue channel.
   * @param array $options
   *   Options overriding the queue defaults.
   *
   * @return mixed|null
   *   Not strongly specified by php-amqplib. Expected to be a 3-item array:
   *   - ProtocolWriter
   *   - Number of clients
   *   - Number of items
   */
  protected function getQueue(AMQPChannel $channel, array $options = []) {
    $options = array_merge($this->options, $options);
    if (!isset($this->queue)) {
      // Declare the queue.
      $this->queue = $channel->queue_declare(
        $this->name,
        $options['passive'] ?? FALSE,
        $options['durable'] ?? TRUE,
        $options['exclusive'] ?? FALSE,
        $options['auto_delete'] ?? TRUE,
        $options['nowait'] ?? FALSE,
        $options['arguments'] ?? NULL,
        $options['ticket'] ?? NULL
      );

      // Bind the queue to an exchange if defined.
      if ($this->queue && !empty($options['routing_keys'])) {
        foreach ($options['routing_keys'] as $routing_key) {
          list($exchange, $key) = explode('.', $routing_key);
          $this->channel->queue_bind($this->name, $exchange, $key);
        }
      }
    }

    return $this->queue;
  }

}
