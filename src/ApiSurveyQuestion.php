<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use stdClass;

class ApiSurveyQuestion
{
  const string SURVEY_RESPONSE = 'SurveyResponse';
  const string ACTIVIST_CODE = 'ActivistCode';
  const int CONTACT_TYPE_WALK = 2;
  
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;
  public function __construct($client, $nlpConfigurationObj, $apiKeyManagementObj) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiSurveyQuestion
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }
  
  /**
   * getApiSurveyQuestions
   *
   * Get the survey questions from the API that are associated with the specified cycle
   * and the specified database.
   *
   * Returns an array of survey questions indexed by the survey question ID. Each question
   * is an array with the following keys:
   * - cycle - the cycle year
   * - responses - an array of responses indexed by the response ID. Each response is an array
   *   with the following keys:
   *   - surveyResponseId - the response ID
   *   - name - the response name
   *   - description - the response description
   *   - type - the response type
   *   - dateCanvassed - the date the response was canvassed
   *   - contactTypeId - the contact type ID
   *   - inputTypeId - the input type ID
   * - type - the question type
   * - question - the question text
   * - description - the question description
   * - dateCreated - the date the question was created
   *
   * @param string $cycleYear - Four digit year, eg 2024
   * @param int $database - 0 for VoterFile, 1 for MyCampaign.
   * @return array
   * @noinspection GrazieStyle
   */
  public function getApiSurveyQuestions(string $cycleYear, int $database=0): array
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues('');
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
   
    $url = "https://$user:$apiKey|$database@$apiURL/surveyQuestions";
    try {
      $request = $this->client->request('GET', $url);
      $result = json_decode($request->getBody(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
      }
    } catch (GuzzleException $e) {
      nlp_debug_msg('getApiSurveyQuestions', $e);
      return [];
    } catch (Exception $e) {
      nlp_debug_msg('getApiSurveyQuestions', $e);
      return [];
    }
    $questionsArray = [];
    foreach ($result['items'] ?? [] as $question) {
      $questionCycle = $question['cycle'];
      $responses = [];
      if($questionCycle==$cycleYear) {
        foreach ($question['responses'] as $response) {
          $responses[$response['surveyResponseId']] = $response;
        }
        $question['responses'] = $responses;
        $questionsArray[$question['surveyQuestionId']] = $question;
      }
    }
    return $questionsArray;
  }
  
  /**
   * reportSurveyResponse
   *
   * Report the survey response to VoteBuilder.
   *
   * The surveyResponse array contains the following keys:
   * - type - the response type: 'nlParticipation', 'pledgeToVote', or 'contactAttempt'
   * - vanid - the Voter's ID
   * - dateCanvassed - the date the response was canvassed
   * - responseName - the response name
   * - ContactTypeCode - the contact type code
   * - rid - the response ID
   * - phoneNumber - the phone number
   * - phoneId - the phone ID
   *
   * @param array $surveyResponse
   * @param string $committeeName - The name of the county or if empty the state committee is used.
   *
   * @return void
   * @noinspection GrazieStyle
   */
  public function reportSurveyResponse(array $surveyResponse, string $committeeName=''): void
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committeeName);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $responseObj = new stdClass();
    $canvassContextObj = new stdClass();
    $canvassResponseObj = new stdClass();
    
    $canvassResponseCodes = $this->nlpConfigurationObj->getCanvassResponseCodes();
    $qid = $cid = $rid = 0;
    $type = '';
    $database = 0; // VoterFile.
    switch ($surveyResponse['type']) {
      
      // The participation survey question records the activity of an NL for
      // the current election.  The question resides in the state committee's
      // MyCampaign database.
      case 'nlParticipation':
        $nlParticipationQuestion = $this->nlpConfigurationObj->getNlParticipationQuestion();
        if (empty($nlParticipationQuestion)) {
          nlp_debug_msg('reportSurveyResponse', 'NL participation survey question is not configured.');
          return;
        }
        $qid = (int) $nlParticipationQuestion['surveyQuestionId'];
        $surveyResponseList = $nlParticipationQuestion['responses'] ?? [];
        $cid = $canvassResponseCodes['Walk']['code'];

        foreach ($surveyResponseList as $surveyResponseItem) {
          if($surveyResponse['responseName'] == $surveyResponseItem['name']) {
            $rid = $surveyResponseItem['surveyResponseId'];
            break;
          }
        }
        if(empty($rid)) {
          nlp_debug_msg('reportSurveyResponse',
            'No survey response ID matched "' . $surveyResponse['responseName']
            . '". Check the NL participation survey question configuration.');
          return;
        }
        $database = 1; // MyCampaign
        $type = self::SURVEY_RESPONSE;
        break;
        
      // The pledge to vote survey question records the response of a voter
      // to the question "Can we count on you to vote?"  The question resides in
      // the state committee's VoterFile database.
      case 'pledgeToVote':
        $type = self::SURVEY_RESPONSE;
        $pledgeToVoteQuestion = $this->nlpConfigurationObj->getPledgeSurveyQuestion();
        $qid = (int) $pledgeToVoteQuestion['surveyQuestionId'];
        $surveyResponseList = $pledgeToVoteQuestion['responses'];
        foreach ($surveyResponseList as $surveyResponseItem) {
          if($surveyResponse['responseName'] == $surveyResponseItem['name']) {
            $rid = $surveyResponseItem['surveyResponseId'];
            break;
          }
        }
        if(empty($rid)) {return;}
        break;
      
      // The contact attempt records a canvass survey response that is not
      // an answer to a survey question, e.g. "not home".
      case 'contactAttempt':
        $cid = $surveyResponse['ContactTypeCode'];
        $rid = $surveyResponse['rid'];
        break;
    }

    if(empty($cid)) {
      $cid = $canvassResponseCodes['Walk']['code'];
    }

    // If the type is set, then we have a response to a survey question to record.
    if(!empty($type)) {
      $responseObj->type = $type;
      $responseObj->surveyQuestionId = $qid;
      $responseObj->surveyResponseId = $rid;

      $surveyResponse = $this->getArr($surveyResponse, $canvassContextObj, $cid, $canvassResponseObj);
      $canvassResponseObj->resultCodeId = NULL;
      $canvassResponseObj->responses = [ $responseObj,];
    
    } else {
      
      // Report a voter contact attempt.
      $surveyResponse = $this->getArr($surveyResponse, $canvassContextObj, $cid, $canvassResponseObj);
      $canvassResponseObj->resultCodeId = $rid;
      $canvassResponseObj->responses = NULL;
      
    }
    
    // Issue the POST
    $vanid = $surveyResponse['vanid'];
    $postUrl = "https://$user:$apiKey|$database@$apiURL/people/$vanid/canvassResponses";
    
    $dataJson = json_encode( $canvassResponseObj );
    $headers = [
      'Content-Type' => 'application/json',
      'Accept'       => 'application/json',
      'Expect'       => '',
    ];
    try {
      $this->client->request('POST', $postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (GuzzleException $e) {
      nlp_debug_msg('reportSurveyResponse', $e);
      // The URL carries the API key, so log it without the credentials.
      nlp_debug_msg('reportSurveyResponse', "postUrl: https://$apiURL/people/$vanid/canvassResponses (database $database)");
      nlp_debug_msg('reportSurveyResponse', "dataJson: $dataJson");
      nlp_debug_msg('$surveyResponse', $surveyResponse);
    } catch (Exception $e) {
      nlp_debug_msg('reportSurveyResponse', $e);
    }

  }

  /**
   * @param array $surveyResponse
   * @param stdClass $canvassContextObj
   * @param mixed $cid
   * @param stdClass $canvassResponseObj
   * @return array
   */
  private function getArr(array $surveyResponse, stdClass $canvassContextObj, mixed $cid, stdClass $canvassResponseObj): array
  {
    $canvassContextObj->dateCanvassed = $surveyResponse['dateCanvassed'];
    $canvassContextObj->contactTypeId = $cid;
    $canvassContextObj->inputTypeId = 11;
    if (!empty($surveyResponse['phoneId'])) {
      $canvassContextObj->phoneId = $surveyResponse['phoneId'];
    }
    if (!empty($surveyResponse['phoneNumber'])) {
      $canvassContextObj->phone = ["phoneNumber" => $surveyResponse['phoneNumber'],];
    }

    $canvassResponseObj->canvassContext = $canvassContextObj;
    return $surveyResponse;
  }

}
