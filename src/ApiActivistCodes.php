<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
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
  private array $stateCommitteeKey;
  
  public function __construct( $client, $nlpConfigurationObj) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->stateCommitteeKey = $this->nlpConfigurationObj->getStateKey();
  }
  
  public static function create(ContainerInterface $container): ApiActivistCodes
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
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
    $apiKey = $this->stateCommitteeKey['API Key'];
    $apiURL = $this->stateCommitteeKey['Url'];
    $user = $this->stateCommitteeKey['App Name'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/activistCodes".'?$top=200';
    $request = $this->client->get($postUrl);
    $results = json_decode($request->getBody(), true);
    return $results['items'];
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
    //nlp_debug_msg('$request', $request);
    $activistCodeName = $request['codeName'];
    $rid = $this->nlpConfigurationObj->getActivistCode($activistCodeName);
    if(empty($rid)) {return;}
    $vanid = $request['vanid'];
    $database = $this->supportedActivistCodes[$activistCodeName];
    
    $responseObj = new stdClass();
    $canvassContextObj = new stdClass();
    $canvassResponseObj = new stdClass();
    
    $apiKey = $this->stateCommitteeKey['API Key'];
    $apiURL = $this->stateCommitteeKey['Url'];
    $user = $this->stateCommitteeKey['App Name'];
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
    $headers = ['Content-Type' => 'application/json', "Accept: application/json, Expect:"];
    try {
      $this->client->post($postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('setApiActivistCode', $e->getMessage());
    }
  }
  
}
