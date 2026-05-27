<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryVoters
{
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($votersObj,$reportsObj, $nlpConfigurationObj) {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpDataEntryVoters
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.configuration')
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
      $voterContacts = $this->reportsObj->getNlpReports($vanid);
      $voterInfo['contacts'] = $voterContacts;
      $voterInfo['activist'] = $this->reportsObj->getNlpAcReport($vanid);
      $voterInfo['historic'] = $this->reportsObj->displayNlReports($voterContacts);
      $voters[$vanid] = $voterInfo;
    }
    return $voters;
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
