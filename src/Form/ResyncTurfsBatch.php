<?php

const loopMax = 10;

/** @noinspection PhpUnused */
function resyncTurfsBatch($arg, &$context): void
{
  $context['finished'] = TRUE;
  $turfs = $arg['turfs'];
 
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if(empty($context['sandbox']['currentTurf'])) {
    // Read the header record.
    $context['sandbox']['currentTurf'] = 0;
    $context['sandbox']['changedTurfCount'] = 0;
    $context['sandbox']['countOfTurfs'] = count($turfs);
  }
  $currentTurf = $context['sandbox']['currentTurf'];
  $countOfTurfs = $context['sandbox']['countOfTurfs'];
  $changedTurfCount = $context['sandbox']['changedTurfCount'];
  
  
  $resyncTurfObj = Drupal::getContainer()->get('nlpservices.resync_turf');
  
  $lastTurf = $currentTurf+loopMax;
  if($lastTurf > $countOfTurfs) {
    $lastTurf = $countOfTurfs;
  }
  
  for ($turfNumber=$currentTurf; $turfNumber<$lastTurf; $turfNumber++) {
    $turfIndex = $turfs[$turfNumber];
    $result = $resyncTurfObj->resyncTurf($turfIndex);
    nlp_debug_msg('$result',$result);
    if($result == 'changed') {
      $changedTurfCount++;
    }
  }
  
  
  $currentTurf = $lastTurf;
  $percent = $currentTurf/$countOfTurfs;
  $context['sandbox']['currentTurf'] = $lastTurf;
  $context['sandbox']['changedTurfCount'] = $changedTurfCount;
  $context['finished'] = $percent;
  if ($currentTurf >= $countOfTurfs) {
    $context['finished'] = 1;
    $context['results']['changedTurfCount'] = $changedTurfCount;
    $context['results']['countOfTurfs'] = $countOfTurfs;
  }
  
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * resyncTurfsBatchFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function resyncTurfsBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
  $messenger->addStatus( t('Batch finished.'));
  if ($success) {
    $messenger->addStatus( t('Of the '.$results['countOfTurfs'].' older turfs, '.$results['changedTurfCount']
      .' required changes.'));
  }
  else {
    $messenger->addError( t('An error occurred.'));
  }
}
