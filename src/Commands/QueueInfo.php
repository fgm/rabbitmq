<?php

namespace Drupal\rabbitmq\Commands;

use Drupal\rabbitmq\Queue\QueueFactory;

/**
 * Class QueueInfo provides the rabbitmq.queue_info service.
 */
class QueueInfo {

  /**
   * QueueInfo constructor.
   *
   * @param \Drupal\rabbitmq\Queue\QueueFactory $queueFactory
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
  public function get(string $queueName): int {
    if (empty($queueName)) {
      return 0;
    }

    $queue = $this->queueFactory->get($queueName);
    $count = $queue->numberOfItems();
    return $count;
  }

}
