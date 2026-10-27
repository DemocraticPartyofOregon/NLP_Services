<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for processing matchback reports.
 *
 * No cron time is set, so core cron doesn't run this queue.  It is run by
 * nlpservices_cron() through NlpCronRunner, in priority order.
 *
 * @QueueWorker(
 *   id = "nlpservices_resync_turfs_queue",
 *   title = @Translation("Turf resync.")
 * )
 */
class nlpservices_resync_turfs_queue extends QueueWorkerBase
{
  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $resyncTurfObj = Drupal::getContainer()->get('nlpservices.resync_turf');
    $resyncTurfObj->processTurfResync($data);
  }
  
}
