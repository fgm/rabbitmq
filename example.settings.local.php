<?php
/**
 * @file
 * Example settings to connect to RabbitMQ.
 *
 * This is the default data to add to your settings.local.php.
 */

$settings['rabbitmq_credentials'] = [
  'host' => 'localhost',
  'port' => 5672,
  'username' => 'guest',
  'password' => 'guest',
  'vhost' => '/'
];

$settings['queue_default'] = 'queue.rabbitmq';
