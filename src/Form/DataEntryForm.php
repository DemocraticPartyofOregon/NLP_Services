<?php /** @noinspection PhpMissingFieldTypeInspection */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpDataEntryVoters;
use Drupal\nlpservices\NlpDataEntryDateBar;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryVoterListDisplay;
use Drupal\nlpservices\NlpDataEntryReports;
use Drupal\nlpservices\ApiMapRegions;

/**
 * @noinspection PhpUnused
 */
class DataEntryForm extends FormBase
{
  protected NlpNls $nlsObj;
  protected NlpVoters $votersObj;
  protected NlpTurfs $turfsObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpReports $reportsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpDataEntryVoters $dataEntryVotersObj;
  protected NlpDataEntryDateBar $dataEntryDateBarObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpDataEntryVoterListDisplay $dataEntryVoterListDisplayObj;
  protected NlpDataEntryReports $dataEntryReportsObj;
  protected ApiMapRegions $mapRegionsObj;

  public function __construct(  $nlsObj, $votersObj, $turfsObj, $sessionDataObj ,
                                $reportsObj, $drupalUserObj, $dataEntryVotersObj,
                                $dataEntryDateBarObj, $nlpConfigurationObj,
                                $dataEntryVoterListDisplayObj, $dataEntryReportsObj,
                                $mapRegionsObj ) {
    $this->nlsObj = $nlsObj;
    $this->votersObj = $votersObj;
    $this->turfsObj = $turfsObj;
    $this->sessionDataObj = $sessionDataObj;

    $this->reportsObj = $reportsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->dataEntryVotersObj = $dataEntryVotersObj;

    $this->dataEntryDateBarObj = $dataEntryDateBarObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->dataEntryVoterListDisplayObj = $dataEntryVoterListDisplayObj;
    $this->dataEntryReportsObj = $dataEntryReportsObj;
    $this->mapRegionsObj = $mapRegionsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DataEntryForm
  {
    return new static(
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.session_data'),

      $container->get('nlpservices.reports'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.data_entry_voters'),

      $container->get('nlpservices.data_entry_date_bar'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.data_entry_voter_list_display'),
      $container->get('nlpservices.data_entry_reports'),
      $container->get('nlpservices.api_map_regions')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_data_entry_form';
  }

  const int DE_PAGE_SIZE = 10;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attached']['library'][] = 'nlpservices/data-entry-table';
    $form['#attached']['library'][] = 'nlpservices/button';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';

    if(!$form_state->get('reenter')) {
      $this->sessionDataObj->set('currentPage', 0);
      $canvassDate = date('Y-m-d',time());  // Today.
      $this->sessionDataObj->set('canvassDate', $canvassDate);
      $form_state->set('page','voters');
    }

    // A contact method change rebuilds the form in the middle of its AJAX
    // request. Rebuild the same turf, page and voters this form last showed
    // rather than re-reading them from the session and database, which another
    // browser tab or a turf update may have changed since, so the reply
    // replaces the table with the rows the user was looking at.
    $contactMethodRebuild = (bool) $form_state->get('contactMethodRebuild');
    $form_state->set('contactMethodRebuild', FALSE);
    $postedVanids = [];

    if($contactMethodRebuild) {
      $turfIndex = $form_state->get('turfIndex');
      $voters = $form_state->get('voters') ?? [];
      $currentPage = $form_state->get('currentPage');
    } else {
      if(!$this->initializeDataReporting($form_state)) {
        return $form;
      }
      $turfIndex = $this->sessionDataObj->get('turfIndex');
      $form_state->set('turfIndex',$turfIndex);
      if(!$form_state->get('reenter')) {
        $this->updateMinivanInfo($turfIndex);
      }

      $page = $form_state->get('page');
      switch ($page) {
        case 'unreportedChanges':
          $form['unreportedChanges'] = $this->unreportedChangesDisplay($turfIndex);
          return $form;
        case 'postcardsSent':
          $canvassDate = $this->sessionDataObj->get('canvassDate') ?? '';
          $form['postcardsList'] = $this->displayVoterList($turfIndex, $canvassDate);
          return $form;
      }

      if($form_state->get('unreportedChanges')) {
        $form['unreportedChanges'] = $this->unreportedChangesDisplay($turfIndex);
        return $form;
      }

      $voters = $this->dataEntryVotersObj->fetchVoters($turfIndex);
      $currentPage = $this->sessionDataObj->get('currentPage');

      // Drupal keeps a copy of the form it showed for six hours. Once that has
      // expired, a submitted form is built here from scratch as if the page were
      // new, which would show page 1 and throw away what the user entered.
      // Rebuild the rows the browser posted instead, so a save, page change or
      // contact method change gets the user's entries.
      if(!$form_state->get('reenter')) {
        $postedVanids = $this->expiredFormVanids($form_state, $voters);
        if(!empty($postedVanids)) {
          Drupal::logger('nlpservices')->notice('Rebuilt the posted rows of an expired data entry form.');
          $voterPosition = array_search(reset($postedVanids), array_keys($voters));
          $currentPage = intdiv($voterPosition, $this::DE_PAGE_SIZE);
          $this->sessionDataObj->set('currentPage', $currentPage);
        }
      }
      $form_state->set('voters', $voters);
      $form_state->set('currentPage', $currentPage);
    }

    $canvassDate = $this->sessionDataObj->get('canvassDate');
    $canvassDate   = (empty($canvassDate))?'':$canvassDate;
    $mcid = $form_state->get('mcid');

    $form_state->set('defaultValues',NULL);

    $pageCount = (int) ceil(count($voters)/$this::DE_PAGE_SIZE);

    $contactMethods = $this->nlpConfigurationObj->getContactMethods();
    $defaultVoterContactMethod = $this->sessionDataObj->get('defaultVoterContactMethod');
    $preferredContactMethod = 0;
    if(!empty($defaultVoterContactMethod)) {
      $preferredContactMethod = array_search($defaultVoterContactMethod, $contactMethods);
    }
    $this->sessionDataObj->set('preferredContactMethod',$preferredContactMethod);

    $form['dateBar'] = $this->dataEntryDateBarObj->createDateBar(
             $canvassDate, $turfIndex, $mcid, $currentPage, $pageCount, $voters);

    $vanidKeys = array_keys($voters);
    $start = $currentPage * $this::DE_PAGE_SIZE;
    $end = min(($start + $this::DE_PAGE_SIZE), count($vanidKeys));

    $shownVanids = $postedVanids;
    if(empty($shownVanids)) {
      $shownVanids = array_slice($vanidKeys, $start, $end - $start);
    }
    $voterArrays = [];
    foreach ($shownVanids as $vanid) {
      $voter = $voters[$vanid];
      $voterArray = $this->dataEntryVoterListDisplayObj->buildDataEntryVoterArray($voter);
      $voterArrays[$voter['vanid']] = $voterArray;
    }

    $form['dataEntryTable'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="data-entry-div">',
      '#suffix' => '</div>',
    ];

    $form_state->set('voterArrays', $voterArrays);
    $form_state->setValue('dataEntry', NULL);
    $user_input = $form_state->getUserInput() ?? [];
    $contactMethodVanid = $this->contactMethodChangeVanid($form_state);
    if ($contactMethodVanid !== NULL) {
      // A contact method change only redraws the table so the voter gets that
      // method's "Voter Wasn't Contacted" choices.  Keep what was entered for
      // every voter, except that voter's old not-contacted pick.
      unset($user_input['dataEntry']['row_2_' . $contactMethodVanid]['col_2_4']['line_2_4_2']);
    } elseif(empty($postedVanids)) {
      // After a save or page change, show the table fresh from the database.
      unset($user_input['dataEntry']);
    }
    $form_state->setUserInput($user_input);

    $form['dataEntryTable']['voters'] = $this->dataEntryVoterListDisplayObj->buildVoterDataEntryTable($voterArrays);

    $form['navigate'] = $this->dataEntryDateBarObj->navigate($pageCount,$currentPage);

    // A right-aligned Save reports bar at the end of the form that sticks to the
    // bottom of the window while the voters scroll but stays within the form (it
    // never covers the footer). It stays hidden until unsaved-changes.js sees an
    // edit, so it doesn't clutter a page that has nothing to save.
    $form['save-floating'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['nlp-floating-save']],
      'save' => [
        '#type'  => 'submit',
        '#value' => t('Save reports'),
        '#name'  => 'save_reports',
      ],
    ];
    $form['#attached']['library'][] = 'nlpservices/unsaved-changes';
    $form['#attributes']['class'][] = 'js-form-changed';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {

    // Drupal answers an AJAX request from its cached copy of the form, which
    // expires after six hours. Once it has, buildForm() rebuilds the rows the
    // browser posted, but a voter who has since left the turf gets no row, so
    // Drupal can't find the changed select and falls back to the first button,
    // last_name_search. That button has no #ajax callback, so Drupal throws
    // "The specified #ajax callback is empty or not callable." Skip the submit
    // and tell the user to reload the page instead.
    $userInput = $form_state->getUserInput();
    $ajaxElementName = $userInput['_triggering_element_name'] ?? '';
    $elementClicked = $form_state->getTriggeringElement()['#name'] ?? '';
    if(str_contains($ajaxElementName, 'line_2_2_2') AND !str_contains($elementClicked, 'line_2_2_2')) {
      Drupal::logger('nlpservices')->notice('Data entry form expired before a contact method change: '.$ajaxElementName);
      $form_state->set('formExpired', TRUE);
      $form_state->setTriggeringElement(['#ajax' => ['callback' => '::expiredFormCallback']]);
      return;
    }

    $vanid = $this->contactMethodChangeVanid($form_state);
    if($vanid !== NULL) {

      $form_state->setRebuild();
      $form_state->set('reenter',TRUE);
      $form_state->set('contactMethodRebuild',TRUE);

      $chosenMethodCode = $form_state->getTriggeringElement()['#value'];
      $contactMethods = $this->nlpConfigurationObj->getContactMethods();

      $values =
        array (
          'vanid' => $vanid,
          'name' => $contactMethods[$chosenMethodCode],
          'code' => $chosenMethodCode,
        );
      $this->sessionDataObj->setVoterContactMethod($values);

    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    if($form_state->get('formExpired')) {
      return;
    }
    $form_state->setRebuild();
    $form_state->set('reenter',TRUE);
    $messenger = Drupal::messenger();

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    $values = $form_state->getValues();
    $newCanvassDate = (!empty($values['canvassDate']))?$values['canvassDate']:'';

    $canvassDate = $this->sessionDataObj->get('canvassDate') ?? '';
    if(!empty($newCanvassDate) AND $canvassDate != $newCanvassDate) {
      $this->sessionDataObj->set('canvassDate',$newCanvassDate);
      $canvassDate = $newCanvassDate;
    }
    switch ($elementClicked) {
      case 'acknowledgeUnreportedChanges':
        $form_state->set('page','unreportedChanges');
        $turfIndex = $form_state->get('turfIndex');
        $newTurf = [
          'unreportedChanges' => 0,
        ];
        $this->turfsObj->updateTurfFields($turfIndex, $newTurf);
        $messenger->addStatus('Thanks for acknowledging the changes to your turf.  Now click the
        My turf tab to see your turf.');
        return;

      case 'postcardSubmit':
        $form_state->set('page','postcardsSent');
        return;

      case 'postcardSelect':
        $form_state->set('page','voters');
        $postcardsSent = $form_state->getValue('postcardsSentList');
        $this->processPostcardsSent($form_state->get('turfIndex'),$postcardsSent,$form_state->get('mcid'),$canvassDate);
        return;
    }

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    $navButtonParts = explode('-',$elementClicked);
    switch ($navButtonParts[0]) {

      case 'save_reports':
        break;
      case 'pageSelect':
        $NewPage = $navButtonParts[1]-1;
        $this->sessionDataObj->set('currentPage', $NewPage);
        break;
      case 'next':
        $this->sessionDataObj->set('currentPage', 6);
        break;
      case 'previous':
        $this->sessionDataObj->set('currentPage', 5);
        break;
      case 'last_name_search':
        $turfIndex = $form_state->get('turfIndex');
        $lastName = $values['last-name'];
        $searchResult = $this->dataEntryVotersObj->voterSearch($turfIndex,$lastName,$this::DE_PAGE_SIZE);
        if($searchResult['found']) {
          $this->sessionDataObj->set('currentPage', $searchResult['page']);
        }
        break;
    }

    $voterArrays = $form_state->get('voterArrays' );
    // A data entry table with no voter rows submits as '' rather than an array.
    $dataEntry = $form_state->getValue('dataEntry');
    if (!is_array($dataEntry)) {
      $dataEntry = [];
    }
    $selections = $this->dataEntryVoterListDisplayObj->validateDataEntrySelection($dataEntry);
    $actions = $this->dataEntryVoterListDisplayObj->whatChanged($selections,$voterArrays);

    $mcid = $form_state->get('mcid');
    $nlsInfo = $this->nlsObj->getNlById($mcid);

    $county = $this->sessionDataObj->getCounty();
    // Report with the date of the voter contact the NL chose, or today if none.
    if (empty($canvassDate)) {
      $canvassDate = date('Y-m-d');
    }
    $voterArrays = $form_state->get('voterArrays');

    foreach ($actions as $vanid => $actionSet) {
      foreach ($actionSet as $action) {
        $report = [
          'vanid' => $vanid,
          'mcid' => $mcid,
          'county' => $county,
          'cd' => $voterArrays[$vanid]['cd'],
          'contactDate' => $canvassDate,
          'firstName' => $nlsInfo['firstName'],
          'lastName' => $nlsInfo['lastName'],
          'nickname' => $nlsInfo['nickname'],
          'qid' => 0,
          'contactId' => 0,
        ];
        $contactMethods = $this->nlpConfigurationObj->getContactMethods();

        $type = $action['type'];
        switch ($type) {
          case 'surveyResponse':
            $contactMethodId = $action['contactMethod'];
            $contactMethod = $contactMethods[$contactMethodId];
            $report['contactMethod'] = $contactMethod;
            if ($contactMethod == 'Postcard') {
              $voterName = $voterArrays[$vanid]['firstName'] . ' ' . $voterArrays[$vanid]['lastName'];
              $messenger->addWarning('If you received an answer from ' . $voterName
                . ' please change the contact method to something other than postcard.');
              break;
            }
            $action['turfIndex'] = $form_state->get('turfIndex');
            $action['firstName'] = $voterArrays[$vanid]['firstName'];
            $this->dataEntryReportsObj->pledgeToVote($report, $action);
            break;
          case 'notContacted':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $this->dataEntryReportsObj->noContactResponse($report,$action);
            break;
          case 'moved':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $this->dataEntryReportsObj->voterMoved($report,$action);
            break;
          case 'hostile':
            $this->dataEntryReportsObj->hostileVoter($report, $action);
            break;
          case 'deceased':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $this->dataEntryReportsObj->deceasedVoter($report, $action);
            break;
          case 'updateContact':
            $action['firstName'] = $voterArrays[$vanid]['firstName'];
            $action['lastName'] = $voterArrays[$vanid]['lastName'];

            $updateContact = $action['updateContact']['contactChange'];
            switch ($updateContact) {
              case 'BC':  // Bad cell number.
                $action['phoneId'] = $voterArrays[$vanid]['cellPhoneId'];
                $action['phoneNumber'] = $voterArrays[$vanid]['cellPhone'];
                $action['vanid'] = $vanid;
                $this->dataEntryReportsObj->badPhone('cellPhone',$report,$action);
                break;
              case 'BH': // Bad home number.
                $action['phoneId'] = $voterArrays[$vanid]['homePhoneId'];
                $action['phoneNumber'] = $voterArrays[$vanid]['homePhone'];
                $action['vanid'] = $vanid;
                $this->dataEntryReportsObj->badPhone('homePhone',$report,$action);
                break;
              case 'OO':  // Opt out of texting
                if(empty($action['cellPhone'])) {
                  $msg = var_export($action, true);
                  Drupal::logger('nlpservices')->notice('Cell phone number is missing: '.$msg);
                  break;
                }
                $cellPhone = $action['cellPhone'];
                $report['contactType'] = 'Phone';
                $report['text'] = $cellPhone;
                $report['value'] = 'Opt out of texting: ';
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);
                break;
              case 'NC': // New cell number.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $cellPhone = $action['updateContact']['newContact'];
                if(empty($cellPhone))  {
                  $messenger->addWarning('You must enter a new cell phone, Voter is '.$voterName);
                  break;
                }
                if(!$this->validatePhoneNumber($cellPhone)) {
                  $messenger->addWarning('You must enter a valid cell phone number , Voter is '.$voterName);
                  break;
                }
                $report['value'] = 'New cell number';
                $report['text'] = $cellPhone;
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);

                $newPhoneNumber = [
                  'cellPhone' => $cellPhone,
                  'cellPhoneId' => NULL
                ];
                $this->votersObj->updateVoterPhone($vanid,$newPhoneNumber);
                break;
              case 'NH': // New home number.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $homePhone = $action['updateContact']['newContact'];
                if(empty($homePhone))  {
                  $messenger->addWarning('You must enter a new home phone, Voter is '.$voterName);
                  break;
                }
                if(!$this->validatePhoneNumber($homePhone)) {
                  $messenger->addWarning('You must enter a valid home phone number , Voter is '.$voterName);
                  break;
                }
                $report['value'] = 'New home number';
                $report['text'] = $homePhone;
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);

                $newPhoneNumber = [
                  'homePhone' => $homePhone,
                  'homePhoneId' => NULL
                ];
                $this->votersObj->updateVoterPhone($vanid,$newPhoneNumber);

                break;

              case 'NE': // New email.
                $messenger = Drupal::messenger();
                $email = $action['updateContact']['newContact'];                $voterName = $action['firstName'] . ' ' . $action['lastName'];

                if(empty($email))  {
                  $messenger->addWarning('You must enter a new email, Voter is '.$voterName);
                  break;
                }
                if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                  $messenger->addWarning('You must enter a valid email address , Voter is '.$voterName);
                  break;
                }

                $report['contactType'] = 'Walk';
                $report['value'] = 'New email';
                $report['text'] = $email;
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);
                break;

              case 'LS': // Wants lawn sign.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $candidates = $action['updateContact']['newContact'];
                if(empty($candidates)) {
                  $messenger->addWarning(t('You must enter candidate name(s) for the lawn sign request, Voter is :name', [':name' => $voterName]));
                  break;
                }
                $report['contactType'] = 'Walk';
                $report['value'] = 'Wants lawn sign';
                $report['text'] = $candidates;
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);
                break;

              case 'RTP': // Needs a ride to polls.
                $report['contactType'] = 'Walk';
                $report['value'] = 'Needs ride to polls';
                $report['text'] = '';
                $report['cycle'] = $this->nlpConfigurationObj->getCycle();
                $report['type'] = 'Change';
                $this->reportsObj->setNlReport($report);
                break;
            }
            break;
          case 'note':
            $action['reportIndex'] = $voterArrays[$vanid]['reportIndex'];
            $this->dataEntryReportsObj->addNote($report, $action);
            break;
        }
      }
    }
  }

  /**
   * unreportedChangesDisplay
   *
   * Returns form elements for displaying the changes made to the voters in the
   * turf by the resyncing process.  The changes are likely because of an
   * update of the VoterFile with registration changes.  However, it could
   * be the result of changes to the turf by a coordinator.
   *
   * @param int $turfIndex
   * @return array
   */
  public function unreportedChangesDisplay(int $turfIndex): array
  {
    $changeHistory = $this->turfsObj->getChangeHistory($turfIndex);

    $form['changesTitle'] = [
      '#type' => 'markup',
      '#markup' => "<p>
The list below reveals the voters added to or deleted from your turf since it was created </br>
for you.   If you were provided a PDF walksheet or are using the MiniVAN app, those lists will be </br>
different.  Please review the changes before you attempt voter contact.</p>",
    ];

    $changeDisplay = '';
    foreach ($changeHistory as $date=>$change) {
      $changeDisplay .= "<h4><b>Date changes were made: $date</b></h4>";
      foreach ($change as $changeType=>$changes) {
        if($changeType == 'date') {continue;}
        if(empty($changes)) {continue;}
        $changeDisplay .= "<h4><u>$changeType</u></h4>";
        $changeDisplay .= "<ul>";
        foreach ($changes as $vanid=>$voterName) {
          $changeDisplay .= "<li>vanid:$vanid, Voter name: $voterName</li>";
        }
        $changeDisplay .= "</ul>";
      }
    }

    $form['changesDisplay'] = [
      '#type' => 'markup',
      '#markup' => $changeDisplay,
    ];

    $form['changesAcknowledgementRequest'] = [
      '#type' => 'markup',
      '#markup' => '<p>
Please acknowledge that you have reviewed these changes.</p>',
    ];

    $form['acknowledgeUnreportedChanges'] = [
      '#type' => 'submit',
      '#name' => 'acknowledgeUnreportedChanges',
      '#value' => 'Acknowledged',
    ];
    return $form;
  }

  /**
   * displayVoterList
   *
   * Returns form elements for displaying the voters in the turf for reporting
   * that a postcard was sent to them.
   * 1. The voters are listed by last name, first name.
   * 2. The voters have a checkbox to indicate a postcard was sent to them.
   * 3. A submit form element button is provided to report changes.
   *
   * @param int $turfIndex
   * @param string $canvassDate - the date of the voter contact chosen so far.
   * @return array
   */
  private function displayVoterList(int $turfIndex, string $canvassDate = ''): array
  {
    $voters = $this->dataEntryVotersObj->fetchVoters($turfIndex);
    $voterDisplay = $postcardsSent = [];
    foreach ($voters as $vanid => $voter) {
      $postcardSent = $this->reportsObj->voterSentPostcard($vanid);
      if(!$postcardSent) {
        $postcardsSent[] = $vanid;
      }
      $voterDisplay[$vanid] = $voter['lastName'].', '.$voter['firstName'];
    }

    $form['postcardNote'] = [
      '#markup' => '<div class="form-box-medium">
<h2>Report the mailing of postcards</h2>
<p>The list of the voters below can be selected to indicate that a postcard
was sent to them.  Check the box for those that you mailed the postcard.  If
the voter has not already received a postcard, the box will be checked.   Those
not checked have already received a postcard for this election.</p></div>',
    ];
    // The same date entry as the data entry page's date bar, so the NL can set
    // the mailing date here without going back.
    $form['canvassDate'] = [
      '#type' => 'date',
      '#title' => t('Date cards were mailed'),
      '#prefix' => '<div class="canvass_date">',
      '#suffix' => '</div>',
    ];
    if(!empty($canvassDate)) {
      $form['canvassDate']['#default_value'] = $canvassDate;
    }
    $form['postcardsSentList'] = [
      '#type' => 'checkboxes',
      '#options' => $voterDisplay,
      '#default_value' => $postcardsSent,
      '#title' => t('Check each voter that you want to report that you sent a postcard.'),
    ];

    $form['postcardSelect'] = [
      '#type' => 'submit',
      '#name' => 'postcardSelect',
      '#value' => 'Submit',
    ];

    return $form;
  }

  /**
   * processPostcardsSent
   *
   * Processes the form submission to report that postcards were sent to the
   * voters selected in the voter list.  The voters are identified by their
   * vanid.  The postcards are identified by the mcid of the MC that sent them.
   *
   * @param int $turfIndex
   * @param array $voters
   * @param int $mcid
   * @param string $canvassDate
   */
  private function processPostcardsSent(int $turfIndex, array $voters,
    int $mcid, string $canvassDate): void
  {
    $nls = $this->nlsObj->getNlById($mcid);
    $county = $this->sessionDataObj->getCounty();
    $contactMethodName = 'Postcard';

    $rid =$this->nlpConfigurationObj->getResponseId('Postcard','Mailed');
    $cid = $this->nlpConfigurationObj->getContactId('Postcard');
    $action = [
      'contactMethod' => $cid,
      'notContacted' => $rid,
      'turfIndex' => $turfIndex,
    ];

    $report  = [
      'mcid' => $mcid,
      'county' => $county,
      'turfIndex' => $turfIndex,
      'contactMethod' => ['name'=>$contactMethodName,'code'=>$cid],
      'contactDate' => $canvassDate,
      'cid' => $cid,
      'rid' => $rid,
      'contactType' => $contactMethodName,
      'responseName' => 'Mailed',
      'nickname' => $nls['nickname'],
    ];
    foreach ($voters as $vanid => $selected) {
      if(empty($selected)) {continue;}  // Card already reported as sent.
      $report['vanid'] = $vanid;
      $this->dataEntryReportsObj->noContactResponse($report,$action);
    }

  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * initializeDataReporting
   *
   * @param FormStateInterface $form_state
   * @return bool
   */
  function initializeDataReporting(FormStateInterface $form_state): bool
  {
    $messenger = Drupal::messenger();

    $county = $this->sessionDataObj->getCounty();
    $form_state->set('county',$county);

    $hdOptions = $this->turfsObj->getTurfHD($county); // HDs with turfs.
    $this->sessionDataObj->setNewDistricts($hdOptions);

    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    if(empty($mcid)) {
      $messenger->addWarning('The MCID is missing from your user login, contact your coordinator.');
      return FALSE;
    }
    $form_state->set('mcid', $mcid);
    $turfIndex = $this->sessionDataObj->get('turfIndex');

    //Do we have a turf?
    $turfArray = $this->turfsObj->turfExists($mcid,$county);
    $form_state->set('turfArray', $turfArray);
    if (empty($turfArray)) {
      $messenger->addWarning("You do not have a turf assigned");
      return FALSE;
    }
    // Select a turf.
    $turfs = $turfArray['turfs'];
    if(empty($turfIndex) OR empty($turfs[$turfIndex])) {
      reset($turfs);
      $turfIndex = key($turfs);
      $this->sessionDataObj->set('turfIndex',$turfIndex);
    }
    $form_state->set('turfIndex', $turfIndex);
    // flag if turf has changes from an update of VoteBuilder with new voter
    // registrations and the user has not yet acknowledged the changes.
    $form_state->set('unreportedChanges',$this->turfsObj->unreportedChanges($turfIndex));

    // Verify we know this NL.
    $nlsInfo = $this->nlsObj->getNlById($mcid);
    // Stop if we don't have this person in the database.
    if (empty($nlsInfo)) {
      $messenger->addWarning('You are not in the list of active Neighborhood
                   Leaders, contact your coordinator.');
      return FALSE;
    }

    // For the display of voters for the contact report, what page are we on?
    $currentPage = $this->sessionDataObj->get('currentPage');
    if(empty($currentPage)) {
      $this->sessionDataObj->set('currentPage', 0);
    }
    $this->sessionDataObj->set('changePage', FALSE);
    return TRUE;
  }

  /**
   * validatePhoneNumber
   *
   * Returns true if the phone number is in a valid format.
   *
   * @param string $phone
   * @return false|int
   */
  function validatePhoneNumber(string $phone): false|int
  {
    // Comprehensive phone number validation
    return preg_match('/^\+?(\d{1,4})?[-.\s]?(\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4})$/', $phone);
  }

  /**
   * expiredFormVanids
   *
   * Returns the vanids of the voter rows a submitted data entry form posted,
   * in the order it showed them, keeping only voters still in the turf, or []
   * if the request posted no voter rows.  The rows are posted as
   * dataEntry[row_<n>_<vanid>][...].
   *
   * @param FormStateInterface $form_state
   * @param array $voters
   *   The turf's voters, keyed by vanid.
   * @return int[]
   */
  private function expiredFormVanids(FormStateInterface $form_state, array $voters): array
  {
    $userInput = $form_state->getUserInput();
    if(($userInput['form_id'] ?? '') != $this->getFormId() OR !is_array($userInput['dataEntry'] ?? NULL)) {
      return [];
    }
    $postedVanids = [];
    foreach (array_keys($userInput['dataEntry']) as $rowKey) {
      if(preg_match('/^row_\d+_(\d+)$/', $rowKey, $matches)) {
        $postedVanids[(int) $matches[1]] = (int) $matches[1];
      }
    }
    $vanids = array_values(array_filter($postedVanids,
      static fn(int $vanid): bool => isset($voters[$vanid])));
    if(count($vanids) < count($postedVanids)) {
      Drupal::messenger()->addWarning('Some voters on the page you submitted are no
      longer in your turf, so what you entered for them was not saved.');
    }
    return $vanids;
  }

  /**
   * contactMethodChangeVanid
   *
   * Returns the vanid of the voter whose contact method dropdown triggered
   * this request, or NULL if something else did.  The dropdown's name looks
   * like dataEntry[row_2_<vanid>][col_2_2][line_2_2_2].
   *
   * @param FormStateInterface $form_state
   * @return string|null
   */
  private function contactMethodChangeVanid(FormStateInterface $form_state): ?string
  {
    $elementClicked = $form_state->getTriggeringElement()['#name'] ?? '';
    if(!str_contains($elementClicked, 'line_2_2_2')) {
      return NULL;
    }
    $rowPos = strpos($elementClicked,'row_2_');
    $vanidEnd = strpos($elementClicked,']',$rowPos+6);
    return substr($elementClicked,$rowPos+6,$vanidEnd-$rowPos-6);
  }

  /**
   * updateMinivanInfo
   *
   * Given the turfIndex, the latest minivan information is retrieved from
   * VoteBuilder and updated in the turf record.   The information includes the
   * newest minivan number and the date it was created.  If the turf has expired,
   * the VoteBuilder returns empty values.
   *
   * @param int $turfIndex
   * @return void
   */
  function updateMinivanInfo(int $turfIndex): void
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    $county = $this->sessionDataObj->getCounty();
    $turfArray[$turf['turfIndex']] = $turf;
    $this->mapRegionsObj->updateMinivanInfo($county, $turfArray);

    $turf = $turfArray[$turfIndex];
    if(!empty($turf['minivanNumber'])) {
      $minivanNumber = $turf['minivanNumber'];
      $minivanCreateDate = $turf['minivanCreateDate'];
      $createDate = date('Y-m-d',strtotime($minivanCreateDate));
      $fields = [
        'minivanNumber' => $minivanNumber,
        'minivanCreateDate' => $createDate,
      ];
      $this->turfsObj->updateTurfFields($turfIndex, $fields);
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * formCallback
   *
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  function formCallback($form, FormStateInterface $form_state) {
    return $form['dataEntryTable'];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * formCallback
   *
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  function selectionCallback($form, FormStateInterface $form_state) {
    return $form['dataEntryTable'];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * expiredFormCallback
   *
   * Answers a contact method change that reached an expired form.
   *
   * @noinspection PhpUnused
   */
  function expiredFormCallback(): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new MessageCommand(
      'This page has been open too long and has expired. Reload the page, then
      change the contact method again.', NULL, ['type' => 'warning']));
    return $response;
  }

}
