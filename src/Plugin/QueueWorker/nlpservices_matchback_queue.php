<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\nlpservices\NlpMatchbacks;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
class nlpservices_matchback_queue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The most records imported for one queue item.
   */
  const int CHUNK_ROWS = 25000;

  const array COUNT_KEYS = ['recordCount', 'newRecordCount', 'skippedRecordCount', 'rejectedRecordCount'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NlpMatchbacks $matchbacksObj,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('nlpservices.matchbacks'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $startTime = time();
    $chunk = $this->matchbacksObj->processMatchbackFile($data['filePath'], (int) ($data['offset'] ?? 0),
      self::CHUNK_ROWS);
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
