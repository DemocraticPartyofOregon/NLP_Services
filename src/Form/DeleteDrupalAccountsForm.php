<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpUserDisplay;
use Drupal\nlpservices\NlpSessionData;

/**
 * @noinspection PhpUnused
 */
class DeleteDrupalAccountsForm extends FormBase
{
  protected DrupalUser $drupalUser;
  protected NlpUserDisplay $userDisplayObj;
  protected NlpSearchBar $searchBarObj;
  protected PrivateTempStoreFactory $privateTempstoreObj;
  protected NlpSessionData $sessionDataObj;
  
  public function __construct($drupalUser, $userDisplayObj, $searchBarObj, $privateTempstoreObj,
                              $sessionDataObj)
  {
    $this->drupalUser = $drupalUser;
    $this->userDisplayObj = $userDisplayObj;
    $this->searchBarObj = $searchBarObj;
    $this->privateTempstoreObj = $privateTempstoreObj;
    $this->sessionDataObj = $sessionDataObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeleteDrupalAccountsForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.user_display'),
      $container->get('nlpservices.search_bar'),
      $container->get('tempstore.private'),
      $container->get('nlpservices.session_data')
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_delete_drupal_user_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    
    if (empty($form_state->get('reenter'))) {
      $form_state->set('reenter', TRUE);
      
      $county = $this->sessionDataObj->getCounty();
      $form_state->set('county',$county);

      $form_state->set('page', 'displayUsers');
      if(!$this->drupalUser->isNlpAdminUser()) {
        $form_state->set('county_selected',$county);
      }
      
      $users = $this->drupalUser->getUsers($county);
      $userCount = count($users);
      
      $hdOptions = $this->hdsWithUsers($users);
      if($userCount<$this->drupalUser::USER_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->sessionDataObj->setNewDistricts($hdOptions);
      $form_state->set('hdOptions',$hdOptions);
    }
    
    $page = $form_state->get('page');
    $county = $form_state->get('county');
    if(empty($county)) {
      $messenger = Drupal::messenger();
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    if ($page == 'displayUsers') {
      
      // A search needle was entered, display all possible matches.
      $needle = $form_state->get('nl-search-needle');
      if(!empty($needle)) {
        $users = $this->drupalUser->getUsers($county);
        $form_state->set('nl-search-needle', NULL);
        $users = $this->searchBarObj->searchInfoArray($needle,$users);
      } else {
        $currentHd = $this->sessionDataObj->getSavedHd();
        $users = $this->drupalUser->getUsers($county);
        if($currentHd != 'All') {
          $users = $this->justUsersInHd($users, $currentHd);
        }
      }
      
      // One of the colummns was clicked to sort the table.
      $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
      if(!empty($sort)) {
        $order = $_GET['order'];
        $_GET['sort'] = '';
        $unsortedUsers = $users;
        $this->userDisplayObj->sortUsers($unsortedUsers,$sort,$order,TRUE);
        $users = [];
        foreach($unsortedUsers as $unsortedUser) {
          $users[$unsortedUser['uid']] = $unsortedUser;
        }
      }
      
      $form_state->set('users',$users);
      
      $hdOptions = $form_state->get('hdOptions');
      $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
      $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
      
      // Create the AJAX wrapper for updating the form changes.
      $form['user-form'] = [
        '#prefix' => '<div id="selectbox-div">',
        '#suffix' => '</div>',
        '#type' => 'fieldset',
        '#attributes' => [
          'style' => ['background-image:none;border:0px;padding:0px;margin:0px;background-color:rgb(255,255,255);'],
        ],
      ];
      $form['user-form']['user-table'] = $this->userDisplayObj->displayUsers($users);
      
      $form['submit'] = [
        '#type' => 'submit',
        '#name' => 'delete-submit',
        '#value' => t('Delete the selected user accounts'),
      ];
    }
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
      
      case 'search_submit':
        return;
        
      case 'hd-select':
        $currentHdIndex = $form_state->getValue('hd-select');
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        return;
      
      case 'delete-submit':
        $userSelections = $this->userDisplayObj->validateUserSelection($form_state->getValue('contacts'));
        $form_state->set('userSelections', $userSelections);
        if(empty($userSelections)) {
          $form_state->setErrorByName('reminder_select','You must select at least one NL to send an email.');
        }
        break;
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();  // form_state will persist.
    
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {
      
      case 'search_submit':
        $needle = $form_state->getValue('nl-search-needle');
        $form_state->set('nl-search-needle', $needle);
        break;
     
      case 'delete-submit':
        $userSelections = $form_state->get('userSelections');
        foreach($userSelections as $userSelection) {
          $uid = $userSelection['uid'];
          $users = $form_state->get('users');
          $user = $users[$uid];
          
          $admin = (in_array('administrator',$user['roles']) OR in_array('nlp_admin',$user['roles']));
          if ($admin) {
            $messenger->addStatus(t("An admin can't be deleted this way."));
          } else {
            $this->drupalUser->deleteUser($uid);
            $msg = 'The account for ' . $user['firstName'] . ' '
              . $user['lastName'] . ' was successfully deleted.';
            $messenger->addStatus(t($msg));
          }
        }
        break;
      }
      
   }
   
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * hdsWithUsers
   *
   * Create a list of HDs that have users in them, sorted in assending order.
   *
   * @param array $users
   * @return array
   */
  function hdsWithUsers(array $users): array
  {
    $hdOptions = [];
    foreach ($users as $user) {
      $hd = $user['hd'];
      if($hd=='Unknown' OR $hd==0) {continue;}
      $hdOptions[$hd] = $hd;
    }
    $hdOptions = array_values($hdOptions);
    asort($hdOptions);
    return $hdOptions;
  }
  
  /**
   * justUsersInHd
   *
   * Create an array of users in the specified HD.
   *
   * @param array $users - Array of user arrays.
   * @param int $selectedHd - The HD to display.
   * @return array
   */
  function justUsersInHd(array $users, int $selectedHd): array
  {
    $hdUsers = [];
    foreach ($users as $uid => $user) {
      $hd = $user['hd'];
      if($hd=='Unknown') {$hd = 0;}
      if($selectedHd==$hd) {
        $hdUsers[$uid] = $user;
      }
    }
    return $hdUsers;
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
    return $form['user-form'];
  }
  
}