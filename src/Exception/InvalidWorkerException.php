<?php

namespace Drupal\rabbitmq\Exception;

use Throwable;

/**
 * Used when a worker plugin id does not match an existing plugin.
 */
class InvalidWorkerException extends \Exception {

  /**
   * InvalidWorkerException constructor.
   *
   * @param string $message
   *   The message.
   * @param int $code
   *   The code.
   * @param \Throwable|NULL $previous
   *   The previous exception to use in stack trace.
   */
  public function __construct(
    $message = 'Queue worker does not implement the worker interface.',
    $code = 0,
    \Throwable $previous = NULL
  ) {
    parent::__construct($message, $code, $previous);
  }

}
