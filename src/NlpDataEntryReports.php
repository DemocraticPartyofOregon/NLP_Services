<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryReports
{
  protected NlpReports $reportsObj;
  protected NlpNls $nlsObj;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  protected NlpVoters $votersObj;
  protected ApiVoter $apiVoterObj;
  protected NlpAwards $awardsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  
  public function __construct($reportsObj, $nlsObj, $apiSurveyQuestionObj, $votersObj,
                              $apiVoterObj, $awardsObj, $nlpConfigurationObj, $apiActivistCodesObj )
  {
    $this->reportsObj = $reportsObj;
    $this->nlsObj = $nlsObj;
    $this->apiSurveyQuestionObj = $apiSurveyQuestionObj;
    $this->votersObj = $votersObj;
    $this->apiVoterObj = $apiVoterObj;
    $this->awardsObj = $awardsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
  }
  
  public static function create(ContainerInterface $container): NlpDataEntryReports
  {
    return new static(
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.survey_question'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.api_voter'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.activist_codes'),
    );
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * hostileVoter
   * 
   * Record a change in the Hostile status for a voter in both VoteBuilder and NLP Services.
   *
   * @param array $report - The report data for the voter.
   *              'vanid' => The Voter Access Number (VANID) of the voter.
   *              'mcid' => The MyCampaign ID for the NL.
   *              'county' => The county of the voter.
   * 
   * @param array $action - The action array containing the contact method and hostile status.
   *              'contactMethod' => The contact method ID.
   *              'hostile' => A boolean indicating if the voter is hostile.
   */
  public function hostileVoter( array $report, array $action): void
  {
    // Record the change in the Hostile status for this voter in the NLP database.
    $contactMethodId = $action['contactMethod'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($contactMethodId);
    $report['reportIndex'] = $this->reportsObj->getAcReportIndex($report['vanid'], 'NLPHostile');
    $report['type'] = 'Activist';
    $report['text'] = 'NLPHostile';
    $report['value'] = (!empty($action['hostile']))?1:0; // Set value true if hostile checked, else clear it.
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $this->reportsObj->mergeReport($report);
    
    // Record the change of the NLP Hostile activist code.
    $request = [
      'vanid' => $report['vanid'],
      'codeName' => 'NLPHostileAC',
      'action' => ($report['value'])?'Apply':'Remove',
      'source' => 'data entry by NL '.$report['mcid'],
    ];
    $this->apiActivistCodesObj->setApiActivistCode($request);
    
    // Results reported by this NL.
    $this->nlsObj->resultsReported($report['mcid'], $report['county']);
    $this->awardsObj->nlParticipation($report['mcid'], 'Completed');
    
    // Remember the state of the NLP Hostile activist code in VoteBuilder.
    $voterStatus = $this->votersObj->getVoterStatus($report['vanid']);
    $voterStatus['hostile'] = $report['value'];
    $this->votersObj->setVoterStatus($report['vanid'], $voterStatus);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * deceasedVoter
   *
   * @param array $report - The report data for the voter.
   *              'vanid' => The Voter Access Number (VANID) of the voter.
   *              'mcid' => The MyCampaign ID for the NL.
   *              'county' => The county of the voter.
   *              'contactDate' => The date of the voter contact.
   *
   * @param array $action - The action array containing the contact method and hostile status.
   *              'contactMethod' => The contact method ID.
   *              'turfIndex' => The turf index for the voter.
 */
  public function deceasedVoter( array $report, array $action ): void
  {
    // This voter is being reported as deceased.  Record in NLP Services.
    $contactMethodId = $action['contactMethod'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($contactMethodId);
    
    // Deceased is not available for Social Media contact method.  Substitute Phone.
    $cid = ($contactMethod=='Social Media')?
      $this->nlpConfigurationObj->getContactId('Phone') : $contactMethodId;
    $rid = $this->nlpConfigurationObj->getResponseId($contactMethod,'Deceased');
    
    // Record the voter is deceased in NLP Services.
    $report['reportIndex'] = NULL;
    $report['type'] = 'contact';
    $report['value'] = 'Deceased';
    $report['text'] = '';
    $report['qid'] = NULL;
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $rIndex = $this->reportsObj->setNlReport($report);
    
    // Report the canvass response code for the deceased to VoteBuilder.
    $canvassDate = date(DATE_ATOM, strtotime($report['contactDate']));
    $surveyResponse['vanid'] = $report['vanid'];
    $surveyResponse['type'] = 'contactAttempt';
    $surveyResponse['rid'] = $rid;
    $surveyResponse['dateCanvassed'] = $canvassDate;
    $surveyResponse['ContactTypeCode'] = $cid;
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
    
    // Mark Contact attempt for this voter.
    $turfVoter = [];
    $turfVoter['vanid'] = $report['vanid'];
    $turfVoter['mcid'] = $report['mcid'];
    $turfVoter['county'] = $report['county'];
    $turfVoter['turfIndex'] = $action['turfIndex'];
    $turfVoter['attemptedContact'] = $rIndex;
    $this->votersObj->updateTurfVoter($turfVoter);
    
    // Results reported by this NL.
    $this->nlsObj->resultsReported($report['mcid'], $report['county']);
    $this->awardsObj->nlParticipation($report['mcid'], 'Completed');
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * noContactResponse
   *
   * @param array $report - The report data for the voter.
   *             'vanid' => The Voter Access Number (VANID) of the voter.
   *             'mcid' => The MyCampaign ID for the NL.
   *             'county' => The county of the voter.
   *             'contactDate' => The date of the voter contact.
   *
   * @param array $action - The action array containing the contact method and attempt result.
   *             'contactMethod' => The contact method ID.
   *             'notContacted' => The response ID for not contacted.
   * @return bool - FALSE if the response isn't one of the method's responses
   *             and nothing was saved.
   */
  public function noContactResponse( array $report, array $action): bool
  {
    $contactMethodId = $action['contactMethod'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($contactMethodId);
    $responseId = $action['notContacted'];

    // A response that isn't one of this method's responses has no name, and
    // its report would show as an attempt with an empty result.  Save nothing.
    $responseName = $this->nlpConfigurationObj->getContactResponse($contactMethod, $responseId);
    if (empty($responseName)) {
      return FALSE;
    }

    // Save the contact attempt in NLP Services.
    $report['type'] = 'contact';
    $report['text'] = '';
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $report['value'] = $responseName;
    $rIndex = $this->reportsObj->setNlReport($report);

    //  Report the canvass response code to VoteBuilder.
    $surveyResponse['vanid'] = $report['vanid'];
    $surveyResponse['type'] = 'contactAttempt';
    $surveyResponse['rid'] = $responseId;
    $surveyResponse['ContactTypeCode'] = $contactMethodId;
    $vanid = $report['vanid'];
    $altPhone = $this->phoneContact($vanid,$contactMethod,$responseName);
    if (!empty($altPhone['phoneNo'])) {
      $surveyResponse['phoneNumber'] = $altPhone['phoneNo'];
    }
    if (!empty($altPhone['phoneNoId'])) {
      $surveyResponse['phoneId'] = $altPhone['phoneNoId'];
    }
    if (!empty($altPhone['subContext'])) {
      $surveyResponse['ContactTypeCode'] = $altPhone['subContext'];
      $surveyResponse['rid'] = $altPhone['subResponse'];
    }
    $canvassDate = date(DATE_ATOM, strtotime($report['contactDate']));
    $surveyResponse['dateCanvassed'] = $canvassDate;
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
    
    // Mark Contact attempt for this voter.
    $turfVoter = [];
    $turfVoter['vanid'] = $report['vanid'];
    $turfVoter['mcid'] = $report['mcid'];
    $turfVoter['county'] = $report['county'];
    $turfVoter['turfIndex'] = $action['turfIndex'];
    $turfVoter['attemptedContact'] = $rIndex;
    $this->votersObj->updateTurfVoter($turfVoter);
    
    // This NL has reported results.
    $this->nlsObj->resultsReported($report['mcid'], $report['county']);
    $this->awardsObj->nlParticipation($report['mcid'], 'Completed');
    return TRUE;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * badPhone
   *
   * Record a bad phone number for a voter in NLP Services and VoteBuilder.
   *
   * @param string $type - Phone type, either cellPhone or homePhone.
   * @param array $report - The report data for the voter.
   * @param array $action - The action array containing the contact method and phone number.
   *            'contactMethod' => The contact method ID.
   *           'phoneNumber' => The phone number that is bad.
   *           'phoneId' => The phone ID for the bad phone number.
   * @return void
   */
  public function badPhone( string $type, array $report, array $action): void
  {
    $phoneNumber = $action['phoneNumber'];
    // Save the bad phone number in NLP Services.
    $report['contactType'] = 'Phone';
    $report['type'] = 'change';
    $report['text'] = $type;
    $report['value'] = 'Wrong number: '.$phoneNumber;
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['cd'] = $this->votersObj->getVoterCd($report['vanid']);
    $this->reportsObj->setNlReport($report);
    
    // Remove the phone number from the voter record in NLP.
    $typeId = $type . 'Id';
    $newPhoneNumber = [
      $type => NULL,
      $typeId => NULL,
    ];
    $this->votersObj->updateVoterPhone($report['vanid'], $newPhoneNumber);
    
    // Report the canvass response code for the bad phone number to VoteBuilder.
    $rid = $this->nlpConfigurationObj->getResponseId('Phone','Wrong Number');
    $cid = $action['contactMethod'];
    $canvassDate = date(DATE_ATOM, strtotime($report['contactDate']));
    $surveyResponse['type'] = 'contactAttempt';
    $surveyResponse['dateCanvassed'] = $canvassDate;
    $surveyResponse['vanid'] = $action['vanid'];
    $surveyResponse['rid'] = $rid;
    $surveyResponse['ContactTypeCode'] = $cid;
    $surveyResponse['phoneId'] = $action['phoneId'];
    $surveyResponse['phoneNumber'] = $action['phoneNumber'];
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * phoneContact
   *
   * Phone and text contacts can be made by the canvasser and reported to NLP.  However,
   * VoteBuilder does not support some reports for these contact methods.   To record these
   * voter contacts, a substitute contact method is used.  The substitute contact method is configured
   * during the election setup in the NLP Configuration.   Basically, the substitute contact methods
   * are either Walk or Web Survey.
   *
   * And, there has to be a phone number for the voter.  If the voter has a cell phone, it is used.
   *
   * @param int $vanid
   * @param string $contactMethod
   * @param string $responseName
   * @return array
   */
  private function phoneContact( int $vanid, string $contactMethod, string $responseName): array
  {
    if (!($contactMethod == 'Phone' || $contactMethod == 'SMS Text')) {
      return [];  // Alternate contact method is only for Phone and SMS Text.
    }
    $altPhone = $this->nlpConfigurationObj->getAlternatePhoneReportCodes();
    $voter = $this->votersObj->getVoterById($vanid);
    
    // If a cell phone is available, use it.  Use a home phone only if no cell phone and not SMS Text.
    if (!empty($voter['cellPhone'])) {
      $altPhoneRc['phoneNo'] = $voter['cellPhone'];
      $altPhoneRc['phoneNoId'] = $voter['cellPhoneId'];
      return $altPhoneRc;
    } elseif (!empty($voter['homePhone']) && $contactMethod != 'SMS Text') {
      $altPhoneRc['phoneNo'] = $voter['homePhone'];
      $altPhoneRc['phoneNoId'] = $voter['homePhoneId'];
      return $altPhoneRc;
    }
    // The requirement to have a phone number is not met.  Use the substitute contact method.
    $altPhoneContactMethod = $altPhone[$contactMethod];
    if (empty($altPhoneContactMethod[$responseName])) {
      $altPhoneRc = $altPhoneContactMethod['Other'];
    } else {
      $altPhoneRc = $altPhoneContactMethod[$responseName];
    }
    return $altPhoneRc;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * addNote
   *
   * A note entered for a voter is saved in NLP Services.  If the voter already has a note,
   * the previous note is updated.
   *
   * @param array $report
   * @param array $action
   * @return void
   */
  public function addNote( array $report, array $action): void
  {
    $noteString = trim(htmlentities(strip_tags($action['note']), ENT_QUOTES));
    $commentMax = $this->reportsObj::MAX_COMMENT;
    if (strlen($noteString) > $commentMax) {
      $note = substr($noteString, 0, $commentMax);  // Truncate the comment.
    } else {
      $note = $noteString;
    }
    $note = str_replace(["\r\n", "\n", "\r"], "<br>", $note);
    // if a note for this voter already exists, update the note else create one.
    $reportIndex = $action['reportIndex'];
    if (empty($reportIndex)) {
      $report['type'] = 'Comment';
      $report['value'] = '';
      $report['text'] = $note;
      $report['cycle'] = $this->nlpConfigurationObj->getCycle();
      $this->reportsObj->setNlReport($report);
    } else {
      $report['text'] = $note;
      $report['reportIndex'] = $reportIndex;
      $this->reportsObj->updateComment($report);
    }
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * voterMoved
   *
   * @param array $report - The report data for the voter.
   *             'vanid' => The Voter Access Number (VANID) of the voter.
   *            'mcid' => The MyCampaign ID for the NL.
   *            'county' => The county of the voter.
   *            'contactDate' => The date of the voter contact.
   * @param array $action - The action array containing the contact method and turf index.
   *            'contactMethod' => The contact method ID.
   *           'turfIndex' => The turf index for the voter.
   * @return void
   */
  public function voterMoved( array $report, array $action): void
  {
    $contactMethodId = $action['contactMethod'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($contactMethodId);
    
    $vanid = $report['vanid'];
    $addressInNlp = $this->votersObj->fetchVoterAddress($vanid);
    $voterInVoteBuilder = $this->apiVoterObj->getApiVoter($vanid);
    $addressInVoteBuilder = $voterInVoteBuilder['address'];
    
    $sameAddress = $this->votersObj->addressCompare($addressInNlp, $addressInVoteBuilder);
    
    $rid = $this->nlpConfigurationObj->getResponseId($contactMethod,'Moved');
    
    $cid = $contactMethodId;
    $altPhone = $this->phoneContact($vanid,$contactMethod,'Moved');
    if (!empty($altPhone['subContext'])) {
      $cid = $altPhone['subContext'];
      $rid = $altPhone['subResponse'];
    }
    
    $canvassDate = date(DATE_ATOM, strtotime($report['contactDate']));
    $surveyResponse['dateCanvassed'] = $canvassDate;
    if ($sameAddress) {
      //  Report the canvass response code to VoteBuilder.
      $surveyResponse['vanid'] = $vanid;
      $surveyResponse['type'] = 'contactAttempt';
      $surveyResponse['rid'] = $rid;
      $surveyResponse['ContactTypeCode'] = $cid;
      if (!empty($altPhone['phoneNo'])) {
        $surveyResponse['phoneNumber'] = $altPhone['phoneNo'];
      }
      $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
    }
    
    // Record the voter moved in NLP Services.
    $simpleAddress = $this->votersObj->addressExtract($addressInVoteBuilder);
    $addressEncode = json_encode($simpleAddress);
    $report['type'] = 'contact';
    $report['value'] = 'Moved';
    $report['text'] = $addressEncode;
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $this->reportsObj->setNlReport($report);
    
    // The voter moved from this address.
    $this->votersObj->setMovedStatus($action['turfIndex'], $vanid, TRUE);
    
    // Results reported by this NL.
    $this->nlsObj->resultsReported($report['mcid'], $report['county']);
    $this->awardsObj->nlParticipation($report['mcid'], 'Completed');
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * pledgeToVote
   *
   * Record a pledge to vote for a voter in NLP Services and VoteBuilder.
   *
   * @param array $report - The report data for the voter.
   *             'vanid' => The Voter Access Number (VANID) of the voter.
   *            'mcid' => The MyCampaign ID for the NL.
   *           'county' => The county of the voter.
   * @param array $action - The action array containing the contact method and survey response
   *            'contactMethod' => The contact method ID.
   *           'surveyResponse' => The survey response ID for the pledge to vote.
   *            'turfIndex' => The turf index for the voter.
   * @return void
   */
  public function pledgeToVote( array $report, array $action ): void
  {
    // Save the survey question response in NLP Services.
   
    $turfIndex = $action['turfIndex'];
    $vanid = $report['vanid'];
    
    $cid = $action['contactMethod'];
    $rid = $action['surveyResponse'];
    $county = $report['county'];
    $mcid = $report['mcid'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($cid);

    $pledgeQuestion = $this->nlpConfigurationObj->getPledgeSurveyQuestion();
    $qid = $pledgeQuestion['surveyQuestionId'];
    $surveyResponseList = $pledgeQuestion['responses'];
    $responseName = $surveyResponseList[$rid]['name'];

    $altPhone = $this->phoneContact($vanid, $contactMethod, $responseName);
    if (!empty($altPhone['subContext'])) {
      $cid = $altPhone['subContext'];
    }
    
    $report['value'] = str_replace(':fn', $action['firstName'], $responseName);
    $report['text'] = $pledgeQuestion['name'];
    $report['type'] = 'Survey';
    $report['qid'] = $qid;
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $rIndex = $this->reportsObj->setNlReport($report);
    
    // Report the response to VoteBuilder.
    $canvassDate = date(DATE_ATOM, strtotime($report['contactDate']));
    $surveyResponse = [
      'type' => 'pledgeToVote',
      'vanid' => $vanid,
      'qid' => $qid,
      'rid' => $rid,
      'dateCanvassed' => $canvassDate,
      'ContactTypeCode' => $cid,
      'responseName' => $responseName,
    ];
    
    if (!empty($altPhone['phoneNo'])) {
      $surveyResponse['phoneNumber'] = $altPhone['phoneNo'];
    }
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
    
    // Mark this voter pledge response.
    $turfVoter = [
      'vanid' => $vanid,
      'mcid' => $mcid,
      'county' => $county,
      'turfIndex' => $turfIndex,
      'pledgedToVote' => $rIndex,
    ];
    $this->votersObj->updateTurfVoter($turfVoter);
    
    // This NL has reported results.
    $this->nlsObj->resultsReported($mcid, $county);
    $this->awardsObj->nlParticipation($mcid, 'Completed');
  }
  
}
