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
   *
   * Each step reads the next page of reports for the working cycle, starting
   * after the last reportIndex read, and appends them to the file.
   */
  public static function process(array $arg, array &$context): void {
    $reportsUri = $arg['uri'];
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
      $context['sandbox'] = ['workingCycle' => $arg['startCycle']];
    }
    // Defaults for anything missing, including in the sandbox of an export
    // that was already running when this version was installed.
    $context['sandbox'] += [
      'lastReportIndex' => 0,
      'recordCount' => 0,
      'votersNotFound' => 0,
      'votersUnchecked' => 0,
    ];
    $sandbox = &$context['sandbox'];
    $workingCycle = $sandbox['workingCycle'];

    $reportsObj = Drupal::service('nlpservices.reports');
    $voterObj = Drupal::service('nlpservices.voters');

    // Checking voters takes VAN requests, so fewer records are read per step
    // to keep each step short.
    $limit = $removeInactive ? self::CHECK_LIMIT : $reportsObj::BATCH_LIMIT;

    $result = $reportsObj->selectAllReports($sandbox['lastReportIndex'], $cycleOptions[$workingCycle], $limit);
    // Read the whole step's records first, so their voters can be looked up together.
    $rawRecords = [];
    while ($result && ($rawRecord = $result->fetchAssoc())) {
      $rawRecords[] = $rawRecord;
    }
    $cycleRecordCount = count($rawRecords);

    $votersExist = $removeInactive ? self::checkVoters($rawRecords, $sandbox) : [];

    // The CD for every voter whose report doesn't have one, in one query.
    $cds = [];
    if ($currentCycleOnly) {
      $missingCd = [];
      foreach ($rawRecords as $rawRecord) {
        if (empty($rawRecord['cd'])) {
          $missingCd[] = (int) $rawRecord['vanid'];
        }
      }
      $cds = $missingCd ? $voterObj->getVoterCds($missingCd) : [];
    }

    foreach ($rawRecords as $rawRecord) {
      $record = $reportsObj->prepareExportRecord($rawRecord);
      $vanid = (int) $rawRecord['vanid'];
      // A voter VAN didn't answer for is kept; only a 404 removes the report.
      if ($removeInactive && !($votersExist[$vanid] ?? TRUE)) {
        continue;
      }
      if ($currentCycleOnly && empty($record['cd'])) {
        $record['cd'] = $cds[$vanid] ?? 0;
      }
      fputcsv($fh, $record, escape: '');
    }
    fclose($fh);

    $sandbox['recordCount'] += $cycleRecordCount;
    if ($cycleRecordCount > 0) {
      $sandbox['lastReportIndex'] = (int) end($rawRecords)['reportIndex'];
    }

    if ($cycleRecordCount == $limit) {
      // More reports in this cycle.
      $context['finished'] = self::progress($sandbox['recordCount'], $countOfRecords);
      return;
    }
    if ($workingCycle != $endCycle) {
      // On to the next cycle.
      $sandbox['workingCycle'] = $workingCycle + 1;
      $sandbox['lastReportIndex'] = 0;
      $context['finished'] = self::progress($sandbox['recordCount'], $countOfRecords);
      return;
    }

    // Done with the last cycle.
    $context['finished'] = 1;
    $context['results']['records'] = $sandbox['recordCount'];
    $context['results']['uri'] = $reportsUri;
    $context['results']['votersExistCount'] = $sandbox['votersExistCount'] ?? 0;
    $context['results']['votersNotFound'] = $sandbox['votersNotFound'];
    $context['results']['votersUnchecked'] = $sandbox['votersUnchecked'];
  }

  /**
   * Checks in VoteBuilder every voter in these records not already checked.
   *
   * The answers are kept in the user's private tempstore, so a later export
   * doesn't ask again.  It is read and written only here: the cache grows
   * with every export, and handling it on every step of an export that
   * doesn't check voters cost time and memory for nothing.
   *
   * @param array $rawRecords - the step's report records.
   * @param array $sandbox - the batch sandbox; the lookup counts are updated.
   * @return array - TRUE/FALSE keyed by vanid for every voter VAN answered for.
   */
  private static function checkVoters(array $rawRecords, array &$sandbox): array {
    $store = Drupal::service('tempstore.private')->get('nlpservices.session_data');
    $votersExist = $store->get('votersExist') ?: [];

    $toCheck = [];
    foreach ($rawRecords as $rawRecord) {
      $vanid = (int) $rawRecord['vanid'];
      if (!isset($votersExist[$vanid])) {
        $toCheck[$vanid] = $vanid;
      }
    }
    if (!empty($toCheck)) {
      $sandbox['votersNotFound'] += count($toCheck);
      $checked = Drupal::service('nlpservices.api_voter')->votersExist(array_values($toCheck));
      $votersExist = $checked + $votersExist;
      $sandbox['votersUnchecked'] += count($toCheck) - count($checked);
      $store->set('votersExist', $votersExist);
    }
    $sandbox['votersExistCount'] = count($votersExist);
    return $votersExist;
  }

  /**
   * The share of the reports read, kept under 1 so the batch isn't finished early.
   */
  private static function progress(int $recordCount, int $countOfRecords): float {
    if ($countOfRecords <= 0) {
      return 0.999;
    }
    return min($recordCount / $countOfRecords, 0.999);
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
