<?php

namespace Drupal\rabbitmq\Controller;

use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\rabbitmq\Queue\Queue;
use Drupal\rabbitmq\Queue\QueueFactory;
use PhpAmqpLib\Connection\AbstractConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * Contains the controller for rabbitmq.properties.
 */
class ServerPropertiesController {

  use StringTranslationTrait;

  /**
   * Controller for rabbitmq.properties.
   *
   * @return array
   *   A render array.
   */
  public function build() {
    try {
      /** @var \Drupal\rabbitmq\Queue\Queue $backend */
      $backend = \Drupal::queue('queue.rabbitmq');
    }
    catch (\ErrorException $e) {
      $build = [
        '#markup' => $this->t('<h2>Error</h2><p>Could not access the queue. Check the <a href=":url">status page</a>.</p>', [
          ':url' => Url::fromRoute('system.status')->toString(),
        ]),
      ];
      if (Settings::get('queue_default') == 'queue.rabbitmq') {
        QueueFactory::overrideSettings();
      }
      return $build;
    }

    if (!$backend instanceof Queue) {
      $build = [
        '#markup' => $this->t('<h2>Error</h2><p>RabbitMQ queue is reachable, but its service is not configured. Check the <a href=":url">status page</a>.</p>', [
          ':url' => Url::fromRoute('system.status')->toString(),
        ]),
      ];
      if (Settings::get('queue_default') == 'queue.rabbitmq') {
        QueueFactory::overrideSettings();
      }
      return $build;
    }

    $serverProperties = $backend
      ->getChannel()
      ->getConnection()
      ->getServerProperties();

    // Read the latest minor version from the library CHANGELOG.md.
    $rc = new \ReflectionClass(AbstractConnection::class);
    $ac = $rc->getFileName();
    $changelog = file(realpath(dirname($ac) . '/../../CHANGELOG.md'));
    $filteredChangelog = array_filter($changelog, function ($row) {
      return preg_match('/^## [\d\.]+ -/', $row);
    });
    $latestChange = preg_replace('/^## /', '', reset($filteredChangelog));

    // Build the library properties from in-code data and changelog data.
    $libraryProperties = AbstractConnection::$LIBRARY_PROPERTIES;
    $usedLibraryProperties = [
      $this->t('Product: @product', ['@product' => $libraryProperties['product'][1]]),
      $this->t('Version: @version', ['@version' => $libraryProperties['version'][1]]),
      $this->t('Changelog version: @version', ['@version' => $latestChange]),
    ];

    $build = [
      'server' => [
        '#type' => 'details',
        '#title' => t('Server properties'),
        '#open' => TRUE,
        'properties' => [
          '#markup' => '<pre>' . Yaml::dump($serverProperties, 3, 2) . '</pre>',
        ],
      ],

      'driver' => [
        '#type' => 'details',
        '#title' => t('Driver library properties'),
        '#open' => TRUE,
        'properties' => [
          '#theme' => 'item_list',
          '#items' => $usedLibraryProperties,
        ],
      ],
    ];

    return $build;
  }

}
