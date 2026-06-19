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
  
  private array $matchbackCountKeys =
    ['recordCount','newRecordCount','skippedRecordCount','rejectedRecordCount'];
  
  /**
   * processItem
   *
   * Process a single item from the queue.  The items are prepared by the cron hook in the
   * module main file.  The data item keys are:
   *   progress - start, end, chunk
   *   reports - array of reports
   *
   * The first item is the start, and the last is the end of the items in the queue.  These are used
   * to time the processing and to count the number of reports.  The intermediate items are the chunks of
   * records with matchback dates.
   *
   * At the end, the counts are logged to the watchdog log and a display to the use if the cron was
   * invoked from the admin page.  The counts have the following keys:
   *  recordCount - total number of records in this matchback update.
   *  newRecordCount - number of new records written to the database.
   *  skippedRecordCount - number of records that were already in the database and were skipped.
   *  rejectedRecordCount - number of records that were rejected, either because the vanid or date was empty.
   *
   * @param $data
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $progress = (!empty($data['progress'])? $data['progress']:'other');
    $matchbackCounts = [];
    switch ($progress) {
      case 'start':
        foreach ($this->matchbackCountKeys as $matchbackCountKey) {
           $matchbackCounts[$matchbackCountKey] = 0;
         }
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        $sessionDataObj->set('matchbackQueueStart', time());
        $sessionDataObj->set('matchbackCounts', $matchbackCounts);
        break;
      case 'end':
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        $startTime = $sessionDataObj->get('matchbackQueueStart');
        nlp_debug_msg('matchback_queue_start',$startTime);
        $elapsedTime = time() - $startTime;
        nlp_debug_msg('matchback_queue_elapsed',$elapsedTime);
        $hours = floor($elapsedTime / 3600);
        $minutes = floor(($elapsedTime % 3600) / 60);
        $seconds = $elapsedTime % 60;
        $matchbackCounts =  $sessionDataObj->get('matchbackCounts');
        $msg = "<br> Matchback update elapsed time: $hours:$minutes:$seconds ".
        '<br> Records processed from email: '.$matchbackCounts['recordCount'].
        '<br> New records: '.$matchbackCounts['newRecordCount'].
        '<br> Skipped records: '.$matchbackCounts['skippedRecordCount'].
        '<br> Rejected records: '.$matchbackCounts['rejectedRecordCount'];
        nlp_debug_msg('matchback_queue_results',t($msg));
        Drupal::logger('nlpservices')->notice('Matchback import: '.$msg);
        break;
      case 'chunk':
        $sessionDataObj = Drupal::getContainer()->get('nlpservices.session_data');
        $matchbackObj = Drupal::getContainer()->get('nlpservices.matchbacks');
        $counts = $matchbackObj->processMatchbackQueueReports($data['reports']);
        $matchbackCounts =  $sessionDataObj->get('matchbackCounts');
        foreach ($this->matchbackCountKeys as $matchbackCountKey) {
          $matchbackCounts[$matchbackCountKey] = $matchbackCounts[$matchbackCountKey] + $counts[$matchbackCountKey];
        }
        $sessionDataObj->set('matchbackCounts', $matchbackCounts);
        break;
        
    }
    
  }
  
}
