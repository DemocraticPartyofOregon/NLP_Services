<?php

const STATE_EARLY_VOTING_READ_COUNT_LIMIT = 12000;

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importStateEarlyVotingBatch
 *
 * Read the state early voting file. For each row, look up the vanid for the
 * "Assigned Voter ID" (stateFileId) and, if found, save the "Ballot Received
 * Date" in the matchback table.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function importStateEarlyVotingBatch($arg, &$context): void
{
  $votersObj = Drupal::service('nlpservices.voters');
  $matchbacksObj = Drupal::service('nlpservices.matchbacks');
  $fileUri = $arg['fileUri'];
  $fieldPos = $arg['fieldPos'];

  $fh = fopen($fileUri, 'r');
  if (!$fh) {
    nlp_debug_msg('File failed to open, arg', $arg);
    $context['finished'] = 1;
    $context['results']['fileUri'] = $fileUri;
    return;
  }
  $filesize = filesize($fileUri);
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if (empty($context['sandbox']['seek'])) {
    // Read the header record.
    fgetcsv($fh, escape: '');
    $matchedCount = 0;
  } else {
    $seek = $context['sandbox']['seek'];
    fseek($fh, $seek);
    $matchedCount = $context['sandbox']['matchedCount'];
  }

  /** @noinspection PhpUnusedLocalVariableInspection */
  $transactionObj = $matchbacksObj->matchbackTransaction();

  $recordCount = 0;
  $done = TRUE;
  $batchVanids = [];

  $latestMatchbackDate = $matchbacksObj->getLatestMatchbackDate();
  $latestMatchbackTime = strtotime($latestMatchbackDate);

  do {
    $row = fgetcsv($fh, escape: '');
    if (!$row) {
      break; // We've processed the last row for this upload.
    }
    $recordCount++;

    $stateFileId = (int) ($row[$fieldPos['stateFileId']] ?? 0);
    $batchLimit = FALSE;
    if (!empty($stateFileId)) {
      $vanid = $votersObj->getVanidByStateId($stateFileId);
      if (!empty($vanid) && empty($batchVanids[$vanid]) && !$matchbacksObj->matchbackExists($vanid)) {
        $batchVanids[$vanid] = $vanid;

        $ballotReceivedDate = $row[$fieldPos['date']] ?? '';
        $ballotReceivedTime = strtotime($ballotReceivedDate);
        if (!empty($ballotReceivedTime)) {
          $convertedDate = date('Y-m-d', $ballotReceivedTime);
          if ($ballotReceivedTime > $latestMatchbackTime) {
            $latestMatchbackTime = $ballotReceivedTime;
            $latestMatchbackDate = $convertedDate;
          }
          $matchedCount++;
          // Create a record for this ballot received status and add it to a
          // group until there are 100 records to insert.
          $batchLimit = $matchbacksObj->insertMatchbacks($vanid, $convertedDate);
          nlp_debug_msg('State early voting matchback inserted, vanid', $vanid);
          nlp_debug_msg('State early voting matchback inserted, date', $convertedDate);
        }
      }
    }

    // When we have completed the batch limit of records, return to the
    // queue to continue processing with a refreshed timer. It also displays
    // progress to the user.
    if ($batchLimit || $recordCount > STATE_EARLY_VOTING_READ_COUNT_LIMIT) {
      $done = FALSE;
      $matchbacksObj->flushMatchbacks();
      if (!empty($latestMatchbackDate)) {
        $matchbacksObj->setLatestMatchbackDate($latestMatchbackDate);
      }

      // Remember where we are when resuming processing the file.
      $seek = ftell($fh);
      $context['sandbox']['seek'] = $seek;
      $context['finished'] = $seek / $filesize;
      $context['sandbox']['matchedCount'] = $matchedCount;
      break;
    }
  } while (TRUE);

  fclose($fh);

  if ($done) {
    $context['results']['fileUri'] = $fileUri;
    // We are done, so insert the last fragment of records.
    $matchbacksObj->flushMatchbacks();
    if (!empty($latestMatchbackDate)) {
      $matchbacksObj->setLatestMatchbackDate($latestMatchbackDate);
    }
    $context['finished'] = 1;
    $context['results']['matchedCount'] = $matchedCount;
  }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importStateEarlyVotingBatchFinished
 *
 * The batch operation is finished. Report the results.
 *
 * @param $success
 * @param $results
 * @param $unused
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function importStateEarlyVotingBatchFinished($success, $results, $unused): void
{
  $messenger = Drupal::messenger();

  $fileUri = $results['fileUri'];
  $fileEntities = Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $fileUri]);
  foreach ($fileEntities as $fileEntity) {
    $fileEntity->delete();
  }
  if ($success) {
    $matchedCount = $results['matchedCount'];
    $messenger->addStatus(t('@count ballots received matched to a vanid.', ['@count' => $matchedCount]));
  }
  else {
    $messenger->addError(t('An error occurred.'));
  }
}
