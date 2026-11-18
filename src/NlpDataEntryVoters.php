<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryVoters
{
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpConfiguration $nlpConfigurationObj;

  /**
   * NL names already looked up, keyed by mcid: ['nickname' => ..., 'lastName' => ...],
   * or [] when neither the nls table nor MyCampaign has both names. Saves
   * looking up the same NL for every voter in the turf.
   */
  private array $nlNames = [];

  public function __construct(
    NlpVoters $votersObj,
    NlpReports $reportsObj,
    NlpConfiguration $nlpConfigurationObj,
    private readonly NlpNls $nlsObj,
    private readonly ApiNls $apiNlsObj,
  ) {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpDataEntryVoters
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.api_nls'),
    );
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * fetchVoters
   *
   * Build the array of voter information for the voters in this NL's turf.
   *
   * @param int $turfIndex
   * @return array - of voter information for this turf.
   */
  public function fetchVoters( int $turfIndex ): array
  {
    $voters = [];
    $votersInTurf = $this->votersObj->fetchVotersByTurf($turfIndex);
    foreach ($votersInTurf as $voterInfo) {
      $vanid = $voterInfo['vanid'];
      $voterInfo['status'] = $this->votersObj->getVoterStatus($vanid);
      $voterContacts = $this->addNlNames($this->reportsObj->getNlpReports($vanid));
      $voterInfo['contacts'] = $voterContacts;
      $voterInfo['activist'] = $this->reportsObj->getNlpAcReport($vanid);
      $voterInfo['historic'] = $this->reportsObj->displayNlReports($voterContacts);
      $voters[$vanid] = $voterInfo;
    }
    return $voters;
  }

  /**
   * addNlNames
   *
   * Fill in the NL's first name (nickname) and last name on reports missing
   * either one, so the historical contacts can say who made each report. The
   * name comes from the nls table, or from MyCampaign when the nls table
   * doesn't have both names, and is saved to the NL's report records so the
   * lookup isn't repeated. When neither has both names, the NL's reports are
   * marked nlNameNotFound instead, and later page loads skip the search.
   *
   * @param array $voterContacts - reports keyed by vanid, as from NlpReports::getNlpReports().
   * @return array - the same reports, with the names filled in where found.
   */
  public function addNlNames(array $voterContacts): array
  {
    foreach ($voterContacts as $vanid => $reports) {
      foreach ($reports as $i => $report) {
        if (!empty($report['nickname']) && !empty($report['lastName'])) {continue;}
        // Already searched for, and not found.
        if (!empty($report['nlNameNotFound'])) {continue;}
        $mcid = (int)($report['mcid'] ?? 0);
        if ($mcid <= 0) {continue;}
        $name = $this->lookupNlName($mcid);
        if (empty($name)) {continue;}
        $voterContacts[$vanid][$i]['nickname'] = $name['nickname'];
        $voterContacts[$vanid][$i]['lastName'] = $name['lastName'];
      }
    }
    return $voterContacts;
  }

  /**
   * lookupNlName
   *
   * Find an NL's first and last name, first in the nls table and then in
   * MyCampaign, and save it to the NL's reports that are missing it. If
   * neither has both names, mark those reports so the search isn't repeated.
   *
   * @param int $mcid
   * @return array - ['nickname' => ..., 'lastName' => ...], or [] if not found.
   */
  private function lookupNlName(int $mcid): array
  {
    if (array_key_exists($mcid, $this->nlNames)) {
      return $this->nlNames[$mcid];
    }
    $name = $this->nameFromNlRecord($this->nlsObj->getNlById($mcid));
    $searchFailed = FALSE;
    if (empty($name)) {
      $apiNl = $this->apiNlsObj->getApiNls('', $mcid);
      // An unrecognized API key comes back as ['Invalid key'].
      if (isset($apiNl[0])) {$apiNl = [];}
      $name = $this->nameFromNlRecord($apiNl);
      // No record can mean MyCampaign doesn't have the NL, or that the API
      // couldn't be reached. Only the first is a reason to stop searching.
      $searchFailed = empty($apiNl)
        && $this->apiNlsObj->getMcidStatus('', $mcid) !== ApiNls::MCID_MISSING;
    }
    if (!empty($name)) {
      $this->reportsObj->updateNlName($mcid, $name['nickname'], $name['lastName']);
    }
    elseif (!$searchFailed) {
      $this->reportsObj->markNlNameNotFound($mcid);
    }
    $this->nlNames[$mcid] = $name;
    return $name;
  }

  /**
   * The name from an NL record (nls table or MyCampaign), using the first
   * name when there's no nickname.
   *
   * @param array $nl
   * @return array - ['nickname' => ..., 'lastName' => ...], or [] if the
   *   record is missing the first or last name.
   */
  private function nameFromNlRecord(array $nl): array
  {
    $nickname = trim((string)($nl['nickname'] ?? ''));
    if ($nickname === '') {
      $nickname = trim((string)($nl['firstName'] ?? ''));
    }
    $lastName = trim((string)($nl['lastName'] ?? ''));
    if ($nickname === '' || $lastName === '') {
      return [];
    }
    return ['nickname' => $nickname, 'lastName' => $lastName];
  }

  /**
   * turfCounts
   *
   * Counts the number of voters in a turf, how many have voted, how many have been contacted,
   * and how many have been attempted.
   *
   * Accepts an optional pre-fetched voters array from fetchVoters() to avoid redundant
   * database queries when the caller already has the voter list.
   *
   * @param int $turfIndex
   * @param array $voters
   * @return int[]
   */
  public function turfCounts(int $turfIndex, array $voters = []): array
  {
    if (empty($voters)) {
      $voters = $this->fetchVoters($turfIndex);
    }
    $voterCount = $votedCount = $attemptedCount = $contactedCount = 0;
    $cycle = $this->nlpConfigurationObj->getCycle();

    foreach ($voters as $voterInfo) {
      if (!empty($voterInfo['status']['voted'])) {
        $votedCount++;
      }
      $contacted = $attempted = false;
      foreach ($voterInfo['contacts'] as $voterContactList) {
        foreach ($voterContactList as $voterContact) {
          if ($voterContact['cycle'] == $cycle) {
            if ($voterContact['type'] == $this->reportsObj::SURVEY) {
              $attempted = $contacted = TRUE;
              break;
            } elseif ($voterContact['type'] == $this->reportsObj::CONTACT) {
              $attempted = TRUE;
            }
          }
        }
      }
      if ($attempted) { $attemptedCount++; }
      if ($contacted) { $contactedCount++; }
      $voterCount++;
    }

    return [
      'voterCount' => $voterCount,
      'votedCount' => $votedCount,
      'attemptedCount' => $attemptedCount,
      'contactedCount' => $contactedCount,
    ];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * voterSearch
   *
   * Search for a voter in the turf by last name.  If found, return the page number
   * where the voter is located.
   *
   * @param int $turfIndex
   * @param string $needle
   * @param int $pageSize
   * @return array
   */
  public function voterSearch(int $turfIndex, string $needle, int $pageSize): array
  {
    $voters = $this->fetchVoters($turfIndex);
    $lcNeedle = strtolower($needle);
    $voterIndex = $page = 0;
    $found = FALSE;
    foreach ($voters as $voter) {
      $lcLastName = strtolower($voter['lastName']);
      if (str_contains($lcLastName, $lcNeedle)) {
        $found = TRUE;
        break;
      }
      $voterIndex++;
    }
    if ($found) {
      $page = (int) floor($voterIndex / $pageSize);
    } else {
      Drupal::messenger()->addStatus(t('Voter not found.'));
    }
    return ['found' => $found, 'page' => $page];
  }

}
