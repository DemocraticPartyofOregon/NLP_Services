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
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class RemoveCoordinatorRoleFrom extends FormBase
{
  
  protected NlpCoordinators $coordinatorsObj;
  protected NlpNls $nlsObj;
  protected DrupalUser $drupalUserObj;
  protected MagicWord $magicWordObj;
  protected NlpCoordinatorsListDisplay $coordinatorsListDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpConfiguration $nlpConfigurationObj;
  
  public function __construct( $coordinatorsObj,$nlsObj,$drupalUserObj,$magicWordObj,
                               $coordinatorsListDisplayObj, $sessionDataObj, $searchBarObj, $nlpConfigurationObj )
  {
    $this->coordinatorsObj = $coordinatorsObj;
    $this->nlsObj = $nlsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->magicWordObj = $magicWordObj;
    $this->coordinatorsListDisplayObj = $coordinatorsListDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): RemoveCoordinatorRoleFrom
  {
    return new static(
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.coordinators_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_remove_coordinator_role_form';
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
      
      $hdOptions = $this->nlsObj->getHdList($county);
      array_unshift($hdOptions , 'All');
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }
    
    $county = $form_state->get('county');
    
    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
    $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
    
   
    $form['manageCoordinators'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div class="coordinator-option-box" id="selectbox-div">',
      '#suffix' => '</div>',
    ];
    
    //$currentHd = $this->sessionDataObj->getSavedHd();
    
    /* --- Remove coordinator assignment --- */
    
    // Get all the coordinators assigned for this county.
    $allAssignedCoordinators = $this->coordinatorsObj->getCoordinators($county);
    //nlp_debug_msg('$assignedCoordinators',$assignedCoordinators);
    //$mcidsOfCoordinators = array_keys($assignedCoordinators);
    $assignedCoordinators = [];
    foreach ($allAssignedCoordinators as $assignedCoordinator) {
      $mcid = $assignedCoordinator['mcid'];
      $assignedCoordinators[$mcid] = $assignedCoordinator;
      $assignedCoordinators[$mcid]['roles'] = [];
      $assignedCoordinators[$mcid]['uid'] = '';
      $coordinator = $this->drupalUserObj->getUserByMcid($mcid);
      if(!empty($coordinator)) {
        $assignedCoordinators[$mcid]['roles'] = $coordinator['roles'];
        $assignedCoordinators[$mcid]['uid'] = $coordinator['uid'];
      }
    }
    //nlp_debug_msg('$assignedCoordinators',$assignedCoordinators);
    
    $coordinators = $this->drupalUserObj->getCoordinators($county);
    
    $hd = $this->sessionDataObj->getSavedHd();
    //nlp_debug_msg('$hd',$hd);
    if(!empty($hd) AND $hd != 'All') {
      foreach ($coordinators as $mcid => $coordinator) {
        if($coordinator['hd'] != $hd) {
          unset($coordinators[$mcid]);
        }
      }
    }
    //nlp_debug_msg('$coordinators',$coordinators);
    
    $unassignedCoordinators = array_filter($coordinators, function ($mcid) use ($assignedCoordinators) {
      return !isset($assignedCoordinators[$mcid]);
    }, ARRAY_FILTER_USE_KEY);
    //nlp_debug_msg('$unassignedCoordinators',$unassignedCoordinators);
    
    //$assignedCoordinators = $coordinators;
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $form_state->set('search-needle', NULL);
      $form_state->set('unassignedCoordinators',$unassignedCoordinators);
      $unassignedCoordinators = $this->searchBarObj->searchInfoArray($needle,$unassignedCoordinators);
    }
    
    $tableArray = $this->coordinatorsListDisplayObj->buildCoordinatorTableArray('remove', $unassignedCoordinators);
    //nlp_debug_msg('$tableArray',$tableArray);
    
    $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
    if(!empty($sort)) {
      $order = $_GET['order'];
      $this->coordinatorsListDisplayObj->sortNlsTable($tableArray,$sort,$order,'remove');
    }
    $form['manageCoordinators']['assignmentRemoval'] =
                  $this->coordinatorsListDisplayObj->buildCoordinatorsTable('remove',$tableArray);
    
    $form['submit'] = [
      '#type' => 'submit',
      '#name' => 'user_select',
      '#value' => t('Remove the coordinator role from the selected user accounts'),
    ];
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
    //$county = $form_state->get('county');
    switch ($elementClicked) {
      
      case 'hd-select':
        $currentHdIndex = $form_state->getValue($elementClicked);
        //nlp_debug_msg('$currentHdIndex',$currentHdIndex);
        // The user changed the HD to display.
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        break;
      
      case 'user_select':
        $contacts = $form_state->getValue('contacts');
        //nlp_debug_msg('$contacts',$contacts);
        $selections = $this->coordinatorsListDisplayObj->validateCoordinatorSelection($contacts);
        //nlp_debug_msg('$selections',$selections);
        $form_state->set('selections',$selections);
        if(empty($selections)) {
          $form_state->setErrorByName('user_select',
            "You must select at least one volunteer from the list.");
          return;
        }
        
       
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
      case 'hd-change':
        $newHdIndex = $form_state->getValue('hd-change');
        //nlp_debug_msg('$newHdIndex',$newHdIndex);
        // The user changed the HD to display.
        $this->sessionDataObj->saveHdIndex($newHdIndex);
        return;
      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;
      
      case 'user_select':
        $selections = $form_state->get('selections');
        //nlp_debug_msg('$selections',$selections);
        
        foreach($selections as $selection) {
          $mcid = $selection['mcid'];
          $coordinator = $this->drupalUserObj->getUserByMcid($mcid);
          $uid = $coordinator['uid'];
          //nlp_debug_msg('$uid',$uid);
          
          $this->magicWordObj->removeCoordinatorRole($mcid);
          $this->drupalUserObj->removeNlpRoles($uid,[NLP_COORDINATOR_ROLE_ID,]);
        }
        
        
        break;
      
    }
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
  function selectboxCallback($form, $unused): array
  {
    //nlp_debug_msg('coordinatorCallback');
    return $form['manageCoordinators'];
  }
  
}


