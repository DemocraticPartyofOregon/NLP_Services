<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use stdClass;

class ApiActivistCodes
{
  const string ACTIVIST_CODE = 'ActivistCode';
  
  private array $supportedActivistCodes = [
    'NLPVoterAC' => 0,
    'NLPHostileAC' => 0,
    'NLPNotRegisteredAC' => 1,
    'NLPDropoutAC' => 1,
  ];
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private ApiKeyManagement $apiKeyManagementObj;

  public function __construct( $client, $nlpConfigurationObj, $apiKeyManagementObj ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiActivistCodes
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }
  
  /**
   * getApiActivistCodes
   *
   * Get the activist codes from the API.
   *
   * Returns an indexed array of activist codes.  Each code is an array with the
   * following keys:
   *   - activistCodeId - number assigned by VoteBuilder
   *   - name - name of the activist code
   *   - type - type assigned by the creator of the AC, like 'Neighborhood Leader'.
   *   - Other fields are returned by the API, but they are not used by NLP Services.
   *
   * @param int $database - 0=VoterFile, 1=MyCampaign
   * @return array
   */
  public function getApiActivistCodes(int $database): array
  {
    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user = $stateCommitteeKey['App Name'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/activistCodes".'?$top=200';
    try {
      $request = $this->client->get($postUrl);
      $results = json_decode($request->getBody(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
      }
      return $results['items'] ?? [];
    } catch (GuzzleException $e) {
      nlp_debug_msg('getApiActivistCodes', $e->getMessage());
      return [];
    } catch (Exception $e) {
      nlp_debug_msg('getApiActivistCodes', $e->getMessage());
      return [];
    }
  }
  
  /**
   * getApiVoterActivistCodes
   *
   * Returns an array of activist code IDs (integers) currently applied to the
   * given voter in the VoterFile database.
   *
   * @param int $vanid
   * @return array
   */
  public function getApiVoterActivistCodes(int $vanid): array
  {
    $database = $this->supportedActivistCodes['NLPVoterAC'];
    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user = $stateCommitteeKey['App Name'];
    $url = "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes";
    try {
      $request = $this->client->get($url);
      $results = json_decode($request->getBody(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
      }
      $codes = [];
      foreach ($results['items'] ?? [] as $item) {
        $codes[] = (int) $item['activistCodeId'];
      }
      return $codes;
    } catch (GuzzleException $e) {
      nlp_debug_msg('getApiVoterActivistCodes', $e->getMessage());
      return [];
    } catch (Exception $e) {
      nlp_debug_msg('getApiVoterActivistCodes', $e->getMessage());
      return [];
    }
  }

  /**
   * hasApiActivistCode
   *
   * Returns TRUE if the given voter already has the named activist code applied.
   *
   * @param int $vanid
   * @param string $codeName - e.g. 'NLPVoterAC'
   * @return bool
   */
  public function hasApiActivistCode(int $vanid, string $codeName): bool
  {
    $rid = $this->nlpConfigurationObj->getActivistCode($codeName);
    if (empty($rid)) {
      return FALSE;
    }
    return in_array($rid, $this->getApiVoterActivistCodes($vanid));
  }

  /**
   * setApiActivistCode
   *
   * Set the activist code for a person in either MyCampaign or VoterFile.
   *
   * Expects an array with the following keys:
   *   - vanid - the person's VAN ID
   *   - activistCodeName - name of the activist code, like 'NLPVoterAC'.
   *   - action - default is 'Apply'; another option is 'Remove'
   *
   * @param array $request
   * @return void
   */
  public function setApiActivistCode(array $request): void
  {
    $activistCodeName = $request['codeName'];
    $rid = $this->nlpConfigurationObj->getActivistCode($activistCodeName);
    if(empty($rid)) {return;}
    $vanid = $request['vanid'];
    $database = $this->supportedActivistCodes[$activistCodeName];
    
    $responseObj = new stdClass();
    $canvassContextObj = new stdClass();
    $canvassResponseObj = new stdClass();

    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user = $stateCommitteeKey['App Name'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses";
    
    $responseObj->type = self::ACTIVIST_CODE;
    $responseObj->activistCodeId = $rid;
    $responseObj->action = $request['action'];
    
    $canvassContextObj->dateCanvassed = NULL;
    $canvassContextObj->contactTypeId = NULL;
    $canvassContextObj->inputTypeId = 11;
    
    $canvassResponseObj->canvassContext = $canvassContextObj;
    $canvassResponseObj->resultCodeId = NULL;
    $canvassResponseObj->responses = [ $responseObj,];
    
    $dataJson = json_encode( $canvassResponseObj );
    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
      'Expect'       => '',
    ];
    try {
      $this->client->post($postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('setApiActivistCode', $e->getMessage());
    } catch (GuzzleException $e) {
      nlp_debug_msg('setApiActivistCode', $e->getMessage());
    }
  }
  
}
