<?php

/**
 * @file
 * Contains RabbitMQConnection.
 */

namespace Drupal\rabbitmq;

use Drupal\Core\Site\Settings;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * RabbitMQ connection class.
 *
 * This class is abstracted from RabbitMQQueue so that a connection can still be
 * obtained and used independently from the Drupal queuing system.
 */
class Connection {

  /**
   * The singleton RabbitMQ connection.
   *
   * @var \PhpAmqpLib\Connection\AMQPConnection
   */
  protected static $connection;

  /**
   * The settings service.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings service.
   */
  public function __construct(Settings $settings) {
    // Cannot continue if the library wasn't loaded.
    assert('class_exists("\PhpAmqpLib\Connection\AMQPConnection")',
      "Could not find php-amqplib. See the rabbitmq/README.md file for details."
    );

    $this->settings = $settings;
  }

  /**
   * Get a configured connection to RabbitMQ.
   */
  public function getConnection() {
    if (!self::$connection) {
      $default_credentials = [
        'host' => 'localhost',
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
      ];
      $credentials = $this->settings->get('rabbitmq.credentials', $default_credentials);
      $connection = new AMQPStreamConnection($credentials['host'],
        $credentials['port'], $credentials['username'],
        $credentials['password'], $credentials['vhost']);

      self::$connection = $connection;
    }

    return self::$connection;
  }

}
