<?php

namespace Drupal\rabbitmq\Queue;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\rabbitmq\ConnectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Class RabbitMQ QueueFactory.
 */
class QueueFactory {

  const SERVICE_NAME = 'queue.rabbitmq';
  const DEFAULT_QUEUE_NAME = 'default';
  const MODULE_CONFIG = 'rabbitmq.config';

  /**
   * The config.factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The server factory service.
   *
   * @var \Drupal\rabbitmq\ConnectionFactory
   */
  protected $connectionFactory;

  /**
   * The logger service for the RabbitMQ channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The module_handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $modules;

  /**
   * Constructor.
   *
   * @param \Drupal\rabbitmq\ConnectionFactory $connectionFactory
   *   The connection factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $modules
   *   The module handler service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service for the RabbitMQ channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config.factory service.
   */
  public function __construct(
    ConnectionFactory $connectionFactory,
    ModuleHandlerInterface $modules,
    LoggerInterface $logger,
    ConfigFactoryInterface $configFactory
  ) {
    $this->configFactory = $configFactory;
    $this->connectionFactory = $connectionFactory;
    $this->logger = $logger;
    $this->modules = $modules;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the Queue holding key and value pairs.
   *
   * @return Queue
   *   The Queue object
   */
  public function get($name) {
    $moduleConfig = $this->configFactory->get(static::MODULE_CONFIG);
    $queue = new Queue($name, $this->connectionFactory, $this->modules, $this->logger, $moduleConfig);
    return $queue;
  }

  /**
   * To limit breakage, reset unavailable queue.rabbitmq to queue.database.
   */
  public static function overrideSettings() {
    // Regrettably, Settings can not be modified using the public core API.
    $settings = Settings::getInstance();
    $reflectionClass = new \ReflectionClass($settings);
    $reflectionProperty = $reflectionClass->getProperty('storage');
    $reflectionProperty->setAccessible(TRUE);
    $storage = $reflectionProperty->getValue($settings);
    unset($storage['queue_default']);
    $reflectionProperty->setValue($settings, $storage);
  }

}
