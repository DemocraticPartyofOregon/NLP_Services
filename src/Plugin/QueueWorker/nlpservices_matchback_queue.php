<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for processing matchback reports.
 *
 * @QueueWorker(
 *   id = "nlpservices_matchback_queue",
 *   title = @Translation("MiniVAN canvass response Queue Worker"),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_matchback_queue extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $matchbackObj = Drupal::service('nlpservices.matchbacks');
    $startTime = time();
    $counts = $matchbackObj->processMatchbackFile($data['filePath']);
    $elapsed = time() - $startTime;
    $h = floor($elapsed / 3600);
    $m = floor(($elapsed % 3600) / 60);
    $s = $elapsed % 60;
    $msg = "Matchback import elapsed: $h:$m:$s" .
      ' Records=' . $counts['recordCount'] .
      ' New=' . $counts['newRecordCount'] .
      ' Skipped=' . $counts['skippedRecordCount'] .
      ' Rejected=' . $counts['rejectedRecordCount'];
    Drupal::logger('nlpservices')->notice($msg);
  }

}
