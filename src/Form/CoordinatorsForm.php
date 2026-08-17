<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpSessionData;

/**
 * @noinspection PhpUnused
 */
class CoordinatorsForm extends FormBase
{
  
  protected NlpCoordinators $coordinatorsObj;
  protected NlpNls $nlsObj;
  protected DrupalUser $drupalUserObj;
  protected MagicWord $magicWordObj;
  protected NlpCoordinatorsListDisplay $coordinatorsListDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpSearchBar $searchBarObj;

  private array $scopeOptions = [
    'choose'=>'Select scope',
    'none' => 'None',
    'county'=>'County',
    'hd'=>'House District',
    'precinct'=>'Group of Precincts',
  ];
  
  public function __construct( $coordinatorsObj,$nlsObj,$drupalUserObj,$magicWordObj,
                               $coordinatorsListDisplayObj, $sessionDataObj, $searchBarObj )
  {
    $this->coordinatorsObj = $coordinatorsObj;
    $this->nlsObj = $nlsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->magicWordObj = $magicWordObj;
    $this->coordinatorsListDisplayObj = $coordinatorsListDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->searchBarObj = $searchBarObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): CoordinatorsForm
  {
    return new static(
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.coordinators_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.search_bar'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_coordinators_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if(empty($form_state->get('reenter'))) {
      $form_state->set('reenter', TRUE);
      $form_state->set('scope','unknown');
      $county = $this->sessionDataObj->getCounty();
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $form_state->set('county',$county);
      
      $allNlRecords = $this->nlsObj->getNls($county, 'All');
      $nlCount = count($allNlRecords);
      
      $hdOptions = $this->nlsObj->getHdList($county);
      if($nlCount<$this->nlsObj::NLS_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }
    
    $county = $form_state->get('county');
    
    $sortRequest  = [];
    $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
    if(!empty($sort)) {
      $order = $_GET['order'];
      $sortRequest = ['sort'=>$sort,'order'=>$order];
    }
    
    $form['help'] = [
      '#type' => 'markup',
      '#markup' => t('<p>The NLP Services module requires that authenticated users provide credentials to access various
functions.  In addition, the NLP Services module uses roles to reveal functions that are appropriate to each role.   One such
role is "NLP Coordinator" which reveals functions needed to manage NLs. There can be any number of people with the
coordinator role for the county. </p>'),
      '#prefix' => '<div class="coordinator-box" >',
    ];
    $form['help1'] = [
      '#type' => 'markup',
      '#markup' => t("<p>One or more coordinators much be identified as the primary contact for
help for the NL.  The primary contact information is used to alter emails and help pages so the NL knows who to
contact first.  In addition, the primary contact information alters the reply-to field in the emails sent by NLP
Services.   This change to the reply-to field redirects any reply from the NL using the NL's email service to the primary
coordinator and not to the NLP Site admin.</p>"),
      '#suffix' => '</div>',
    ];
    
    // Get all the coordinators assigned for this county.
    $assignedCoordinators = $this->coordinatorsObj->getCoordinators($county);
    foreach ($assignedCoordinators as $cIndex=>$assignedCoordinator) {
      $mcid = $assignedCoordinator['mcid'];
      $assignedCoordinators[$cIndex]['roles'] = [];
      $assignedCoordinators[$cIndex]['uid'] = '';
      $coordinator = $this->drupalUserObj->getUserByMcid($mcid);
      if(!empty($coordinator)) {
        $assignedCoordinators[$cIndex]['roles'] = $coordinator['roles'];
        $assignedCoordinators[$cIndex]['uid'] = $coordinator['uid'];
      }
    }
    
    $form_state->set('assignedCoordinators',$assignedCoordinators);
    // Get the Drupal users with the coordinator role for this county.
    $form['manageCoordinators'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="coordinator-div">',
      '#suffix' => '</div>',
    ];
    
    $this->sessionDataObj->getSavedHdIndex();
    $currentHd = $this->sessionDataObj->getSavedHd();
    $countyNLs = $this->nlsObj->getCountyNls($county,$currentHd);
    
    $availableNLs = $countyNLs;
    $unassignedCoordinators = $countyNLs;
    $form_state->set('unassignedCoordinators',$unassignedCoordinators);
    
    /* - - - assign a coordinator  - - - */
    
    // Search the list of available NLs for a match with the needle.
    $needle = $form_state->get('search-needle');
    //nlp_debug_msg('needle',$needle);
    if(!empty($needle)) {
      $allCountyNls = $this->nlsObj->getCountyNls($county);
      $availableNLs = $this->searchBarObj->searchInfoArray($needle,$allCountyNls);
    }
    
    $scope = $form_state->get('scope');
    
    $form['manageCoordinators']['assignmentRemoval'] = $this->buildCoordinatorAssignment($assignedCoordinators,$sortRequest);
    $form['manageCoordinators']['coordinatorSelect'] = $this->buildCoordinatorSelect($scope,$availableNLs,$sortRequest);
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
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('elementClicked',$elementClicked);
    $county = $form_state->get('county');
    switch ($elementClicked) {
      
      case 'hd-select-add':
        // The user changed the HD to display.
        $currentHdIndex = $form_state->getValue($elementClicked);
        //nlp_debug_msg('currentHdIndex',$currentHdIndex);;
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        break;
      
      case 'add_coordinator_assignment':
        $contacts = $form_state->getValue('contacts');
        $selections = $this->coordinatorsListDisplayObj->validateCoordinatorSelection($contacts);
        if(empty($selections) OR count($selections)!=1) {
          $form_state->setErrorByName('add_coordinator_assignment',
            "You must select one and only one volunteer from the list.");
          return;
        }
        
        $form_state->set('addCoordinators',$selections);
        
        $allCountyCoordinators = $this->coordinatorsObj->getAllCoordinators($county);
        $countyCoordinators = (empty($allCountyCoordinators[$county]))?[]:$allCountyCoordinators[$county];
        $scope = $form_state->get('scope');
        //nlp_debug_msg('scope',$scope);
        $assignmentRole = [
          'county' => $county,
          'scope' => $scope,
          'hd' => NULL,
          'precincts' => [],
        ];
        
        switch ($scope) {
          case 'unknown':
            $form_state->setErrorByName('scope',t('You must select a scope.'));
            break;
          case 'precinct':
            if(empty($form_state->getValue('pct-list'))) {
              $form_state->setErrorByName('pct-list',t('You must name at least one precinct.'));
            }
            $hdSelect = $form_state->getValue('hd-assigned');
            if(empty($hdSelect)) {
              $form_state->setErrorByName('hd-assigned',t('You must select an HD to be managed.'));
              break;
            }
            $hdList = $this->nlsObj->getHdList($county);
            $hdOptions = array_combine($hdList, $hdList);
            array_unshift($hdOptions , 'Select an HD');
            $hd = $hdOptions[$hdSelect];
            $precinctList = $form_state->getValue('pct-list');
            $precincts = explode(',',$precinctList);
            foreach ($precincts as $precinct) {
              if($this->coordinatorsObj->duplicateScope('precinct',$precinct,$countyCoordinators)) {
                $form_state->setErrorByName('scope',t('A contact coordinator for precinct '.
                  $precinct.' is already assigned.'));
              }
            }
            $assignmentRole['precinctList'] = $precinctList;
            $assignmentRole['hd'] = $hd;
            break;
          
          case 'hd':
            
            $hdSelect = $form_state->getValue('hd-assigned');
            $hdList = $this->nlsObj->getHdList($county);
            $hdOptions = array_combine($hdList, $hdList);
            array_unshift($hdOptions , 'Select an HD');
            $hd = $hdOptions[$hdSelect];
            //nlp_debug_msg('$hd',$hd);
            if($this->coordinatorsObj->duplicateScope('hd',$hd,$countyCoordinators)) {
              $form_state->setErrorByName('scope',t('A contact coordinator for this HD is already assigned.'));
            }
            $assignmentRole['hd'] = $hd;
            $assignmentRole['precinctList'] = NULL;
            break;
          case 'county':
            if($this->coordinatorsObj->duplicateScope('county',$county,$countyCoordinators)) {
              $form_state->setErrorByName('scope',t('A county-wide contact coordinator is already assigned..'));
            }
            $assignmentRole['hd'] = NULL;
            $assignmentRole['precinctList'] = NULL;
            break;
        }
        //nlp_debug_msg('assignmentRole', $assignmentRole);
        $form_state->set('assignmentRole',$assignmentRole);
        $altEmail = $form_state->getValue('altEmail');
        if(!empty($altEmail) AND !Drupal::service('email.validator')->isValid($altEmail)) {
          $form_state->setErrorByName('altEmail',t('Email format is invalid.'));
        }
        $form_state->set('altEmail',$altEmail);
        break;
      
      case 'remove_coordinator_assignment':
        $contacts = $form_state->getValue('display');
        $selections = $this->coordinatorsListDisplayObj->validateCoordinatorSelection($contacts);
        if(empty($selections)) {
          $form_state->setErrorByName('remove_coordinator_assignment',
            "You must select one volunteer from the list.");
          return;
        }
        $form_state->set('removeCoordinators',$selections);
        break;
      
      case 'scope-select':
        $scopeSelect = $form_state->getValue('scope-select');
        //nlp_debug_msg('scopeSelect',$scopeSelect);
        $form_state->set('scope',$scopeSelect);
        break;
      
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();  // form_state will persist.
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {
      
      case 'search_submit':
        $form_state->set('reenter', TRUE);
        $form_state->setRebuild();  // form_state will persist.
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;
      
      case 'remove_coordinator_assignment':
        $removeCoordinators = $form_state->get('removeCoordinators');
        foreach ($removeCoordinators as $removeCoordinator) {
          $cIndex = $removeCoordinator['cIndex'];
          $this->coordinatorsObj->deleteCoordinator($cIndex);
        }
        break;
      
      case 'add_coordinator_assignment':
        // Add the new coordinator.
        $addCoordinators = reset($form_state->get('addCoordinators'));
        $mcid = $addCoordinators['mcid'];
        $altEmail = $form_state->get('altEmail');
        $assignmentRole = $form_state->get('assignmentRole');
        
        $nlsRecord = $this->nlsObj->getNlById($mcid);
        $email = (!empty($altEmail))?$altEmail:$nlsRecord['email'];
        if(empty($email)) {
          $messenger = Drupal::messenger();
          $messenger->addWarning(t('This selection does not have an email.  An email is required to be a coordinator.'));
          return;
        }
        
        $firstName = (!empty($nlsRecord['nickname']))?$nlsRecord['nickname']:$nlsRecord['firstName'];
        
        $req = [
          'county' => $assignmentRole['county'],
          'mcid' => $mcid,
          'firstName' => $firstName,
          'lastName' => $nlsRecord['lastName'],
          'email' => $email,
          'phone' => $nlsRecord['phone'],
          'scope' => $assignmentRole['scope'],
          'hd' => $assignmentRole['hd'],
          'precinctList' => $assignmentRole['precinctList'],
        ];
        $this->coordinatorsObj->createCoordinator($req);
        $req['hd'] = $nlsRecord['hd'];  // The hd is that of the Coordinator and not necessarily the assigned hd.
        $req['email'] = $nlsRecord['email'];
        $this->createCoordinatorAccount($req);
        break;
      
    }
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildScope
   *
   * Build the form entries for selecting the role scope for the new
   * coordinator.  This form uses and AJAX function to change the other entries
   * based on scope.
   *
   * @param string $scope
   * @param $options
   * @param array $hdArray
   * @return array
   */
  function buildScope(string $scope,$options,array $hdArray): array
  {
    //Build a wrapper around the part that will change with input.
    $form_element['scope'] = [
      '#prefix' => '<div id="scope-wrapper">',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
    ];
    $form_element['scope']['scope-select'] = [
      '#type' => 'select',
      '#title' => t('Select the scope.'),
      '#options' => $options,
      '#ajax' => [
        'callback' => '::scopeSelectedCallback',
        'wrapper' => 'scope-wrapper',
      ],
    ];
    $form_element['scope']['scope-description'] = [
      '#type' => 'markup',
      '#markup' => '<p>You can have only one person identified for each unique scope.  
If coordinators are sharing responsibility for a scope, use the HD or precinct scope to assign the second 
coordinator to groups of NLs.</p>',
    ];

    $hdTitle = ($scope!='precinct')?'Select the HD to be managed by the new HD coordinator.':
      'Select the HD that contains the precincts to be managed by the new coordinator.';
    array_unshift($hdArray , 'Select an HD');
    if($scope=='hd' OR $scope=='precinct') {
      $form_element['scope']['hd-assigned'] = [
        '#type' => 'select',
        '#title' => t($hdTitle),
        '#options' => $hdArray,
      ];
    }

    if($scope=='precinct') {
      $form_element['scope']['pct-list'] = [
        '#title' => 'Enter a list of precincts, separated by commas',
        '#size' => 30,
        '#maxlength' => 60,
        '#type' => 'textfield',
        '#required' => TRUE,
      ];
    }

    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * createCoordinatorAccount
   *
   * @param $coordinator
   */
  function createCoordinatorAccount($coordinator): void
  {
    $messenger = Drupal::messenger();
    $mcid = $coordinator['mcid'];
    $email = $coordinator['email'];
    if(empty($email)) {
      $messenger->addWarning(t('This selection does not have an email.
      An email is required to be a coordinator.'));
      return;
    }
    
    $userAccount = $this->drupalUserObj->getUserByMcid($mcid);
    if(empty($userAccount)) {
      $userAccount = $this->drupalUserObj->getUserByEmail($email);
    }
    $lcUsrName = strtolower($coordinator['firstName'] . '.' . $coordinator['lastName']);
    $userName = preg_replace('/-|\s+|&#0*39;|\'/', '', $lcUsrName);
    if(empty($userAccount)) {
      $userAccount = $this->drupalUserObj->getUserByName($userName);
    }
    if(!empty($userAccount)) {
      if($mcid != $userAccount['mcid'] AND !empty($userAccount['mcid'])) {
        $messenger->addWarning(t('The MCID of the Drupal account does not
        match the MyCampaign record, please contact the NLP Administrator.'));
        return;
      }
      $roles = $userAccount['roles'];
      
      if(!in_array(NLP_COORDINATOR_ROLE_ID,$roles)) {
        $messenger->addStatus(t('The coordinator role was added to the existing account.'));
        $uid = $userAccount['uid'];
        $update['uid'] = $uid;
        $update['roles'] = [NLP_COORDINATOR_ROLE_ID=>NLP_COORDINATOR_ROLE_LABEL,];
        $update['mcid'] = $mcid;
        $this->drupalUserObj->updateUser($update);
      }
      return;
    }
    
    $magicWord = $this->magicWordObj->createMagicWord();
    
    $newAccount = [
      'userName' => $userName,
      'email' => $email,
      'firstName' => $coordinator['firstName'],
      'lastName' => $coordinator['lastName'],
      'phone' => $coordinator['phone'],
      'county' => $coordinator['county'],
      'mcid' => $coordinator['mcid'],
      'hd' => $coordinator['hd'],
      'magicWord' => $magicWord,
      //'sharedEmail' => NULL,
      'roles' => [NLP_COORDINATOR_ROLE_ID => NLP_COORDINATOR_ROLE_LABEL,],
    ];
    $newUser = $this->drupalUserObj->addUser($newAccount);
    switch ($newUser['status']) {
      case 'error':
        $messenger->addError(t('Something went wrong with creating an
        account.  Please contact NLP tech support'));
        break;
      case 'exists':
        $messenger->addError(t("The NL's name is already in use.
        Please contact NLP tech support"));
        break;
      case 'complete':
        $messenger->addStatus(t('An account was created for this Coordinator.'
          . '<br>Username: '.$newUser['userName']
          . '<br>Password: '.$magicWord));
        $this->magicWordObj->setMagicWord($mcid,$magicWord);
        break;
    }
    
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCoordinatorSelect
   *
   * @param $scope
   * @param $availableNLs
   * @param $sortRequest
   * @return array
   */
  function buildCoordinatorSelect($scope,$availableNLs,$sortRequest): array
  {
    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
    
    $form['note1h'] = [
      '#type' => 'markup',
      '#markup' => t('<hr><h2>Assign NLP Coordinator as primary contacts for NLs.</h2>'),
    ];
    
    $form['assignment'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div class="coordinator-option-box">',
      '#suffix' => '</div>',
    ];
    
    // Add the line for selecting an HD.
    $form['assignment']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
    
    $form['assignment']['nl-select-header'] = [
      '#markup' => '<b>Check the box for the NL to be assigned as a coordinator.</b>',
    ];
    
    $tableArray = $this->coordinatorsListDisplayObj->buildCoordinatorTableArray('add', $availableNLs);
    
    if(!empty($sortRequest)) {
      $this->coordinatorsListDisplayObj->sortNlsTable($tableArray,$sortRequest['sort'],$sortRequest['order'],'add');
    }
    
    $form['assignment']['coordinator_choice'] =
      $this->coordinatorsListDisplayObj->buildCoordinatorsTable('add',$tableArray);
    
    $county = $this->sessionDataObj->getCounty();
    $hdList = $this->nlsObj->getHdList($county);
    $hdOptions = array_combine($hdList, $hdList);
    $form['assignment']['scope_selection'] =
      $this->buildScope($scope, $this->scopeOptions,$hdOptions);
    
    $form['assignment']['add-coordinator'] = [
      '#type' => 'submit',
      '#value' => 'Add this Coordinator.',
      '#name' => 'add_coordinator_assignment',
    ];
    
    return $form;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCoordinatorAssignment
   *
   * @param $assignedCoordinators
   * @param $sortRequest
   * @return array
   */
  function buildCoordinatorAssignment($assignedCoordinators,$sortRequest): array
  {
    $form['note2h'] = [
      '#type' => 'markup',
      '#markup' => '<br><hr><h2>Current coordinator assignments.</h2>',
      '#prefix' => '<div class="coordinator-box" >',
    ];
    
    $form['current'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div class="coordinator-option-box">',
      '#suffix' => '</div>',
    ];
    //nlp_debug_msg('assignedCoordinators',$assignedCoordinators);
    $tableArray = $this->coordinatorsListDisplayObj->buildCoordinatorTableArray('current', $assignedCoordinators);
    //nlp_debug_msg('tableArray',$tableArray);
    if(!empty($sortRequest)) {
      $this->coordinatorsListDisplayObj->sortNlsTable($tableArray,$sortRequest['sort'],$sortRequest['order'],'current');
    }
    
    $form['current']['editing'] = $this->coordinatorsListDisplayObj->buildCoordinatorsTable('current',$tableArray);
    
    $form['current']['remove_coordinator'] = [
      '#type' => 'submit',
      '#value' => 'Remove the selected Coordinator(s).',
      '#name' => 'remove_coordinator_assignment',
    ];
    
    return $form;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * scopeSelectedCallback
   *
   * AJAX call back for the selection of the HD
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function scopeSelectedCallback ($form,$unused): array
  {
    return $form['manageCoordinators']['coordinatorSelect']['assignment']['scope_selection']['scope'];
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * coordinatorCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function coordinatorCallback($form, $unused): array
  {
    return $form['manageCoordinators'];
  }
  
}
