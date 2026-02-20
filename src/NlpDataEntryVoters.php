<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal;

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
   * @param $turfIndex
   * @return array - of voter information for this turf or FALSE if error.
   */
  function fetchVoters($turfIndex): array
  {
    $voters = [];
    $votersInTurf = $this->votersObj->fetchVotersByTurf($turfIndex);
    foreach ($votersInTurf as $voterInfo) {
      $vanid = $voterInfo['vanid'];
      $voterStatus = $this->votersObj->getVoterStatus($vanid);
      $voterInfo['status'] = $voterStatus;
      $voterContacts = $this->reportsObj->getNlpReports($vanid);
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
   * @param $turfIndex
   * @return int[]
   */
  public function turfCounts($turfIndex): array
  {
    $votersInTurf = $this->votersObj->fetchVotersByTurf($turfIndex);
    $voterCount = $votedCount = $attemptedCount = $contactedCount = 0;

    foreach ($votersInTurf as $voterInfo) {
      $vanid = $voterInfo['vanid'];
      $voterStatus = $this->votersObj->getVoterStatus($vanid);
      $voterInfo['status'] = $voterStatus;
      if(!empty($voterStatus['voted'])) {
        $votedCount++;
      }
      $voterContacts = $this->reportsObj->getNlpReports($vanid);
      $contacted = $attempted = false;
      $cycle = $this->nlpConfigurationObj->getCycle();
      
      foreach ($voterContacts as $voterContactList) {
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
      if($attempted) {$attemptedCount++;}
      if($contacted) {$contactedCount++;}
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
   * @param $turfIndex int
   * @param $needle string
   * @param $pageSize
   * @return array
   */
  function voterSearch( int $turfIndex, string $needle, $pageSize): array
  {
    $voters = $this->fetchVoters($turfIndex);
    $lcNeedle = strtolower($needle);
    $voterIndex = $page = 0;
    $found = FALSE;
    foreach($voters as $voter) {
      $lcLastName = strtolower( $voter['lastName']);
      if(str_contains($lcLastName,$lcNeedle)) {
        $found = TRUE;
        break;
      }
      $voterIndex++;
    }
    if($found) {
      $page = (int) floor($voterIndex/$pageSize);
    } else {
      $messenger = Drupal::messenger();
      $messenger->addStatus('Voter not found.');
    }
    return ['found'=> $found,'page'=> $page];
  }
  
}