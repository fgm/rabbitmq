<?php

/**
 * @file
 * Contains RabbitMQ QueueBase.
 */

namespace Drupal\rabbitmq\Queue;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\rabbitmq\Connection;
use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;

/**
 * Class QueueBase.
 */
abstract class QueueBase {

  const LOGGER_CHANNEL = 'rabbitmq';

  /**
   * Object that holds a channel to RabbitMQ.
   *
   * @var \PhpAmqpLib\Channel\AMQPChannel
   */
  protected $channel;

  /**
   * The RabbitMQ connection service.
   *
   * @var \Drupal\rabbitmq\Connection
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
   */
  protected $name;

  /**
   * The queue options.
   */
  protected $options;

  /**
   * Constructor.
   *
   * @param string $name
   *   The name of the queue to work with: an arbitrary string.
   * @param \Drupal\rabbitmq\Connection $connection
   *   The RabbitMQ connection service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $modules
   *   The module handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct($name, Connection $connection,
    ModuleHandlerInterface $modules, LoggerInterface $logger) {
    // Check our active storage to find the full config
    $config = \Drupal::config('rabbitmq.config');
    $queues = $config->get('queues');

    if (!$queues && !isset($queues[$name])) {
      $logger->error('Cannot find queue information in active storage %yml for queue %name', array('%yml' => 'rabbitmq.queues', '%name' => $name));
      // We should probably throw an error instead?
      return;
    }

    $this->options = $queues[$name];
    $this->name = $name;
    $this->connection = $connection;
    $this->logger = $logger;
    $this->modules = $modules;

    // Declare any exchanges required
    $exchanges = $config->get('exchanges');
    if ($exchanges) {
      foreach ($exchanges as $name => $exchange) {
        $this->getChannel()->exchange_declare(
          $name, 
          $exchange['type'],
          $exchange['passive'],
          $exchange['durable'],
          $exchange['auto_delete'],
          $exchange['internal'],
          $exchange['nowait']
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
    if ($this->channel) {
      return $this->channel;
    }

    $this->channel = $this->connection->getConnection()->channel();

    // Initialize a queue on the channel.
    $this->getQueue($this->channel);
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
   *   Not strongly specified by php-amqplib.
   */
  protected function getQueue(AMQPChannel $channel, array $options = []) {    
    // Add the queue name to the options
    $this->options['name'] = $this->name;

    // Declare the queue
    $channel->queue_declare(
      $this->name,
      $this->options['passive'],
      $this->options['durable'],
      $this->options['exclusive'],
      $this->options['auto_delete']
    );

    // Bind the queue to an exchange if defined
    if (!empty($this->options['bindings'])) {
      $this->channel->queue_bind($this->name, $this->options['bindings']['exchange'], $this->options['bindings']['routing_key']);
    }
  }

}
