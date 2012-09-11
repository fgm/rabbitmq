<?php

/**
 * RabbitMQ connection class.
 *
 * This class is abstracted from RabbitMQQueue so that a connection
 * can still be obtained and used independantly from the Drupal queing system.
 */
class RabbitMQConnection {

  static protected $connection;

  /**
   * Get a configured connection to RabbitMQ.
   */
  static public function get() {
    if (!self::$connection) {
      $search_paths = array(dirname(__FILE__) . '/php-amqplib');

      // Load up the php-amqplib library.
      if (function_exists('libraries_get_path')) {
        array_push($search_paths, libraries_get_path('php-amqplib'));
      }

      // Search for the AMQP php library.
      while ($search_path = array_pop($search_paths)) {
        if (file_exists($search_path . '/amqp.inc')) {
          require_once $search_path . '/amqp.inc';
          break;
        }
      }

      // Cannot continue if the library wasn't loaded.
      if (!class_exists('AMQPConnection')) {
        throw new Exception("Could not find php-amqplib. Please download and install from https://github.com/videlalvaro/php-amqplib/tree/v1.0. See rabbitmq INSTALL file for more details.");
      }

      $credentials = variable_get('rabbitmq_credentials', array(
        'host' => 'localhost',
        'port' => 5672,
        'username' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
      ));
      $connection = new AMQPConnection($credentials['host'], $credentials['port'], $credentials['username'], $credentials['password'], $credentials['vhost']);

      self::$connection = $connection;
    }

    return self::$connection;
  }
}

class DrupalRabbitMQ {

  /**
   * Static copy of the name of the queue.
   */
  protected $name;

  /**
   * Object that holds a channel to RAbbitMQ.
   */
  protected $channel;

  /**
   * Start working with a queue.
   *
   * @param string $name
   *   Arbitrary string. The name of the queue to work with.
   */
  public function __construct($name) {
    $this->name = $name;
  }

  /**
   * Obtain a singleton channel to the queue.
   */
  public function getChannel() {
    if ($this->channel) {
      return $this->channel;
    }

    $this->channel = RabbitMQConnection::get()->channel();

    // Initialize the queue.
    $this->getQueue($this->channel);
    return $this->channel;
  }

  /**
   * Declare a queue and obtain information about the queue.
   */
  protected function getQueue(AMQPChannel $channel, Array $options = array()) {
    $queue_options = array(
      'passive' => FALSE,
      // Whether the queue is persistant or not. A durable queue
      // is slower but can survive if rabbitMQ fails.
      'durable' => TRUE,
      'exclusive' => FALSE,
      'auto_delete' => FALSE,
      'nowait' => 'FALSE',
      'arguments' => NULL,
      'ticket' => NULL,
    );

    $queue_info = module_invoke_all('rabbitmq_queue_info');

    // Allow modules to override queue settings.
    if (isset($queue_info[$this->name])) {
      $queue_options += $queue_info[$this->name];
    }

    $queue_options += $options;
    $queue_options['name'] = $this->name;

    return $channel->queue_declare($this->name, $queue_options['passive'], $queue_options['durable'], $queue_options['exclusive'], $queue_options['auto_delete']);
  }

}

/**
 * RabbitMQ queue implementation.
 */
class RabbitMQQueue extends DrupalRabbitMQ implements DrupalReliableQueueInterface {

  /**
   * Array of message objects claimed from the queue.
   */
  protected $messages = array();

  /**
   * Add a queue item and store it directly to the queue.
   *
   * @param $data
   *   Arbitrary data to be associated with the new task in the queue.
   * @return
   *   TRUE if the item was successfully created and was (best effort) added
   *   to the queue, otherwise FALSE. We don't guarantee the item was
   *   committed to disk etc, but as far as we know, the item is now in the
   *   queue.
   */
  public function createItem($data) {
    $channel = $this->getChannel();
    // Data must be a string.
    $item = new AMQPMessage(serialize($data), array(
      'delivery_mode' => 2,
    ));
    $channel->basic_publish($item, '', $this->name);
    watchdog('rabbitmq', "Item sent to @queue", array("@queue" => $this->name), WATCHDOG_INFO);
  }

  /**
   * Retrieve the number of items in the queue.
   *
   * This is intended to provide a "best guess" count of the number of items in
   * the queue. Depending on the implementation and the setup, the accuracy of
   * the results of this function may vary.
   *
   * e.g. On a busy system with a large number of consumers and items, the
   * result might only be valid for a fraction of a second and not provide an
   * accurate representation.
   *
   * @return
   *   An integer estimate of the number of items in the queue.
   */
  public function numberOfItems() {
    // Retrive information about the queue without modifying it.
    list($name, $jobs, $consumers) = $this->getQueue(RabbitMQConnection::get()->channel(), array('passive' => TRUE));
    return $jobs;
  }

  /**
   * Claim an item in the queue for processing.
   *
   * @param $lease_time
   *   How long the processing is expected to take in seconds, defaults to an
   *   hour. After this lease expires, the item will be reset and another
   *   consumer can claim the item. For idempotent tasks (which can be run
   *   multiple times without side effects), shorter lease times would result
   *   in lower latency in case a consumer fails. For tasks that should not be
   *   run more than once (non-idempotent), a larger lease time will make it
   *   more rare for a given task to run multiple times in cases of failure,
   *   at the cost of higher latency.
   * @return
   *   On success we return an item object. If the queue is unable to claim an
   *   item it returns false. This implies a best effort to retrieve an item
   *   and either the queue is empty or there is some other non-recoverable
   *   problem.
   */
  public function claimItem($lease_time = 3600) {
    $this->getChannel()->basic_qos(null, 1, null);
    if (!$msg = $this->getChannel()->basic_get($this->name)) {
      return FALSE;
    }
    $msg->delivery_info['channel'] = $this->getChannel();
    $this->messages[$msg->delivery_info['delivery_tag']] = $msg;

    $item = new stdClass;
    $item->id = $msg->delivery_info['delivery_tag'];
    $item->data = unserialize($msg->body);
    $item->expire = time() + $lease_time;
    watchdog('rabbitmq', "Item :id claimed from @queue", array(":id" => $item->id, "@queue" => $this->name), WATCHDOG_INFO);
    return $item;
  }

  /**
   * Delete a finished item from the queue.
   *
   * @param $item
   *   The item returned by DrupalQueueInterface::claimItem().
   */
  public function deleteItem($item) {
    watchdog('rabbitmq', "Item :id acknowledged from @queue", array(":id" => $item->id, "@queue" => $this->name), WATCHDOG_INFO);
    return $this->messages[$item->id]->delivery_info['channel']->basic_ack($item->id); 
  }

  /**
   * Release an item that the worker could not process, so another
   * worker can come in and process it before the timeout expires.
   *
   * @param $item
   * @return boolean
   */
  public function releaseItem($item) {
    // The item will be released back when the connection closes.
    // So this just eliminates that capability to send an acknoledgement
    // to the server which would remove the item from the queue.
    unset($this->messages[$item->id]);
    return TRUE;
  }

  /**
   * Create a queue.
   *
   * Called during installation and should be used to perform any necessary
   * initialization operations. This should not be confused with the
   * constructor for these objects, which is called every time an object is
   * instantiated to operate on a queue. This operation is only needed the
   * first time a given queue is going to be initialized (for example, to make
   * a new database table or directory to hold tasks for the queue -- it
   * depends on the queue implementation if this is necessary at all).
   */
  public function createQueue() {
    return $this->getQueue($this->getChannel());
  }

  /**
   * Delete a queue and every item in the queue.
   */
  public function deleteQueue() {
    $this->getChannel()
         ->queue_delete($this->name);
  }
}
