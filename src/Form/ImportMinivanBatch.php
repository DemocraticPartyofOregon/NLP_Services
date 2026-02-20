<?php

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importMinivanBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function importMinivanBatch($arg,&$context): void
{
  $fileType = $arg['fileType'];
  $subject = $arg['subject'];
  $month = $arg['month'];
  $day = $arg['day'];
  $minivanObj = Drupal::getContainer()->get('nlpservices.minivan');
  $counts = $minivanObj->processMinivanQueueReports($arg['records'],$fileType);
  $context['finished'] = 1;
  $context['results']['subject'] = $subject;
  $context['results']['month'] = $month;
  $context['results']['day'] = $day;
  $context['results']['counts'] = $counts;
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importMinivanFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $unused
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function importMinivanFinished($success, $results, $unused): void
{
  $messenger = Drupal::messenger();
  if ($success) {
    $counts = $results['counts'];
    $msg = $counts['recordCnt'].' records processed from email, processed: '.
      $counts['processedCnt'].', duplicates: '.$counts['duplicateCnt'].', rejected: '.$counts['rejectedCnt'].
      ', Not an NLP Voter: '.$counts['notNlp'].' - subject: '.$results['subject'].'  Date: '.$results['month'].' '.
      $results['day'];
    $messenger->addStatus($msg, TRUE);
    Drupal::logger('nlpservices')->notice('MiniVAN import: '.$msg);
  }
  else {
    $messenger->addError(t('An error occurred.'));
    Drupal::logger('nlpservices')->notice('MiniVAN import: An error occurred.');
  }
}
