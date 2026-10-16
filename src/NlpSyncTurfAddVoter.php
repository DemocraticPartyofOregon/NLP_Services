<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpSyncTurfAddVoter
{
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected ApiActivistCodes $activistCodesObj;
  protected NlpConfiguration $configurationObj;
  public function __construct($votersObj, $reportsObj, $activistCodesObj,
                              $configurationObj) {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->activistCodesObj = $activistCodesObj;
    $this->configurationObj = $configurationObj;
  }

  public static function create(ContainerInterface $container): NlpSyncTurfAddVoter
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * @param string $county
   * @param array $voter
   * @param int $turfIndex
   * @param int $mcid
   * @return void
   */
  public function syncAddVoter(string $county, array $voter, int $turfIndex, int $mcid ): void
  {
    if(empty($voter['vanid'])) {return;}
    $vanid = $voter['vanid'];
    // Update voter status based on imported values of the Activist Codes.
    $voterStatus = $this->votersObj->getVoterStatus($vanid);
    $cycle = $this->configurationObj->getCycle();
    if(empty($voterStatus['vanid'])) {
      $voterStatus['vanid'] = $vanid;
      $voterStatus['cycle'] = $cycle;
    }
    // Check if the status is from a different election.  Clear a stale status.
    if($voterStatus['cycle'] != $cycle) {
      $voterStatus = $this->votersObj->nullVoterStatus();
      $voterStatus['vanid'] = $vanid;
      $voterStatus['cycle'] = $cycle;
    }

    $voterStatus['hostile'] = (!empty($voter['activistCodes']['hostile']))?1:0;

    // Set common fields for setting the AC.
    $acRequest = [
      'vanid' => $vanid,
      'codeName' => 'NLPVoterAC',
      'action' => 'Apply',
      'source' => "turf resync (turfIndex $turfIndex)",
    ];
    $this->activistCodesObj->setApiActivistCode($acRequest);

    // Add voter to turf.
    $voter['address']['turfIndex'] = $turfIndex;
    $voter['address']['moved'] = NULL;

    $this->votersObj->createVoter($voter);
    $this->votersObj->setVoterStatus($vanid, $voterStatus);
    $turfVoter = [
      'mcid' => $mcid,
      'county' => $county,
      'turfIndex' => $turfIndex,
      'vanid' => $vanid,
    ];
    $this->votersObj->createTurfVoter($turfVoter);

    // Restore the information we already know about this voter.
    $this->reloadVoterStatus($vanid,$voter,$mcid);
  }
  
  /**
   * Recover the information known about this voter and rebuild the status
   * information for the data entry display pages.
   *
   * @param int $vanid
   * @param array $voter
   * @param int $mcid
   */
  private function reloadVoterStatus(int $vanid, array $voter, int $mcid): void
  {
    $surveyReports = $this->reportsObj->getNlpTypeReports($vanid,'Survey');
    $pledgedToVote = 0;
    foreach ($surveyReports as $surveyReport) {
      if($surveyReport['reportIndex'] > $pledgedToVote) {
        $pledgedToVote = $surveyReport['reportIndex'];
      }
    }
    
    $surveyReports = $this->reportsObj->getNlpTypeReports($vanid,'contact');
    $attemptedContact = 0;
    foreach ($surveyReports as $surveyReport) {
      if($surveyReport['reportIndex'] > $attemptedContact) {
        $attemptedContact = $surveyReport['reportIndex'];
      }
    }
  
    // Update the pledge to vote status for this voter.
    $turfVoter = [];
    $turfVoter['vanid'] = $vanid;
    $turfVoter['attemptedContact'] = $attemptedContact;
    $turfVoter['pledgedToVote'] = $pledgedToVote;
    $turfVoter['turfIndex'] = $voter['address']['turfIndex'];
    $this->votersObj->updateTurfVoter($turfVoter);
    
    //  Get the moved report if it exists.
    $movedRequest = [
      'vanid' => $vanid,
      'type' => 'contact',
      'value' => 'moved',
      'cycle' => NULL,  // All cycles.
    ];
    $movedReport = $this->reportsObj->getReport($movedRequest);
    // If the voter was marked as moved, check if there is a new address.
    if(!empty($movedReport['text'])) {
      $existingAddressObj = json_decode($movedReport['text'], TRUE);
      if(json_last_error() !== JSON_ERROR_NONE) { $existingAddressObj = []; }
      $existingAddress = (array) $existingAddressObj;
      $moveStatus = 1;
      $address = $voter['address'];
      $match = $this->votersObj->addressCompare($address,$existingAddress);
      if($match) {
        // Voter is at the same address where marked as moved.
        $this->votersObj->setMovedStatus($voter['address']['turfIndex'] ,$vanid,$moveStatus);
      }
    }
    
    //  Get the comment for this election cycle.
    $req = ['vanid' => $vanid, 'mcid' => $mcid];
    $comment = $this->reportsObj->getComment($req);
    $note = (!empty($comment['text']))?$comment['text']:'';
    $reportIndex = (!empty($comment['reportIndex']))?$comment['reportIndex']:0;
    $noteId = 0;
    $turfIndex = $voter['address']['turfIndex'];
    $this->votersObj->updateTurfNote($turfIndex,$vanid,$note,$reportIndex,$noteId);
    
  }
  
}
