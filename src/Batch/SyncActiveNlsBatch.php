<?php

namespace Drupal\nlpservices\Batch;

use Drupal;

class SyncActiveNlsBatch {

  public static function process(array $arg, array &$context): void {
    Drupal::service('nlpservices.sync_active_nls_processor')->processBatch($arg, $context);
  }

  /** @noinspection PhpUnusedParameterInspection */

  /**
   * Handles completion of a batch operation and provides user feedback.
   *
   * @param bool $success
   *   Indicates whether the batch operation completed successfully.
   * @param array $results
   *   Contains results of the batch operation, including processed record count.
   * @param array $operations
   *   Contains information about the operations executed in the batch process.
   *
   * @return void
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = Drupal::messenger();
    if ($success) {
      $messenger->addStatus(t('The list of active NLs is successfully updated.'));
      $messenger->addStatus(t('@count records processed.', ['@count' => $results['recordCount']]));
    } else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
