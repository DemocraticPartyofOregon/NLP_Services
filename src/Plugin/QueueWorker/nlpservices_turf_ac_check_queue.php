<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for checking activist code completion on expired turf records.
 *
 * @QueueWorker(
 *   id = "nlpservices_turf_ac_check_queue",
 *   title = @Translation("NLP turf expired activist code check."),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_turf_ac_check_queue extends QueueWorkerBase
{
  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $turfAcCheckerObj = Drupal::service('nlpservices.turf_ac_checker');
    $turfAcCheckerObj->checkExpiredTurfAc($data);
  }

}
