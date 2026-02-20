<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApiKeyVerification
{
  protected NlpEncryption $nlpEncryptObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiNls $apiNlsObj;
  protected ApiFolders $apiFoldersObj;
  public function __construct($nlpEncryptObj, $nlpConfigurationObj, $apiNlsObj, $apiFoldersObj )
  {
    $this->nlpEncryptObj = $nlpEncryptObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiNlsObj = $apiNlsObj;
    $this->apiFoldersObj = $apiFoldersObj;
  }
  
  public static function create(ContainerInterface $container): ApiKeyVerification
  {
    return new static(
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_nls'),
      $container->get('nlpservices.folders'),
    );
  }
  
  /**
   * apiKeyVerification
   *
   * Validates API keys for all committees and checks the status of related services.
   *
   * @param int $mcid The MyCampaign ID to verify the people endpoint for NL records.
   *
   * @return array An array of results for each committee, including the status of "myCampaign", "voterFile", and "nls".
   */
  public function apiKeyVerification(int $mcid=0): array
  {
    $apiKeys = $this->nlpConfigurationObj->getKeys();
    $results = [];
    foreach ($apiKeys as $committee=>$committeeKey) {
      $county = ($committee=='State Committee')?'':$committee;
      $committeeKey['API Key'] = $this->nlpEncryptObj->encrypt_decrypt('decrypt', $committeeKey['API Key']);
      $result = $this->testApiKey($county,$committeeKey, $mcid);
      $result = array_merge( ['county' => t("<b>$committee</b>")], $result);
      $results[] = $result;
    }
    return $results;
  }
  
  /**
   * testApiKey
   *
   * Validates the API key for a given committee and determines the status of related services.
   *
   * @param string $committee The committee identifier used to retrieve folders and validate the API key.
   * @param array $committeeKey The key associated with the committee, used for API key verification.
   * @param int $mcid The MyCampaign ID to verify the people endpoint for NL records.
   *
   * @return array An associative array indicating the status of "myCampaign", "voterFile", and "nls".
   *               Each key will have a value of 'ok', 'fail', or other specific status messages.
   */
  private function testApiKey(string $committee,array $committeeKey, int $mcid): array
  {
    $result = [
      'myCampaign' => 'fail',
      'voterFile' => 'fail',
      'nls' => 'fail',
    ];
    // Verify we can access the NL folder for a committee.  The folder contains the list of active NLs.
    $myCampaignFolders = $this->apiFoldersObj->getApiFolders($committee,1);
    if(!empty($myCampaignFolders) AND !isset($myCampaignFolders[0])) {
      foreach ($myCampaignFolders as $folderName) {
        if(str_contains($folderName, '_NLs')) {
          $result['myCampaign'] = 'ok';
        }
      }
    }
    // Verify we can access the VoterFile for a committee.
    $voterFileFolders = $this->apiFoldersObj->getApiFolders($committee,0);
    if(!empty($voterFileFolders)) {
      $result['voterFile'] = 'ok';
      if(!empty($voterFileFolders[0])) {
        $result['voterFile'] = 'Unsure, no folders found';
      }
    }
    // Verify we can access a NL record for a committee.
    $keyValidity = $this->apiNlsObj->apiKeyVerify($committeeKey,$mcid);
    if($keyValidity == 'ok' OR $keyValidity == 'Invalid mcid') {
      $result['nls'] = 'ok';
    }
    return $result;
  }
  
}
