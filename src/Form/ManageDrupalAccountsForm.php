<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpUserDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class ManageDrupalAccountsForm extends FormBase
{
  protected DrupalUser $drupalUserObj;
  protected NlpNls $nlsObj;
  protected MagicWord $magicWordObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpUserDisplay $userDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  public function __construct( $drupalUserObj, $nlsObj, $magicWordObj,
                              $searchBarObj, $userDisplayObj, $sessionDataObj, $nlpConfigurationObj)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->nlsObj = $nlsObj;
    $this->magicWordObj = $magicWordObj;
    $this->searchBarObj = $searchBarObj;
    $this->userDisplayObj = $userDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ManageDrupalAccountsForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.user_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration')
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_manage_drupal_accounts_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $messenger = Drupal::messenger();
    
    $form = [];
    if (empty($form_state->get('reenter'))) {
      $form_state->set('reenter', TRUE);
      $form_state->set('page', 'displayUsers');
      
      $county = $this->sessionDataObj->getCounty();
      $form_state->set('county',$county);
      
      $users = $this->drupalUserObj->getUsers($county);
      $userCount = count($users);
      
      $hdOptions = $this->hdsWithUsers($users,'All');
      if($userCount<$this->drupalUserObj::USER_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $form_state->set('hdOptions',$hdOptions);
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }
    
    $hdOptions  = $this->sessionDataObj->getHdOptions();
    $county = $form_state->get('county');
    if(empty($county)) {
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    
    $page = $form_state->get('page');
    $users = $this->drupalUserObj->getUsers($county);
    
    // Convert the Unknown hd to zero for display.
    foreach ($users as $vanid => $user) {
      if($user['hd'] == 'Unknown') {
        $users[$vanid]['hd'] = 0;
      }
    }
    
    $adminRole = $this->drupalUserObj->isNlpAdminUser();
    
    $form_state->set('users', $users);
    
    switch ($page) {
      
      case 'displayUsers':
       
        $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
        $currentHd = $this->sessionDataObj->getSavedHd();
        $hdOptions = $this->sessionDataObj->getHdOptions();
       
        $needle = $form_state->get('search-needle');
        if(!empty($needle)) {
          $form_state->set('search-needle', NULL);
          $users = $this->drupalUserObj->getUsers($county);  // All the uses in the county for the search.
          $users = $this->searchBarObj->searchInfoArray($needle,$users);  // just the ones with the needle.
        } else {
          $this->hdsWithUsers($users,$currentHd);
        }
        
        $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
        if(!empty($sort)) {
          $order = $_GET['order'];
          $this->userDisplayObj->sortUsers($users,$sort,$order,TRUE);
        }
        
        $form_state->set('users',$users);
        
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
          '#name' => 'user_select',
          '#value' => t('Edit the selected user accounts'),
        ];
        break;
      
      case 'editUsers':
        $users  = $form_state->get('users');
        // Display the list of selected users
        $uids = $form_state->get('editUsers');
        
        $form['user_login_title'] = [
          '#markup' => "<h2>Selected user accounts to edit</h2>",
        ];
        
        foreach ($uids as $uid) {
          $user = $users[$uid];
          $mcid = $user['mcid'];
          $msg = $this->drupalUserObj->displayCredentials($mcid,$user);
          $form['user_login'.$uid] = [
            '#markup' => t($msg),
          ];
        }
        
        $form['user_edit_title'] = [
          '#markup' => "<h2>Edit user</h2>",
        ];
        $currentEditIndex = $form_state->get('currentEditIndex');
        $uid = $uids[$currentEditIndex-1];
        $user = $users[$uid];
        $userIsNlpAdmin = $this->drupalUserObj->isNlpAdminUser($user);
        
        $mcid = $user['mcid'];
        $form_state->set('mcid',$mcid);
        if($userIsNlpAdmin AND !$adminRole) {
          $password = '*******';
        } elseif(empty($mcid)) {
          $password = 'unknown';
        } else {
          $password = $this->magicWordObj->getMagicWord($mcid);
          if(empty($password)) {
            $password = 'unknown';
          }
        }
        
        $user['password'] = $password;
        $currentValue = [];
        $countyNames = $this->nlpConfigurationObj->getCountyNames();
        $form['edit_user'] = $this->displayUser($user,$currentValue,$countyNames);
        $currentValue['uid'] = $uid;
        
        $form_state->set('currentValue', $currentValue);
        
        if(!$userIsNlpAdmin OR $adminRole) {
          $form['edit_submit'] = [
            '#type' => 'submit',
            '#name' => 'edit_submit',
            '#value' => t('Save changes'),
            '#description' => t('And advance to next account'),
          ];
          $form['edit_done'] = [
            '#type' => 'submit',
            '#name' => 'edit_done',
            '#value' => t('Done editing user accounts'),
          ];
          
        } else {
          $form['edit_warning'] = [
            '#markup' => 'You are not permitted to edit this account.<br><br>',
          ];
          $form['edit_done'] = [
            '#type' => 'submit',
            '#name' => 'edit_done',
            '#value' => t('Skip this account'),
          ];
        }
        break;
    }
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/info-boxes';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
    $form['#attached']['library'][] = 'nlpservices/edit-user';
    $form['#attached']['library'][] = 'nlpservices/form-helper';
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    
    $triggering_element = $form_state->getTriggeringElement();
    $elementClicked = $triggering_element['#name'];
    switch ($elementClicked) {
      case 'hd-select':
        // The user changed the HD to display.
        $currentHdIndex = $form_state->getValue('hd-select');
        $this->sessionDataObj->saveHdIndex($currentHdIndex);
        return;
      
      case 'edit_submit':
        
        $editable = ['firstName', 'lastName', 'mcid', 'county',
          'hd', 'phone', 'uname', 'magic-word', 'mail',];
        $values = $form_state->getValues();
        $currentValue = $form_state->get('currentValue');
        $changed = [];
        foreach ($editable as $key) {
          
          if($values[$key] == $currentValue[$key]) {continue;}
          switch ($key) {
            case 'firstName':
            case 'lastName':
            case 'phone':
            case 'magic-word':
              if (empty($values[$key])) {
                $form_state->setErrorByName($key, t($key . ' must not be empty.'));
              } else {
                $changed[$key] = $values[$key];
              }
              break;
            case 'mcid':
              $userCheck = $this->drupalUserObj->getUserByMcid($values[$key]);
              if (!empty($userCheck)) {
                $form_state->setErrorByName($key, t($values[$key] . ' is already assigned to another user account.'));
                break;
              }
              $nls = $this->nlsObj->getNlById($values[$key]);
              if (empty($nls)) {
                $form_state->setErrorByName($key, t($values[$key] . ' is not in the list of NLs.'));
                break;
              }
              $changed[$key] = $values[$key];
              break;
            case 'mail':
              if (empty($values[$key])) {
                $form_state->setErrorByName($key, t($key . ' must not be empty.'));
                break;
              }
              $userCheck = $this->drupalUserObj->getUserByEmail($values[$key]);
              if (!empty($userCheck)) {
                $form_state->setErrorByName($key, t($key . ' is already assigned to another user account.'));
                break;
              }
              $changed[$key] = $values[$key];
              break;
            case 'uname':
              if (empty($values[$key])) {
                $form_state->setErrorByName($key, t($key . ' must not be empty.'));
              }
              $userCheck = $this->drupalUserObj->getUserByName($values[$key]);
              if (!empty($userCheck)) {
                $form_state->setErrorByName($key, t($key . ' is already assigned to another user account.'));
                break;
              }
              $changed[$key] = $values[$key];
              break;
            default:
              $changed[$key] = $values[$key];
              break;
          }
        }
        
        $notLeader = empty($values['selected_roles']['nlp_leader']);
        $notCoordinator = empty($values['selected_roles']['nlp_coordinator']);
        if($notLeader AND $notCoordinator) {
          $form_state->setErrorByName('selected_roles', t('At least one role must be selected.'));
          return;
        }
        
        $wasNotLeader = empty($currentValue['roles']['roleDefaults']['nlp_leader']);
        $wasNotCoordinator = empty($currentValue['roles']['roleDefaults']['nlp_coordinator']);
        
        if($notLeader != $wasNotLeader OR $notCoordinator != $wasNotCoordinator) {
          $changed['roles'][NLP_LEADER_ROLE_ID] = ($notLeader)?0:NLP_LEADER_ROLE_LABEL;
          $changed['roles'][NLP_COORDINATOR_ROLE_ID] = ($notCoordinator)?0:NLP_COORDINATOR_ROLE_LABEL;
        }
        
        $form_state->set('changed', $changed);
        return;
        
      case 'user_select':
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
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();
    
    $triggering_element = $form_state->getTriggeringElement();
    $element_clicked = $triggering_element['#name'];
    
    switch ($element_clicked) {
      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        break;
      
      case 'user_select':
        $uids = [];
        $userSelections = $form_state->get('userSelections');
        foreach ($userSelections as $userSelection) {
          $uids[] = $userSelection['uid'];
        }
        
        $form_state->set('currentEditIndex', count($uids));
        $form_state->set('editUsers', $uids);
        $form_state->set('page', 'editUsers');
        return;
      
      case 'edit_submit':
        $editUpdate = [];
        $changed = $form_state->get('changed');
        foreach ($changed as $key => $value) {
          switch ($key) {
            case 'lastName':
            case 'firstName':
              $name = str_replace("'", "&#039;", trim ( $value));
              $editUpdate[$key] = $name;
              break;
            case 'magic-word':
              $editUpdate['magicWord'] = $value;
              break;
            case 'county':
              $countyNames = $form_state->get('countyNames');
              $editUpdate['county'] = $countyNames[$value];
              break;
            case 'roles':
              $editUpdate['roles'] = $changed['roles'];
              break;
            case 'uname':
              $editUpdate['username'] = $value;
              break;
            default:
              $editUpdate[$key] = $value;
              break;
          }
        }
        $currentValue = $form_state->get('currentValue');
        $editUpdate['uid'] = $currentValue['uid'];
        $this->drupalUserObj->updateUser($editUpdate);
        if(isset($editUpdate['magicWord'])) {
          $mcid = $form_state->get('mcid');
          if(!empty($mcid)) {
            $this->magicWordObj->setMagicWord($mcid,$editUpdate['magicWord']);
          }
        }
        
        $currentEditIndex = $form_state->get('currentEditIndex')-1;
        if($currentEditIndex > 0) {
          $form_state->set('currentEditIndex',$currentEditIndex);
        } else {
          $form_state->set('editUsers',NULL);
          $form_state->set('page', 'displayUsers');
        }
        return;
      
      case 'edit_done':
        $form_state->set('page', 'displayUsers');
        // start a new list.
        $form_state->set('currentEditIndex',0);
        $form_state->set('editUsers',NULL);
        return;
    }
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * displayUser
   *
   * Construct the form elements to display the fields for a drupal user
   * to permit changing each field.
   *
   * @param $user
   * @param $currentValue
   * @param $countyNames
   * @return array
   */
  function displayUser($user,&$currentValue,$countyNames): array
  {
    $allowedRoles = $this->drupalUserObj->getNlpRoles();
    unset($allowedRoles['nlp_admin']);
    
    // Lay the fields out on a grid (see css/edit-user.css) so the whole
    // account fits on screen without scrolling.  Children render in array
    // order, filling the grid row by row.
    $form_element['edit'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['nlp-edit-user-grid']],
    ];

    $firstName = str_replace("&#039;","'",$user['firstName']);
    $currentValue['firstName'] = $firstName;
    $form_element['edit']['firstName'] = [
      '#type' => 'textfield',
      '#id' => 'firstname',
      '#title' => 'First Name',
      '#default_value' => $firstName,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Edit the spelling of the user's chosen first name.",
    ];

    $lastName = str_replace("&#039;","'",$user['lastName']);
    $currentValue['lastName'] = $lastName;
    $form_element['edit']['lastName'] = [
      '#type' => 'textfield',
      '#id' => 'lastname',
      '#title' => 'Last Name',
      '#default_value' => $lastName,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Edit the spelling of the user's last name.",
    ];

    $phoneNumber = $user['phone'];
    $currentValue['phone'] = $phoneNumber;
    $form_element['edit']['phone'] = [
      '#type' => 'textfield',
      '#id' => 'phone',
      '#title' => 'Phone Number',
      '#default_value' => $phoneNumber,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Edit (or add) a phone number.",
    ];

    $mcid = $user['mcid'];
    $currentValue['mcid'] = $mcid;
    $form_element['edit']['mcid'] = [
      '#type' => 'textfield',
      '#id' => 'mcid',
      '#title' => 'MCID',
      '#default_value' => $mcid,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Edit (or add) the MyCampaign ID number.",
    ];

    $county = $user['county'];
    $countyIndex = array_flip($countyNames)[$county];
    $currentValue['county'] = $countyIndex;

    $form_element['edit']['county'] = [
      '#type' => 'select',
      '#title' => t('County'),
      '#options' => $countyNames,
      '#default_value' => $countyIndex,
      '#description' => 'Select a county.',
    ];

    $hd = $user['hd'];
    $currentValue['hd'] = $hd;

    $form_element['edit']['hd'] = [
      '#type' => 'textfield',
      '#title' => t('House District'),
      '#default_value' => $hd,
      '#size' => 4,
      '#maxlength' => 4,
      '#description' => 'Select a State House District.',
    ];

    $username = $user['userName'];
    $currentValue['uname'] = $username;
    $form_element['edit']['uname'] = [
      '#type' => 'textfield',
      '#id' => 'uname',
      '#title' => 'Username',
      '#default_value' => $username,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Change the Username for this user."
        . '<div class="hint_note hint-link-text"><p>Username convention'
        . '<span class="hint-list nlp-username-hint">The username is typically assigned by NLP Services'
        . ' when the first turf is synced.  It is constructed of the first and last name of the NL'
        . ' with a dot between the two names.  Since NLs recognize this pattern, you should use it'
        . ' here.  Of course, the NL can ask for anything they want as long as it is unique.'
        . '</span></p></div>',
    ];

    $password = $user['password'];
    $currentValue['magic-word'] = $password;
    $form_element['edit']['magic-word'] = [
      '#type' => 'textfield',
      '#id' => 'magic-word',
      '#title' => 'Password',
      '#default_value' => $password,
      '#size' => 28,
      '#maxlength' => 32,
      '#description' => "Change the password.",
    ];

    $userRoles = $user['roles'];
    unset($userRoles[0]);
    $userRoleIds = array_flip($userRoles);
    $roleDefaults = [];
    foreach ($allowedRoles as $roleId => $roleName) {
      if(!empty($userRoleIds[$roleId])) {
        $roleDefaults[$roleId] = $roleId;
      } else {
        $roleDefaults[$roleId] = '';
      }
    }

    $currentValue['roles']['roleDefaults'] = $roleDefaults;
    $currentValue['roles']['allowedRoles'] = $allowedRoles;

    // Roles sit in the third column, spanning the Username/Password and Email rows.
    $form_element['edit']['selected_roles'] = [
      '#type' => 'checkboxes',
      '#title' => 'Roles',
      '#options' => $allowedRoles,
      '#default_value' => $roleDefaults,
      '#description' => 'Select roles for this user.',
      '#prefix' => '<div class="nlp-edit-user-roles">',
      '#suffix' => '</div>',
    ];

    $email = $user['email'];
    $currentValue['mail'] = $email;
    $form_element['edit']['mail'] = [
      '#type' => 'textfield',
      '#id' => 'email',
      '#title' => 'Email',
      '#default_value' => $email,
      '#size' => 60,
      '#maxlength' => 160,
      '#description' => "Edit the user's email.",
      '#prefix' => '<div class="nlp-edit-user-email">',
      '#suffix' => '</div>',
    ];

    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * hdsWithUsers
   *
   * Get a list of HDs with users in the HD.
   *
   * @param $users
   * @param string $selectedHd
   * @return array
   */
  function hdsWithUsers(&$users, string $selectedHd): array
  {
    $hdOptions = $hdUsers = [];
    foreach ($users as $uid => $user) {
      $hd = $user['hd'];
      if($hd=='Unknown' OR empty($hd)) {continue;}
      if($selectedHd=='All' OR $selectedHd==$hd) {
        $hdOptions[$hd] = $hd;
        $hdUsers[$uid] = $user;
      }
    }
    $users = $hdUsers;
    asort($hdOptions);
    return array_values($hdOptions);
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
