<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

/**
 * @noinspection PhpUnused
 */
class ApiNls {
  use ApiRetryTrait;

  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private ApiKeyManagement $apiKeyManagementObj;
  public function __construct($client, $nlpConfigurationObj, $apiKeyManagementObj ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiNls
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }
  
  /**
   * nlApiCall
   *
   * Make an API call to get the record from MyCampaign associated with the mcid.
   *
   * @param string $committee
   * @param int $mcid - the vanid in MyCampaign for the person.
   * @param string  $expandOptions - options for additional information, like
   *               '?$expand=phones, emails, addresses, districts, preferences, externalIds'
   * @param bool $errorStatus - set to true to check for an invalid key.
   * @return array - results body of API call.
   */
  private function nlApiCall(string $committee, int $mcid, string $expandOptions, bool $errorStatus=FALSE): array
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $post_url = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid.$expandOptions;
    for ($attempt = 0; ; $attempt++) {
      try {
        $request = $this->client->request('GET', $post_url);
        $results = json_decode($request->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          return [];
        }
        return $results ?? [];
      } catch (GuzzleException $e) {
        $code = $e->getCode();
        if ($code == 404) {return [];}
        if ($errorStatus && $code == 401) {
          return ['Invalid key'];
        }
        if ($this->isRateLimited($e) && $attempt < self::MAX_RETRIES) {
          $this->sleepMs($this->retryDelayMs($e, $attempt));
          continue;
        }
        nlp_debug_msg('nlApiCall', $e->getMessage());
        return [];
      } catch (Exception $e) {
        nlp_debug_msg('nlApiCall', $e->getMessage());
        return [];
      }
    }
  }
  
  /**
   * nlExists
   *
   * Check to see if a record exists in MyCampaign for the mcid.
   *
   * @param string $committee
   * @param int $mcid
   * @return bool
   * @noinspection PhpUnused
   * Used in ImportCredentialsForm
   */
  public function nlExists(string $committee, int $mcid): bool
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $post_url = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid;
    try {
      $this->client->request('GET', $post_url);
    } catch (GuzzleException $e) {
      $code = $e->getCode();
      if ($code == 404) {return FALSE;}
      nlp_debug_msg('nlExists', $e->getMessage());
      return FALSE;
    } catch (Exception $e) {
      nlp_debug_msg('nlExists', $e->getMessage());
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
   * @param string $committee
   * @param int $mcid
   * @param bool $errorStatus - set to true to check for an invalid key.
   * @return array - simplified array of information about a volunteer in MyCampaign.
   */
  public function getApiNls(string $committee, int $mcid, bool $errorStatus=FALSE): array
  {
    $expandOptions = '?$expand=phones,emails,addresses,districts,preferences,externalIds';
    $result = $this->nlApiCall($committee, $mcid, $expandOptions, $errorStatus);
    if (empty($result)) { return []; }
    if (!empty($result[0]) && $result[0] == 'Invalid key') { return $result; }
    return $this->processNlResult($result);
  }

  private function processNlResult(array $result): array
  {
    $nl = [];
    $nl['mcid'] = $result['vanId'];
    $nl['firstName'] = $result['firstName'];
    $nl['middleName'] = $result['middleName'];
    $nl['lastName'] = $result['lastName'];
    $nl['nickname'] = $result['nickname'];
    $nl['city'] = $nl['address'] = $nl['zip'] = '';
    if (!empty($result['addresses'])) {
      foreach ($result['addresses'] as $address) {
        if ($address['type'] == 'Voting') {
          $nl['address'] = $address['addressLine1'];
          $nl['city'] = $address['city'];
          $nl['zip'] = '';
          if (!empty($address['zipOrPostalCode'])) {
            $zipParts = explode('-', $address['zipOrPostalCode']);
            $nl['zip'] = $zipParts[0];
          }
        }
      }
    }
    $nl['homePhone'] = $nl['cellPhone'] = $nl['phone'] = '';
    if (!empty($result['phones'])) {
      foreach ($result['phones'] as $phone) {
        switch ($phone['phoneType']) {
          case 'Home':
            $nl['homePhone'] = $phone['phoneNumber'];
            $nl['homePhoneId'] = $phone['phoneId'];
            break;
          case 'Cell':
            $nl['cellPhone'] = $phone['phoneNumber'];
            $nl['cellPhoneId'] = $phone['phoneId'];
            break;
        }
        if ($phone['isPreferred'] || $phone['isBest']) {
          $nl['phone'] = $phone['phoneNumber'];
        }
      }
    }
    $nl['email'] = NULL;
    if (!empty($result['emails'])) {
      foreach ($result['emails'] as $email) {
        if ($email['isPreferred']) {
          $nl['email'] = $email['email'];
        }
      }
    }
    $nl['precinct'] = $nl['hd'] = NULL;
    if (!empty($result['districts'])) {
      foreach ($result['districts'] as $district) {
        switch ($district['name']) {
          case 'County':
            $nl['county'] = $district['districtFieldValues'][0]['name'];
            break;
          case 'Precinct':
            $nl['precinct'] = $district['districtFieldValues'][0]['name'];
            break;
          case 'State House':
            $nl['hd'] = $district['districtFieldValues'][0]['name'];
            break;
        }
      }
    }
    $vfid = 0;
    if (!empty($result['identifiers'])) {
      foreach ($result['identifiers'] as $identifier) {
        if ($identifier['type'] == 'VoterVANID') {
          $vfid = $identifier['externalId'];
        }
      }
    }
    $nl['vfid'] = $vfid;
    return $nl;
  }

  public function getNls(string $committee, array $mcids): array
  {
    if (empty($mcids)) { return []; }

    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $expandOptions = '?$expand=phones,emails,addresses,districts,preferences,externalIds';

    $nls = [];
    $this->sendInRateLimitedGroups(
      $mcids,
      fn($mcid) => new GuzzleRequest('GET', "https://$user:$apiKey|1@$apiURL/people/$mcid$expandOptions"),
      function($response, $mcid) use (&$nls) {
        $result = json_decode($response->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE || empty($result)) {
          nlp_debug_msg('Something is wrong.  Contact NLP Support,  MCID: ' . $mcid, '');
          return;
        }
        $nl = $this->processNlResult($result);
        $nls[$nl['mcid']] = $nl;
      },
      function($reason, $mcid) {
        nlp_debug_msg('Something is wrong.  Contact NLP Support,  MCID: ' . $mcid, $reason->getMessage());
      },
    );

    return $nls;
  }
  
  /**
   * apiKeyVerify
   *
   * Verify that the API key is valid by accessing a known mcid in MyCampaign.
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
      $this->client->request('GET', $postUrl);
    } catch (GuzzleException $e) {
      $code = $e->getCode();
      if ($code == 404) {return 'Invalid mcid';}
      if ($code == 401) {return 'Invalid key';}
      nlp_debug_msg('apiKeyVerify', $e->getMessage());
      return 'Invalid key';
    } catch (Exception $e) {
      nlp_debug_msg('apiKeyVerify', $e->getMessage());
      return 'Invalid key';
    }
    return 'ok';
  }
  
}
