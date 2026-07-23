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
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class DeleteCoordinatorForm extends FormBase
{

  protected NlpCoordinators $coordinatorsObj;
  protected NlpNls $nlsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpCoordinatorsListDisplay $coordinatorsListDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct( $coordinatorsObj, $nlsObj, $drupalUserObj, $coordinatorsListDisplayObj,
                               $sessionDataObj, $searchBarObj, $nlpConfigurationObj )
  {
    $this->coordinatorsObj = $coordinatorsObj;
    $this->nlsObj = $nlsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->coordinatorsListDisplayObj = $coordinatorsListDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeleteCoordinatorForm
  {
    return new static(
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.drupal_user'),
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
    return 'nlpservices_delete_coordinator_form';
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
      $form_state->set('hdOptions',$hdOptions);
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }

    $county = $form_state->get('county');

    $sortRequest  = [];
    $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
    if(!empty($sort)) {
      $order = $_GET['order'];
      $sortRequest = ['sort'=>$sort,'order'=>$order];
      nlp_debug_msg('sort', "sort=.$sort, order=$order");
    }


    // Get all the coordinators assigned for this county.
    $assignedCoordinators = $this->coordinatorsObj->getCoordinators($county);
    $mcidsOfCoordinators = array_keys($assignedCoordinators);
    //nlp_debug_msg('$mcidsOfCoordinators',$mcidsOfCoordinators);
    foreach ($mcidsOfCoordinators as $mcid) {
      $assignedCoordinators[$mcid]['roles'] = [];
      $assignedCoordinators[$mcid]['uid'] = '';
      $drupalUser = $this->drupalUserObj->getUserByMcid($mcid);
      //nlp_debug_msg('$coordinator',$coordinator);
      if(!empty($drupalUser)) {
        $assignedCoordinators[$mcid]['roles'] = $drupalUser['roles'];
        $assignedCoordinators[$mcid]['uid'] = $drupalUser['uid'];
      }
    }

    //nlp_debug_msg('$assignedCoordinators',$assignedCoordinators);
    $form_state->set('assignedCoordinators',$assignedCoordinators);
    // Get the Drupal users with the coordinator role for this county.
    //$usersWithCoordinatorRole = $this->drupalUserObj->getCoordinators($county);
    //nlp_debug_msg('$usersWithCoordinatorRole',$usersWithCoordinatorRole);

    $form['manageCoordinators'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="coordinator-div">',
      '#suffix' => '</div>',
    ];

    /* --- Remove coordinator assignment --- */

    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $form_state->set('search-needle', NULL);
      $assignedCoordinators = $this->searchBarObj->searchInfoArray($needle,$assignedCoordinators);
    }
    $currentInfo['sortRequest'] = $sortRequest;
    $currentInfo['scope'] = $form_state->get('scope');
    $currentInfo['assignedCoordinators'] = $assignedCoordinators;
    $form['manageCoordinators']['assignmentRemoval'] = $this->buildRemoveAssignment($currentInfo);
    //$form['manageCoordinators']['coordinatorSelect'] = $this->buildCoordinatorSelect($currentInfo);
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
    //$values = $form_state->getValues();
    //nlp_debug_msg('$values',$values);
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('$element_clicked',$elementClicked);
    switch ($elementClicked) {

      case 'hd-select-remove':
        $currentHdIndex = $form_state->getValue($elementClicked);
        //nlp_debug_msg('$currentHdIndex',$currentHdIndex);
        // The user changed the HD to display.
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        break;

      case 'delete_current_coordinator':
        //$results = $this->validateNlSelection($values, 'remove');
        $contacts = $form_state->getValue('contacts');
        $selections = $this->coordinatorsListDisplayObj->validateCoordinatorSelection($contacts);
        if(empty($selections)) {
          $form_state->setErrorByName('delete_current_coordinator',
            "You must select at least one coordinator from the list.");
        }
        $form_state->set('selectionsToDelete', $selections);
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
    //nlp_debug_msg('$triggeringElement',$triggeringElement);
    //nlp_debug_msg('values',$form_state->getValues());
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('$element_clicked',$element_clicked);
    switch ($elementClicked) {

      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;

      case 'delete_current_coordinator':
        $selectionsToDelete = $form_state->get('selectionsToDelete');
        $coordinators = $form_state->get('assignedCoordinators');
        //nlp_debug_msg('$selectionsToDelete',$selectionsToDelete);
        //nlp_debug_msg('$coordinators',$coordinators);

        foreach ($selectionsToDelete as $selectionToDelete) {
          $mcid = $selectionToDelete['mcid'];
          $cIndex = $coordinators[$mcid]['cIndex'];
          $this->coordinatorsObj->deleteCoordinator($cIndex);
        }
        break;
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildRemoveAssignment
   *
   * @param $currentInfo
   * @return array
   */
  function buildRemoveAssignment($currentInfo): array
  {
    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();

    $assignedCoordinators = $currentInfo['assignedCoordinators'];

    $form['note2h'] = [
      '#type' => 'markup',
      '#markup' => '<br><hr><h2>Remove volunteers who are no longer coordinators.</h2>',
      '#prefix' => '<div class="coordinator-box" >',
    ];

    $form['current'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div class="coordinator-option-box">',
      '#suffix' => '</div>',
    ];

    $form['current']['note2'] = [
      '#type' => 'markup',
      '#markup' => '
<p>These are people identified to help NLs with questions.  You can remove those who are no longer volunteering
to help NLs.</p>',
      '#prefix' => '<div class="coordinator-box" >',
    ];

    $form['current']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);

    $form['current']['nl-select-header'] = [
      '#markup' => '<b>Check one or more boxes to select coordinators to be removed.</b>',
    ];

    $tableArray = $this->coordinatorsListDisplayObj->buildCoordinatorTableArray('remove', $assignedCoordinators);

    $sortRequest = $currentInfo['sortRequest'];
    if(!empty($sortRequest)) {
      $this->coordinatorsListDisplayObj->sortNlsTable($tableArray,$sortRequest['sort'],$sortRequest['order'],'remove');
    }

    $form['current']['editing'] = $this->coordinatorsListDisplayObj->buildCoordinatorsTable('remove',$tableArray);

    $form['current']['delete-coordinator'] = [
      '#type' => 'submit',
      '#value' => 'Remove the selected coordinators.',
      '#name' => 'delete_current_coordinator',
    ];

    return $form;
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
    //nlp_debug_msg('coordinatorCallback');
    return $form['manageCoordinators'];
  }

}
