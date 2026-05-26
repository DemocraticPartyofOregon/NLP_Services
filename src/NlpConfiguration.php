<?php

namespace Drupal\nlpservices;

use DateTime;
use Exception;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpConfiguration
{
  protected NlpEncryption $nlpEncrypt;
  protected ConfigFactoryInterface $config;
  protected ImmutableConfig $nlpConfig;
  protected ApiKeyManagement $apiKeyManagementObj;

  private array $canvassResponseCodes;

  public function __construct( $apiKeyManagementObj, $nlpEncrypt, $config ) {
    $this->nlpEncrypt = $nlpEncrypt;
    $this->config = $config;
    $this->apiKeyManagementObj = $apiKeyManagementObj;

    $this->nlpConfig = $this->config->get('nlpservices.configuration');
    $canvassResponseCodes = $this->nlpConfig->get('nlpservices_canvass_response_codes');
    $this->canvassResponseCodes = empty($canvassResponseCodes) ? [] : $canvassResponseCodes;
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.api_key_management'),
      $container->get('nlpservices.encryption'),
      $container->get('config.factory'),
    );
  }

  /**
   * getDebug
   *
   * Get the debug status to indicate debug messages should be displayed.
   *
   * @return bool
   *   The debug status indicator.
   */
  public function getDebug(): bool
  {
    $debug = $this->nlpConfig->get('nlpservices-debug');
    return empty($debug) ? FALSE : $debug;
  }

  /**
   * setDebug
   *
   * Set the debug mode for NLP Services.  Used to insert debug messages during a live election.
   *
   * @param bool $debug
   *   TRUE to enable debug mode, FALSE to disable.
   */
  public function setDebug(bool $debug): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices-debug', $debug)->save();
  }

  /**
   * @param string $key
   * @param mixed $value
   * @return void
   */
  public function set( string $key, mixed $value): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set($key, $value)->save();
  }

  /**
   * getActivistCode
   *
   * Get the Activist Code associated with the given code name.
   *
   * @param string $codeName
   * @return int
   *   Returns the NLP Voter Activist Code.
   */
  public function getActivistCode(string $codeName): int
  {
    $activistCode = $this->nlpConfig->get($codeName);
    return empty($activistCode) ? 0 : $activistCode;
  }

  /**
   * setActivistCode
   *
   * @param string $codeName
   * @param int $codeValue
   * @return void
   */
  public function setActivistCode(string $codeName, int $codeValue): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set($codeName, $codeValue)->save();
  }

  /**
   * getCycle
   *
   * Get the election cycle. This text is configured in the election setup.
   *
   * @return string
   *   Returns the election cycle.
   */
  public function getCycle(): string
  {
    $electionDates = $this->nlpConfig->get('nlpservices-election-configuration');
    return empty($electionDates['nlp_election_cycle'])?'':$electionDates['nlp_election_cycle'];
  }

  /**
   * getElectionName
   *
   * Get the election name. This text is configured in the election setup.
   *
   * @return string
   *   Returns the election name.
   */
  public function getElectionName(): string
  {
    $electionDates = $this->nlpConfig->get('nlpservices-election-configuration');
    return $electionDates['nlp_cycle_name'];
  }

  /**
   * getElectionDate
   *
   * Get the election date. This date is configured in the election setup and is saved in
   * the form: yyyy-mm-dd.
   *
   * @param bool $formatted
   *   If TRUE, return the date formatted like "Monday 1st of January 2024".
   *
   * @return string
   *   Returns the election date.
   */
  public function getElectionDate(bool $formatted = FALSE): string
  {
    $electionDates = $this->nlpConfig->get('nlpservices-election-configuration');
    $electionDate = $electionDates['nlp_election_date'];
    if($formatted) {
      $electionDate = date('F j, Y',strtotime($electionDate));
      try {
        $date = new DateTime($electionDate);
        $timestamp = $date->getTimestamp();
        $electionDate = date('l jS \of F Y',$timestamp);
      } catch (Exception $e) {
        nlp_debug_msg('getElectionDate',$e->getMessage());
        $electionDate = '';
      }
    }
    return $electionDate;
  }

  /**
   * getBallotDropDate
   *
   * Get the ballot drop date. This date is configured in the election setup and is saved in
   * the form: yyyy-mm-dd.
   *
   * @param bool $formatted
   *   If TRUE, return the date formatted like "Monday 1st of January 2024".
   *
   * @return string
   *   Returns the ballot drop date.
   */
  public function getBallotDropDate(bool $formatted = FALSE): string
  {
    $electionDates = $this->nlpConfig->get('nlpservices-election-configuration');
    $ballotDropDate = $electionDates['nlp_ballot_drop_date'];
    if($formatted) {
      $electionDate = date('F j, Y',strtotime($ballotDropDate));
      try {
        $date = new DateTime($electionDate);
        $timestamp = $date->getTimestamp();
        $ballotDropDate = date('l jS \of F Y',$timestamp);
      } catch (Exception $e) {
        nlp_debug_msg('getBallotDropDate',$e->getMessage());
        $ballotDropDate = '';
      }
    }
    return $ballotDropDate;
  }

  /**
   * getStateName
   *
   * Get the name of the state for this instance of NLP Services. This text is
   * configured in the election setup.
   *
   * @return string
   *   Returns the state name.
   */
  public function getStateName(): string
  {
    $committeeNames = $this->nlpConfig->get('nlpservices-county-names');
    return (!empty($committeeNames['State']))?$committeeNames['State']:'';
  }

  public function setStateName(string $stateName): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices-state-name', $stateName)->save();
  }

  /**
   * getVoterBuilderUpdateDate
   *
   * Get the most recent date of the VoterFile.  This date is
   * set by the NLP Admin whenever the state party updates the VoterFile for new
   * voter registrations.  It indicates that existing turfs may be out of date and
   * a resyncing is needed.
   *
   * @return string
   *   Returns the VoteBuilder update date.
   */
  public function getVoterBuilderUpdateDate(): string
  {
    return empty($this->nlpConfig->get('nlpservices-votebuilder-update-date'))?'':
        $this->nlpConfig->get('nlpservices-votebuilder-update-date');
  }

  /**
   * setVotebuilderUpdateDate
   *
   * @param string $date
   * @return void
   */
  public function setVotebuilderUpdateDate(string $date): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices-votebuilder-update-date', $date)->save();
  }

  /**
   * getCountyNames
   *
   * Get the names of the counties in the state. This text is configured in the election setup.
   *
   * @return array
   *   Returns an array of county names.
   */
  public function getCountyNames(): array
  {
    $counties = $this->nlpConfig->get('nlpservices-county-names');
    $counties = (!empty($counties)) ? $counties : [];
    return array_keys($counties);
  }

  /**
   * getHDNames
   *
   * Get the list of house districts in the requested county. This text is
   * configured in the election setup.  The house districts are assumed to be
   * numbers, and the name and index are the same.
   *
   * @param string $committee
   *   The name of the county to get.
   * @param bool $all
   *  If TRUE, include an "All" option in the list of house districts.
   *
   * @return array
   *   Returns an array of house districts in the county.
   */
  public function getHDNames(string $committee, bool $all = TRUE): array {
    $counties = $this->nlpConfig->get('nlpservices-county-names');
    if (empty($counties[$committee])) { return []; }
    $hdNames = ($all) ? ['All' => 'All'] : [];
    foreach ($counties[$committee] as $hd) {
      $hdNames[$hd] = $hd;
    }
    return $hdNames;
  }

  /**
   * getPledgeSurveyQuestion
   *
   * Get the pledge-to-vote survey question. This is configured in the election setup.
   *
   * The survey question provides the following keys:
   * - surveyQuestionId (int) - The ID of the survey question.
   * - scriptQuestion (string) - The text of the survey question.
   * - responses (array) - An indexed array of responses to the survey question.
   *    The response array contains:
   *      The index is the surveyResponseId, and the value is an array.
   *      - surveyResponseId (int) - The ID of the response.
   *      - name (string) - The text of the response.
   *
   * @return array
   *   Returns the pledge to vote survey question.
   */
  public function getPledgeSurveyQuestion(): array
  {
    $pledgeToVote = $this->nlpConfig->get('nlpservices_pledge_to_vote');
    return empty($pledgeToVote) ? [] : $pledgeToVote;
  }

  /**
   * getNlParticipationQuestion
   *
   * Get the NL Participation survey question. This text is configured in the election setup.
   *
   * The survey question provides the following keys:
   * - surveyQuestionId (int) - The ID of the survey question.
   * - scriptQuestion (string) - The text of the survey question.
   * - responses (array) - An indexed array of responses to the survey question.
   *    The response array contains:
   *      The index is the surveyResponseId, and the value is an array.
   *      - surveyResponseId (int) - The ID of the response.
   *      - name (string) - The text of the response.
   *
   * @return array
   *   Returns the NL Participation survey question.
   */
  public function getNlParticipationQuestion(): array
  {
    $nlParticipation = $this->nlpConfig->get('nlpservices_nl_participation');
    return empty($nlParticipation) ? [] : $nlParticipation;
  }

  /**
   * getCanvassResponseCodes
   *
   * Get the canvass response codes.  These are the responses used by the canvassers
   * to record the results of their canvassing.
   *
   * @return array
   *   Returns an array of canvass response codes.
   */
  public function getCanvassResponseCodes(): array
  {
    return $this->canvassResponseCodes;
  }

  /**
   * setCanvassResponseCodes
   *
   * Set the canvass response codes.  These are the responses used by the canvassers
   * to record the results of their canvassing.
   *
   * @param array $responseCodes
   * @return void
   */
  public function setCanvassResponseCodes(array $responseCodes): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices_canvass_response_codes', $responseCodes)->save();
    $this->canvassResponseCodes = $responseCodes;
  }

  /**
   * getResponseId
   *
   * Get the response ID for a given method and response name.
   *
   * @param string $methodName
   *   The name of the method (e.g., 'Walk').
   * @param string $responseName
   *   The name of the response (e.g., 'Left Message/Lit').
   *
   * @return int
   *   Returns the response ID, or 0 if not found.
   */
  public function getResponseId(string $methodName, string $responseName): int
  {
    return !empty($this->canvassResponseCodes[$methodName]['responses'][$responseName])
      ? $this->canvassResponseCodes[$methodName]['responses'][$responseName] : 0;
  }

  /**
   * getContactResponses
   *
   * Get the contact responses for a given method.  The method is an array with a 'name' key.
   * The responses are the responses used by the canvassers to record the results of their canvassing.
   *
   * @param array $method
   *   An array containing the 'name' of the method.
   *
   * @return array
   *   Returns an array of contact responses for the specified method.
   */
  public function getContactResponses(array $method): array {
    if (empty($this->canvassResponseCodes[$method['name']])) { return []; }
    return $this->canvassResponseCodes[$method['name']]['responses'];
  }

  /**
   * getContactResponse
   *
   * Get the contact response name for a given method and response ID.
   *
   * @param string $method
   *   The name of the method (e.g., 'Walk').
   * @param int $responseId
   *   The ID of the response.
   *
   * @return string
   *   Returns the contact response name or an empty string if not found.
   */
  public function getContactResponse(string $method, int $responseId): string {
    $responses = $this->getContactResponses(['name' => $method]);
    foreach ($responses as $responseName => $response) {
      if ($response == $responseId) {
        return $responseName;
      }
    }
    return '';
  }

  /**
   * getContactId
   *
   * Get the contact ID for a given contact method name.
   *
   * @param string $contactMethodName
   *   The name of the contact method (e.g., 'Walk').
   *
   * @return int
   *   Returns the contact ID, or 0 if not found.
   */
  public function getContactId(string $contactMethodName): int
  {
    return $this->canvassResponseCodes[$contactMethodName]['code'];
  }

  /**
   * getContactMethods
   *
   * Get the contact methods.  The contact methods are the methods used by the canvassers
   * to record the results of their canvassing.
   * The contact methods are returned as an array with the contactMethodId as the key
   * and the contactMethodName as the value.
   *
   * @return array
   *   Returns an array of contact methods with their codes.
   */
  public function getContactMethods(): array
  {
    $contactMethods = [];
    foreach ($this->canvassResponseCodes as $method => $methodData) {
      $contactMethods[$methodData['code']] = $method;
    }
    return $contactMethods;
  }

  /**
   * getContactMethod
   *
   * Get the contact method name for a given contact ID.
   *
   * @param int $contactId
   *   The ID of the contact method.
   *
   * @return string
   *   Returns the contact method name or an empty string if not found.
   */
  public function getContactMethod(int $contactId): string
  {
    $contactMethods = $this->getContactMethods();
    return !empty($contactMethods[$contactId]) ? $contactMethods[$contactId] : '';
  }

  /**
   * getAlternatePhoneReportCodes
   *
   * Get the alternate phone report codes. These are the codes used to report
   * alternate phone numbers for voters.
   *
   * @return array
   *   Returns an array of alternate phone report codes.
   */
  public function getAlternatePhoneReportCodes(): array
  {
    return $this->nlpConfig->get('nlpservices_alt_phone_response_codes');
  }

  /**
   * setAltPhoneResponseCodes
   *
   * @param array $responseCodes
   * @return void
   */
  public function setAltPhoneResponseCodes(array $responseCodes): void
  {
    $iConfig = $this->config->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices_alt_phone_response_codes', $responseCodes)->save();
  }

  /**
   * getCrosstabCreateTime
   *
   * Get the time the crosstab information uploaded to NLP was created. This is the time
   * when the crosstab was last created.
   *
   * @return int
   *   Returns the crosstab create time in ISO format.
   */
  public function getCrosstabCreateTime(): int
  {
    return empty($this->nlpConfig->get('nlpservices-crosstab-create-time'))?0
        :$this->nlpConfig->get('nlpservices-crosstab-create-time');
  }

  /**
   * getNotificationEmail
   *
   * Get the email address to send notifications to. This is used to send notifications
   * about important events, such as when a turf is created or updated.
   *
   * @return string
   *   Returns the notification email address.
   */
  public function getNotificationEmail(): string
  {
    $emailConfiguration = $this->nlpConfig->get('nlpservices-email-configuration');
    return $emailConfiguration['notifications']['email'];
  }

  /**
   * getEmailConfiguration
   *
   * Get the email configuration for a given type.
   *
   * @param string $type
   *   The type of email configuration to get.
   *
   * @return array
   *   Returns the email configuration for the given type.
   */
  public function getEmailConfiguration(string $type): array
  {
    $emailConfiguration = $this->nlpConfig->get('nlpservices-email-configuration');
    return $emailConfiguration[$type];
  }

}
