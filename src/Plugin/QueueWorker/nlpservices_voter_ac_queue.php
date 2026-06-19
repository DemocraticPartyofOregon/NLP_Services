<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for checking and applying missing NLP Voter activist codes.
 *
 * @QueueWorker(
 *   id = "nlpservices_voter_ac_queue",
 *   title = @Translation("NLP Voter activist code check."),
 *   cron = {"time" = 1800}
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
