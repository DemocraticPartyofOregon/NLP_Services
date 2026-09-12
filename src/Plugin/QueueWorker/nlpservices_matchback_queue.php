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
 * @QueueWorker(
 *   id = "nlpservices_matchback_queue",
 *   title = @Translation("MiniVAN canvass response Queue Worker"),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_matchback_queue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    $counts = $this->matchbacksObj->processMatchbackFile($data['filePath']);
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
