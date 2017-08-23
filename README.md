RabbitMQ Integration
====================

[![Build Status](https://travis-ci.org/FGM/rabbitmq.svg?branch=travis)](https://travis-ci.org/FGM/rabbitmq)[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/comicrelief/rabbitmq/badges/quality-score.png?b=8.x-1.x)](https://scrutinizer-ci.com/g/comicrelief/rabbitmq/?branch=8.x-1.x)

Requirements
------------

* RabbitMQ server needs to be installed and configured.
* Drupal 8.3.0 or more recent must be configured with `php-amqplib`  
    * go to the root directory of your site
    * edit `composer.json` (not `core/composer.json`)
    * insert `"php-amqplib/php-amqplib": "^2.6"` in the `require` section of 
      the file, then save it.
    * update your `vendor` directory by typing `composer update`.

Example module
--------------

To test RabbitMQ from your Drupal site, enable the `rabbitmq_example` module and following the instructions from the README.

Installation
------------

* Provide connection credentials as part of the `$settings` global variable in 
  `settings.php`.

        $settings['rabbitmq_credentials'] = [
          'host' => 'localhost',
          'port' => 5672,
          'vhost' => '/'
          'username' => 'guest',
          'password' => 'guest',
        ];

* Configure RabbitMQ as the queuing system for the queues you want RabbitMQ to 
  maintain, either as the default queue service, default reliable queue service,
  or specifically for each queue:
    * If you want to set RabbitMQ as the default queue manager, then add the 
      following to your settings.

          $settings['queue_default'] = 'queue.rabbitmq';
    * Alternatively you can also set for each queue to use RabbitMQ using one 
      of these formats:

          $settings['queue_service_{queue_name}'] = 'queue.rabbitmq';
          $settings['queue_reliable_service_{queue_name}'] = 'queue.rabbitmq';


Customization
-------------

Modules may override queue or exchange defaults built in a custom module by implementing
`config/install//rabbitmq.config.yml`. See `src/Queue/QueueBase.php` and 
`src/Tests/RabbitMqTestBase::setUp()` for details.


SSL
-------

It is similar to the normal one, but you need to add 2 extra arrays. 

This is an example of how `settings.php` should looks like :

```
$settings['rabbitmq_credentials'] = [
  'host' => 'host',
  'port' => 5672,
  'vhost' => '/',
  'username' => 'guest',
  'password' => 'guest',
  'ssl' => [
    'verify_peer_name' => false,
    'verify_peer' => false,
    'local_pk' => '~/.ssh/id_rsa',
  ],
  'options' => [
    'connection_timeout' => 20,
    'read_write_timeout' => 20,
  ],
];
```
