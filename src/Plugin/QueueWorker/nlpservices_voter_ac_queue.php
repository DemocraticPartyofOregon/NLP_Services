<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for checking and applying missing NLP Voter activist codes.
 *
 * No cron time is set, so core cron doesn't run this queue.  It is run by
 * nlpservices_cron() through NlpCronRunner, in priority order.
 *
 * @QueueWorker(
 *   id = "nlpservices_voter_ac_queue",
 *   title = @Translation("NLP Voter activist code check.")
 * )
 */
class nlpservices_voter_ac_queue extends QueueWorkerBase
{
  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $turfIndex = (int) $data['turfIndex'];
    $voterAcProcessorObj = Drupal::service('nlpservices.voter_ac_processor');
    $voterAcProcessorObj->processTurf($turfIndex);
  }

}
