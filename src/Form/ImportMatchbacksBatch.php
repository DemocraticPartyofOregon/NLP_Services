<?php /** @noinspection PhpUnusedAliasInspection */

use Drupal\nlpservices\NlpMatchbacks;


const READ_COUNT_LIMIT = 12000;

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importMatchbacksBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function importMatchbacksBatch($arg,&$context): void
{
  $matchbacksObj = Drupal::getContainer()->get('nlpservices.matchbacks');
  $fileUri = $arg['fileUri'];
  $fieldPos = $arg['fieldPos'];
  $fileType = $arg['fileType'];
  // Open the ballot received the file.
  $fh = fopen($fileUri, "r");
  if (empty($fh)) {
    nlp_debug_msg('File failed to open, arg', $arg);
    $context['finished'] = 1;
    $context['results']['fileUri'] = $fileUri;
    return;
  }
  $filesize = filesize($fileUri);
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if(empty($context['sandbox']['seek'])) {
    // Read the header record.
    fgets($fh);
    $matchbackCount = 0;
  } else {
    // Seek to where we will restart.
    $seek = $context['sandbox']['seek'];
    fseek($fh, $seek);
    $matchbackCount = $context['sandbox']['matchbackCount'];
  }

  // Let indexing happen in the background (much, much faster).
  /** @noinspection PhpUnusedLocalVariableInspection */
  $transactionObj = $matchbacksObj->matchbackTransaction();

  $recordCount = 0;
  $done = TRUE;
  $batchVanids = [];

  $latestMatchbackDate = $matchbacksObj->getLatestMatchbackDate();
  $latestMatchbackTime = strtotime($latestMatchbackDate);

  do {
    if($fileType == 'csv') {
      $voterInfo = fgetcsv($fh);
      if (empty($voterInfo)) {break;} //We've processed the last voter for this upload.
      $recordCount++;
    } else {
      $voter_raw = fgets($fh);
      if (!$voter_raw) {break;} //We've processed the last voter for this upload.
      $recordCount++;
      $voter_record = trim(strip_tags(htmlentities(stripslashes($voter_raw))));
      // Parse the voter record into the various fields.
      $voterInfo = explode("\t", $voter_record);
    }

    // Get the county name, party, and ballot received date (if set) for this voter.
    // If we have a ballot received date for a Dem, schedule for the insert.
    $br = !empty($voterInfo[$fieldPos['ballotReceived']]);
    $batchLimit = FALSE;
    if ($br) {
      $matchbackCount++;
      $vanid = $voterInfo[$fieldPos['vanid']];
      if(empty($vanid)) {continue;}
      // Check for a duplicate.
      if(!empty($batchVanids[$vanid])) {continue;}
      $batchVanids[$vanid] = $vanid;

      // If the record already exists, skip the insert.
      $exists = $matchbacksObj->matchbackExists($vanid);
      if($exists) {continue;}
      
      $ballotReceivedDate = $voterInfo[$fieldPos['ballotReceived']];
      if(empty($ballotReceivedDate)) {continue;}
      $ballotReceivedTime = strtotime($ballotReceivedDate);  // Convert US date to time.
      if($ballotReceivedTime === FALSE OR $ballotReceivedTime==0) {continue;}
      $convertedDate = date('Y-m-d',$ballotReceivedTime);  // Convert to ISO date.
      if($ballotReceivedTime > $latestMatchbackTime) {
        $latestMatchbackTime = $ballotReceivedTime;
        $latestMatchbackDate = $convertedDate;
      }
      // Create a record for this ballot received status and add it to a
      // group until there are 100 records to insert.
      $batchLimit = $matchbacksObj->insertMatchbacks($vanid,$convertedDate);
    }
    
    // When we have completed the batch limit of records, return to the
    // queue to continue processing with a refreshed timer.   It also displays
    // progress to the user.
    if ($batchLimit OR $recordCount>READ_COUNT_LIMIT) {
      $done = FALSE;
      $matchbacksObj->flushMatchbacks();
      $matchbacksObj->setLatestMatchbackDate($latestMatchbackDate);

      // Remember where we are when resuming processing the file.
      $seek = ftell($fh);
      $context['sandbox']['seek'] = $seek;
      $context['finished'] = $seek/$filesize;
      $context['sandbox']['matchbackCount'] = $matchbackCount;
      break;
    }
  } while (TRUE);

  if($done) {
    $context['results']['fileUri'] = $fileUri;
    // We are done, so insert the last fragment of records.
    $matchbacksObj->flushMatchbacks();
    $matchbacksObj->setLatestMatchbackDate($latestMatchbackDate);
    $context['finished'] = 1;
    $context['results']['matchbackCount'] = $matchbackCount;
  }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importMatchbacksBatchFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $unused
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function importMatchbacksBatchFinished($success, $results, $unused): void
{
  $messenger = Drupal::messenger();

  $fileObj = Drupal::getContainer()->get('file_system');
  $fileUri = $results['fileUri'];
  $fileObj->unlink($fileUri);
  if ($success) {
    // Report results.
    $matchbackCount = $results['matchbackCount'];
    $messenger->addStatus(t('@count ballots received.', ['@count' => $matchbackCount]));
    $messenger->addStatus(t('The NLP voted status successfully updated.'));
  }
  else {
    $messenger->addError(t('An error occurred.'));
  }
}
