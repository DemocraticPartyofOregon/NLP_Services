<?php

namespace Drupal\nlpservices\Batch;

class SyncActiveNlsBatch {

  public static function process(array $arg, array &$context): void {
    \Drupal::service('nlpservices.sync_active_nls_processor')->processBatch($arg, $context);
  }

  /** @noinspection PhpUnusedParameterInspection */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addStatus(t('The list of active NLs is successfully updated.'));
      $messenger->addStatus(t('@count records processed.', ['@count' => $results['recordCount']]));
    } else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
