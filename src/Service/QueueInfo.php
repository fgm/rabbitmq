<?php

namespace Drupal\rabbitmq\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Class QueueInfo provides the rabbitmq.queue_info service.
 */
class QueueInfo {

  /**
   * The queue.rabbitmq service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * QueueInfo constructor.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue.rabbitmq service.
   */
  public function __construct(QueueFactory $queueFactory) {
    $this->queueFactory = $queueFactory;
  }

  /**
   * Return the number of elements in a queue.
   *
   * @param string $queueName
   *   The name of the queue.
   *
   * @return int
   *   An approximation of the number of items.
   */
  public function count(string $queueName): int {
    if (empty($queueName)) {
      return 0;
    }
    $queue = $this->queueFactory->get($queueName);
    $count = $queue->numberOfItems();

    return $count;
  }

}
