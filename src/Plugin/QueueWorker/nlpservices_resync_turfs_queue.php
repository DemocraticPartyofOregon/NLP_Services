<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for processing matchback reports.
 *
 * @QueueWorker(
 *   id = "nlpservices_resync_turfs_queue",
 *   title = @Translation("Turf resync."),
 *   cron = {"time" = 1800}
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
