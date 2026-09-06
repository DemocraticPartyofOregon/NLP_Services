<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpCredentialsEmail;
use Drupal\nlpservices\NlpUserDisplay;
use Drupal\nlpservices\NlpSessionData;

/**
 * @noinspection PhpUnused
 */
class LoginCredentialsReminderForm extends FormBase
{
  protected DrupalUser $drupalUser;
  protected NlpNls $nlsObj;
  protected NlpCredentialsEmail $credentialsEmailObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpUserDisplay $userDisplayObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;

  public function __construct($drupalUser, $nlsObj, $credentialsEmailObj, $searchBarObj,
                              $userDisplayObj, $nlpConfigurationObj, $nlpSessionDataObj )
  {
    $this->drupalUser = $drupalUser;
    $this->nlsObj = $nlsObj;
    $this->credentialsEmailObj = $credentialsEmailObj;
    $this->searchBarObj = $searchBarObj;

    $this->userDisplayObj = $userDisplayObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): LoginCredentialsReminderForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.credentials_email'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.user_display'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_login_credentials_reminder_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $messenger = Drupal::messenger();

    if (empty($form_state->get('reenter'))) {
      $form_state->set('reenter', TRUE);
      $form_state->set('page', 'displayUsers');
      $form_state->set('hd', 0);

      $county = $this->nlpSessionDataObj->getCounty();
      $form_state->set('county',$county);
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab
        to select a county.'));
        return [];
      }

      $users = $this->drupalUser->getUsers($county);
      $userCount = count($users);

      $hdOptions = $this->hdsWithUsers($users,'All');
      if($userCount<$this->drupalUser::USER_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $form_state->set('hdOptions',$hdOptions);

      $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
      $form_state->set('currentHdIndex',$currentHdIndex);
    }

    $currentHdIndex = $form_state->get('currentHdIndex');
    $hdOptions = $form_state->get('hdOptions');
    $currentHd = $hdOptions[$currentHdIndex];
    $county = $form_state->get('county');

    $page = $form_state->get('page');
    $users = $this->drupalUser->getUsers($county);

    // The user selected a phrase to search, display all possible candidates.
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $form_state->set('search-needle', NULL);
      $users = $this->searchBarObj->searchInfoArray($needle,$users);
    }

    // The user clicked a column to sort.
    $sort = (!empty($_GET['sort']))?$_GET['sort']:'';
    if(!empty($sort)) {
      $order = $_GET['order'];
      $this->userDisplayObj->sortUsers($users, $sort, $order, true );
    }

    $this->hdsWithUsers($users,$currentHd);  // Populate the HD field.
    $form_state->set('$users', $users);

    switch ($page) {
      case 'displayUsers':

        $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);

        if(empty($users)) {
          $messenger->addError(t('No users selected'));
          break;
        }

        // Create the AJAX wrapper for updating the form changes.
        $form['user-form'] = [
          '#prefix' => '<div id="selectbox-div">',
          '#suffix' => '</div>',
          '#type' => 'fieldset',
          '#attributes' => [
            'style' => ['background-image:none;border:0px;padding:0px;margin:0px;background-color:rgb(255,255,255);'],
          ],
        ];

        $form['user-form']['user-table'] = $this->userDisplayObj->displayUsers( $users, true );

        $form['reminder_select'] = [
          '#type' => 'submit',
          '#name' => 'reminder_select',
          '#value' => t('Remind selected users of login credentials.'),
        ];
        break;
    }
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/info-boxes';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
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
      /*
      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;
        */
      case 'hd-select':
        $currentHdIndex = $form_state->getValue('hd-select');
        // The user changed the HD to display.
        $hdOptions = $form_state->get('hdOptions');
        $form_state->set('currentHdIndex',$currentHdIndex);
        $form_state->set('currentHd',$hdOptions[$currentHdIndex]);
        $this->nlpSessionDataObj->saveHdIndex($currentHdIndex);
        return;

      case 'reminder_select':
        $userSelections = $this->userDisplayObj->validateUserSelection($form_state->getValue('contacts'));
        $form_state->set('userSelections', $userSelections);
        if(empty($userSelections)) {
          $form_state->setErrorByName('reminder_select','You must select at least one NL to send an email.');
        }
    }



  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    switch ($elementClicked) {

      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;

      case 'reminder_select':
        $userSelections = $form_state->get('userSelections');
        foreach($userSelections as $userSelection) {
          $uid = $userSelection['uid'];
          $user = $this->drupalUser->getUserByUid($uid);
          if(empty($user['email'])) {
            $messenger->addError($user['firstName'].' '.$user['lastName'].
              ' does not have an email address.');
            continue;
          }
          $mcid = $user['mcid'];
          if(empty($mcid)) {
            $messenger->addError($user['firstName'].' '.$user['lastName'].
              ' does not have the MCID field set and may not be a valid NL.');
            continue;
          }

          $result = $this->remindUserCredentials($user);
          if($result) {
            $messenger->addStatus(t('A reminder email was sent to '
              .$user['firstName'].' '.$user['lastName'].'.'));
          } else {
            $messenger->addStatus(t('The reminder email to '
              .$user['firstName'].' '.$user['lastName'].' failed.'));
          }
        }
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * hdsWithUsers
   *
   * Return an array of HDs that have users in them, sorted in ascending order.
   *
   * @param $users - Array of Drupal users.
   * @param $selectedHd - Array of HDs to display.
   * @return array
   */
  function hdsWithUsers(&$users,$selectedHd): array
  {
    $hdOptions = $hdUsers = [];
    foreach ($users as $uid => $user) {
      if(!empty($user['hd'])) {
        if($selectedHd=='All' OR $selectedHd==$user['hd']) {
          $hdOptions[$user['hd']] = $user['hd'];
          $hdUsers[$uid] = $user;
        }
      }
    }
    $users = $hdUsers;
    $hdOptions = array_values($hdOptions);
    asort($hdOptions);
    return $hdOptions;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlp_remindUserCredentials
   *
   * Email the specified user with their login credentials.
   *
   * @param $user - The Drupal user array.
   * @return bool
   */
  function remindUserCredentials($user): bool
  {
    $messenger = Drupal::messenger();

    $today = date("F j, Y, g:i a");
    $subject = 'Neighborhood Leader account login reminder'.' - '.$today;

    $messageTemplate = "<p>@nickname,</p>".
      "<p>The following information provides the credentials you need to access NLP Services for @county County</p>".
      '@nlpLogin'.
      '<p>Please contact me if you have any questions.<br>Thanks<br></p>'.
      '@coordinatorContactInfo';

    $currentUser = $this->drupalUser->getCurrentUser();
    $adminContact = [
      'firstName' => $currentUser['firstName'],
      'lastName' => $currentUser['lastName'],
      'phone' => $currentUser['phone'],
      'email' => $currentUser['email'],
    ];

    $result = $this->credentialsEmailObj->sendCredentialsEmail(
      (int) $user['mcid'], $messageTemplate, '', [], $adminContact, 'account_reminder', $subject
    );
    if (!$result) {
      $messenger->addError(t('There was a problem sending your message and it was not sent.'));
      return FALSE;
    }
    return TRUE;
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
