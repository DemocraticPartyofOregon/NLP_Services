<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\nlpservices\NlpTurfAcChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A queue worker for checking activist code completion on expired turf records.
 *
 * No cron time is set, so core cron doesn't run this queue.  It is run by
 * nlpservices_cron() through NlpCronRunner, in priority order.
 *
 * @QueueWorker(
 *   id = "nlpservices_turf_ac_check_queue",
 *   title = @Translation("NLP turf expired activist code check.")
 * )
 */
class nlpservices_turf_ac_check_queue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NlpTurfAcChecker $turfAcCheckerObj,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('nlpservices.turf_ac_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $this->turfAcCheckerObj->checkExpiredTurfAc($data);
  }

}
