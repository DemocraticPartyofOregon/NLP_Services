<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use GuzzleHttp\ClientInterface;

/**
 * @noinspection PhpUnused
 */
class ApiNls {
  
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private array $stateCommitteeKey;
  public function __construct($client, $nlpConfigurationObj) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->stateCommitteeKey = $this->nlpConfigurationObj->getStateKey();}
  
  public static function create(ContainerInterface $container): ApiNls
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * nlApiCall
   *
   * Make an API call to get the record from MyCampaign associated with the mcid.
   *
   * @param string $county
   * @param int $mcid - the vanid in MyCampaign for the person.
   * @param string  $expandOptions - options for additional information, like
   *               '?$expand=phones, emails, addresses, districts, preferences, externalIds'
   * @param bool $errorStatus - set to true to check for an invalid key.
   * @return array - results body of API call.
   */
  private function nlApiCall(string $county, int $mcid, string $expandOptions, bool $errorStatus=FALSE): array
  {
    if(empty($county)) {
      $committeeKey = $this->stateCommitteeKey;
    } else {
      $committeeKey = $this->nlpConfigurationObj->getCountyKey($county);
    }
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $post_url = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid.$expandOptions;
    try {
      $request = $this->client->get($post_url);
      $results = json_decode($request->getBody(), true);
    } catch (Exception $e) {
      $code = $e->getCode();
      if($code == 404) {return [];}
      if($errorStatus AND $code==401) {
        return ['Invalid key'];
      }
      nlp_debug_msg('nlApiCall',$e->getMessage());
      return [];
    }
    if(empty($results)) {return [];}
    return $results;
  }
  
  /**
   * nlExists
   *
   * Check to see if a record exists in MyCampaign for the mcid.
   *
   * @param string $county
   * @param int $mcid
   * @return bool
   * @noinspection PhpUnused
   * Used in ImportCredentialsForm
   */
  public function nlExists(string $county, int $mcid): bool
  {
    $committeeKey = $this->nlpConfigurationObj->getCountyKey($county);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $post_url = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid;
    try {
      $this->client->get($post_url);
    } catch (Exception $e) {
      $code = $e->getCode();
      if($code == 404) {return FALSE;}
      nlp_debug_msg('nlApiCall',$e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * getApiNls
   *
   * Get the information about a volunteer in MyCampaign.
   *
   * Returns an array with these keys:
   * mcid
   * firstName
   * middleName
   * lastName
   * nickname
   * city
   * address - voting address
   * zip,
   * homePhone,
   * cellPhone,
   * phone - preferred phone
   * email - preferred email
   * precinct
   * hd
   * county
   * vfid - VoterFile ID if it exists. If missing, the volunteer's record is not linked to
   *         a record in the VoterFile.
   *
   * @param string $county
   * @param int $mcid
   * @param bool $errorStatus - set to true to check for an invalid key.
   * @return array - simplified array of information about a volunteer in MyCampaign.
   */
  public function getApiNls(string $county, int $mcid, bool $errorStatus=FALSE): array
  {
    $expandOptions = '?$expand=phones,emails,addresses,districts,preferences,externalIds';
    $result = $this->nlApiCall($county,$mcid,$expandOptions, $errorStatus);
    if(empty($result)) {return [];}
    if(!empty($result[0]) AND $result[0] == 'Invalid key') {return $result;}
    $nl = [];
    $nl['mcid'] = $result['vanId'];
    $nl['firstName'] = $result['firstName'];
    $nl['middleName'] = $result['middleName'];
    $nl['lastName'] = $result['lastName'];
    $nl['nickname'] = $result['nickname'];
    $nl['city'] = $nl['address'] = $nl['zip'] = '';
    if(!empty($result['addresses'])) {
      foreach ($result['addresses'] as $address) {
        $type = $address['type'];
        if($type == 'Voting') {
          $nl['address'] = $address['addressLine1'];
          $nl['city'] = $address['city'];
          $nl['zip'] = '';
          if(!empty($nl['zipOrPostalCode'])) {
            $zipOrPostalCode = $nl['zipOrPostalCode'];
            $zipParts = explode("-",$zipOrPostalCode);
            $nl['zip'] = $zipParts[0];
          }
        }
      }
    }
    $nl['homePhone'] = $nl['cellPhone'] = $nl['phone'] = '';
    if(!empty($result['phones'])) {
      foreach ($result['phones'] as $phone) {
        $phoneType = $phone['phoneType'];
        switch ($phoneType) {
          case 'Home':
            $nl['homePhone'] = $phone['phoneNumber'];
            $nl['homePhoneId'] = $phone['phoneId'];
            break;
          case 'Cell':
            $nl['cellPhone'] = $phone['phoneNumber'];
            $nl['cellPhoneId'] = $phone['phoneId'];
            break;
        }
        if($phone['isPreferred']) {
          $nl['phone'] = $phone['phoneNumber'];
        }
      }
    }
    $nl['email'] = NULL;
    if(!empty($result['emails'])) {
      foreach ($result['emails'] as $email) {
        if($email['isPreferred']) {
          $nl['email'] = $email['email'];
        }
      }
    }
    $nl['precinct'] = $nl['hd']= NULL;
    if(!empty($result['districts'])) {
      foreach ($result['districts'] as $district) {
        $districtType = $district['name'];
        switch ($districtType) {
          case 'County':
            $field = $district['districtFieldValues'][0];
            $nl['county'] = $field['name'];
            break;
          case 'Precinct':
            $field = $district['districtFieldValues'][0];
            $nl['precinct'] = $field['name'];
            break;
          case 'State House':
            $field = $district['districtFieldValues'][0];
            $nl['hd'] = $field['name'];
            break;
        }
      }
    }
    
    $vfid = 0;
    if(!empty($result['identifiers'])) {
      foreach ($result['identifiers'] as $identifier) {
        if($identifier['type'] == 'VoterVANID') {
          $vfid = $identifier['externalId'];
        }
      }
    }
    $nl['vfid'] = $vfid;
    
    return $nl;
  }
  
  /**
   * apiKeyVerify
   *
   * Verify that the API key is valid by accessing a known mcid in MyCampaign..
   *
   * @param array $committeeKey
   * @param int $mcid
   * @return string
   */
  public function apiKeyVerify(array $committeeKey,int $mcid): string
  {
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $postUrl = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid;
    try {
      $this->client->get($postUrl);
    } catch (Exception $e) {
      $code = $e->getCode();
      if($code == 404) {return 'Invalid mcid';}
      if($code==401) {return 'Invalid key';}
      nlp_debug_msg('apiKeyVerify',$e->getMessage());
      return 'Invalid key';
    }
    return 'ok';
  }
  
}
