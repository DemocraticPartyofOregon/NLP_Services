<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpExportCredentials
{
  protected FileSystemInterface $fileSystem;
  protected Connection $connection;
  protected DrupalUser $drupalUser;
  protected MagicWord $magicWord;
  
  public function __construct($fileSystem, $connection, $drupalUser, $magicWord)
  {
    $this->fileSystem = $fileSystem;
    $this->connection = $connection;
    $this->drupalUser = $drupalUser;
    $this->magicWord = $magicWord;
  }
  
  public static function create(ContainerInterface $container): NlpExportCredentials
  {
    return new static(
      $container->get('file.system'),
      $container->get('database'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
    );
  }
  
  /**
   * @return string - HTML with a link to a file with information to recreate user accounts.
   */
  public function createNlCredentialsFile(): string
  {
   
    $output = '';
    $temp_dir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s', time());
    $userUri = $temp_dir . '/' . 'nl_user_credentials' . '-' . $contactDate . '.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $userUri, FileSystemInterface::EXISTS_REPLACE);
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
    
    $columns = $this->magicWord->getColumnNames();
    $key = array_search('magicWordEncrypted', $columns);
    if ($key !== false) {
      $columns[$key] = 'magicWord';
    }
    fputcsv($fh, $columns);
    
    $mcids = $this->magicWord->getKeys();
    foreach ($mcids as $mcid) {
      $credentials = $this->magicWord->getCredentials($mcid);
      if (empty($credentials)) {continue;}
      foreach ($columns as $column) {
        $reordered[$column] = $credentials[$column];
      }
      $reordered['roles'] = json_encode($credentials['roles']);
      fputcsv($fh, $reordered);
    }
    fclose($fh);
    $userUrl = Drupal::service('file_url_generator')->generateAbsoluteString($userUri);
    $output .= "<p>The file is a list of credentials for NL users in CSV format.  It is used to move user accounts
                and passwords to a new site.</p>";
    $output .= '<p> <a href="' . $userUrl . '">Right-click to download the user accounts file. </a></p>';
    return $output;
  }
  
  
}