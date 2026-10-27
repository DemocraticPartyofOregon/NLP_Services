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
 * A large file is imported in chunks.  When a chunk is done, an item for the
 * rest of the file is queued, with the counts so far, and the import goes on
 * in this cron run if there is time or in the next one.
 *
 * @QueueWorker(
 *   id = "nlpservices_matchback_queue",
 *   title = @Translation("MiniVAN canvass response Queue Worker")
 * )
 */
class nlpservices_matchback_queue extends QueueWorkerBase {

  /**
   * The most records imported for one queue item.
   */
  const int CHUNK_ROWS = 25000;

  const array COUNT_KEYS = ['recordCount', 'newRecordCount', 'skippedRecordCount', 'rejectedRecordCount'];

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $matchbackObj = Drupal::service('nlpservices.matchbacks');
    $startTime = time();
    $chunk = $matchbackObj->processMatchbackFile($data['filePath'], (int) ($data['offset'] ?? 0), self::CHUNK_ROWS);
    $counts = [];
    foreach (self::COUNT_KEYS as $key) {
      $counts[$key] = ($data['counts'][$key] ?? 0) + $chunk[$key];
    }
    $elapsed = ($data['elapsed'] ?? 0) + time() - $startTime;
    $chunks = ($data['chunks'] ?? 0) + 1;

    if (!empty($chunk['nextOffset'])) {
      Drupal::queue('nlpservices_matchback_queue')->createItem([
        'filePath' => $data['filePath'],
        'offset' => $chunk['nextOffset'],
        'counts' => $counts,
        'elapsed' => $elapsed,
        'chunks' => $chunks,
      ]);
      return;
    }

    $h = floor($elapsed / 3600);
    $m = floor(($elapsed % 3600) / 60);
    $s = $elapsed % 60;
    $msg = "Matchback import elapsed: $h:$m:$s" .
      ' Records=' . $counts['recordCount'] .
      ' New=' . $counts['newRecordCount'] .
      ' Skipped=' . $counts['skippedRecordCount'] .
      ' Rejected=' . $counts['rejectedRecordCount'] .
      ' Chunks=' . $chunks;
    Drupal::logger('nlpservices')->notice($msg);
  }

}
