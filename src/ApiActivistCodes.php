<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
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
      $request = $this->client->request('GET', $postUrl);
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
   * given voter in the VoterFile or MyCampaign database.
   *
   * @param int $vanid
   * @param int $database - 0=VoterFile, 1=MyCampaign
   * @param string $committee - The committee whose key should be used; empty
   *   string uses the State Committee key. MyCampaign records belong to a
   *   specific county committee, so callers checking a MyCampaign activist
   *   code must pass that county.
   * @return array
   */
  public function getApiVoterActivistCodes(int $vanid, int $database = 0, string $committee = ''): array
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $url = "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes";
    try {
      $request = $this->client->request('GET', $url);
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
      nlp_debug_msg('getApiVoterActivistCodes', "URL: $url");
      return [];
    } catch (Exception $e) {
      nlp_debug_msg('getApiVoterActivistCodes', $e->getMessage());
      nlp_debug_msg('getApiVoterActivistCodes', "URL: $url");
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
   * @param string $committee - The committee whose key should be used; empty
   *   string uses the State Committee key. Must match the committee the code
   *   was applied under for MyCampaign codes.
   * @return bool
   */
  public function hasApiActivistCode(int $vanid, string $codeName, string $committee = ''): bool
  {
    $rid = $this->nlpConfigurationObj->getActivistCode($codeName);
    if (empty($rid)) {
      return FALSE;
    }
    $database = $this->supportedActivistCodes[$codeName];
    return in_array($rid, $this->getApiVoterActivistCodes($vanid, $database, $committee));
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
   *   - committee - optional; the committee whose key should be used. Empty
   *     uses the State Committee key. MyCampaign codes belong to a specific
   *     county committee, so callers setting a MyCampaign code must pass
   *     that county here.
   *
   * @param array $request
   * @return bool - TRUE on success, FALSE if the activist code ID is unknown or the API call fails.
   */
  public function setApiActivistCode(array $request): bool
  {
    $activistCodeName = $request['codeName'];
    $rid = $this->nlpConfigurationObj->getActivistCode($activistCodeName);
    if(empty($rid)) {return FALSE;}
    $vanid  = $request['vanid'];
    $action = $request['action'];
    $committee = $request['committee'] ?? '';
    $database = $this->supportedActivistCodes[$activistCodeName];

    $responseObj = new stdClass();
    $canvassContextObj = new stdClass();
    $canvassResponseObj = new stdClass();

    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses";

    $responseObj->type = self::ACTIVIST_CODE;
    $responseObj->activistCodeId = $rid;
    $responseObj->action = $action;

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
      $this->client->request('POST', $postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('setApiActivistCode', $e->getMessage());
      return FALSE;
    } catch (GuzzleException $e) {
      nlp_debug_msg('setApiActivistCode', $e->getMessage());
      return FALSE;
    }

    $codeIsSet = $this->hasApiActivistCode($vanid, $activistCodeName, $committee);
    $verified  = ($action === 'Apply') ? $codeIsSet : !$codeIsSet;
    if ($verified) {
      return TRUE;
    }

    Drupal::logger('nlpservices')->warning(
      'Activist code @code @action not confirmed for vanid @vanid after POST; retrying.',
      ['@code' => $activistCodeName, '@action' => $action, '@vanid' => $vanid]
    );
    try {
      $this->client->request('POST', $postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('setApiActivistCode retry', $e->getMessage());
      return FALSE;
    } catch (GuzzleException $e) {
      nlp_debug_msg('setApiActivistCode retry', $e->getMessage());
      return FALSE;
    }

    $codeIsSet = $this->hasApiActivistCode($vanid, $activistCodeName, $committee);
    $verified  = ($action === 'Apply') ? $codeIsSet : !$codeIsSet;
    if (!$verified) {
      Drupal::logger('nlpservices')->error(
        'Activist code @code @action still not confirmed for vanid @vanid after retry.',
        ['@code' => $activistCodeName, '@action' => $action, '@vanid' => $vanid]
      );
      return FALSE;
    }
    return TRUE;
  }

  /**
   * hasApiActivistCodeBatch
   *
   * Checks multiple voters concurrently and returns the subset that does NOT
   * have the named activist code applied.
   *
   * @param array  $vanids   - flat array of int vanids to check
   * @param string $codeName - e.g. 'NLPVoterAC'
   * @return array            - vanids (int) that are missing the code
   */
  public function hasApiActivistCodeBatch(array $vanids, string $codeName): array
  {
    if (empty($vanids)) {
      return [];
    }
    $rid = $this->nlpConfigurationObj->getActivistCode($codeName);
    if (empty($rid)) {
      return $vanids;
    }
    $database = $this->supportedActivistCodes[$codeName];
    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user   = $stateCommitteeKey['App Name'];
    if (empty($apiKey) || empty($apiURL) || empty($user)) {
      Drupal::logger('nlpservices')->error('hasApiActivistCodeBatch: missing State Committee API credentials.');
      return $vanids;
    }

    $hasCode = [];
    $requests = function() use ($vanids, $user, $apiKey, $database, $apiURL) {
      foreach ($vanids as $vanid) {
        $url = "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes";
        yield $vanid => new GuzzleRequest('GET', $url);
      }
    };

    $pool = new Pool($this->client, $requests(), [
      'concurrency' => 10,
      'fulfilled' => function($response, $vanid) use (&$hasCode, $rid) {
        $result = json_decode($response->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $hasCode[$vanid] = FALSE;
          return;
        }
        $codes = array_map(fn($item) => (int) $item['activistCodeId'], $result['items'] ?? []);
        $hasCode[$vanid] = in_array($rid, $codes);
      },
      'rejected' => function($reason, $vanid) use (&$hasCode) {
        nlp_debug_msg('hasApiActivistCodeBatch', $reason->getMessage());
        $hasCode[$vanid] = FALSE;
      },
    ]);
    $pool->promise()->wait();

    $missing = [];
    foreach ($vanids as $vanid) {
      if (!($hasCode[$vanid] ?? FALSE)) {
        $missing[] = $vanid;
      }
    }
    return $missing;
  }

  /**
   * setApiActivistCodeBatch
   *
   * Applies (or removes) an activist code to multiple voters concurrently.
   *
   * @param array  $vanids   - flat array of int vanids to update
   * @param string $codeName - e.g. 'NLPVoterAC'
   * @param string $action   - 'Apply' or 'Remove'
   */
  public function setApiActivistCodeBatch(array $vanids, string $codeName, string $action = 'Apply'): void
  {
    if (empty($vanids)) {
      return;
    }
    $rid = $this->nlpConfigurationObj->getActivistCode($codeName);
    if (empty($rid)) {
      return;
    }
    $database = $this->supportedActivistCodes[$codeName];
    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user   = $stateCommitteeKey['App Name'];
    if (empty($apiKey) || empty($apiURL) || empty($user)) {
      Drupal::logger('nlpservices')->error('setApiActivistCodeBatch: missing State Committee API credentials.');
      return;
    }

    $responseObj = new stdClass();
    $responseObj->type = self::ACTIVIST_CODE;
    $responseObj->activistCodeId = $rid;
    $responseObj->action = $action;

    $canvassContextObj = new stdClass();
    $canvassContextObj->dateCanvassed = NULL;
    $canvassContextObj->contactTypeId = NULL;
    $canvassContextObj->inputTypeId = 11;

    $canvassResponseObj = new stdClass();
    $canvassResponseObj->canvassContext = $canvassContextObj;
    $canvassResponseObj->resultCodeId = NULL;
    $canvassResponseObj->responses = [$responseObj];

    $dataJson = json_encode($canvassResponseObj);
    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
      'Expect'       => '',
    ];

    $requests = function() use ($vanids, $user, $apiKey, $database, $apiURL, $headers, $dataJson) {
      foreach ($vanids as $vanid) {
        $url = "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses";
        yield $vanid => new GuzzleRequest('POST', $url, $headers, $dataJson);
      }
    };

    $pool = new Pool($this->client, $requests(), [
      'concurrency' => 10,
      'fulfilled' => function() {},
      'rejected' => function($reason) {
        nlp_debug_msg('setApiActivistCodeBatch', $reason->getMessage());
      },
    ]);
    $pool->promise()->wait();
  }

}
