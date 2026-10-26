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
        $request = $this->client->request('GET', $post_url, self::REQUEST_OPTIONS);
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
        if ($this->isRetryable($e) && $attempt < $this->maxRetries($e)) {
          $this->logRetry($e, $mcid, $attempt);
          $this->sleepMs($this->retryDelayMs($e, $attempt));
          continue;
        }
        nlp_debug_msg('nlApiCall', $e);
        return [];
      } catch (Exception $e) {
        nlp_debug_msg('nlApiCall', $e);
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
      nlp_debug_msg('nlExists', $e);
      return FALSE;
    } catch (Exception $e) {
      nlp_debug_msg('nlExists', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  const string MCID_EXISTS = 'exists';
  const string MCID_MERGED = 'merged';
  const string MCID_MISSING = 'missing';
  const string MCID_UNKNOWN = 'unknown';

  /**
   * getMcidStatus
   *
   * Check whether a MyCampaign record still exists under this MCID.
   *
   * Unlike nlExists(), a failed request is reported as unknown rather than
   * missing, so a caller that acts on a missing record (like re-pointing the
   * NLP database to a new MCID) never acts on an API outage.
   *
   * @param string $committee
   * @param int $mcid
   * VAN answers a request for an MCID destroyed by a merge with a 404, not
   * the surviving record, so that is reported as MCID_MISSING. MCID_MERGED
   * covers a different vanId in the response, in case VAN ever starts
   * answering with the surviving record.
   *
   * @return string - MCID_EXISTS; MCID_MISSING on a 404; MCID_MERGED when VAN
   *   answers with a different vanId; MCID_UNKNOWN when the API can't be reached.
   */
  public function getMcidStatus(string $committee, int $mcid): string
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    try {
      $response = $this->requestWithRetry('GET', "https://$user:$apiKey|1@$apiURL/people/$mcid", $mcid);
    } catch (GuzzleException $e) {
      if ($e->getCode() == 404) {return self::MCID_MISSING;}
      nlp_debug_msg('getMcidStatus', 'MCID: ' . $mcid . ' HTTP code: ' . $e->getCode());
      return self::MCID_UNKNOWN;
    }
    $result = json_decode($response->getBody(), TRUE);
    if (json_last_error() !== JSON_ERROR_NONE || empty($result['vanId'])) {
      return self::MCID_UNKNOWN;
    }
    return ((int) $result['vanId'] == $mcid) ? self::MCID_EXISTS : self::MCID_MERGED;
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
    // VoteBuilder offers no "preferred" choice when a person has only one
    // email, so that email arrives with isPreferred false. Use the preferred
    // email when there is one, otherwise the first.
    $nl['email'] = NULL;
    if (!empty($result['emails'])) {
      $nl['email'] = $result['emails'][0]['email'] ?? NULL;
      foreach ($result['emails'] as $email) {
        if (!empty($email['isPreferred'])) {
          $nl['email'] = $email['email'];
          break;
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
        // Key by the requested MCID, not the vanId in the response: VAN can
        // answer with a different vanId (e.g., a merged record), and the
        // caller needs to see that rather than lose the record.
        $nls[$mcid] = $this->processNlResult($result);
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
      nlp_debug_msg('apiKeyVerify', $e);
      return 'Invalid key';
    } catch (Exception $e) {
      nlp_debug_msg('apiKeyVerify', $e);
      return 'Invalid key';
    }
    return 'ok';
  }
  
}
