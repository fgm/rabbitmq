<?php

namespace Drupal\rabbitmq;

/**
 * Interface DecoderAwareWorker defines optional behaviors for queue workers.
 */
interface DecoderAwareWorkerInterface {

  /**
   * Get the decoder to apply to the payload.
   *
   * @return callable
   *   The decoder.
   */
  public function getDecoder(): callable;

}
