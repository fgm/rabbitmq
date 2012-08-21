<?php

/**
 * Implementation of hook_drush_command().
 */
function rabbitmq_drush_command() {
  $commands['rabbitmq-worker'] = array(
    'arguments' => array(
      'worker' => t('The name of the queue to process'),
    ),
    'description' => t('Connect to RabbitMQ and wait for jobs to do'),
  );
  return $commands;
}

/**
 * Command callback: Create a queue worker.
 */ 
function drush_rabbitmq($queue) {
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
      $item->expire = time() + $lease_time;

      // Call the queue worker.
      $callback($item);

      // Remove the item from the queue.
      $msg->delivery_info['channel']->basic_ack($item->id);
      watchdog('rabbitmq', "Item :id acknowledged from @queue", array(":id" => $item->id, "@queue" => $queue), WATCHDOG_INFO);
    }
    catch (Exception $e) {
      watchdog_exception('rabbitmq', $e);
      $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], TRUE);
    }
  });

  // Begin listening for messages to process.
  while (count($rabbitmq->getChannel()->callbacks)) {
    $rabbitmq->getChannel()->wait();
  }
}
