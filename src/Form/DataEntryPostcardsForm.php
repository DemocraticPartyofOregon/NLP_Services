<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpDataEntryVoters;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\ApiSurveyQuestion;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DataEntryPostcardsForm extends FormBase
{
  protected DrupalUser $drupalUserObj;
  protected NlpReports $reportsObj;
  protected NlpVoters $votersObj;
  
  protected NlpNls $nlsObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpDataEntryVoters $dataEntryVotersObj;
  protected NlpConfiguration $configurationObj;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  public function __construct($drupalUserObj, $reportsObj, $votersObj, $nlsObj,
                              $sessionDataObj, $dataEntryVotersObj, $configurationObj,
                              $apiSurveyQuestionObj)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->reportsObj = $reportsObj;
    $this->votersObj = $votersObj;
    $this->nlsObj = $nlsObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->dataEntryVotersObj = $dataEntryVotersObj;
    $this->configurationObj = $configurationObj;
    $this->apiSurveyQuestionObj = $apiSurveyQuestionObj;
  }
  
  public static function create(ContainerInterface $container): DataEntryPostcardsForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.data_entry_voters'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.survey_question'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'data_entry_postcard_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $turfIndex = $this->sessionDataObj->get('turfIndex');
    $voters = $this->dataEntryVotersObj->fetchVoters($turfIndex);
    $voterList = $this->buildPostcardSelection($voters);
    $vanids = array_keys($voterList);
    
    if(empty($form_state->get('reenter'))) {
      $form_state->set('allVoters',FALSE);
      $form_state->set('rep',1);  // used to avoid the Drupal decision to
      // preserve user checkbox values for a rebuild.
      $canvassDate = date('Y-m-d',time());  // Today.
      $form_state->set('canvassDate',$canvassDate);
    }
    
    $form['postcards'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="postcard-wrapper" class="postcard-mailed-box">',
    ];
    
    $defaultDate = $form_state->get('canvassDate');
    $form['postcards']['canvassDate'] = [
      '#type' => 'date',
      '#title' => 'Date of the voter contact',
      '#default_value' => $defaultDate,
    ];
    
    $form['postcards']['AllVotersDescription'] = [
      '#markup' => '<p>Check the <b>All Voters</b> box below to select all the
voters in your turf that you have not already marked as having sent them a
postcard for this election.  It should be rare that a voter gets two postcards.
</p>',
    ];
    
    $form['postcards']['allVoters'] = [
      '#type' => 'checkbox',
      '#title' => 'All Voters',
      '#ajax' => [
        'callback' => '::postcardCallback',
        'wrapper' => 'postcard-wrapper',
      ],
      '#suffix' => '</div>',
    ];
    
    $form['postcards']['space'] = [
      '#markup' => '<p> </p>',
    ];
    
    // Identify the voters that have already been reported as having a postcard sent.
    foreach ($vanids as $vanid) {
      $postcardSend = $this->reportsObj->voterSentPostcard($vanid);
      if($postcardSend) {
        $voterList[$vanid] .= ' **';
      }
    }
    
    $allVoters = !empty($form_state->get('allVoters'));
    $rep = $form_state->get('rep');
    foreach ($vanids as $vanid) {
      $postcardSend = $this->reportsObj->voterSentPostcard($vanid);
      $default = (!$postcardSend AND $allVoters)?1:0;

      $form['postcards']['voter-'.$rep.'-'.$vanid] = [
        '#type' => 'checkbox',
        '#title' => $voterList[$vanid],
        '#default_value' => $default,
      ];
    }
    
    $form['postcards']['AllVotersFootnote'] = [
      '#markup' => '<p>** Voter has already been reported as having a postcard
sent.  There is no need to report this an additional time.</p>',
    ];
    
    $form['reportMailed'] = [
      '#type' => 'submit',
      '#value' => 'Report postcards mailed',
      '#name' => 'reportMailed',];
    $form['#attached']['library'][] = 'nlpservices/info-boxes';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/form-helper';
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $form_state->set('reenter',TRUE);
    
    $rep = $form_state->get('rep')+1;
    $form_state->set('rep',$rep);
    if(!empty($values['allVoters'])) {
      $form_state->set('allVoters', TRUE);
    } else {
      $form_state->set('allVoters', FALSE);
    }
    
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $form_state->set('reenter',TRUE);
    
    $rep = $form_state->get('rep')+1;
    $form_state->set('rep',$rep);
    $form_state->setRebuild();  // form_state will persist.
    
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    $canvassDate = $values['canvassDate'];

    // For each voter with the checkbox checked, report the postcard mailed.
    foreach ($values as $valueIndex=>$value) {
      $valueIndexParts =  explode('-',$valueIndex);
      if($valueIndexParts[0] == 'voter') {
        if(!empty($value)) {
          $vanid = $valueIndexParts[2];
          $this->reportPostcardMailed($vanid,$canvassDate,$mcid);
        }
      }
    }
    
    $form_state->set('allVoters', FALSE);
    $form_state->setUserInput(['allVoters'=>0,]);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildPostcardSelection
   *
   * Build an array of voters with text to help select the voter for a postcard.
   *
   * @param array $voters
   * @return array
   */
  public function buildPostcardSelection(array $voters): array
  {
    $voterDisplay = [];
    foreach ($voters as $voter) {
      $voterDisplay[$voter['vanid']] = $voter['firstName'] . ' ' . $voter['lastName'].' - '.
        $voter['address']['mAddress'].', '.$voter['address']['mCity'];
    }
    return $voterDisplay;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * reportPostcardMailed
   *
   * Records a postcard mailed to a voter in the NLP database, VoteBuilder, and record
   * that the NL reported results.
   *
   * @param int $vanid
   * @param string $canvassDate
   * @param int $mcid
   * @return void
   */
  public function reportPostcardMailed(array $vanid, string $canvassDate, int $mcid): void
  {
    $county = $this->sessionDataObj->getCounty();
    
    // Get the canvass response codes for the Postcard contact method.
    $canvassResponseCodes = $this->configurationObj->getCanvassResponseCodes();
    $contactMethod = 'Postcard';
    $noContactOption = 'Mailed';
    $rid = $canvassResponseCodes['Postcard']['responses']['Mailed'];
    $cid = $canvassResponseCodes['Postcard']['code'];
    $cycle = $this->configurationObj->getCycle();
    $nlsInfo = $this->nlsObj->getNlById($mcid);
    $cd = $this->votersObj->getVoterCd($vanid);
    
    // Record a voter contact in the NLP database.
    $report = [
      'vanid'=>$vanid,
      'cycle'=>$cycle,
      'cd'=>$cd,
      'county'=>$county,
      'mcid'=>$mcid,
      'nickname'=>$nlsInfo['nickname'],
      'lastName'=>$nlsInfo['lastName'],
      'contactDate'=>$canvassDate,
      'contactType'=>$contactMethod,
      'type'=>'contact',
      'value'=>$noContactOption,
      'text'=>'',
    ];
    $rIndex = $this->reportsObj->setNlReport($report);
    
    //  Report the canvass response code to VoteBuilder.
    $surveyResponse['vanid'] = $vanid;
    $surveyResponse['type'] = 'contactAttempt';
    $surveyResponse['rid'] = $rid;
    $surveyResponse['ContactTypeCode'] = $cid;
    $surveyResponse['dateCanvassed'] = $canvassDate;
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
    
    // Mark Contact attempt for this voter.
    $turfIndex = $this->sessionDataObj->get('turfIndex');
    $turfVoter = [];
    $turfVoter['vanid'] = $vanid;
    $turfVoter['mcid'] = $mcid;
    $turfVoter['county'] = $county;
    $turfVoter['turfIndex'] = $turfIndex;
    $turfVoter['attemptedContact'] = $rIndex;
    $this->votersObj->updateTurfVoter($turfVoter);
    
    // This NL has reported results.
    $this->nlsObj->resultsReported($mcid,$county);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * postcardCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function postcardCallback($form, $unused): array
  {
    return $form['postcards'];
  }
  
}