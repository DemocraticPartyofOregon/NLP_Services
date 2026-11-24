<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryReports
{
  // What became of a report the data entry page asked to save.
  public const string SAVED = 'saved';
  public const string DUPLICATE = 'duplicate';
  public const string NOT_SAVED = 'notSaved';
  // A voter gets one postcard a cycle, so a postcard already reported for
  // the voter this cycle, by anyone on any date, isn't reported again.
  public const string ALREADY_MAILED = 'alreadyMailed';

  // The longest phone number or email the data entry page takes.
  public const int MAX_CONTACT_TEXT = 128;
  // A phone number the data entry page accepts.
  public const string PHONE_PATTERN = '/^\+?(\d{1,4})?[-.\s]?(\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4})$/';

  protected NlpReports $reportsObj;
  protected NlpNls $nlsObj;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  protected NlpVoters $votersObj;
  protected ApiVoter $apiVoterObj;
  protected NlpAwards $awardsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  
  public function __construct(
    NlpReports $reportsObj,
    NlpNls $nlsObj,
    ApiSurveyQuestion $apiSurveyQuestionObj,
    NlpVoters $votersObj,
    ApiVoter $apiVoterObj,
    NlpAwards $awardsObj,
    NlpConfiguration $nlpConfigurationObj,
    ApiActivistCodes $apiActivistCodesObj,
  )
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
   *              'county' => The committee of the NL who reported it.
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
   *              'county' => The committee of the NL who reported it.
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
    $vanMethod = ($contactMethod=='Social Media') ? 'Phone' : $contactMethod;
    $cid = ($vanMethod != $contactMethod)?
      $this->nlpConfigurationObj->getContactId('Phone') : $contactMethodId;
    $rid = $this->nlpConfigurationObj->getResponseId($vanMethod,'Deceased');

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
    // VoteBuilder needs a phone number for a phone or text contact; without
    // one, use the configured substitute contact method.
    $surveyResponse = $this->addPhoneContact($surveyResponse, $vanMethod, 'Deceased');
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
   *             'county' => The committee of the NL who reported it.
   *             'contactDate' => The date of the voter contact.
   *
   * @param array $action - The action array containing the contact method and attempt result.
   *             'contactMethod' => The contact method ID.
   *             'notContacted' => The response ID for not contacted.
   * @return string - SAVED; DUPLICATE if the NL already reported this same
   *             attempt for this date (nothing is saved again);
   *             ALREADY_MAILED for a postcard when one was already reported
   *             for the voter this cycle; NOT_SAVED if the response isn't one
   *             of the method's responses.
   */
  public function noContactResponse( array $report, array $action): string
  {
    $contactMethodId = $action['contactMethod'];
    $contactMethod = $this->nlpConfigurationObj->getContactMethod($contactMethodId);
    $responseId = $action['notContacted'];

    // A response that isn't one of this method's responses has no name, and
    // its report would show as an attempt with an empty result.  Save nothing.
    $responseName = $this->nlpConfigurationObj->getContactResponse($contactMethod, $responseId);
    if (empty($responseName)) {
      return self::NOT_SAVED;
    }
    if ($contactMethod === 'Postcard' && $this->reportsObj->voterSentPostcard((int) $report['vanid'])) {
      return self::ALREADY_MAILED;
    }

    // Save the contact attempt in NLP Services.
    $report['type'] = 'contact';
    $report['text'] = '';
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['contactType'] = $contactMethod;
    $report['value'] = $responseName;
    if ($this->reportsObj->isDuplicateReport($report)) {
      return self::DUPLICATE;
    }
    $rIndex = $this->reportsObj->setNlReport($report);

    //  Report the canvass response code to VoteBuilder.
    $surveyResponse['vanid'] = $report['vanid'];
    $surveyResponse['type'] = 'contactAttempt';
    $surveyResponse['rid'] = $responseId;
    $surveyResponse['ContactTypeCode'] = $contactMethodId;
    $surveyResponse = $this->addPhoneContact($surveyResponse, $contactMethod, $responseName);
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
    return self::SAVED;
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
   * addPhoneContact
   *
   * Add the voter's phone number to a phone or text contact attempt, or switch
   * it to the substitute contact method when the voter has no usable phone.
   * See phoneContact.
   *
   * @param array $surveyResponse - The canvass response, with 'vanid', 'rid' and 'ContactTypeCode'.
   * @param string $contactMethod - The contact method name.
   * @param string $responseName - The response name, e.g. 'Deceased'.
   * @return array - The canvass response with the phone or substitute added.
   */
  private function addPhoneContact( array $surveyResponse, string $contactMethod, string $responseName): array
  {
    $altPhone = $this->phoneContact($surveyResponse['vanid'], $contactMethod, $responseName);
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
    return $surveyResponse;
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
    $note = $this->cleanNote($action['note']);
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

  /**
   * cleanNote
   *
   * A note as it's saved: no HTML, at most MAX_COMMENT characters, and line
   * breaks as <br>.
   *
   * @param string $note
   * @return string
   */
  private function cleanNote(string $note): string
  {
    $noteString = trim(htmlentities(strip_tags($note), ENT_QUOTES));
    $commentMax = $this->reportsObj::MAX_COMMENT;
    if (strlen($noteString) > $commentMax) {
      $noteString = substr($noteString, 0, $commentMax);  // Truncate the comment.
    }
    return str_replace(["\r\n", "\n", "\r"], "<br>", $noteString);
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * reportTextProblem
   *
   * Why the corrected text of a report can't be saved, or '' if it can.  A
   * new phone number must be a valid phone number and a new email a valid
   * email, as when they were first reported.
   *
   * @param array $report - the report's row from the results table.
   * @param string $text - the corrected text.
   * @return string
   */
  public function reportTextProblem(array $report, string $text): string
  {
    $text = $this->cleanContactText($text);
    return match ($this->reportsObj->typedChange($report)) {
      'cellPhone', 'homePhone' => preg_match(self::PHONE_PATTERN, $text)
        ? '' : 'Enter a valid phone number.  The report was not changed.',
      'email' => filter_var($text, FILTER_VALIDATE_EMAIL)
        ? '' : 'Enter a valid email address.  The report was not changed.',
      default => '',
    };
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * editReportText
   *
   * Correct the text of a note, or of a new phone number or email, from the
   * data entry page's Edit list.  A note is cleaned the same way as one typed
   * in the note box.  A new phone number was also saved as the voter's phone,
   * so the voter's phone is corrected too while it's still that number.  Check
   * reportTextProblem() first.
   *
   * @param array $report - the report's row from the results table.
   * @param string $text - the corrected text.
   * @return void
   */
  public function editReportText(array $report, string $text): void
  {
    $isNote = strtolower((string)($report['type'] ?? '')) === 'comment';
    $newText = $isNote ? $this->cleanNote($text) : $this->cleanContactText($text);
    $this->reportsObj->updateComment([
      'reportIndex' => $report['reportIndex'],
      'text' => $newText,
    ]);

    $phoneType = $this->reportsObj->typedChange($report);
    if ($phoneType !== 'cellPhone' && $phoneType !== 'homePhone') {
      return;
    }
    $vanid = (int) $report['vanid'];
    $voter = $this->votersObj->getVoterById($vanid);
    $digits = static fn($phone): string => (string) preg_replace('/\D/', '', (string) $phone);
    if (!empty($voter) && $digits($voter[$phoneType] ?? '') === $digits($report['text'] ?? '')) {
      $this->votersObj->updateVoterPhone($vanid, [$phoneType => $newText]);
    }
  }

  /**
   * A typed phone number or email as it's saved: no HTML, on one line.
   */
  private function cleanContactText(string $text): string
  {
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    return mb_substr($text, 0, self::MAX_CONTACT_TEXT);
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * deleteVoterReport
   *
   * Delete a report from the data entry page's Edit list.  The voter's turf
   * record points at the voter's latest pledge and latest contact attempt;
   * when one of those is deleted, it points at the next latest instead (or
   * none), so the contact status stays right.  Deleting the voter's last
   * Moved report clears the moved flag.  Only NLP Services' copy is deleted;
   * what was sent to VoteBuilder stays as it was.
   *
   * @param array $report - the report's row from the results table.
   * @return void
   */
  public function deleteVoterReport(array $report): void
  {
    $reportIndex = (int) $report['reportIndex'];
    $vanid = (int) $report['vanid'];
    $this->reportsObj->deleteReport($reportIndex);

    $turfVoter = $this->votersObj->getTurfVoter($vanid);
    if (empty($turfVoter)) {
      return;
    }
    $fields = [];
    if ((int)($turfVoter['pledgedToVote'] ?? 0) === $reportIndex) {
      $fields['pledgedToVote'] = $this->newestReportIndex($vanid, NlpReports::SURVEY);
    }
    if ((int)($turfVoter['attemptedContact'] ?? 0) === $reportIndex) {
      $fields['attemptedContact'] = $this->newestReportIndex($vanid, NlpReports::CONTACT);
    }
    if (!empty($fields)) {
      $this->votersObj->updateTurfVoter(
        ['vanid' => $vanid, 'turfIndex' => $turfVoter['turfIndex']] + $fields);
    }

    $isMoved = strtolower((string)($report['type'] ?? '')) === NlpReports::CONTACT
      && strtolower((string)($report['value'] ?? '')) === 'moved';
    if ($isMoved) {
      $movedReport = $this->reportsObj->getReport(
        ['vanid' => $vanid, 'type' => NlpReports::CONTACT, 'value' => 'moved', 'cycle' => NULL]);
      if (empty($movedReport)) {
        $this->votersObj->setMovedStatus((int) $turfVoter['turfIndex'], $vanid, FALSE);
      }
    }
  }

  /**
   * The index of the voter's newest report of this type this cycle, or 0.
   */
  private function newestReportIndex(int $vanid, string $type): int
  {
    $newest = 0;
    foreach ($this->reportsObj->getNlpTypeReports($vanid, $type) as $report) {
      $newest = max($newest, (int) $report['reportIndex']);
    }
    return $newest;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * voterMoved
   *
   * @param array $report - The report data for the voter.
   *             'vanid' => The Voter Access Number (VANID) of the voter.
   *            'mcid' => The MyCampaign ID for the NL.
   *            'county' => The committee of the NL who reported it.
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
   *           'county' => The committee of the NL who reported it.
   * @param array $action - The action array containing the contact method and survey response
   *            'contactMethod' => The contact method ID.
   *           'surveyResponse' => The survey response ID for the pledge to vote.
   *            'turfIndex' => The turf index for the voter.
   * @return string - SAVED, or DUPLICATE if the NL already reported this same
   *            response for this date (nothing is saved again).
   */
  public function pledgeToVote( array $report, array $action ): string
  {
    // Save the survey question response in NLP Services.
   
    $turfIndex = $action['turfIndex'];
    $vanid = $report['vanid'];
    
    $cid = $action['contactMethod'];
    $rid = $action['surveyResponse'];
    $committee = $report['county'];
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
    if ($this->reportsObj->isDuplicateReport($report)) {
      return self::DUPLICATE;
    }
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
      'county' => $committee,
      'turfIndex' => $turfIndex,
      'pledgedToVote' => $rIndex,
    ];
    $this->votersObj->updateTurfVoter($turfVoter);
    
    // This NL has reported results.
    $this->nlsObj->resultsReported($mcid, $committee);
    $this->awardsObj->nlParticipation($mcid, 'Completed');
    return self::SAVED;
  }
  
}
