<?php

const MAX_QUEUE_LIMIT = '100';

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importReportsBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function importReportsBatch($arg,&$context): void
{
  
  $context['finished'] = TRUE;
  $uri = $arg['uri'];
  
  $fh = fopen($uri, "r");
  if (empty($fh)) {
    Drupal::logger('nlpservices')->notice('Failed to open reports file.');
    $context['finished'] = TRUE;
    return;
  }
  
  $filesize = filesize($uri);
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if(empty($context['sandbox']['seek'])) {
    // Read the header record.
    $context['sandbox']['recordCount'] = 0;
    $csvColumnNames = fgetcsv($fh);
    $context['sandbox']['csvColumnNames'] = $csvColumnNames;
  } else {
    // Seek to where we will restart.
    $seek = $context['sandbox']['seek'];
    fseek($fh, $seek);
    $csvColumnNames = $context['sandbox']['csvColumnNames'];
  }
  $recordCount = $context['sandbox']['recordCount'];
  
  $reports = Drupal::getContainer()->get('nlpservices.reports');
  
  $done = TRUE;
  do {
    $report = fgetcsv($fh);
    if(empty($report)) {break;}
    $recordCount++;
    
    $namedFields = (array) array_combine($csvColumnNames, $report);
    
    $namedFields['text'] = trim(strip_tags(htmlentities(stripslashes($namedFields['text']),ENT_QUOTES)));
    if(strlen($namedFields['text']) >= 1023) {
      $namedFields['text'] = substr($namedFields['text'],0,1022);
    }
    
    $contactDate = $namedFields['contactDate'];
    $contactTime = strtotime($contactDate);
    $date = date('Y-m-d',$contactTime);
    $namedFields['contactDate'] = $date;
    
    $value = str_replace("'","\'",$namedFields['value']);
    $namedFields['value'] = $value;

    unset($namedFields['reportIndex']);
    
    $batchLimit = $reports->insertNlReports($namedFields);
    
    if ($batchLimit) {
      $done = FALSE;
      $seek = ftell($fh);
      $context['sandbox']['seek'] = $seek;
      $percent = $seek/$filesize;
      $context['finished'] = $percent;
      // We are done when the read pointer is at the end.
      if($seek == $filesize) {
        $done = TRUE;
      }
      $context['sandbox']['recordCount'] = $recordCount;
      break;
    }
    
  } while (TRUE);  // Keep looping to read records until the break at EOF.
  
  // Finish the batch if we are done.
  if($done) {
    $reports->flushNlReports();
    $context['finished'] = 1;
    $context['results']['recordCount'] = $recordCount;
    $context['results']['uri'] = $uri;
  }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importReportsBatchFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function importReportsBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
  $messenger->addStatus( t('Batch finished.'));
  if ($success) {
    $recordCount = $results['recordCount'];
    $messenger->addStatus( t('@count records processed.',
      array('@count' => $recordCount)));
    $messenger->addStatus( t('The reports file successfully updated.'));
  }
  else {
    $messenger->addError( t('An error occurred.'));
  }
  
}
