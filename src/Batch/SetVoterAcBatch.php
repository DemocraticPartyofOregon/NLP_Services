<?php

namespace Drupal\nlpservices\Batch;

use Drupal;
use Drupal\nlpservices\NlpFileFunctions;

class SetVoterAcBatch {

  public static function process(array $arg, array &$context): void {
    Drupal::service('nlpservices.voter_ac_processor')->processBatch($arg, $context);
  }

  /** @noinspection PhpUnusedParameterInspection */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = Drupal::messenger();
    if ($success) {
      $turfsProcessed  = $results['turfsProcessed'] ?? 0;
      $votersChecked   = $results['votersChecked'] ?? 0;
      $votersMissingAc = $results['votersMissingAc'] ?? 0;

      $reportOnly = $results['reportOnly'] ?? FALSE;
      $message = $reportOnly
        ? 'Checked @checked voters across @turfs turfs. NLP Voter AC was missing for @missing voters (not set).'
        : 'Checked @checked voters across @turfs turfs. NLP Voter AC was missing and set for @missing voters.';
      $messenger->addStatus(t($message, [
        '@checked' => $votersChecked,
        '@turfs'   => $turfsProcessed,
        '@missing' => $votersMissingAc,
      ]));

      $missingAcRecords = $results['missingAcRecords'] ?? [];
      if (!empty($missingAcRecords)) {
        /** @var NlpFileFunctions $fileFunctionsObj */
        $fileFunctionsObj = Drupal::service('nlpservices.file_functions');
        $fileData = $fileFunctionsObj->createTempCsvFile('missing-voter-ac');
        if ($fileData !== FALSE) {
          fputcsv($fileData['fh'], ['VANID', 'Turf Name', 'County', 'Coordinator']);
          foreach ($missingAcRecords as $record) {
            fputcsv($fileData['fh'], [
              $record['vanid'],
              $record['turfName'],
              $record['county'],
              $record['coordinator'],
            ]);
          }
          fclose($fileData['fh']);
          $fileUrl = Drupal::service('file_url_generator')->generateAbsoluteString($fileData['uri']);
          Drupal::logger('nlpservices')->notice(
            'NLP Voter AC missing report: @url',
            ['@url' => $fileUrl]
          );
          /** @noinspection PhpUnresolvedPathReferenceInspection */
          $messenger->addStatus(t('Missing AC report: <a href=":url">@url</a>', [':url' => $fileUrl, '@url' => $fileUrl]));
        }
      }
    } else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
