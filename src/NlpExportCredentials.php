<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpExportCredentials
{
  protected NlpFileFunctions $fileFunctionsObj;
  protected MagicWord $magicWordObj;
  protected DatabaseFunctions $dbFunctionsObj;

  public function __construct($fileFunctionsObj, $magicWordObj, $dbFunctionsObj)
  {
    $this->fileFunctionsObj = $fileFunctionsObj;
    $this->magicWordObj = $magicWordObj;
    $this->dbFunctionsObj = $dbFunctionsObj;
  }

  public static function create(ContainerInterface $container): NlpExportCredentials
  {
    return new static(
      $container->get('nlpservices.file_functions'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.database_functions'),
    );
  }

  public function createNlCredentialsFile(): string
  {
    $result = $this->fileFunctionsObj->createTempCsvFile('nl_user_credentials');
    if ($result === false) {
      return 'Oops!';
    }
    ['uri' => $userUri, 'fh' => $fh] = $result;

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
      $reordered['roles'] =  json_encode($credentials['roles']);
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
