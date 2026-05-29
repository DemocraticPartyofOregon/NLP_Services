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
  $minivanObj = Drupal::service('nlpservices.minivan');
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
    $messenger->addStatus(t(
      '@count records processed from email, processed: @processed, duplicates: @dupes, rejected: @rejected, Not an NLP Voter: @notNlp - subject: @subject  Date: @month @day',
      [
        '@count'     => $counts['recordCnt'],
        '@processed' => $counts['processedCnt'],
        '@dupes'     => $counts['duplicateCnt'],
        '@rejected'  => $counts['rejectedCnt'],
        '@notNlp'    => $counts['notNlp'],
        '@subject'   => $results['subject'],
        '@month'     => $results['month'],
        '@day'       => $results['day'],
      ]
    ), TRUE);
    Drupal::logger('nlpservices')->notice('MiniVAN import: @count records processed, processed: @processed, duplicates: @dupes, rejected: @rejected, not NLP: @notNlp, subject: @subject, date: @month @day',
      [
        '@count'     => $counts['recordCnt'],
        '@processed' => $counts['processedCnt'],
        '@dupes'     => $counts['duplicateCnt'],
        '@rejected'  => $counts['rejectedCnt'],
        '@notNlp'    => $counts['notNlp'],
        '@subject'   => $results['subject'],
        '@month'     => $results['month'],
        '@day'       => $results['day'],
      ]);
  }
  else {
    $messenger->addError(t('An error occurred.'));
    Drupal::logger('nlpservices')->notice('MiniVAN import: An error occurred.');
  }
}
