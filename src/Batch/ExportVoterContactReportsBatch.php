<?php

namespace Drupal\nlpservices\Batch;

use Drupal;

class ExportVoterContactReportsBatch {

  /**
   * Query the database and write records to a file.
   */
  public static function process(array $arg, array &$context): void {
    $factory = Drupal::service('tempstore.private');
    $store = $factory->get('nlpservices.session_data');
    $votersExist = $store->get('votersExist');
    if (empty($votersExist)) {
      $votersExist = [];
    }

    $reportsUri = $arg['uri'];
    $startCycle = $arg['startCycle'];
    $endCycle = $arg['endCycle'];
    $currentCycleOnly = $arg['currentCycleOnly'];
    $cycleOptions = $arg['cycleOptions'];
    $countOfRecords = $arg['countOfRecords'];

    $removeInactive = $arg['removeInactive'];

    // Open the input file and position for writing at the end.
    $fh = fopen($reportsUri, "a");
    if (!$fh) {
      Drupal::messenger()->addStatus(t('File open error: @uri', ['@uri' => $reportsUri]));
      $context['finished'] = TRUE;
      return;
    }
    $context['finished'] = 0;
    if (empty($context['sandbox']['recordCount'])) {
      $nextCycleRecord = $recordCount = 0;
      $workingCycle = $startCycle;
      $context['sandbox']['workingCycle'] = $workingCycle;
      $votersNotFound = 0;
    } else {
      // Seek to where we will restart.
      $recordCount = $context['sandbox']['recordCount'];
      $nextCycleRecord = $context['sandbox']['nextCycleRecord'];
      $workingCycle = $context['sandbox']['workingCycle'];
      $votersNotFound = $context['sandbox']['votersNotFound'];
    }
    $reportsObj = Drupal::service('nlpservices.reports');
    $voterObj = Drupal::service('nlpservices.voters');
    $apiVoterObj = Drupal::service('nlpservices.api_voter');

    $selectedCycle = $cycleOptions[$workingCycle];
    $result = $reportsObj->selectAllReports($nextCycleRecord, $selectedCycle);
    // Get record one at a time.
    $cycleRecordCount = 0;
    do {
      $rawRecord = $result->fetchAssoc();
      if (!$rawRecord) {
        break;
      }  // The last record processed.
      $cycleRecordCount++;
      $record = $reportsObj->prepareExportRecord($rawRecord);

      $vanid = $rawRecord['vanid'];
      $voterExists = TRUE;
      if ($removeInactive) {
        if (isset($votersExist[$vanid])) {
          $voterExists = $votersExist[$vanid];
        } else {
          $votersNotFound++;
          $voterExists = $apiVoterObj->voterExists($vanid);
          $votersExist[$vanid] = $voterExists;
        }
      }

      if ($voterExists) {
        // Get the cd of the voter if we have it.
        if ($currentCycleOnly && empty($record['cd'])) {
          $record['cd'] = $voterObj->getVoterCd($vanid);
        }
        fputcsv($fh, $record, escape: '');
      }

    } while (TRUE);
    fclose($fh);

    $store->set('votersExist', $votersExist);

    // Finish the batch if we are done.
    if ($cycleRecordCount != $reportsObj::BATCH_LIMIT) {
      // We're done with the last record.
      $recordCount += $cycleRecordCount;
      $context['sandbox']['recordCount'] = $recordCount;

      if ($workingCycle == $endCycle) {
        // Done with the last cycle.
        $context['finished'] = 1;
        $context['results']['records'] = $context['sandbox']['recordCount'];
        $context['results']['uri'] = $reportsUri;
        $context['results']['votersExistCount'] = count($votersExist);
        $context['results']['votersNotFound'] = $votersNotFound;

      } else {
        $context['sandbox']['workingCycle'] = ++$workingCycle;
        $context['sandbox']['nextCycleRecord'] = 0;
        $context['sandbox']['votersNotFound'] = $votersNotFound;
        $percent = $recordCount / $countOfRecords;
        if ($percent == 1) {
          $percent = 0.999;
        }
        $context['finished'] = $percent;
      }

    } else {
      // Not done with cycle.
      $nextCycleRecord += $reportsObj::BATCH_LIMIT;
      $context['sandbox']['nextCycleRecord'] = $nextCycleRecord;

      $recordCount += $reportsObj::BATCH_LIMIT;
      $context['sandbox']['recordCount'] = $recordCount;
      $context['sandbox']['votersNotFound'] = $votersNotFound;

      $percent = $recordCount / $countOfRecords;
      if ($percent == 1) {
        $percent = 0.999;
      }
      $context['finished'] = $percent;
    }
  }

  /**
   * A nice report when we are done.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = Drupal::messenger();
    if ($success) {
      $votersExistCount = $results['votersExistCount'];
      $votersNotFound = $results['votersNotFound'];
      $records = $results['records'];
      $messenger->addStatus(t('@count reports exported.', ['@count' => $records]));
      $messenger->addStatus(t('VotersExist count: @count', ['@count' => $votersExistCount]));
      $messenger->addStatus(t('New lookups: @count', ['@count' => $votersNotFound]));
      $messenger->addStatus(t('NL reports successfully exported.'));
    } else {
      $messenger->addStatus(t('An error occurred.'));
    }
  }

}
