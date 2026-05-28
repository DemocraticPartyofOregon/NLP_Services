<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpExportCredentials
{
  protected FileSystemInterface $fileSystem;
  protected Connection $connection;
  protected MagicWord $magicWordObj;
  protected DatabaseFunctions $dbFunctionsObj;

  public function __construct($fileSystem, $connection, $magicWordObj, $dbFunctionsObj )
  {
    $this->fileSystem = $fileSystem;
    $this->connection = $connection;
    $this->magicWordObj = $magicWordObj;
    $this->dbFunctionsObj = $dbFunctionsObj;
  }

  public static function create(ContainerInterface $container): NlpExportCredentials
  {
    return new static(
      $container->get('file.system'),
      $container->get('database'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.database_functions'),
    );
  }

  /**
   * createNlCredentialsFile
   *
   * Creates a CSV file containing the credentials of NL users. The file is stored in a temporary directory and is intended for use in migrating user accounts and passwords to a new site.
   *
   * @return string A message containing a link to download the generated CSV file, or an error message if the file creation fails.
   */
  public function createNlCredentialsFile(): string
  {
    $temp_dir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s');
    $userUri = $temp_dir . '/' . 'nl_user_credentials' . '-' . $contactDate . '.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $userUri, FileExists::Replace);
    } catch (EntityStorageException $e) {
      nlp_debug_msg('File create failed.', $e->getMessage());
      return 'Oops!';
    }
    $file->setTemporary();
    try {
      $file->save();
    } catch (Exception $e) {
      nlp_debug_msg('error', $e->getMessage());
      return '';
    }

    $fh = fopen($userUri, "w");
    if (!$fh) {
      return 'Oops!';
    }

    $columns = $this->dbFunctionsObj->getColumnNames($this->magicWordObj::MAGIC_WORD_TBL);
    $key = array_search('magicWordEncrypted', $columns);
    if ($key !== false) {
      $columns[$key] = 'magicWord';
    }
    fputcsv($fh, $columns);

    $mcids = $this->magicWordObj->getKeys();
    foreach ($mcids as $mcid) {
      $credentials = $this->magicWordObj->getCredentials($mcid);
      if (empty($credentials)) {continue;}
      $reordered = [];
      foreach ($columns as $column) {
        $reordered[$column] = $credentials[$column] ?? '';
      }
      $reordered['roles'] = json_encode($credentials['roles']);
      fputcsv($fh, $reordered);
    }
    fclose($fh);
    $userUrl = Drupal::service('file_url_generator')->generateAbsoluteString($userUri);
    $output = "<p>The file is a list of credentials for NL users in CSV format.  It is used to move user accounts
                and passwords to a new site.</p>";
    $output .= '<p> <a href="' . $userUrl . '">Right-click to download the user accounts file. </a></p>';
    return $output;
  }

}
