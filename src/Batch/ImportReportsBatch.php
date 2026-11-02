<?php

namespace Drupal\nlpservices\Batch;

use Drupal;

class ImportReportsBatch {

  /**
   * Read the provided file and save the Dems.
   */
  public static function process(array $arg, array &$context): void {
    $uri = $arg['uri'];

    $fh = fopen($uri, "r");
    if (!$fh) {
      Drupal::logger('nlpservices')->notice('Failed to open reports file.');
      $context['finished'] = TRUE;
      return;
    }

    $filesize = filesize($uri);
    $context['finished'] = 0;
    // Position the file at the start or where we left off for the previous batch.
    if (empty($context['sandbox']['seek'])) {
      // Read the header record.
      $context['sandbox']['recordCount'] = 0;
      $csvColumnNames = fgetcsv($fh, escape: '');
      $context['sandbox']['csvColumnNames'] = $csvColumnNames;
    } else {
      // Seek to where we will restart.
      $seek = $context['sandbox']['seek'];
      fseek($fh, $seek);
      $csvColumnNames = $context['sandbox']['csvColumnNames'];
    }
    $recordCount = $context['sandbox']['recordCount'];

    $reports = Drupal::service('nlpservices.reports');

    $done = TRUE;
    do {
      $report = fgetcsv($fh, escape: '');
      if (!$report) { break; }
      $recordCount++;

      // array_combine() throws on PHP 8 when the counts differ, so skip a
      // short or long row (such as a blank line) before combining.
      if (count($report) != count($csvColumnNames)) { continue; }
      $namedFields = array_combine($csvColumnNames, $report);

      $namedFields['text'] = trim(strip_tags(htmlentities(stripslashes($namedFields['text']),ENT_QUOTES)));
      if(strlen($namedFields['text']) >= 1023) {
        $namedFields['text'] = substr($namedFields['text'],0,1022);
      }

      $contactDate = $namedFields['contactDate'];
      $contactTime = strtotime($contactDate) ?: 0;
      $date = date('Y-m-d', $contactTime);
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

  /**
   * The batch operation is finished.  Report the results.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = Drupal::messenger();
    $messenger->addStatus(t('Batch finished.'));
    // The form moved the upload into public://temp; don't leave it there.
    if (!empty($results['uri'])) {
      Drupal::service('file_system')->unlink($results['uri']);
    }
    if ($success) {
      $recordCount = $results['recordCount'];
      $messenger->addStatus(t('@count records processed.', ['@count' => $recordCount]));
      $messenger->addStatus(t('The reports file successfully updated.'));
    }
    else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
