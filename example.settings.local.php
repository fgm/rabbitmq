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
  'vhost' => '/',
  'username' => 'guest',
  'password' => 'guest',
  // Uncomment the lines below if you are using AMQP over SSL.
  /*
  'ssl' => [
    'verify_peer_name' => FALSE,
    'verify_peer' => FALSE,
    'local_pk' => '~/.ssh/id_rsa',
  ],
   */
  'options' => [
    'connection_timeout' => 5,
    'read_write_timeout' => 5,
  ],
];

$settings['queue_default'] = 'queue.rabbitmq';
