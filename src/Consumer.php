<?php

namespace Drupal\rabbitmq;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\rabbitmq\Exception\Exception;
use Drupal\rabbitmq\Exception\InvalidArgumentException;
use Drupal\rabbitmq\Exception\InvalidWorkerException;
use Drupal\rabbitmq\Exception\OutOfRangeException;
use Drupal\rabbitmq\Exception\RuntimeException;
use Drupal\rabbitmq\Queue\Queue;
use Drupal\rabbitmq\Queue\QueueBase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPOutOfRangeException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class Consumer provides a service wrapping queue consuming operations.
 *
 * Note that it does not carray the value of its options, but getters for them,
 * to support multiple ways of accessing options, e.g. Drush vs Console vs Web.
 */
class Consumer {
  use StringTranslationTrait;

  const EXTENSION_PCNTL = 'pcntl';

  const OPTION_MAX_ITERATIONS = 'max_iterations';
  const OPTION_MEMORY_LIMIT = 'memory_limit';
  const OPTION_TIMEOUT = 'rabbitmq_timeout';

  // Known option names and their default value.
  const OPTIONS = [
    self::OPTION_MAX_ITERATIONS => 0,
    self::OPTION_MEMORY_LIMIT => -1,
    self::OPTION_TIMEOUT => NULL,
  ];

  /**
   * Continue listening ?
   *
   * @var bool
   */
  protected $continueListening = FALSE;

  /**
   * The rabbitmq logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * A callback providing the ability to read service runtime options.
   *
   * This is needed to support non-Drush use scenarios.
   *
   * @var callable
   */
  protected $optionGetter;

  /**
   * Was the Pre-Flight Check successful ? Yes | No | Not yet run.
   *
   * @var bool|null
   */
  protected $pfcOk = NULL;

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The plugin.manager.queue_worker service.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $workerManager;

  /**
   * Consumer constructor.
   *
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $workerManager
   *   The plugin.manager.queue_worker service.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The rabbitmq logger channel.
   */
  public function __construct(
    QueueWorkerManagerInterface $workerManager,
    QueueFactory $queueFactory,
    LoggerChannelInterface $logger
  ) {
    $this->logger = $logger;
    $this->queueFactory = $queueFactory;
    $this->workerManager = $workerManager;
  }

  /**
   * Is the queue name valid ?
   *
   * @param string $queueName
   *   The requested name.
   *
   * @return bool
   *   Is is valid?
   */
  public function isQueueNameValid(string $queueName): bool {
    $workers = $this->workerManager->getDefinitions();
    if (!isset($workers[$queueName])) {
      return drush_set_error('rabbitmq', $this->t('No known worker for queue @queue', [
        '@queue' => $queueName,
      ]));
    }
  }

  /**
   * Decode the data received from the queue using a chain of decoder choices.
   *
   * - 1st/2nd choices: the one already set on the service instance
   *   - 1st: set on the service instance manually during or after construction.
   *   - 2nd: the one set on the service instance within consume() if the
   *     worker implements DecoderAwareInterface.
   * - 3rd choice: a legacy-compatible JSON decoder.
   *
   * @param mixed $data
   *   The message payload to decode.
   *
   * @return mixed
   *   The decoded value.
   */
  public function decode($data) {
    $decoder = $this->decoder ?? 'json_decode';
    return $decoder($data);
  }

  /**
   * Get the value of a queue consumer option.
   *
   * @param string $name
   *   The name of the option.
   *
   * @return mixed
   *   The value returned by the configured option getter, or NULL if the option
   *   is unknown.
   */
  public function getOption(string $name) {
    if (!array_key_exists($name, static::OPTIONS)) {
      return NULL;
    }
    $getter = $this->optionGetter;
    return is_callable($getter) ? $getter($name) : NULL;
  }

  /**
   * Log an event about the queue run.
   */
  public function logStart() {
    $this->preFlightCheck();
    $maxIterations = $this->getOption(self::OPTION_MAX_ITERATIONS);
    if ($maxIterations > 0) {
      $readyMessage = "RabbitMQ worker ready to receive up to @count messages.";
      $readyArgs = ['@count' => $maxIterations];
    }
    else {
      $readyMessage = "RabbitMQ worker ready to receive an unlimited number of messages.";
      $readyArgs = [];
    }
    $this->logger->debug($readyMessage, $readyArgs);
  }

  /**
   * Signal handler.
   *
   * @see \Drupal\rabbitmq\Consumer::consume()
   *
   * On a timeout signal, the connections is already closed, so do not attempt
   * to shutdown the queue.
   */
  public function onTimeout() {
    drupal_set_message($this->t('Timeout reached'));
    $this->logger->info('Timeout reached');
    $this->stopListening();
  }

  /**
   * Main logic: consume the specified queue using AMQP.
   *
   * @param string $queueName
   *   The name of the queue to consume.
   *
   * @throws \Exception
   */
  public function consume(string $queueName) {
    $this->preFlightCheck();
    $this->startListening();
    $worker = $this->getWorker($queueName);
    // Allow obtaining a decoder from the worker to have a sane default, while
    // being able to override it on service instantiation.
    if ($worker instanceof DecoderAwareWorkerInterface && !isset($this->decoder)) {
      $this->setDecoder($worker->getDecoder());
    }

    /* @var \Drupal\rabbitmq\Queue\queue $queue */
    $queue = $this->queueFactory->get($queueName);
    assert($queue instanceof Queue);

    $channel = $this->getChannel($queue);
    assert($channel instanceof AMQPChannel);
    $channel->basic_qos(NULL, 1, FALSE);

    $maxIterations = $this->getOption(self::OPTION_MAX_ITERATIONS);
    $memoryLimit = $this->getOption(self::OPTION_MEMORY_LIMIT);
    $timeout = $this->getOption(self::OPTION_TIMEOUT);
    if ($timeout) {
      pcntl_signal(SIGALRM, [$this, 'onTimeout']);
    }
    $callback = $this->getCallback($worker, $queueName, $timeout);

    while ($this->continueListening) {
      try {
        $channel->basic_consume($queueName, '', FALSE, FALSE, FALSE, FALSE, $callback);

        // Begin listening for messages to process.
        $iteration = 0;
        while (count($channel->callbacks) && $this->continueListening) {
          if ($timeout) {
            pcntl_alarm($timeout);
          }
          $channel->wait(NULL, FALSE, $timeout);
          if ($timeout) {
            pcntl_alarm(0);
          }

          // Break on memory_limit reached.
          if ($this->hitMemoryLimit($memoryLimit)) {
            $this->stopListening();
            break;
          }

          // Break on max_iterations reached.
          $iteration++;
          if ($this->hitIterationsLimit($maxIterations, $iteration)) {
            $this->stopListening();
          }
        }
        $this->stopListening();
      }
      catch (AMQPIOWaitException $e) {
        $this->stopListening();
        $channel->close();
      }
      catch (AMQPTimeoutException $e) {
        $this->startListening();
      }
      catch (Exception $e) {
        throw new Exception('Could not obtain channel for queue.', 0, $e);
      }
    }
  }

  /**
   * Main logic: consume the specified queue using Queue API.
   *
   * @param string $queueName
   *   The name of the queue to consume.
   *
   * @throws \Exception
   *
   * @TODO Probably needs to do more on SuspendQueueException.
   */
  public function consumeQueueApi(string $queueName) {
    $this->preFlightCheck();
    $this->startListening();
    $worker = $this->getWorker($queueName);
    // Allow obtaining a decoder from the worker to have a sane default, while
    // being able to override it on service instantiation.
    if ($worker instanceof DecoderAwareWorkerInterface && !isset($this->decoder)) {
      $this->setDecoder($worker->getDecoder());
    }

    /* @var \Drupal\rabbitmq\Queue\queue $queue */
    $queue = $this->queueFactory->get($queueName);
    assert($queue instanceof Queue);

    $maxIterations = $this->getOption(self::OPTION_MAX_ITERATIONS);
    $memoryLimit = $this->getOption(self::OPTION_MEMORY_LIMIT);
    $timeout = $this->getOption(self::OPTION_TIMEOUT);
    if (isset($timeout)) {
      pcntl_signal(SIGALRM, [$this, 'onTimeout']);
    }
    else {
      $timeout = 0;
    }

    $iteration = 0;
    $startTime = microtime(TRUE);
    do {
      $item = NULL;
      if ($timeout) {
        pcntl_alarm($timeout);
        $item = $queue->claimItem();
        pcntl_alarm(0);
      }
      else {
        $item = $queue->claimItem();
      }

      // Break on memory_limit reached before process.
      if ($this->hitMemoryLimit($memoryLimit)) {
        $this->stopListening();
        break;
      }

      $currentTime = microtime(TRUE);
      // If we did not get an object, do not try to process it.
      if (!is_object($item)) {
        usleep(10);
        // Only loop if the current continuous wait did not exceed timeout.
        if ($currentTime > $startTime + $timeout) {
          break;
        }
        else {
          continue;
        }
      }

      // We got a normal item, try to handle it.
      try {
        // Call the queue worker.
        $worker->processItem($item->data);

        // Remove the item from the queue.
        $queue->deleteItem($item);
        $this->logger->debug('(Drush) Item @id acknowledged from @queue', [
          '@id' => $item->id,
          '@queue' => $queueName,
        ]);
      }
      // Reserved QueueAPI exception: releaseItem and continue work.
      catch (RequeueException $e) {
        $queue->releaseItem($item);
        $this->logger->debug('(Drush) Item @id put back on @queue', [
          '@id' => $item->id,
          '@queue' => $queueName,
        ]);
      }
      // Reserved QueueAPI exception: stop working on this queue.
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $this->stopListening();
      }
      // Restart wait period: we handled a valid item.
      $startTime = microtime(TRUE);

      // Break on memory_limit reached after process.
      if ($this->hitMemoryLimit($memoryLimit)) {
        $this->stopListening();
        break;
      }

      // Break on max_iterations reached. Only count actual items.
      $iteration++;
      if ($this->hitIterationsLimit($maxIterations, $iteration)) {
        $this->stopListening();
      }
    } while ($this->continueListening);
  }

  /**
   * Provide a message callback for events.
   *
   * @param \Drupal\Core\Queue\QueueWorkerInterface $worker
   *   The worker plugin.
   * @param string $queueName
   *   The queue name.
   * @param int $timeout
   *   The queue wait timeout. Since it is only for queue wait, not worker wait,
   *   it has to be reset before starting work, and reinitialized when ending
   *   work.
   *
   * @return \Closure
   *   The callback.
   */
  protected function getCallback(
    QueueWorkerInterface $worker,
    string $queueName,
    int $timeout = 0
  ): \Closure {
    $callback = function (AMQPMessage $msg) use ($worker, $queueName, $timeout) {
      if ($timeout) {
        pcntl_alarm(0);
      }
      $this->logger->info('(Drush) Received queued message: @id', [
        '@id' => $msg->delivery_info['delivery_tag'],
      ]);

      try {
        // Build the item to pass to the queue worker.
        $item = (object) [
          'id' => $msg->delivery_info['delivery_tag'],
          'data' => $this->decode($msg->body),
        ];

        // Call the queue worker.
        $worker->processItem($item->data);

        // Remove the item from the queue.
        $msg->delivery_info['channel']->basic_ack($item->id);
        $this->logger->info('(Drush) Item @id acknowledged from @queue', [
          '@id' => $item->id,
          '@queue' => $queueName,
        ]);
      }
      catch (\Exception $e) {
        watchdog_exception('rabbitmq', $e);
        $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'],
          TRUE);
      }
      if ($timeout) {
        pcntl_alarm($timeout);
      }
    };

    return $callback;
  }

  /**
   * Get the channel instance for a given queue.
   *
   * Convert the various low-level known exceptions to module-level ones to make
   * it easier to catch cleanly.
   *
   * @param \Drupal\rabbitmq\Queue\Queue $queue
   *   The queue from which to obtain a channel.
   *
   * @return \PhpAmqpLib\Channel\AMQPChannel
   *   The channel instance.
   *
   * @throws \Drupal\rabbitmq\Exception\InvalidArgumentException
   * @throws \Drupal\rabbitmq\Exception\OutOfRangeException
   * @throws \Drupal\rabbitmq\Exception\RuntimeException
   */
  protected function getChannel(Queue $queue) {
    try {
      $channel = $queue->getChannel();
    }
    // May be thrown by StreamIO::__construct()
    catch (\InvalidArgumentException $e) {
      throw new InvalidArgumentException($e->getMessage());
    }
    // May be thrown during getChannel()
    catch (AMQPRuntimeException $e) {
      throw new RuntimeException($e->getMessage());
    }
    // May be thrown during getChannel()
    catch (AMQPOutOfRangeException $e) {
      throw new OutOfRangeException($e->getMessage());
    }

    return $channel;
  }

  /**
   * Get a worker instance for a queue name.
   *
   * @param string $queueName
   *   The name of the queue for which to get a worker.
   *
   * @return \Drupal\Core\Queue\QueueWorkerInterface
   *   The worker instance.
   *
   * @throws \Drupal\rabbitmq\Exception\InvalidWorkerException
   */
  protected function getWorker(string $queueName): QueueWorkerInterface {
    // Before we start listening for messages, make sure the worker is valid.
    $worker = $this->workerManager->createInstance($queueName);
    if (!($worker instanceof QueueWorkerInterface)) {
      throw new InvalidWorkerException('Invalid worker for requested queue.');
    }
    return $worker;
  }

  /**
   * Did consume() hit the max_iterations limit ?
   *
   * @param int $maxIterations
   *   The value of the max_iterations option.
   * @param int $iteration
   *   The current number of iterations in the consume() loop.
   *
   * @return bool
   *   Did it ?
   */
  protected function hitIterationsLimit(int $maxIterations, int $iteration) {
    if ($maxIterations > 0 && $maxIterations <= $iteration) {
      $this->logger->notice('RabbitMQ worker has reached max number of iterations: @count. Exiting.',
        [
          '@count' => $maxIterations,
        ]);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Evaluate whether worker should exit.
   *
   * If the --memory_limit option is set, check the memory usage
   * and exit if the limit has been exceeded or met.
   *
   * @param int $memoryLimit
   *   The maximum memory the service may consume, or -1 for unlimited.
   *
   * @return bool
   *   - TRUE: consume() should stop,
   *   - FALSE: consume() may continue.
   */
  protected function hitMemoryLimit(int $memoryLimit) {
    // Evaluate whether worker should exit.
    // If the --memory_limit option is set, check the memory usage
    // and exit if the limit has been exceeded or met.
    if ($memoryLimit > 0) {
      $memoryUsage = memory_get_peak_usage() / 1024 / 1024;
      if ($memoryUsage >= $memoryLimit) {
        $this->logger->notice('RabbitMQ worker has reached or exceeded set memory limit of @limitMB and will now exit.', [
          '@limit' => $memoryLimit,
        ]);
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Implements hook_requirements().
   */
  public static function hookRequirements($phase, array &$req) {
    $key = QueueBase::MODULE . '-consumer';
    $req[$key]['title'] = t('RabbitMQ Consumer');
    $options = [
      ':ext' => Url::fromUri('http://php.net/pcntl')->toString(),
      '%option' => static::OPTION_TIMEOUT,
    ];
    if (!extension_loaded(static::EXTENSION_PCNTL)) {
      $req[$key]['description'] = t('Extension <a href=":ext">PCNTL</a> not present in PHP. Option  %option is not available in the RabbitMQ consumer.', $options);
      $req[$key]['severity'] = REQUIREMENT_WARNING;
    }
    else {
      $req[$key]['description'] = t('Extension <a href=":ext">PCNTL</a> is present in PHP. Option   %option is available in the RabbitMQ consumer.', $options);
      $req[$key]['severity'] = REQUIREMENT_OK;
    }
  }

  /**
   * Ensures options are consistent with configuration.
   *
   * @throws \Drupal\rabbitmq\Exception\InvalidArgumentException
   *   Options are not compatible with configuration.
   */
  protected function preFlightCheck() {
    if ($this->pfcOk) {
      return;
    }
    $this->pfcOk = FALSE;
    $timeout = $this->getOption(self::OPTION_TIMEOUT);
    if (!empty($timeout) && !extension_loaded(static::EXTENSION_PCNTL)) {
      $message = $this->t('Option @option is not available without the @ext extension.', [
        '@option' => static::OPTION_TIMEOUT,
        '@ext' => static::EXTENSION_PCNTL,
      ]);
      throw new InvalidArgumentException($message);
    }
    $this->pfcOk = TRUE;
  }

  /**
   * Shutdown a queue.
   *
   * @param string $queueName
   *   The name of the queue, also the name of the QueueWorker plugin processing
   *   its items.
   */
  public function shutdownQueue(string $queueName) {
    $queue = $this->queueFactory->get($queueName);
    if ($queue instanceof Queue) {
      $queue->shutdown();
    }
  }

  /**
   * Register a decoder for message payloads.
   *
   * @param callable $decoder
   *   The decoder.
   */
  public function setDecoder(callable $decoder) {
    $this->decoder = $decoder;
  }

  /**
   * Register a method able to get option values.
   *
   * @param callable $optionGetter
   *   The getter.
   */
  public function setOptionGetter(callable $optionGetter) {
    $this->optionGetter = $optionGetter;
  }

  /**
   * Mark listening as active.
   */
  public function startListening() {
    $this->continueListening = TRUE;
  }

  /**
   * Mark listening as inactive.
   */
  public function stopListening() {
    $this->continueListening = FALSE;
  }

}
