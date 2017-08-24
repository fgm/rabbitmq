<?php

namespace Drupal\rabbitmq\Tests;

use Drupal\rabbitmq\Queue\Queue;

/**
 * Class RabbitMqQueueTest.
 *
 * @group RabbitMQ
 */
class RabbitMqQueueTest extends RabbitMqTestBase {

  /**
   * The default queue, handled by RabbitMq.
   *
   * @var \Drupal\rabbitmq\Queue\Queue
   */
  protected $queue;

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->queueFactory = $this->container->get('queue');
    $this->queue = $this->queueFactory->get($this->queueName);
    $this->assertInstanceOf(Queue::class, $this->queue, 'Queue API settings point to RabbitMQ');
    $this->queue->createQueue();
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    $this->queue->deleteQueue();
    parent::tearDown();
  }

  /**
   * Test queue registration.
   */
  public function testQueueCycle() {
    $data = 'foo';
    $this->queue->createItem($data);
    $actual = $this->queue->numberOfItems();
    $expected = 1;
    $this->assertEquals($expected, $actual, 'Queue contains something before deletion');

    $this->queue->deleteQueue();
    $expected = 0;
    $actual = $this->queue->numberOfItems();
    $this->assertEquals($expected, $actual, 'Queue no longer contains anything after deletion');
  }

  /**
   * Test the queue item lifecycle.
   */
  public function testItemCycle() {
    $count = 0;
    $data = 'foo';
    $this->queue->createItem($data);

    $actual = $this->queue->numberOfItems();
    $expected = $count + 1;
    $this->assertEquals($expected, $actual, 'Creating an item increases the item count.');

    $item = $this->queue->claimItem();
    $this->assertTrue(is_object($item), 'Claiming returns an item');

    $expected = $data;
    $actual = $item->data;
    $this->assertEquals($expected, $actual, 'Item content matches submission.');

    $actual = $this->queue->numberOfItems();
    $expected = $count;
    $this->assertEquals($expected, $actual, 'Claiming an item reduces the item count.');

    $this->queue->releaseItem($item);
    $actual = $this->queue->numberOfItems();
    $expected = $count + 1;
    $this->assertEquals($expected, $actual, 'Releasing an item increases the item count.');

    $item = $this->queue->claimItem();
    $this->assertTrue(is_object($item), 'Claiming returns an item');

    $this->queue->deleteItem($item);
    $actual = $this->queue->numberOfItems();
    $expected = $count;
    $this->assertEquals($expected, $actual, 'Deleting an item reduces the item count.');
  }

}
