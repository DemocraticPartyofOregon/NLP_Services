<?php

namespace Drupal\nlpservices\Batch;

use Drupal;

class AwakeBatch {

  /**
   * Reads and filters a 5 000-row chunk of the voted CSV file.
   *
   * Drupal calls this function repeatedly (same $arg each time) until
   * $context['finished'] reaches 1.  $context['sandbox'] carries the
   * mutable state — current byte offset and total file size — between calls.
   */
  public static function process(array $arg, array &$context): void {
    // First call: initialise sandbox and results.
    if (empty($context['sandbox'])) {
      $context['sandbox']['offset']   = $arg['headerOffset'];
      $context['sandbox']['fileSize'] = filesize($arg['filePath']);
      $context['results']['voterRecords'] = [];
      $context['results']['fid']          = $arg['fid'];
    }

    $fh = fopen($arg['filePath'], 'r');
    if (!$fh) {
      $context['finished'] = 1;
      return;
    }
    fseek($fh, $context['sandbox']['offset']);

    $colIndex       = $arg['colIndex'];
    $activeCounties = $arg['activeCounties'];
    $startTimestamp = $arg['startTimestamp'];
    $endTimestamp   = $arg['endTimestamp'];

    $atEof    = FALSE;
    $rowsRead = 0;
    while ($rowsRead < 5000) {
      $row = fgetcsv($fh);
      if ($row === FALSE) {
        $atEof = TRUE;
        break;
      }
      $rowsRead++;

      $record = [];
      foreach ($colIndex as $fieldName => $index) {
        $record[$fieldName] = $row[$index] ?? '';
      }
      if (($record['STATUS'] ?? '') !== 'Active') {
        continue;
      }
      if (!in_array($record['RES_COUNTY'] ?? '', $activeCounties)) {
        continue;
      }
      $votedTimestamp = strtotime($record['DATE_VOTED'] ?? '');
      if ($votedTimestamp === FALSE || $votedTimestamp < $startTimestamp || $votedTimestamp > $endTimestamp) {
        continue;
      }
      $mappingValue = $record['Mapping Value'] ?? '';
      if ($mappingValue !== '') {
        $context['results']['voterRecords'][$mappingValue] = $record;
      }
    }

    $context['sandbox']['offset'] = ftell($fh);
    fclose($fh);

    $fileSize = $context['sandbox']['fileSize'];
    $context['finished'] = $atEof ? 1 : $context['sandbox']['offset'] / max(1, $fileSize);
  }

  /** @noinspection PhpUnusedParameterInspection */
  public static function finished(bool $success, array $results, array $operations): void {
    $fid = $results['fid'] ?? 0;
    if ($fid) {
      $file = Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        $file->delete();
      }
    }

    $messenger = Drupal::messenger();
    if ($success) {
      $count = count($results['voterRecords'] ?? []);
      $messenger->addStatus(t('Voted file processing complete. @count active voters matched.', ['@count' => $count]));
      nlp_debug_msg('$voterRecords', $results['voterRecords'] ?? []);
    } else {
      $messenger->addError(t('An error occurred processing the voted file.'));
    }
  }

}
