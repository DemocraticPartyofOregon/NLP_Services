<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker that checks VAN shows the activist code changes NLP Services
 * made, and logs the voters it doesn't.
 *
 * No cron time is set, so core cron doesn't run this queue.  It is run by
 * nlpservices_cron() through NlpCronRunner, in priority order.
 *
 * @QueueWorker(
 *   id = "nlpservices_ac_verify_queue",
 *   title = @Translation("NLP activist code change check.")
 * )
 */
class nlpservices_ac_verify_queue extends QueueWorkerBase
{
  /**
   * VAN can take seconds to show a change it accepted. A change queued less
   * than this long ago is put back until it is older.
   */
  const int MIN_AGE_SECS = 120;

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $age = time() - (int) ($data['queuedAt'] ?? 0);
    if ($age < self::MIN_AGE_SECS) {
      throw new DelayedRequeueException(self::MIN_AGE_SECS - $age);
    }
    Drupal::service('nlpservices.activist_codes')->verifyActivistCodeChange($data);
  }

}
