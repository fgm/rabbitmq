<?php

/**
 * Implementation of hook_drush_command().
 */
function rabbitmq_drush_command() {
  $commands['rabbitmq-worker'] = array(
    'arguments' => array(
      'worker' => t('The name of the queue to process'),
    ),
    'options' => array(
      'memory_limit' => t('Set the max amount of memory the worker should occupie before exiting. Given in megabytes.'),
      'max_iterations' => t('Number of iterations to process before exiting. If not present, exit criteria will not evaluate the amount of iterations processed.'),
    ),
    'description' => t('Connect to RabbitMQ and wait for jobs to do'),
  );
  return $commands;
}

/**
 * Command callback: Create a queue worker.
 */ 
function drush_rabbitmq_worker($queue) {
  $queue_info = module_invoke_all('rabbitmq_queue_info');
  if (!isset($queue_info[$queue])) {
    $queue_info = module_invoke_all('cron_queue_info');
  }
  if (!isset($queue_info[$queue], $queue_info[$queue]['worker callback'])) {
    return drush_set_error("No known callback for queue: $queue");
  }
  $callback = $queue_info[$queue]['worker callback'];

  // Before we start listening for messages, make sure the callback
  // worker is callable.
  if (!function_exists($callback)) {
    return drush_set_error("Callback function for queue is not callable.");
  }

  $rabbitmq = new DrupalRabbitMQ($queue);
  $rabbitmq->getChannel()->basic_qos(null, 1, null);
  $rabbitmq->getChannel()->basic_consume($queue, '', false, false, false, false, function ($msg) use ($callback, $queue) {
    watchdog('rabbitmq', 'Recieved queued message: :id', array(':id' => $msg->delivery_info['delivery_tag']), WATCHDOG_INFO);

    try {
      // Build the item to pass to the queue worker.
      $item = new stdClass;
      $item->id = $msg->delivery_info['delivery_tag'];
      $item->data = unserialize($msg->body);

      // Call the queue worker.
      $callback($item->data);

      // Remove the item from the queue.
      $msg->delivery_info['channel']->basic_ack($item->id);
      watchdog('rabbitmq', "Item :id acknowledged from @queue", array(":id" => $item->id, "@queue" => $queue), WATCHDOG_INFO);
    }
    catch (Exception $e) {
      watchdog_exception('rabbitmq', $e);
      $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
    }
  });

  watchdog('rabbitmq', "RabbitMQ worker ready to recieve messages", array(), WATCHDOG_INFO);

  // Begin listening for messages to process.
  while (count($rabbitmq->getChannel()->callbacks)) {
    $rabbitmq->getChannel()->wait();

    // Evaluate if worker should exit.
    // If the --memory_limit option is set, check the memory usage and
    // exit if the limit has been exceeded or met.
    if ($memory_limit = drush_get_option('memory_limit', FALSE)) {
      $memory_usage = memory_get_peak_usage() / 1024 / 1024;
      if ($memory_usage >= $memory_limit) {
        watchdog('rabbitmq', "RabbitMQ worker has reached or exceeded set memory limit of @amountMB and will now exit.", array("@amount" => $memory_limit), WATCHDOG_INFO);
        break;
      }
    }

    // If max iterations are set, don't allow loop to continue longer than max iterations.
    if ($max_iterations = drush_get_option('max_iterations', FALSE)) {
      $i = isset($i) ? $i : 0;
      $i++;
      if ($max_iterations <= $i) {
        watchdog('rabbitmq', "Rabbit worker has reached max number of iterations. Exiting.", array(), WATCHDOG_INFO);
        break;
      }
    }
  }
}
