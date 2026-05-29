<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpSessionData;

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

  public function __construct( $coordinatorsObj, $nlsObj, $drupalUserObj, $coordinatorsListDisplayObj,
                               $sessionDataObj, $searchBarObj )
  {
    $this->coordinatorsObj = $coordinatorsObj;
    $this->nlsObj = $nlsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->coordinatorsListDisplayObj = $coordinatorsListDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->searchBarObj = $searchBarObj;
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
      $county = $this->sessionDataObj->getCounty();
      if(empty($county)) {
        $this->messenger()->addWarning($this->t('The county is not specified. Use the "Manage County" tab to select a county.'));
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

    $sortRequest = [];
    $query = $this->requestStack->getCurrentRequest()->query;
    $sort = $query->get('sort', '');
    if(!empty($sort)) {
      $sortRequest = ['sort' => $sort, 'order' => $query->get('order', '')];
    }

    // Get all the coordinators assigned for this county.
    $assignedCoordinators = $this->coordinatorsObj->getCoordinators($county);
    $mcidsOfCoordinators = array_keys($assignedCoordinators);
    foreach ($mcidsOfCoordinators as $mcid) {
      $assignedCoordinators[$mcid]['roles'] = [];
      $assignedCoordinators[$mcid]['uid'] = '';
      $drupalUser = $this->drupalUserObj->getUserByMcid($mcid);
      if(!empty($drupalUser)) {
        $assignedCoordinators[$mcid]['roles'] = $drupalUser['roles'];
        $assignedCoordinators[$mcid]['uid'] = $drupalUser['uid'];
      }
    }

    $form_state->set('assignedCoordinators',$assignedCoordinators);

    $form['manageCoordinators'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="coordinator-div">',
      '#suffix' => '</div>',
    ];

    /* --- Remove coordinator assignment --- */

    $needle = $form_state->get('nl-search-needle-remove');
    if(!empty($needle)) {
      $form_state->set('nl-search-needle-remove', NULL);
      $assignedCoordinators = $this->searchBarObj->searchInfoArray($needle,$assignedCoordinators);
    }
    $currentInfo = ['sortRequest' => $sortRequest, 'assignedCoordinators' => $assignedCoordinators];
    $form['manageCoordinators']['assignmentRemoval'] = $this->buildRemoveAssignment($currentInfo);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {

      case 'hd-select-remove':
        $currentHdIndex = $form_state->getValue($elementClicked);
        // The user changed the HD to display.
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        break;

      case 'delete_current_coordinator':
        $contacts = $form_state->getValue('contacts');
        $selections = $this->coordinatorsListDisplayObj->validateCoordinatorSelection($contacts);
        if(empty($selections)) {
          $form_state->setErrorByName('delete_current_coordinator',
            $this->t('You must select at least one coordinator from the list.'));
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
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {

      case 'search_submit-remove':
        $needle = $form_state->getValue('nl-search-needle-remove');
        $form_state->set('nl-search-needle-remove', $needle);
        return;

      case 'delete_current_coordinator':
        $selectionsToDelete = $form_state->get('selectionsToDelete');
        $coordinators = $form_state->get('assignedCoordinators');
        foreach ($selectionsToDelete as $selectionToDelete) {
          $mcid = $selectionToDelete['mcid'];
          $cIndex = $coordinators[$mcid]['cIndex'];
          $this->coordinatorsObj->deleteCoordinator($cIndex);
        }
        break;
    }
  }

  /**
   * buildRemoveAssignment
   *
   * @param array $currentInfo
   * @return array
   */
  private function buildRemoveAssignment(array $currentInfo): array
  {
    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();

    $assignedCoordinators = $currentInfo['assignedCoordinators'];

    $form['note2h'] = [
      '#type' => 'markup',
      '#markup' => '<br><hr><h2>' . $this->t('Remove volunteers who are no longer coordinators.') . '</h2>',
      '#prefix' => '<div class="coordinator-box" >',
    ];

    $form['current'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div class="coordinator-option-box">',
      '#suffix' => '</div>',
    ];

    $form['current']['note2'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('These are people identified to help NLs with questions.  You can remove those who are no longer volunteering to help NLs.') . '</p>',
      '#prefix' => '<div class="coordinator-box" >',
    ];

    $form['current']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);

    $form['current']['nl-select-header'] = [
      '#markup' => '<b>' . $this->t('Check one or more boxes to select coordinators to be removed.') . '</b>',
    ];

    $tableArray = $this->coordinatorsListDisplayObj->buildCoordinatorTableArray('remove', $assignedCoordinators);

    $sortRequest = $currentInfo['sortRequest'];
    if(!empty($sortRequest)) {
      $this->coordinatorsListDisplayObj->sortNlsTable($tableArray,$sortRequest['sort'],$sortRequest['order'],'remove');
    }

    $form['current']['editing'] = $this->coordinatorsListDisplayObj->buildCoordinatorsTable('remove',$tableArray);

    $form['current']['delete-coordinator'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove the selected coordinators.'),
      '#name' => 'delete_current_coordinator',
    ];

    return $form;
  }

  /**
   * coordinatorCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  public function coordinatorCallback($form, $unused): array
  {
    return $form['manageCoordinators'];
  }

}
