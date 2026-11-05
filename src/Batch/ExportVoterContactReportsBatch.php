<?php

namespace Drupal\nlpservices\Batch;

use Drupal;

class ExportVoterContactReportsBatch {

  /**
   * Reports read per step when voters are checked in VoteBuilder.  At VAN's
   * 15 requests a second, a step that checks a new voter for every report
   * still finishes in about 20 seconds.
   */
  public const int CHECK_LIMIT = 300;

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
    // The first step.  (recordCount stays 0 past the first step when the
    // first cycle has no reports, so it can't be the test.)
    if (!isset($context['sandbox']['workingCycle'])) {
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

    // Checking voters takes VAN requests, so fewer records are read per step
    // to keep each step short.
    $limit = $removeInactive ? self::CHECK_LIMIT : $reportsObj::BATCH_LIMIT;
    $votersUnchecked = $context['sandbox']['votersUnchecked'] ?? 0;

    $selectedCycle = $cycleOptions[$workingCycle];
    $result = $reportsObj->selectAllReports($nextCycleRecord, $selectedCycle, $limit);
    // Read the whole step's records first, so their voters can be checked together.
    $rawRecords = [];
    while ($result && ($rawRecord = $result->fetchAssoc())) {
      $rawRecords[] = $rawRecord;
    }
    $cycleRecordCount = count($rawRecords);

    // Check every voter not seen yet in one batch of VAN requests.
    if ($removeInactive) {
      $toCheck = [];
      foreach ($rawRecords as $rawRecord) {
        $vanid = (int) $rawRecord['vanid'];
        if (!isset($votersExist[$vanid])) {
          $toCheck[$vanid] = $vanid;
        }
      }
      if (!empty($toCheck)) {
        $votersNotFound += count($toCheck);
        $checked = $apiVoterObj->votersExist(array_values($toCheck));
        $votersExist = $checked + $votersExist;
        $votersUnchecked += count($toCheck) - count($checked);
      }
    }

    foreach ($rawRecords as $rawRecord) {
      $record = $reportsObj->prepareExportRecord($rawRecord);

      $vanid = (int) $rawRecord['vanid'];
      // A voter VAN didn't answer for is kept; only a 404 removes the report.
      $voterExists = !$removeInactive || ($votersExist[$vanid] ?? TRUE);

      if ($voterExists) {
        // Get the cd of the voter if we have it.
        if ($currentCycleOnly && empty($record['cd'])) {
          $record['cd'] = $voterObj->getVoterCd($vanid);
        }
        fputcsv($fh, $record, escape: '');
      }

    }
    fclose($fh);

    $store->set('votersExist', $votersExist);
    $context['sandbox']['votersUnchecked'] = $votersUnchecked;

    // Finish the batch if we are done.
    if ($cycleRecordCount != $limit) {
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
        $context['results']['votersUnchecked'] = $votersUnchecked;

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
      $nextCycleRecord += $limit;
      $context['sandbox']['nextCycleRecord'] = $nextCycleRecord;

      $recordCount += $limit;
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
      $votersUnchecked = $results['votersUnchecked'] ?? 0;
      if ($votersUnchecked > 0) {
        $messenger->addWarning(t('VoteBuilder did not answer for @count voters. Their reports were kept in the export.', ['@count' => $votersUnchecked]));
      }
      $messenger->addStatus(t('NL reports successfully exported.'));
    } else {
      $messenger->addStatus(t('An error occurred.'));
    }
  }

}
