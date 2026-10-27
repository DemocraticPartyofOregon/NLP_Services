<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\QueueWorkerBase;

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
