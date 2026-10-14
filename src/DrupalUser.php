<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Exception;

class DrupalUser {
  public const int USER_ALL_MAX = 200;

  protected MagicWord $magicWordObj;
  protected RequestStack $requestStack;

  public function __construct (
    $magicWordObj,
    $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly UserAuthInterface $userAuth,
  ) {
    $this->magicWordObj = $magicWordObj;
    $this->requestStack = $requestStack;
  }

  public static function create(ContainerInterface $container): DrupalUser
  {
    return new static(
      $container->get('nlpservices.magic_word'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('user.auth'),
    );
  }

  /**
   * getUserStorage
   *
   * Returns the user entity storage, or NULL if the entity type can't be loaded.
   *
   * @param string $caller - identifies the caller in the debug message.
   * @return EntityStorageInterface|null
   */
  private function getUserStorage(string $caller): ?EntityStorageInterface
  {
    try {
      return $this->entityTypeManager->getStorage('user');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg($caller, $e);
      return NULL;
    }
  }

  /**
   * extractUserInfo
   *
   * Get the custom fields used by NLP as well as a few of the standard fields we use.
   * Returns a nice associative array of user information.
   *
   * @param $user
   * @return array
   */
  private function extractUserInfo($user): array
  {
    $roles = $user->getRoles();
    $uid = $user->id();
    $userInfo['uid'] = $uid;

    $userInfo['email'] = $user->getEmail();
    $userInfo['userName'] = $user->getAccountName();
    $userInfo['roles'] = $roles;
    $userInfo['access'] = $user->getLastAccessedTime();

    $userInfo['mcid'] = NULL;
    $userInfo['firstName'] =
    $userInfo['lastName'] =
    $userInfo['phone'] =
    $userInfo['county'] =
    $userInfo['hd'] =
    $userInfo['created'] =
    $userInfo['login'] = NULL;

    // Attempt to get the mcid field.   If an error is thrown, the custom fields are not set up.
    try {
      $mcid = $user->get('field_mcid')->value;
    }
    catch (Exception $e) {
      $messenger = Drupal::messenger();
      $messenger->addError($e->getMessage());
      return $userInfo;
    }

    // No need to catch errors as we can assume all were set up correctly if the mcid field is present.
    $userInfo['mcid'] = $mcid;
    $userInfo['firstName'] = $user->get('field_first_name')->value;
    $userInfo['lastName'] = $user->get('field_last_name')->value;
    $userInfo['phone'] = $user->get('field_phone')->value;
    $userInfo['county'] = $user->get('field_county')->value;
    $userInfo['hd'] = $user->get('field_hd')->value;
    $userInfo['created'] = $user->get('created')->value;
    $userInfo['login'] = $user->get('login')->value;

    return $userInfo;
  }

  /**
   * getCurrentUser
   *
   * Returns the NLP User account array for the current user.
   *
   * @return array
   * Used in:
   *   nlpservices.module
   *   ExportTableController.php
   *   ReportsController.php
   *   DataEntryForm.php
   *   DataEntryPostcardsForm.php
   *   TurfSelectForm.php
   *   NlpDataEntryPrint.php
   *   NlpSessionData.php
   * @noinspection PhpUnused
   */
  public function getCurrentUser(): array
  {
    $uid = $this->currentUser->id();
    $user = User::load($uid);
    return $this->extractUserInfo($user);
  }

  /**
   * isNlpAdminUser
   *
   * Returns TRUE if the user has admin permissions.
   *
   * @param array $user
   * @return bool - indicates the user has admin permissions.
   * Used in:
   *   nlpservices.module
   *   CountyManagementController.php
   *   AddDrupalAccountForm.php
   *   DeleteDrupalAccountsForm.php
   *   DocumentsForm.php
   *   ExportNlsStatusForm.php
   *   ManageDrupalAccountsForm.php
   *   TurfDeliveryMessageForm.php
   *   NlpResultsSummary.php
   *   NlpSessionData.php
   * @noinspection PhpUnused
   */
  public function isNlpAdminUser( array $user=[] ): bool
  {
    if(empty($user)) {
      $user = $this->getCurrentUser();
    }
    $userRoles = $user['roles'];
    if(in_array(NLP_ADMIN_ROLE_ID,$userRoles)) {return TRUE;}
    if(in_array('administrator',$userRoles)) {return TRUE;}
    return FALSE;
  }

  /**
   * getUserByMcid
   *
   * Returns the NLP user array given the mcid.
   *
   * @param int $mcid
   * @return array
   * Used in:
   *   SyncTurfBatch.php
   *   AddDrupalAccountForm.php
   *   CoordinatorsForm.php
   *   ManageDrupalAccountsForm.php
   *   ReassignMcidForm.php
   *   RemoveCoordinatorRoleFrom.php
   *   NlpCredentialsEmail.php
   *   NlpMcidReassignment.php
   *   NlpMinivan.php
   *   NlpNlsListDisplay.php
   *   NlpSyncActiveNlsProcessor.php
   * @noinspection PhpUnused
   */
  public function getUserByMcid( int $mcid ): array
  {
    try {
      $userMcid = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['field_mcid.value' => $mcid,]);
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('getUserByMcid', $e);
      return [];
    }
    if(empty($userMcid)) {return [];}
    $user = reset($userMcid);
    return $this->extractUserInfo($user);
  }

  /**
   * getLastAccessByMcid
   *
   * Returns the last access time of every user with a mcid, keyed by mcid.
   * One query, so an export of many NLs doesn't load each user entity.
   *
   * @return array
   * Used in:
   *   NlpExportNlsStatus.php
   * @noinspection PhpUnused
   */
  public function getLastAccessByMcid(): array
  {
    try {
      $query = Drupal::database()->select('user__field_mcid', 'm');
      $query->join('users_field_data', 'u', 'u.uid = m.entity_id');
      $query->addField('m', 'field_mcid_value', 'mcid');
      $query->addField('u', 'access');
      $query->condition('m.deleted', 0);
      return $query->execute()->fetchAllKeyed();
    } catch (Exception $e) {
      nlp_debug_msg('getLastAccessByMcid', $e);
      return [];
    }
  }

  /**
   * getUserByName
   *
   * Returns the NLP user array given the userName.
   *
   * @param string $userName
   * @return array
   * Used in:
   *   nlpservices.module
   *   ImportUserAccountsBatch.php
   *   CoordinatorsForm.php
   *   ImportCredentialsForm.php
   *   ManageDrupalAccountsForm.php
   *   NlpSyncActiveNlsProcessor.php
   * @noinspection PhpUnused
   */
  public function getUserByName( string $userName ): array
  {
    if(empty($userName)) {return [];}
    $userStorage = $this->getUserStorage('getUserByName');
    if(empty($userStorage)) {return [];}
    $users = $userStorage->loadByProperties(['name' => $userName]);
    $user = reset($users);
    if(empty($user)) {return [];}
    return $this->extractUserInfo($user);
  }

  /**
   * getUserByEmail
   *
   * Returns the NLP user array given the email.
   * Note, the email is required and unique for each NLP user.
   *
   * @param string $email
   * @return array
   * Used in:
   *   CoordinatorsForm.php
   *   ManageDrupalAccountsForm.php
   *   NlpSyncActiveNlsProcessor.php
   * @noinspection PhpUnused
   */
  public function getUserByEmail( string $email ): array
  {
    $userStorage = $this->getUserStorage('getUserByEmail');
    if(empty($userStorage)) {return [];}
    $users = $userStorage->loadByProperties(['mail' => $email]);
    $user = reset($users);
    if(empty($user)) {return [];}
    return $this->extractUserInfo($user);
  }

  /**
   * loginNameForEmail
   *
   * Returns the username of the account whose email address was typed into
   * the login form's username field, or '' if what was typed isn't such an
   * email.  NLs often log in with their email, which Drupal doesn't accept.
   * A username that happens to contain an @ is left for Drupal to check.
   *
   * @param string $name - what was typed as the username.
   * @return string
   */
  public function loginNameForEmail( string $name ): string
  {
    $email = trim($name);
    if(!str_contains($email, '@')) {return '';}
    if(!empty($this->getUserByName($email))) {return '';}
    $user = $this->getUserByEmail($email);
    return $user['userName'] ?? '';
  }

  /**
   * getUserByUid
   *
   * Returns the NLP user array given the Drupal uid.
   *
   * @param int $uid
   * @return array
   * Used in:
   *   LoginCredentialsReminderForm.php
   * @noinspection PhpUnused
   */
  public function getUserByUid( int $uid ): array
  {
    $user = User::load($uid);
    if(empty($user)) {return [];}
    return $this->extractUserInfo($user);
  }

  /**
   * addUser
   *
   * Creates a new Drupal user account given information about the user.
   *
   * Login accounts are created for an NLP user from information in MyCampaign.  This information should be
   * validated and correct.   If not, the information needs to be updated in MyCampaign.
   *
   * Some information is saved in NLP Services and is used to recreate a user account for a returning NL.
   * After an election, most user accounts are deleted to remove abandoned accounts.  Returning NLs
   * have their accounts restored when the list of active NLs is synced from MyCampaign.
   *
   * The created field should contain the original creation date for the user account.   This
   * original date is restored for each election.   The access field should contain the last access
   * date for the user.  It is intended to help us detect NLs that have become inactive.
   *
   * Required keys are:
   * - `userName` - the username for the user.  (This field is required and must be unique.)
   * - `email` - the email address for the user.  This field is required and must be unique.
   * Optional keys are:
   * (While there is no check, each should have a value. Incorrect
   * values may cause problems with the user account, especially the mcid.)
   * - `firstName`
   * - `lastName`
   * - `phone`
   * - `county`
   * - `hd`
   * - `mcid` - the MyCampaign id for the user.
   * - `magicWord` - the password for the user.
   * - `roles` - an array of role ids to assign to the user.
   * Optional keys:
   * - `access` - the last access time for the user.
   * - `created` - the time the user was created.
   *
   *
   * Returns the NLP user array with the following keys:
   * - `uid`
   * - `userName`
   * - `email`
   * - `firstName`
   * - `lastName`
   * - `phone`
   * - `county`
   * - `hd`
   * - `mcid`
   * - `roles`
   *     NLP_LEADER_ROLE_ID => NLP_LEADER_ROLE_LABEL
   *     NLP_COORDINATOR_ROLE_ID => NLP_LEADER_ROLE_LABEL
   * - `access`
   * - `created`
   * - `status` - indicates the status of the user creation.
   *
   * @param array $user
   * @return array - simplified information from the newly created user entity.
   * Used in:
   *   ImportUserAccountsBatch.php
   *   AddDrupalAccountForm.php
   *   CoordinatorsForm.php
   * @noinspection PhpUnused
   */
  public function addUser( array $user ): array
  {
    if(empty($user['userName'])) {
      $addUserError['status'] = 'no userName';
      return $addUserError;
    }
    $userName = $user['userName'];
    $userByName = $this->getUserByName($userName);
    if(!empty($userByName)) {
      $addUserError['status'] = 'exists';
      return $addUserError;
    }
    if(empty($user['email'])) {
      $addUserError['status'] = 'no email';
      return $addUserError;
    }

    $email = $user['email'];
    $roles = $user['roles'];

    $login = (empty($user['login']))?NULL:$user['login'];
    $values = [
      'name'                     => $userName,
      'pass'                     => $user['magicWord'],
      'mail'                     => $email,
      'init'                     => $email,
      'timezone'                 => date_default_timezone_get(),
      'login'                    => $login,
      'preferred_langcode'       => 'en',
      'preferred_admin_langcode' => 'en',
    ];

    if(!empty($user['created'])) {
      $values['created'] = $user['created'];
    }

    $userStorage = $this->getUserStorage('addUser');
    if(empty($userStorage)) {
      $addUserError['status'] = 'add user error';
      return $addUserError;
    }
    $newUser = $userStorage->create($values);

    try {
      $newUser->save();
    } catch (EntityStorageException $e) {
      $addUserError['status'] = 'add user error';
      nlp_debug_msg('addUser', $e);
      return $addUserError;
    }

    $newUsers = $userStorage->loadByProperties(['name' => $userName]);
    $newUser = reset($newUsers);
    if(!$newUser instanceof UserInterface) {
      $addUserError['status'] = 'add user error';
      return $addUserError;
    }

    $newUser->set("field_first_name", $user['firstName']);
    $newUser->set("field_last_name", $user['lastName']);
    $newUser->set("field_county", $user['county']);
    $newUser->set("field_hd", $user['hd']);
    $newUser->set("field_phone", $user['phone']);

    $mcid = (!empty($user['mcid']))?$user['mcid']:NULL;
    $newUser->set("field_mcid", $mcid);
    $newUser->activate();

    foreach (array_keys($roles) as $roleId) {
      $newUser->addRole($roleId);
    }
    if(!empty($user['access'])) {
      $newUser->setLastAccessTime($user['access']);
    }
    try {
      $newUser->save();
    } catch (EntityStorageException $e) {
      nlp_debug_msg('User account create error.', $e);
      $user['status'] = 'error';
      return $user;
    }

    if(!empty($user['created'])) {
      $newUser->set('created', $user['created']);
    }

    try {
      $newUser->save();
    } catch (EntityStorageException $e) {
      nlp_debug_msg('User account create error.', $e);
      $user['status'] = 'error';
      return $user;
    }

    $user = $this->extractUserInfo($newUser);
    $user['status'] = 'complete';
    return $user;
  }

  /**
   * updateUser
   *
   * Updates a Drupal account with new values for fields as identified in the
   * editUpdate array.
   *
   * @param array $editUpdate
   * @return array
   * Used in:
   *   ImportUserAccountsBatch.php
   *   CoordinatorsForm.php
   *   ManageDrupalAccountsForm.php
   *   ReassignMcidForm.php
   *   NlpExportUserAccounts.php
   *   NlpMcidReassignment.php
   *   NlpMinivan.php
   *   NlpSyncActiveNlsProcessor.php
   * @noinspection PhpUnused
   */
  public function updateUser( array $editUpdate ): array
  {
    $uid = $editUpdate['uid'];
    $user = User::load($uid);
    foreach ($editUpdate as $nlpKey => $nlpValue) {
      switch ($nlpKey) {
        case 'mcid':
          $user->set("field_mcid", $nlpValue);
          break;
        case 'firstName':
          $user->set("field_first_name", $nlpValue);
          break;
        case 'lastName':
          $user->set("field_last_name", $nlpValue);
          break;
        case 'username':
          $user->setUsername($nlpValue);
          break;
        case 'mail':
          $user->setEmail($nlpValue);
          break;
        case 'county':
          $user->set("field_county", $nlpValue);
          break;
        case 'hd':
          $user->set("field_hd", $nlpValue);
          break;
        case 'phone':
          $user->set("field_phone", $nlpValue);
          break;
        case 'magicWord':
          $user->setPassword($nlpValue);
          break;
        case 'roles':
          foreach ($nlpValue as $roleId=>$roleLabel) {
            if(empty($roleLabel)) {
              $user->removeRole($roleId);
            } else {
              $user->addRole($roleId);
            }
          }
          break;
        case 'access':
          $user->setLastAccessTime($nlpValue);
          break;
        case 'created':
          $user->set('created', $nlpValue);
          try {
            $user->save();
          } catch (EntityStorageException $e) {
            nlp_debug_msg('User account update error.', $e);
          }
          break;
      }
    }

    try {
      $user->save();
    }
    catch (EntityStorageException $e) {
      nlp_debug_msg('User account update error.', $e);
      $updatedUser['status'] = 'error';
      return $updatedUser;
    }
    $updatedUser = $this->extractUserInfo($user);
    $updatedUser['status'] = 'complete';
    return $updatedUser;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * createDrupalAccount
   *
   * Create a Drupal log in account given an array of user information
   * Required keys:
   * - `userName`
   * - `email`
   * - `firstName`
   * - `lastName`
   * - `phone`
   * - `county`
   * - `mcid`
   * - `magicWord`
   * - `roles`
   *    NLP_LEADER_ROLE_ID => NLP_LEADER_ROLE_LABEL
   *    NLP_COORDINATOR_ROLE_ID => NLP_LEADER_ROLE_LABEL
   *
   * @param array $userInfo
   * @return array
   * Used in:
   *   NlpSyncActiveNlsProcessor.php
   * @noinspection PhpUnused
   */
  public function createDrupalAccount( array $userInfo ): array
  {
    if(empty($userInfo['email'])) {return [];}
    $roles = (!empty($userInfo['roles']))?$userInfo['roles']:[ NLP_LEADER_ROLE_ID => NLP_LEADER_ROLE_LABEL,];
    $magicWord = (!empty($userInfo['magicWord']))?$userInfo['magicWord']:$this->magicWordObj->createMagicWord();
    $account = [
      'userName' => $userInfo['userName'],
      'email' => $userInfo['email'],
      'firstName' => $userInfo['firstName'],
      'lastName' => $userInfo['lastName'],
      'phone' => $userInfo['phone'],
      'county' => $userInfo['county'],
      'hd' => $userInfo['hd'],
      'mcid' => $userInfo['mcid'],
      'magicWord' => $magicWord,
      'roles' => $roles,
      'created' => time(),
    ];
    if(!empty($userInfo['created'])) {
      $account['created'] = $userInfo['created'];
    }
    if(!empty($userInfo['access'])) {
      $account['access'] = $userInfo['access'];
    }
    return $this->addUser($account);
  }

  /**
   * getCounties
   *
   * Returns a list of counties that have NLP users.
   *
   * @return array - of county names
   * Used in:
   *   NlpExportUserAccounts.php
   * @noinspection PhpUnused
   */
  public function getCounties(): array
  {
    $userStorage = $this->getUserStorage('getCounties');
    if(empty($userStorage)) {return [];}
    $query = $userStorage->getQuery()
      ->accessCheck(FALSE)
    ;
    $result = $query->execute();
    $countyNames = [];
    foreach ($result as $uid) {
      $user = User::load($uid);
      $county = $user->get('field_county')->value;
      if(!empty($county)) {
        $countyNames[$county] = $county;
      }
    }
    ksort($countyNames);
    return $countyNames;
  }

  /**
   * Returns a list of NLP user arrays for all users or those in a specific county.  Or, for
   * all counties if the searchCounty is empty.
   *
   * @param string $searchCounty
   * @return array
   * Used in:
   *   NlpResetForm.php
   *   DeleteDrupalAccountsForm.php
   *   LoginCredentialsReminderForm.php
   *   ManageDrupalAccountsForm.php
   *   NlpExportUserAccounts.php
   * @noinspection PhpUnused
   */
  public function getUsers( string $searchCounty=''): array
  {
    $userStorage = $this->getUserStorage('getUsers');
    if(empty($userStorage)) {return [];}
    $query = $userStorage->getQuery()
      ->accessCheck(FALSE);
    if (!empty($searchCounty)) {
      $query->condition('field_county', $searchCounty);
    }
    $result = $query->execute();
    $users = [];
    foreach ($result as $uid) {
      $user = User::load($uid);
      if(empty($user)) { continue; }
      $county = $user->get('field_county')->value;
      if(empty($searchCounty) && empty($county)) { continue; }
      $userArray = $this->extractUserInfo($user);
      if($userArray['uid'] > 1) {
        $users[$userArray['uid']] = $userArray;
      }
    }
    return $users;
  }

  /**
   * removeNlpRoles
   *
   * Removes the NLP roles from a user account given the uid and an array of role ids.
   *
   * @param int $uid
   * @param array $rids
   * @return bool
   * Used in:
   *   RemoveCoordinatorRoleFrom.php
   * @noinspection PhpUnused
   */
  public function removeNlpRoles( int $uid, array $rids): bool
  {
    $user = User::load($uid);
    if(empty($user)) {return FALSE;}
    foreach ($rids as $rid) {
      $user->removeRole($rid);
    }
    try {
      $user->save();
    } catch (EntityStorageException $e) {
      nlp_debug_msg('removeNlpRoles.', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * deleteUser
   *
   * Deleted a Drupal user account given the uid.
   *
   * @param int $uid
   * @return void
   * Used in:
   *   NlpResetForm.php
   *   DeleteDrupalAccountsForm.php
   *   ReassignMcidForm.php
   * @noinspection PhpUnused
   */
  public function deleteUser(int $uid ): void
  {
    try {
      $userStorage = $this->entityTypeManager->getStorage('user');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('deleteUser', $e);
      return;
    }
    if(empty($userStorage)) {return;}
    $user = $userStorage->load($uid);
    try {
      $user->delete();
    } catch (EntityStorageException $e) {
      nlp_debug_msg('Delete user error', $e);
    }
  }

  /**
   * verifyPassword
   *
   * Returns TRUE if the given plain-text password matches the stored password for the named user.
   *
   * @param string $userName
   * @param string $password
   * @return bool
   * Used in:
   *   ImportCredentialsForm.php
   * @noinspection PhpUnused
   */
  public function verifyPassword( string $userName, string $password ): bool
  {
    if(empty($userName) || empty($password)) {return FALSE;}
    $uid = $this->userAuth->authenticate($userName, $password);
    return !empty($uid);
  }

  /**
   * getNlpRoles
   *
   * Returns an array of NLP roles that are defined in Drupal.
   *
   * @return array
   * Used in:
   *   AddDrupalAccountForm.php
   *   ManageDrupalAccountsForm.php
   * @noinspection PhpUnused
   */
  public function getNlpRoles(): array
  {
    $nlpRoles = [];
    try {
      $drupalRoles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
        nlp_debug_msg('getNlpRoles', $e);
        return [];
    }
    foreach ($drupalRoles as $drupalRole) {
      $roleId = $drupalRole->id();
      $roleLabel = $drupalRole->label();
      if(str_contains($roleId,'nlp_')) {
        $nlpRoles[$roleId] = $roleLabel;
      }
    }
    return $nlpRoles;
  }

  /**
   * displayCredentials
   *
   * Returns a displayable string with the login credentials for an NLP user given an NLP user array or
   * alternatively, the mcid of the user.
   *
   * @param int $mcid
   * @param array $user
   * @return string
   * Used in:
   *   AddDrupalAccountForm.php
   *   ManageDrupalAccountsForm.php
   * @noinspection PhpUnused
   */
  public function displayCredentials( int $mcid, array $user=[]): string
  {
    if(empty($user)) {
      $user = $this->getUserByMcid($mcid);
    }

    if(!isset($user['mcid'])) {
      $msg = var_export($user, true);
      Drupal::logger('nlpservices')->notice('User is missing NLP fields:'.' '.$msg);
      $credentials = "<b>Probably not an NLP user. <br>";
    } else {
      $firstName = $user['firstName'];
      $lastName = $user['lastName'];
      $mcid = $user['mcid'];
      $credentials = "<b>$firstName $lastName</b> [$mcid] <br>";
    }

    $serverUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
    $credentials .= $serverUrl.'<br>';
    $password = 'unknown';
    if(!empty($mcid)) {
      $password = $this->magicWordObj->getMagicWord($mcid);
    }
    $userName = $user['userName'];
    $credentials .= "Username: $userName <br>Password: $password<br><br>";
    return $credentials;
  }

  /**
   * getCoordinators
   *
   * Returns a list of NLP users with the coordinator role for a specific county.
   *
   * @param string $county
   * @return array
   * Used in:
   *   RemoveCoordinatorRoleFrom.php
   * @noinspection PhpUnused
   */
  public function getCoordinators( string $county ): array
  {
    $userList = [];
    $userStorage = $this->getUserStorage('getCoordinators');
    if(empty($userStorage)) {return [];}
    $ids = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'nlp_coordinator')
      ->condition('field_county', $county, 'CONTAINS')
      ->execute();

    foreach ($ids as $uid) {
      $user = $this->getUserByUid($uid);
      $userList[$user['mcid']] = $user;
    }
    return $userList;
  }

  /**
   * purgeNlUsers
   *
   * Remove all the Drupal logins for NLs.
   *
   * This removes NLs that will no longer participate.
   * Returning NLs will get added back with their original login credentials.
   *
   * @return void
   * Used in:
   *   ExportUserAccountsController.php
   * @noinspection PhpUnused
   */
  public function purgeNlUsers(): void
  {
    $counties = $this->getCounties();
    foreach ($counties as $county) {
      $users = $this->getUsers($county);
      foreach ($users as $user) {
        $roles = $user['roles'];
        $roles = array_flip($roles);
        if(count($roles)==2 AND isset($roles['authenticated']) AND isset($roles[NLP_LEADER_ROLE_ID])) {
          $this->deleteUser($user['uid']);
        }
      }
    }
  }
}
