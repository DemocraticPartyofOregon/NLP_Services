<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileExists;
use Exception;

class NlpFileFunctions
{
  /**
   * Creates a temporary CSV file and returns its URI and file handle.
   *
   * @param string $filePrefix
   *   The prefix to use for the temporary CSV file name.
   *
   * @return array|false
   *   An associative array containing the URI of the created CSV file ('uri') and the opened file handle ('fh'),
   *   or false if the file cannot be created.
   */
  public function createTempCsvFile(string $filePrefix): array|false
  {
    $uri = 'public://temp/' . $filePrefix . '-' . date('Y-m-d-H-i-s') . '.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $uri, FileExists::Replace);
    } catch (EntityStorageException $e) {
      nlp_debug_msg('File create failed.', $e->getMessage());
      return false;
    }
    $file->setTemporary();
    try {
      $file->save();
    } catch (Exception $e) {
      nlp_debug_msg('error', $e->getMessage());
      return false;
    }
    $fh = fopen($uri, 'w');
    if (!$fh) {
      return false;
    }
    return ['uri' => $uri, 'fh' => $fh];
  }

}
