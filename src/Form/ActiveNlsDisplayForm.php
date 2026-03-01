<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpNlsListDisplay;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\ApiActivistCodes;

/**
 * @noinspection PhpUnused
 */
class ActiveNlsDisplayForm extends FormBase
{
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpNlsListDisplay $nlsListDisplayObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpAwards $awardsObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  public function __construct( $votersObj, $reportsObj, $nlsObj, $turfsObj,
                               $searchBarObj, $nlsListDisplayObj, $awardsObj, $sessionDataObj,
                               $nlpConfigurationObj, $apiSurveyQuestionObj ,$apiActivistCodesObj) {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlsListDisplayObj = $nlsListDisplayObj;
    $this->awardsObj = $awardsObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiSurveyQuestionObj = $apiSurveyQuestionObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.nls_list_display'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.survey_question'),
      $container->get('nlpservices.activist_codes'),
    );
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_active_nls_display_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if(empty($form_state->get('page'))) {
      $form_state->set('page','data_entry');
      
      $county = $this->sessionDataObj->getCounty();
      $form_state->set('county',$county);
      
      $allNlRecords = $this->nlsObj->getNls($county, 'All');
      $nlCount = count($allNlRecords);
      
      $hdOptions = $this->nlsObj->getHdList($county);
      if($nlCount<$this->nlsObj::NLS_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $form_state->set('hdOptions',$hdOptions);
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }
  
    $county = $form_state->get('county');
    if(empty($county)) {
      $this->messenger()->addWarning($this->t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    $page = $form_state->get('page');
    $form = [];
    // Select the HD to display.
    if ($page == 'data_entry') {
      $hdOptions = $this->sessionDataObj->getHdOptions();
      $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
      $currentHd = $this->sessionDataObj->getSavedHd();
    
      // Add the line for selecting an HD and CSV download.
      $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex, 'nlsForm');
      
      // Fetch the list of NL names and contact information.
      $needle = $form_state->get('nl-search-needle');
      if(!empty($needle)) {
        $form_state->set('nl-search-needle', NULL);
        $nlRecords = $this->nlsObj->searchNls($county,$needle);
      } else {
        $nlRecords = $this->nlsObj->getNls($county, $currentHd);
      }
      if(!empty($nlRecords)) {
        $nlKeys = array_keys($nlRecords);
        foreach ($nlKeys as $mcid) {
          $nlRecords[$mcid]['progress'] = $this->getProgress($mcid);
          $nlStatus = $nlRecords[$mcid]['status'];
          $statusNote = $nlStatus['notes'];
          $awardRecord = $this->awardsObj->getAward($mcid);
          $participationNote = $awardRecord['notes'];
          if($statusNote != $participationNote) {
            if(empty($participationNote)) {
              $awardRecord['notes'] = $statusNote;
              $this->awardsObj->mergeAward($awardRecord);
            } else {
              $nlStatus['notes'] = $participationNote;
              $nlRecords[$mcid]['status']['notes'] = $participationNote;
              $this->nlsObj->setNlsStatus($nlStatus);
            }
          }
          
          $contactMethod = $nlStatus['contact'];
          $preferredContactMethod = (!empty($awardRecord['preferredContactMethod']))?
                                                    $awardRecord['preferredContactMethod']:'canvass';
          if($contactMethod != $preferredContactMethod) {
            if(empty($preferredContactMethod)) {
              $awardRecord['preferredContactMethod'] = $contactMethod;
              $this->awardsObj->mergeAward($awardRecord);
            } else {
              $nlStatus['contact'] = $preferredContactMethod;
              $nlRecords[$mcid]['status']['contact'] = $preferredContactMethod;
              $this->nlsObj->setNlsStatus($nlStatus);
            }
          }
          
          $nlRecords[$mcid]['nlParticipationDisplay'] = $this->awardsObj->nlParticipationDisplay($mcid);
          $awardRecord['preferredContactMethod'] = $contactMethod;
          
        }
      }
      $nlsTable = $this->nlsListDisplayObj->createTableArray($nlRecords);
      $sort = Drupal::request()->query->get('sort', '');
      if(!empty($sort)) {
        $order = Drupal::request()->query->get('order', '');
        $this->nlsListDisplayObj->sortNlsTable($nlsTable,$sort,$order);
      }
      
      // Create the AJAX wrapper for updating the form changes.
      $form['nlsForm'] = [
        '#prefix' => '<div id="nlsFormDiv">',
        '#suffix' => '</div>',
        '#type' => 'fieldset',
        '#attributes' => ['class' => ['nlp-nls-form-wrapper']],
      ];
      
      // Build the table of NLs names and status.
      $form['nlsForm']['nls_table'] = $this->nlsListDisplayObj->buildNlsTable($nlsTable);
      
    }
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $county = $form_state->get('county');
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('$elementClicked',$elementClicked);
    switch ($elementClicked) {
      
      case 'hd-select':
        $currentHdIndex = $form_state->getValue('hd-select');
        // The user changed the HD to display.
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        break;
        
      case 'search_submit':
        break;
        
      default:
        $mcid = 0;
        $columnId = '';
        $parents = $triggeringElement['#parents'];
        if(!empty($parents) && $parents[0]=='contacts') {
          //$mcid = $parents[1];
          $columnId  = $parents[2];
        }

        $columnParts = explode('-',$columnId);
        if (count($columnParts) != 2) { break; }
        $mcid = $columnParts[1];
        $column = $columnParts[0];
        $status = $this->nlsObj->getNlsStatus($mcid,$county);

        $value = $triggeringElement['#value'];
        // Process the checkbox, select or text box for this NL.
        switch ($column) {
          case 'td':  // Turf Delivered.
            // If the turf-delivered status is set, update the date the turf was delivered.
            if($value) {
              $this->turfsObj->setAllTurfsDelivered($mcid,$county);
            }
            $cellDisplay = ($value)?'Y':'';
            $status['turfDelivered'] = $cellDisplay;
            $this->nlsObj->setNlsStatus($status);
            break;
          case 'tc':  // Turf cut.
            $cellDisplay = ($value)?'Y':'';
            $status['turfCut'] = $cellDisplay;
            $this->nlsObj->setNlsStatus($status);
            break;
          case 'notes':  // Notes (text box).
            $trunc = (strlen($value) > $this->nlsObj::NOTES_MAX) ?
              substr($value,0,$this->nlsObj::NOTES_MAX).'...' : $value;
            $safe = htmlentities($trunc, ENT_QUOTES);
            $status['notes'] = $safe;
            $this->nlsObj->setNlsStatus($status);
            
            $awardRecord = $this->awardsObj->getAward($mcid);
            $awardRecord['notes'] = $safe;
            $this->awardsObj->mergeAward($awardRecord);
            break;
          case 'co':  // Contact type (select); canvass, post card, phone.
            $contactList = $this->nlsObj->contactList;
            $status['contact'] = $contactList[$value];
            $this->nlsObj->setNlsStatus($status);
            
            $awardRecord = $this->awardsObj->getAward($mcid);
            $awardRecord['preferredContactMethod'] = $contactList[$value];
            $this->awardsObj->mergeAward($awardRecord);
            
            break;
          default:  // Ask type (select); Default(NULL), Asked, Yes, No, Quit.
            $askList = $this->nlsObj->askList;
            $nlProgress = $askList[$value];
            $this->processAsk($mcid,$nlProgress);
            break;
        }
        break;
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('submitForm',"Element clicked: $elementClicked");
    if($elementClicked=='search_submit') {
      $needle = $form_state->getValue('nl-search-needle');
      $form_state->set('nl-search-needle', $needle);
    }
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * processAsk
   *
   * @param int $mcid
   * @param string $askStatus
   * @return void
   */
  function processAsk(int $mcid, string $askStatus): void
  {
    $currentStatus = $this->nlsObj->getNlsStatus($mcid);
    $status = $currentStatus;
    $status['asked'] = $askStatus;
    $this->nlsObj->setNlsStatus($status);
    $this->awardsObj->nlParticipation($mcid,$askStatus);
    //$database = 1;
    $request = [
      'vanid' => $mcid,
      //'contactTypeCode' => $contactMethodId,
      'codeName' => 'NLPDropoutAC',
      //'action' => ($report['value'])?'Apply':'Remove',
    ];
    if ($askStatus == 'Dropout') {
      $request['action'] = 'Apply';
      $this->apiActivistCodesObj->setApiActivistCode($request);
      //$this->apiActivistCodesObj->setActivistCode($database,$mcid,'NLPDropoutAC');
    } elseif($currentStatus['asked'] == 'Dropout') {
      $request['action'] = 'Remove';
      $this->apiActivistCodesObj->setApiActivistCode($request);
    }

    $surveyResponse = [
      'type' => 'nlParticipation',
      'vanid' => $mcid,
      'responseName' => $askStatus,
      'dateCanvassed' => date(DATE_ATOM, time()),
    ];
    $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getProgress
   *
   * Create an associate array with the progress of the NL to make contact with
   * the voters on the turf, the progress on making face-to-face contact and
   * a judgment that they are done.
   *
   * @param int $mcid
   * @return array
   */
  function getProgress(int $mcid): array
  {
    $progress['attempts'] = $progress['contacts'] = $progress['voterCount'] = 0;
    // Get the voters assigned to this NL for all the turfs.
    $vanids= $this->votersObj->fetchVanIdsByNl($mcid);
    if(empty($vanids)) {return $progress;}
    
    $turfs = $this->turfsObj->getTurfsById($mcid);
    $doorCount = 0;
    foreach ($turfs as $turf) {
      $doorCount += $turf['doorCount'];
    }
    
    $voterCount = count($vanids);
    if ($voterCount == 0) {return $progress;}
    // Now get all the reports from this NL for this cycle.
    $reports = $this->reportsObj->getNlReportsForVoters($vanids);

    // Flag the voters with whom the NL asked the survey question.  (There
    // could have been more than one.)
    $voters = [];
    foreach ($reports as $voterReports) {
      foreach ($voterReports as $report) {
        $vanid = $report['vanid'];
        if(!empty($vanids[$vanid])) {
          $voters[$vanid]['attempt'] = TRUE;
          if($report['type']==$this->reportsObj::SURVEY) {
            $voters[$vanid]['contact'] = TRUE;
          }
        }
      }
    }
    //  Now count the voters with contact attempts or survey responses.
    $contactCount = $attemptCount = 0;
    if(!empty($voters)) {
      foreach ($voters as $voter) {
        if(!empty($voter['attempt'])) {
          $attemptCount++;
          if(!empty($voter['contact'])) {
            $contactCount++;
          }
        }
      }
    }
    // Return the strings for display of voter contact attempts and contacts.
    $progress['attempts'] = $attemptCount;  // Voter contact attempts.
    $progress['contacts'] = $contactCount; // Voter contacts.
    $progress['voterCount'] = $voterCount;
    $progress['doorCount'] = $doorCount;
    return $progress;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * checkboxCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function checkboxCallback($form, $unused): array
  {
    return $form['nlsForm'];
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * textboxCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function textboxCallback($form, $unused): array
  {
    return $form['nlsForm'];
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * selectboxCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function selectboxCallback($form, $unused): array
  {
    return $form['nlsForm'];
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlsFormCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function nlsFormCallback($form, $unused): array
  {
    return $form['nlsForm'];
  }
  
}
