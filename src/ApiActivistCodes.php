<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use ReflectionClass;
use stdClass;
use Throwable;

class ApiActivistCodes
{
  use ApiRetryTrait;

  const string ACTIVIST_CODE = 'ActivistCode';

  /**
   * Page size for reading a voter's activist codes. VAN's usual maximum
   * $top is 200; without $top it sends a smaller default page.
   */
  const int VOTER_CODES_PAGE_SIZE = 200;

  /**
   * Stops a VAN response that always sends a nextPageLink from looping forever.
   */
  const int VOTER_CODES_MAX_PAGES = 10;

  /**
   * Queue of activist code changes to check on cron. VAN can take seconds to
   * show a change it accepted, so the check is done later instead of making
   * the person who saved the change wait for it.
   */
  const string VERIFY_QUEUE = 'nlpservices_ac_verify_queue';

  /**
   * NLPVoterAC changed both ways for a voter within this many seconds is a
   * conflict.  VAN may finish the two changes in either order.
   */
  const int CONFLICT_WINDOW_SECS = 3600;

  /**
   * How long after a conflicting change a voter is checked again.  The first
   * check can pass before VAN finishes the other change.
   */
  const int LATE_CHECK_SECS = 3600;

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
    } catch (GuzzleException|Exception $e) {
      nlp_debug_msg('getApiActivistCodes', $e);
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
    $baseUrl = "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes";
    // VAN pages this list. A voter with many codes can have the one we want
    // on a later page, so keep reading while VAN says there are more.
    $codes = [];
    $skip = 0;
    try {
      do {
        $url = $baseUrl.'?$top='.self::VOTER_CODES_PAGE_SIZE.'&$skip='.$skip;
        $request = $this->client->request('GET', $url);
        $results = json_decode($request->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          return [];
        }
        $items = $results['items'] ?? [];
        foreach ($items as $item) {
          $codes[] = (int) $item['activistCodeId'];
        }
        $skip += self::VOTER_CODES_PAGE_SIZE;
      } while (!empty($results['nextPageLink']) && !empty($items)
        && $skip < self::VOTER_CODES_PAGE_SIZE * self::VOTER_CODES_MAX_PAGES);
      return $codes;
    } catch (GuzzleException|Exception $e) {
      nlp_debug_msg('getApiVoterActivistCodes', $e);
      // The URL carries the API key, so log it without the credentials.
      nlp_debug_msg('getApiVoterActivistCodes', 'URL: https://'.$apiURL."/people/$vanid/activistCodes".'?$top='
        .self::VOTER_CODES_PAGE_SIZE.'&$skip='.$skip." (database $database)");
      return [];
    }
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
   *   - source - optional; what made the change, like 'data entry'. It is
   *     logged if the POST fails or VAN doesn't show the change.
   *
   * Returns as soon as VAN answers the POST. Whether VAN shows the change is
   * checked later on cron by verifyActivistCodeChange().
   *
   * @param array $request
   * @return bool - TRUE if VAN accepted the POST, FALSE if the activist code
   *   ID is unknown or the POST failed.
   */
  public function setApiActivistCode(array $request): bool
  {
    $activistCodeName = $request['codeName'];
    $rid = $this->nlpConfigurationObj->getActivistCode($activistCodeName);
    if(empty($rid)) {return FALSE;}
    $vanid  = $request['vanid'];
    $action = $request['action'];
    $committee = $request['committee'] ?? '';
    $source = $request['source'] ?? '';
    $database = $this->supportedActivistCodes[$activistCodeName];

    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses";

    $dataJson = $this->canvassResponseJson($rid, $action);
    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
      'Expect'       => '',
    ];
    try {
      $this->client->request('POST', $postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (GuzzleException | Exception $e) {
      $this->logPostFailure($e, $vanid, $activistCodeName, $action, $source);
      if ($this->outcomeUnknown($e)) {
        $this->queueVerification([$vanid], $activistCodeName, $rid, $action, $database, $committee, $source);
      }
      return FALSE;
    }
    $this->queueVerification([$vanid], $activistCodeName, $rid, $action, $database, $committee, $source);
    return TRUE;
  }

  /**
   * hasApiActivistCodeBatch
   *
   * Checks multiple voters concurrently and returns the subset that does NOT
   * have the named activist code applied.
   *
   * A voter whose codes couldn't be read (VAN refused or didn't answer the
   * request, as it does with a 404 for a merged vanid) is not counted as
   * missing the code.  Those voters are logged and returned in $unread.
   *
   * @param array  $vanids   - flat array of int vanids to check
   * @param string $codeName - e.g., 'NLPVoterAC'
   * @param array|null $unread - set to the vanids whose codes couldn't be read.
   * @return array            - vanids (int) that are missing the code
   */
  public function hasApiActivistCodeBatch(array $vanids, string $codeName, ?array &$unread = NULL): array
  {
    $unread = [];
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
    $unreadReasons = [];
    $this->sendInRateLimitedGroups(
      $vanids,
      fn($vanid) => new GuzzleRequest('GET', "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes".'?$top='.self::VOTER_CODES_PAGE_SIZE),
      function($response, $vanid) use (&$hasCode, &$unreadReasons, $rid, $database) {
        $result = json_decode($response->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $unreadReasons[$vanid] = 'invalid JSON';
          return;
        }
        $codes = array_map(fn($item) => (int) $item['activistCodeId'], $result['items'] ?? []);
        if (!in_array($rid, $codes) && !empty($result['nextPageLink'])) {
          // The code may be on a later page.
          $codes = $this->getApiVoterActivistCodes($vanid, $database);
        }
        $hasCode[$vanid] = in_array($rid, $codes);
      },
      function($reason, $vanid) use (&$unreadReasons) {
        $response = ($reason instanceof RequestException) ? $reason->getResponse() : NULL;
        $unreadReasons[$vanid] = $response ? 'status '.$response->getStatusCode() : 'no response';
      },
    );

    $missing = [];
    foreach ($vanids as $vanid) {
      if (isset($unreadReasons[$vanid])) {
        $unread[] = $vanid;
      } elseif (!($hasCode[$vanid] ?? FALSE)) {
        $missing[] = $vanid;
      }
    }
    if ($unreadReasons) {
      $byReason = [];
      foreach ($unreadReasons as $vanid => $why) {
        $byReason[$why][] = $vanid;
      }
      $details = [];
      foreach ($byReason as $why => $reasonVanids) {
        $details[] = "$why: ".implode(', ', $reasonVanids);
      }
      $this->log('warning',
        'Could not read activist codes from VAN to check @code for @count voter(s), so they were not counted as missing. @details',
        ['@code' => $codeName, '@count' => count($unreadReasons), '@details' => implode('; ', $details)]);
    }
    return $missing;
  }

  /**
   * setApiActivistCodeBatch
   *
   * Applies (or removes) an activist code to multiple voters concurrently.
   *
   * A POST that fails is logged with its vanid. Every change VAN accepted,
   * or may have accepted, is queued to be checked on cron.
   *
   * @param array  $vanids   - flat array of int vanids to update
   * @param string $codeName - e.g., 'NLPVoterAC'
   * @param string $action   - 'Apply' or 'Remove'
   * @param string $source   - what made the change, like 'turf sync'; logged
   *   if a POST fails or VAN doesn't show the change.
   */
  public function setApiActivistCodeBatch(array $vanids, string $codeName, string $action, string $source = ''): void
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
    if (empty($stateCommitteeKey['API Key']) || empty($stateCommitteeKey['Url']) || empty($stateCommitteeKey['App Name'])) {
      Drupal::logger('nlpservices')->error('setApiActivistCodeBatch: missing State Committee API credentials.');
      return;
    }

    $toVerify = $this->sendChange($vanids, $stateCommitteeKey, $codeName, $rid, $action, $database, $source);
    $this->queueVerification($toVerify, $codeName, $rid, $action, $database, '', $source);
    $this->queueLateCheck($toVerify, $codeName, $rid, $action, $database, $source);
  }

  /**
   * Sends an activist code change and records it in the NLPVoterAC history.
   * Returns the vanids VAN accepted, or may have accepted.
   */
  private function sendChange(array $vanids, array $committeeKey, string $codeName, int $rid,
                              string $action, int $database, string $source): array
  {
    $sent = $this->postActivistCodeBatch($vanids, $committeeKey, $codeName, $rid, $action, $database, $source);
    $history = $this->acHistory($codeName);
    if ($history) {
      $history->record($sent, ($action === 'Apply') ? NlpAcHistory::APPLY : NlpAcHistory::REMOVE, $source);
      $history->record(array_values(array_diff($vanids, $sent)),
        ($action === 'Apply') ? NlpAcHistory::APPLY_REFUSED : NlpAcHistory::REMOVE_REFUSED, $source);
    }
    return $sent;
  }

  /**
   * Queues a late check of the voters NLPVoterAC was changed the other way
   * for within CONFLICT_WINDOW_SECS.  The first check can pass before VAN
   * finishes the other change, and the other change can undo this one.
   */
  private function queueLateCheck(array $vanids, string $codeName, int $rid, string $action,
                                  int $database, string $source): void
  {
    $history = $this->acHistory($codeName);
    if (!$history || empty($vanids)) {
      return;
    }
    $opposite = ($action === 'Apply') ? NlpAcHistory::REMOVE : NlpAcHistory::APPLY;
    $conflicts = $history->vanidsWithEventSince($vanids, [$opposite], time() - self::CONFLICT_WINDOW_SECS);
    $this->queueVerification($conflicts, $codeName, $rid, $action, $database, '', $source,
      ['minAge' => self::LATE_CHECK_SECS, 'late' => TRUE]);
  }

  /**
   * The NLPVoterAC history, or NULL for other codes.
   */
  private function acHistory(string $codeName): ?NlpAcHistory
  {
    if ($codeName !== 'NLPVoterAC' || !Drupal::hasService('nlpservices.ac_history')) {
      return NULL;
    }
    return Drupal::service('nlpservices.ac_history');
  }

  /**
   * POSTs an activist code change for each voter and logs each POST that
   * failed. Returns the vanids VAN accepted, or may have accepted.
   */
  private function postActivistCodeBatch(array $vanids, array $committeeKey, string $codeName, int $rid,
                                         string $action, int $database, string $source): array
  {
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user   = $committeeKey['App Name'];
    $dataJson = $this->canvassResponseJson($rid, $action);
    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
      'Expect'       => '',
    ];

    $sent = [];
    $this->sendInRateLimitedGroups(
      $vanids,
      fn($vanid) => new GuzzleRequest('POST', "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses", $headers, $dataJson),
      function($response, $vanid) use (&$sent) {
        $sent[] = $vanid;
      },
      function($reason, $vanid) use (&$sent, $codeName, $action, $source) {
        $this->logPostFailure($reason, $vanid, $codeName, $action, $source);
        if ($this->outcomeUnknown($reason)) {
          $sent[] = $vanid;
        }
      },
    );
    return $sent;
  }

  /**
   * The canvassResponses body that applies or removes one activist code.
   */
  private function canvassResponseJson(int $rid, string $action): string
  {
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

    return json_encode($canvassResponseObj);
  }

  /**
   * verifyActivistCodeChange
   *
   * Checks that VAN shows a change queued by queueVerification(). The change
   * is sent once more to voters VAN doesn't show it for, and checked again
   * on a later cron. If VAN still doesn't show it, an error names them.
   * A Remove of NLPVoterAC that took the code from a voter now in a turf is
   * followed by an Apply. Called on cron by the nlpservices_ac_verify_queue
   * worker.
   *
   * @param array $item - keys vanids, codeName, rid, action, database,
   *   committee, source, queuedAt; a retry also has firstQueuedAt, and a
   *   late check has minAge and late.
   * @return void
   */
  public function verifyActivistCodeChange(array $item): void
  {
    $vanids = $item['vanids'] ?? [];
    if (empty($vanids)) {
      return;
    }
    $rid = (int) $item['rid'];
    $action = $item['action'];
    $database = (int) $item['database'];
    $committee = $item['committee'] ?? '';

    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user   = $committeeKey['App Name'];

    $hasCode = [];
    $unread = [];
    $this->sendInRateLimitedGroups(
      $vanids,
      fn($vanid) => new GuzzleRequest('GET', "https://$user:$apiKey|$database@$apiURL/people/$vanid/activistCodes".'?$top='.self::VOTER_CODES_PAGE_SIZE),
      function($response, $vanid) use (&$hasCode, &$unread, $rid, $database, $committee) {
        $result = json_decode($response->getBody(), TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $unread[] = $vanid;
          return;
        }
        $codes = array_map(fn($code) => (int) $code['activistCodeId'], $result['items'] ?? []);
        if (!in_array($rid, $codes) && !empty($result['nextPageLink'])) {
          // The code may be on a later page.
          $codes = $this->getApiVoterActivistCodes($vanid, $database, $committee);
        }
        $hasCode[$vanid] = in_array($rid, $codes);
      },
      function($reason, $vanid) use (&$unread) {
        $unread[] = $vanid;
      },
    );

    // NLPVoterAC is checked against the turfs the voters are in now, not just
    // the change that was sent.  A voter can be synced into a new turf while
    // the Remove from a deleted turf is still waiting in VAN.
    $inTurfs = [];
    if ($action === 'Remove' && $item['codeName'] === 'NLPVoterAC' && $hasCode
      && Drupal::hasService('nlpservices.voters')) {
      $inTurfs = Drupal::service('nlpservices.voters')->getVanidsInTurfs(array_keys($hasCode));
    }
    $notShown = [];
    $lostCode = [];
    foreach ($hasCode as $vanid => $codeIsSet) {
      if (($action === 'Apply') ? !$codeIsSet : $codeIsSet) {
        // A voter synced into a turf after the Remove was sent has the code again on purpose.
        // Sending the Remove again would take it from a voter whose NL needs it.
        if (!in_array($vanid, $inTurfs)) {
          $notShown[] = $vanid;
        }
      } elseif (in_array($vanid, $inTurfs)) {
        // The Remove took the code from a voter who is now in a turf.
        $lostCode[] = $vanid;
      }
    }

    $history = $this->acHistory($item['codeName']);
    $firstQueuedAt = (int) ($item['firstQueuedAt'] ?? $item['queuedAt']);
    if ($lostCode && $history) {
      // A voter with an Apply sent since the Remove has a late check queued for it,
      // and VAN may not have finished that Apply yet.
      $lostCode = array_values(array_diff($lostCode,
        $history->vanidsWithEventSince($lostCode, [NlpAcHistory::APPLY], $firstQueuedAt)));
    }
    $check = !empty($item['late']) ? 'late check' : (empty($item['firstQueuedAt']) ? 'first check' : 'second check');
    $missing = ($action === 'Apply' && $check !== 'first check') ? $notShown : [];
    if ($history && ($missing || $lostCode)) {
      $history->record(array_merge($missing, $lostCode), NlpAcHistory::MISSING, "$check of $action from {$item['source']}");
    }

    $context = [
      '@code' => $item['codeName'],
      '@rid' => $rid,
      '@action' => $action,
      '@source' => $item['source'] ?: 'unknown',
      '@time' => date('Y-m-d H:i:s', $firstQueuedAt),
      '@database' => $database ? 'MyCampaign' : 'VoterFile',
      '@committee' => $committee ?: 'State Committee',
      '@check' => $check,
    ];
    // The history of each voter around the change, to look for a pattern.
    $describe = fn(array $vanids): string => $history
      ? ' History: ' . $history->describe($vanids, $firstQueuedAt - self::CONFLICT_WINDOW_SECS) : '';
    if ($notShown && empty($item['firstQueuedAt'])) {
      $this->log('warning',
        'Activist code @code (@rid) @action from @source at @time is not shown by VAN for @count voter(s) at the @check: @vanids. '
        . 'Database: @database, committee key: @committee. Sending it again.@history',
        $context + ['@count' => count($notShown), '@vanids' => implode(', ', $notShown),
          '@history' => $missing ? $describe($notShown) : '']);
      $sent = $this->sendChange($notShown, $committeeKey, $item['codeName'], $rid, $action, $database,
        "{$item['source']} (sent again)");
      $this->queueVerification($sent, $item['codeName'], $rid, $action, $database, $committee, $item['source'],
        ['firstQueuedAt' => (int) $item['queuedAt']]);
    } elseif ($notShown) {
      $this->log('error',
        'Activist code @code (@rid) @action from @source at @time is not shown by VAN for @count voter(s): @vanids, '
        . 'even after it was sent again. Database: @database, committee key: @committee.@history',
        $context + ['@count' => count($notShown), '@vanids' => implode(', ', $notShown),
          '@history' => $missing ? $describe($notShown) : '']);
    }
    if ($lostCode) {
      $this->log('warning',
        'Activist code @code (@rid) is gone from @count voter(s) now in a turf, at the @check of the Remove from @source '
        . 'at @time: @vanids. Applying it again.@history',
        $context + ['@count' => count($lostCode), '@vanids' => implode(', ', $lostCode),
          '@history' => $describe($lostCode)]);
      $source = "re-apply after Remove from {$item['source']}";
      $sent = $this->sendChange($lostCode, $committeeKey, $item['codeName'], $rid, 'Apply', $database, $source);
      $this->queueVerification($sent, $item['codeName'], $rid, 'Apply', $database, $committee, $source);
    }
    if ($unread) {
      $this->log('warning',
        'Could not read activist codes from VAN to check @code (@rid) @action from @source at @time for @count voter(s): @vanids. '
        . 'Database: @database, committee key: @committee.',
        $context + ['@count' => count($unread), '@vanids' => implode(', ', $unread)]);
    }
  }

  /**
   * Queues a change for verifyActivistCodeChange(). The code's ID is stored,
   * not just its name, since after an election the name goes to a new code.
   * $extra can have firstQueuedAt, set when the change is being sent again
   * to when it was first queued, or minAge and late for a late check.
   */
  private function queueVerification(array $vanids, string $codeName, int $rid, string $action,
                                     int $database, string $committee, string $source,
                                     array $extra = []): void
  {
    if (empty($vanids) || !Drupal::hasService('queue')) {
      return;
    }
    Drupal::queue(self::VERIFY_QUEUE)->createItem($extra + [
      'vanids' => array_values($vanids),
      'codeName' => $codeName,
      'rid' => $rid,
      'action' => $action,
      'database' => $database,
      'committee' => $committee,
      'source' => $source,
      'queuedAt' => time(),
    ]);
  }

  /**
   * Whether VAN may have applied a POST that failed: there was no answer at
   * all (a timeout or dropped connection), or VAN or its gateway answered
   * with a 5xx. A 4xx means VAN refused it.
   */
  private function outcomeUnknown(Throwable $reason): bool
  {
    if (!($reason instanceof RequestException) || !$reason->hasResponse()) {
      return TRUE;
    }
    return $reason->getResponse()->getStatusCode() >= 500;
  }

  /**
   * Logs a POST that failed, with the voter it was for. VAN's error body is
   * logged rather than the exception message, which repeats the request URL.
   */
  private function logPostFailure(Throwable $reason, int|string $vanid, string $codeName,
                                  string $action, string $source): void
  {
    $response = ($reason instanceof RequestException) ? $reason->getResponse() : NULL;
    if ($response) {
      $detail = mb_substr((string) $response->getBody(), 0, 500) ?: '(empty body)';
    } else {
      $context = method_exists($reason, 'getHandlerContext') ? $reason->getHandlerContext() : [];
      $detail = $context['error'] ?? 'no response';
    }
    $this->log('error',
      'Activist code @code @action failed for vanid @vanid from @source (@class, status @status: @detail). @outcome',
      [
        '@code' => $codeName,
        '@action' => $action,
        '@vanid' => $vanid,
        '@source' => $source ?: 'unknown',
        '@class' => (new ReflectionClass($reason))->getShortName(),
        '@status' => $response ? $response->getStatusCode() : 'none',
        '@detail' => $detail,
        '@outcome' => $this->outcomeUnknown($reason)
          ? 'VAN may still have applied it; it will be checked on cron.'
          : 'VAN refused it.',
      ]);
  }

  private function log(string $level, string $message, array $context): void
  {
    if (Drupal::hasService('logger.factory')) {
      Drupal::logger('nlpservices')->log($level, $message, $context);
    }
  }

}
