<?php

namespace Drupal\rabbitmq\Tests;

use Drupal\rabbitmq\Queue\QueueFactory;
use Drupal\KernelTests\KernelTestBase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Class BeanstalkdTestBase is a base class for Beanstalkd tests.
 */
abstract class RabbitMqTestBase extends KernelTestBase {
  const MODULE = 'rabbitmq';

  public static $modules = ['system', 'rabbitmq'];

  /**
   * Server factory.
   *
   * @var \Drupal\rabbitmq\ConnectionFactory
   */
  protected $connectionFactory;

  /**
   * The name requested for the temporary queue created during tests.
   *
   * @var string
   */
  protected $queueName;

  /**
   * The routing key, actually equal to the queue name, but not necessarily so.
   *
   * @var string
   */
  protected $routingKey;

  /*
   * Uncomment this line to be able to step into the code with a debugger.
   *
   * @var bool
   */
  /* protected $runTestInSeparateProcess = FALSE; */

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->installConfig(['rabbitmq']);
    $time = $this->container->get('datetime.time')->getCurrentTime();
    $this->routingKey = $this->queueName = 'test-' . date('c', $time);

    // Override the database queue to ensure all requests to it come to us.
    $this->container->setAlias('queue.database', QueueFactory::SERVICE_NAME);
    $this->connectionFactory = $this->container->get('rabbitmq.connection.factory');

    $config = $this->config('rabbitmq.config');
    $queues = $config->get('queues');
    $queues[$this->queueName] = [
      'passive' => FALSE,
      'durable' => TRUE,
      'exclusive' => FALSE,
      'auto_delete' => FALSE,
      'nowait' => FALSE,
      'routing_keys' => [],
    ];
    $config->set('queues', $queues)->save();
  }

  /**
   * Initialize a server and free channel.
   *
   * @return array[]
   *   - \AMQPChannel: A channel to the default queue.
   *   - string: the queue name.
   */
  protected function initChannel($name = QueueFactory::DEFAULT_QUEUE_NAME) {
    $connection = $this->connectionFactory->getConnection();
    $this->assertTrue($connection instanceof AMQPStreamConnection, 'Default connections is an AMQP Connection');
    $channel = $connection->channel();
    $this->assertTrue($channel instanceof AMQPChannel, 'Default connection provides channels');
    $passive = FALSE;
    $durable = FALSE;
    $exclusive = FALSE;
    $auto_delete = TRUE;

    list($actual_name,,) = $channel->queue_declare($name, $passive, $durable, $exclusive, $auto_delete);
    $this->assertEquals($name, $actual_name, 'Queue declaration succeeded');

    return [$channel, $actual_name];
  }

  /**
   * Clean up after a test.
   *
   * @param \PhpAmqpLib\Channel\AMQPChannel $channel
   *   The channel to clean up.
   */
  protected function cleanUp(AMQPChannel $channel) {
    $connection = $channel->getConnection();
    $channel->close();
    $connection->close();
  }

}
