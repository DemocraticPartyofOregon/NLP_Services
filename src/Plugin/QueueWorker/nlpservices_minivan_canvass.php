<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A queue worker for processing MiniVAN canvass responses.
 *
 * @QueueWorker(
 *   id = "nlpservices_minivan_canvass",
 *   title = @Translation("MiniVAN canvass response Queue Worker"),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_minivan_canvass extends QueueWorkerBase {
  //private array $minivanCountKeys =
  //  ['recordCount','newRecordCount','skippedRecordCount','rejectedRecordCount'];
  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    //nlp_debug_msg('$data',$data);
    $progress = (!empty($data['progress'])? $data['progress']:'other');
    $minivanCounts = [];
    switch ($progress) {
      case 'start':
        $minivanObj = Drupal::getContainer()->get('nlpservices.minivan');
        $minivanCountKeys = $minivanObj->getMinivanCountKeys();

        foreach ($minivanCountKeys as $minivanCountKey) {
          $minivanCounts[$minivanCountKey] = 0;
        }
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        $sessionDataObj->set('minivanQueueStart', time());
        $sessionDataObj->set('minivanCounts', $minivanCounts);
        break;
      case 'end':
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        $startTime = $sessionDataObj->get('minivanQueueStart');
        //nlp_debug_msg('minivan_queue_start',$startTime);
        $elapsedTime = time() - $startTime;
        //nlp_debug_msg('minivan_queue_elapsed',$elapsedTime);
        $hours = floor($elapsedTime / 3600);
        $minutes = floor(($elapsedTime % 3600) / 60);
        $seconds = $elapsedTime % 60;
        $minivanCounts =  $sessionDataObj->get('minivanCounts');
        $msg = "<br> MiniVAN update elapsed time: $hours:$minutes:$seconds ".
          '<br> Records processed from email: '.$minivanCounts['recordCount'].
          '<br> New records: '.$minivanCounts['newRecordCount'].
          '<br> Skipped records: '.$minivanCounts['skippedRecordCount'].
          '<br> Rejected records: '.$minivanCounts['rejectedRecordCount'].
          '<br> Not an NLP Voter: '.$minivanCounts['notNlpCount'];
        nlp_debug_msg('minivan_queue_results',t($msg));
        
        Drupal::logger('nlpservices')->notice('Matchback import: '.$msg);
        break;
      case 'chunk':
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        
        $minivanObj = Drupal::getContainer()->get('nlpservices.minivan');
        $counts = $minivanObj->processMinivanQueueReports($data['reports'],$data['fileType']);
        //nlp_debug_msg('minivan_queue_counts',$counts);

        $minivanCounts =  $sessionDataObj->get('minivanCounts');
        $minivanCountKeys = $minivanObj->getMinivanCountKeys();
        foreach ($minivanCountKeys as $minivanCountKey) {
          $minivanCounts[$minivanCountKey] = $minivanCounts[$minivanCountKey] + $counts[$minivanCountKey];
        }
        $sessionDataObj->set('minivanCounts', $minivanCounts);
        break;
      
    }
    
  }
  
}
