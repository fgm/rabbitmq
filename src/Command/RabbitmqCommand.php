<?php

namespace Drupal\rabbitmq\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Drush\Commands\DrushCommands;
use Drupal\Core\Queue\QueueWorkerInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * Implementation of the Drush commands for RabbitMQ.
 */
class RabbitmqCommand extends DrushCommands {

    /**
     * Return information about a queue.
     *
     * @param string $queueName
     *   The name of the queue to get information from.
     *
     * @return PropertyList|null
     *   The command result.
     *
     * @command rabbitmq:queue-info
     * @aliases rqqi
     * @field-labels
     *   queue-name: Queue name
     *   count: Items count
     */
    public function queueInfo($queueName = NULL) {
        if (null === $queueName) {
            return null;
        }

        /* @var \Drupal\Core\Queue\QueueFactory $queue_factory */
        $queue_factory = \Drupal::service('queue');

        $queue = $queue_factory->get($queueName);
        $count = $queue->numberOfItems();
        echo Yaml::dump([$queueName => $count]);
    }

    /**
     * Create a queue worker.
     *
     * @param string $queueName
     *   The name of the queue to get information from.
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @command rabbitmq:worker
     * @aliases rqwk
     */
    public function worker($queueName = NULL) {
        /* @var \Drupal\Core\Queue\QueueWorkerManager $worker_manager */
        $worker_manager = \Drupal::service('plugin.manager.queue_worker');

        $workers = $worker_manager->getDefinitions();

        if (!isset($workers[$queueName])) {
            throw new \Exception('No known worker for queue ' . $queueName);
        }

        // Before we start listening for messages, make sure the callback
        // worker is callable.
        $worker = $worker_manager->createInstance($queueName);
        if (!($worker instanceof QueueWorkerInterface)) {
            throw new \Exception('Worker for queue does not implement the worker interface.');
        }

        /* @var \Drupal\Core\Queue\QueueFactory $queue_factory */
        $queue_factory = \Drupal::service('queue');
        /* @var \Drupal\rabbitmq\Queue\queue $queue */
        $queue = $queue_factory->get($queueName);
        \assert('$queue instanceof \Drupal\rabbitmq\Queue\Queue');

        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = \Drupal::service('logger.channel.rabbitmq-drush');

        $callback = function (AMQPMessage $msg) use ($worker, $queueName, $logger) {
            $logger->info('(Drush) Received queued message: @id', [
                '@id' => $msg->delivery_info['delivery_tag'],
            ]);

            try {
                // Build the item to pass to the queue worker.
                $item = (object) [
                    'id' => $msg->delivery_info['delivery_tag'],
                    'data' => json_decode($msg->body),
                ];

                // Call the queue worker.
                $worker->processItem($item->data);

                // Remove the item from the queue.
                $msg->delivery_info['channel']->basic_ack($item->id);
                $logger->info('(Drush) Item @id acknowledged from @queue', [
                    '@id' => $item->id,
                    '@queue' => $queueName,
                ]);
            }
            catch (\Exception $e) {
                $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
                throw new \Exception('rabbitmq', $e);
            }
        };

        $queue->getChannel()->basic_qos(NULL, 1, NULL);
        $queue->getChannel()->basic_consume($queueName, '', FALSE, FALSE, FALSE, FALSE, $callback);

        $ready_message = 'RabbitMQ worker ready to receive an unlimited number of messages.';
        $ready_args = [];
        $logger->info($ready_message, $ready_args);

        // Begin listening for messages to process.
        while (\count($queue->getChannel()->callbacks)) {
            $queue->getChannel()->wait();
        }
    }
}
