<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\nlpservices\NlpResyncTurf;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A queue worker for processing matchback reports.
 *
 * @QueueWorker(
 *   id = "nlpservices_resync_turfs_queue",
 *   title = @Translation("Turf resync."),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_resync_turfs_queue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NlpResyncTurf $resyncTurfObj,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('nlpservices.resync_turf'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $this->resyncTurfObj->processTurfResync($data);
  }

}
