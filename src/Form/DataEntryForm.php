<?php /** @noinspection PhpMissingFieldTypeInspection */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
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
use Drupal\nlpservices\NlpImpersonation;
use Drupal\nlpservices\NlpContactDate;

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
  protected LockBackendInterface $lock;
  protected KeyValueExpirableFactoryInterface $keyValueExpirable;

  public function __construct(
    NlpNls $nlsObj,
    NlpVoters $votersObj,
    NlpTurfs $turfsObj,
    NlpSessionData $sessionDataObj,
    NlpReports $reportsObj,
    DrupalUser $drupalUserObj,
    NlpDataEntryVoters $dataEntryVotersObj,
    NlpDataEntryDateBar $dataEntryDateBarObj,
    NlpConfiguration $nlpConfigurationObj,
    NlpDataEntryVoterListDisplay $dataEntryVoterListDisplayObj,
    NlpDataEntryReports $dataEntryReportsObj,
    ApiMapRegions $mapRegionsObj,
    LockBackendInterface $lock,
    KeyValueExpirableFactoryInterface $keyValueExpirable,
    // Not private or readonly: Drupal caches the form, and its
    // DependencySerializationTrait::__wakeup() (in FormBase's scope) must be
    // able to put the service back when the form is unserialized.
    protected NlpImpersonation $impersonationObj,
  )
  {
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
    $this->lock = $lock;
    $this->keyValueExpirable = $keyValueExpirable;
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
      $container->get('nlpservices.api_map_regions'),
      $container->get('lock'),
      $container->get('keyvalue.expirable'),
      $container->get('nlpservices.impersonation'),
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
  const string NL_MISSING_MSG = 'Your reports were not saved because your
    Neighborhood Leader record was not found.  If the NL list is being
    updated, wait a few minutes and try again.  Otherwise, contact your
    coordinator.';
  const string ALREADY_SAVED_MSG = 'Your reports were saved.  The page was
    submitted twice, so the second copy was ignored.';
  const string SAVE_IN_PROGRESS_MSG = 'Your reports are still being saved.
    Wait a minute, then check your turf before entering them again.';

  // A save result for a report deleted or corrected in the Edit list (the
  // others are NlpDataEntryReports::SAVED, DUPLICATE and NOT_SAVED).
  const string CHANGED = 'changed';

  // Key-value collection that remembers which submissions have been saved.
  const string SAVED_SUBMISSIONS = 'nlpservices.data_entry_saved';
  // How long a duplicate of a saved submission is recognized.
  const int SAVED_SUBMISSION_SECS = 3600;
  // How long one copy of a submission may hold the save lock.
  const int SAVE_LOCK_SECS = 60;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attached']['library'][] = 'nlpservices/data-entry-table';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
    // Core's single-submit behavior ignores a second click of Submit.
    $form['#attached']['library'][] = 'core/drupal.form';

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
    $impersonating = $this->impersonationObj->isImpersonating();
    foreach ($shownVanids as $vanid) {
      $voter = $voters[$vanid];
      $voterArray = $this->dataEntryVoterListDisplayObj->buildDataEntryVoterArray(
        $voter, (int) $mcid, $impersonating);
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

    $form['dataEntryTable']['voters'] = $this->dataEntryVoterListDisplayObj->buildVoterDataEntryTable(
      $voterArrays, $form_state->get('saveResults') ?? []);

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

    // The date of the voter contact can't be in the future.  With the error,
    // nothing is saved and the page shows again with what was entered.
    // (A contact method change saves nothing, so it isn't held up.)
    $dateProblem = NlpContactDate::problem((string)($form_state->getValue('canvassDate') ?? ''));
    if ($dateProblem !== '' && $this->contactMethodChangeVanid($form_state) === NULL) {
      $form_state->setErrorByName('canvassDate', $dateProblem . '  Your reports were not saved; fix the date and save again.');
      return;
    }

    $vanid = $this->contactMethodChangeVanid($form_state);
    if($vanid !== NULL) {

      // Nothing is saved; don't show the last save's results again.
      $form_state->set('saveResults', []);
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
    // What became of each voter's reports, shown on the voter when the page is
    // rebuilt so volunteers can see their reports were saved.
    $this->saveResults = [];
    $this->voterNames = [];
    $this->saveSubmission($form_state);
    $form_state->set('saveResults', $this->saveResults);
    $this->summarizeSaveResults($form_state->get('voterArrays') ?? []);
  }

  /**
   * What became of each voter's reports in this submission, keyed by vanid:
   * a list of ['status' => NlpDataEntryReports::SAVED|DUPLICATE|NOT_SAVED,
   * 'text' => what was reported].
   */
  private array $saveResults = [];

  /**
   * noteResult
   *
   * Remember what became of one of a voter's reports.
   *
   * @param int|string $vanid
   * @param string $status - NlpDataEntryReports::SAVED, DUPLICATE or NOT_SAVED.
   * @param string $text - what was reported, e.g. "Phone: Left message".
   * @return void
   */
  private function noteResult(int|string $vanid, string $status, string $text): void
  {
    $this->saveResults[$vanid][] = ['status' => $status, 'text' => $text];
  }

  /**
   * The names of the voters with save results, keyed by vanid, for voters
   * who aren't among the page's voterArrays (e.g. the postcard list).
   */
  private array $voterNames = [];

  /**
   * summarizeSaveResults
   *
   * Messages at the top of the page naming the voters whose reports were
   * saved, and, separately, the voters whose reports were skipped as already
   * reported (the same report for the same date, or a second postcard this
   * cycle), with what was skipped.  The page can show up to 10 voters, and
   * after Next page the voters saved for aren't on the page at all, so the
   * names say whose reports these were.
   *
   * @param array $voterArrays - the voters the page showed, keyed by vanid.
   * @return void
   */
  private function summarizeSaveResults(array $voterArrays): void
  {
    $saved = $skipped = [];
    foreach ($this->saveResults as $vanid => $results) {
      $name = $this->voterNames[$vanid] ?? trim(($voterArrays[$vanid]['firstName'] ?? '')
        . ' ' . ($voterArrays[$vanid]['lastName'] ?? ''));
      if ($name === '') {
        $name = 'Voter ' . $vanid;
      }
      foreach ($results as $result) {
        if ($result['status'] === NlpDataEntryReports::SAVED) {
          $saved[$vanid] = $name;
        } elseif ($result['status'] === NlpDataEntryReports::DUPLICATE) {
          $skipped[$vanid][] = $result['text'] . ', already saved for that date';
        } elseif ($result['status'] === NlpDataEntryReports::ALREADY_MAILED) {
          $skipped[$vanid][] = 'a postcard was already reported this election';
        }
      }
      if (isset($skipped[$vanid])) {
        $skipped[$vanid] = $name . ' (' . implode('; ', $skipped[$vanid]) . ')';
      }
    }
    $messenger = Drupal::messenger();
    if (!empty($saved)) {
      $messenger->addStatus($this->t('Your reports were saved for @names.  Thank you!',
        ['@names' => $this->listNames($saved)]));
    }
    if (!empty($skipped)) {
      $messenger->addStatus($this->t('Already reported, so not saved again: @names.',
        ['@names' => implode(', ', $skipped)]));
    }
    // A reminder to report right after the contact, for reports saved late.
    if (!empty($saved)
      && NlpContactDate::isLate((string)($this->sessionDataObj->get('canvassDate') ?? ''))) {
      $messenger->addWarning(NlpContactDate::LATE_MSG);
    }
  }

  /**
   * "Ann Lee", "Ann Lee and Bob Smith", or "Ann Lee, Bob Smith and Carl Diaz".
   */
  private function listNames(array $names): string
  {
    $names = array_values($names);
    $last = array_pop($names);
    return empty($names) ? $last : implode(', ', $names) . ' and ' . $last;
  }

  /**
   * saveSubmission
   *
   * Act on the button that was clicked and save the reports entered on the
   * page, noting each voter's results (noteResult()).
   *
   * @param FormStateInterface $form_state
   * @return void
   */
  private function saveSubmission(FormStateInterface $form_state): void
  {
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
        $this->processPostcardsSent($form_state->get('turfIndex'),$postcardsSent,$form_state->get('mcid'),
          $canvassDate,$this->submissionKey($form_state));
        return;
    }

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    $navButtonParts = explode('-',$elementClicked);
    switch ($navButtonParts[0]) {

      case 'save_reports':
        break;
      // A report's Delete or Save button in the Edit list.  Anything else
      // entered on the page is saved below as well.
      case 'report_delete':
      case 'report_save':
        $this->changeVoterReport($form_state, $navButtonParts[0], (int)($navButtonParts[1] ?? 0));
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
    // The NL was found when the form was built, but the record can be gone by
    // the time of the save, e.g., while an active NLs sync rebuilds the list.
    if (!empty($actions) AND empty($nlsInfo)) {
      $messenger->addWarning(self::NL_MISSING_MSG);
      return;
    }

    // A double-click posts the same page twice; save its reports only once.
    $submissionKey = empty($actions) ? '' : $this->submissionKey($form_state);
    if (!$this->claimSubmission($submissionKey)) {
      return;
    }

    $county = $this->sessionDataObj->getCounty();
    // Report with the date of the voter contact the NL chose, or today if none.
    if (empty($canvassDate)) {
      $canvassDate = date('Y-m-d');
    }
    $voterArrays = $form_state->get('voterArrays');
    $saved = NlpDataEntryReports::SAVED;
    $notSaved = NlpDataEntryReports::NOT_SAVED;

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
          'reportIp' => $this->getRequest()->getClientIp(),
        ];
        $contactMethods = $this->nlpConfigurationObj->getContactMethods();

        $type = $action['type'];
        switch ($type) {
          case 'surveyResponse':
            $contactMethodId = $action['contactMethod'];
            $contactMethod = $contactMethods[$contactMethodId];
            $report['contactMethod'] = $contactMethod;
            $pledgeText = 'Pledge to vote: ' . $this->pledgeResponseName($action['surveyResponse'] ?? 0,
                $voterArrays[$vanid]['nickname'] ?? $voterArrays[$vanid]['firstName']);
            if ($contactMethod == 'Postcard') {
              $voterName = $voterArrays[$vanid]['firstName'] . ' ' . $voterArrays[$vanid]['lastName'];
              $messenger->addWarning('If you received an answer from ' . $voterName
                . ' please change the contact method to something other than postcard.');
              $this->noteResult($vanid, $notSaved, $pledgeText . ' (the contact method can\'t be Postcard)');
              break;
            }
            $action['turfIndex'] = $form_state->get('turfIndex');
            $action['firstName'] = $voterArrays[$vanid]['firstName'];
            $this->noteResult($vanid, $this->dataEntryReportsObj->pledgeToVote($report, $action),
              "[$contactMethod] $pledgeText");
            break;
          case 'notContacted':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $method = $this->nlpConfigurationObj->getContactMethod((int)($action['contactMethod'] ?? 0));
            $attemptText = "[$method] "
              . $this->nlpConfigurationObj->getContactResponse((string)$method, (int)($action['notContacted'] ?? 0));
            $result = $this->dataEntryReportsObj->noContactResponse($report,$action);
            if ($result === $notSaved) {
              $voterName = $voterArrays[$vanid]['firstName'] . ' ' . $voterArrays[$vanid]['lastName'];
              $messenger->addWarning('The response for ' . $voterName . ' was not saved.  Choose the'
                . ' response again and save.');
            }
            $this->noteResult($vanid, $result, $attemptText);
            break;
          case 'moved':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $this->dataEntryReportsObj->voterMoved($report,$action);
            $this->noteResult($vanid, $saved, empty($action['moved']) ? 'Moved box unchecked' : 'Moved');
            break;
          case 'hostile':
            $this->dataEntryReportsObj->hostileVoter($report, $action);
            $this->noteResult($vanid, $saved, empty($action['hostile']) ? 'No longer hostile' : 'Hostile');
            break;
          case 'deceased':
            $action['turfIndex'] = $form_state->get('turfIndex');
            $this->dataEntryReportsObj->deceasedVoter($report, $action);
            $this->noteResult($vanid, $saved, empty($action['deceased']) ? 'Deceased box unchecked' : 'Deceased');
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
                $this->noteResult($vanid, $saved, 'Wrong number: ' . $action['phoneNumber']);
                break;
              case 'BH': // Bad home number.
                $action['phoneId'] = $voterArrays[$vanid]['homePhoneId'];
                $action['phoneNumber'] = $voterArrays[$vanid]['homePhone'];
                $action['vanid'] = $vanid;
                $this->dataEntryReportsObj->badPhone('homePhone',$report,$action);
                $this->noteResult($vanid, $saved, 'Wrong number: ' . $action['phoneNumber']);
                break;
              case 'OO':  // Opt out of texting
                if(empty($voterArrays[$vanid]['cellPhone'])) {
                  $msg = var_export($action, true);
                  Drupal::logger('nlpservices')->notice('Cell phone number is missing: '.$msg);
                  $this->noteResult($vanid, $notSaved, 'Opt out of texting (there is no cell phone)');
                  break;
                }
                $cellPhone = $voterArrays[$vanid]['cellPhone'];
                $report['contactType'] = 'Phone';
                $report['text'] = $cellPhone;
                $report['value'] = 'Opt out of texting: ';
                $this->noteResult($vanid, $this->saveChangeReport($report), 'Opt out of texting: ' . $cellPhone);
                break;
              case 'NC': // New cell number.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $cellPhone = $action['updateContact']['newContact'];
                if(empty($cellPhone))  {
                  $messenger->addWarning('You must enter a new cell phone, Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New cell number (enter the number)');
                  break;
                }
                if(!$this->validatePhoneNumber($cellPhone)) {
                  $messenger->addWarning('You must enter a valid cell phone number , Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New cell number ' . $cellPhone . ' (not a valid phone number)');
                  break;
                }
                $report['value'] = 'New cell number';
                $report['text'] = $cellPhone;
                $result = $this->saveChangeReport($report);
                if ($result === $saved) {
                  $newPhoneNumber = [
                    'cellPhone' => $cellPhone,
                    'cellPhoneId' => NULL
                  ];
                  $this->votersObj->updateVoterPhone($vanid,$newPhoneNumber);
                }
                $this->noteResult($vanid, $result, 'New cell number ' . $cellPhone);
                break;
              case 'NH': // New home number.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $homePhone = $action['updateContact']['newContact'];
                if(empty($homePhone))  {
                  $messenger->addWarning('You must enter a new home phone, Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New home number (enter the number)');
                  break;
                }
                if(!$this->validatePhoneNumber($homePhone)) {
                  $messenger->addWarning('You must enter a valid home phone number , Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New home number ' . $homePhone . ' (not a valid phone number)');
                  break;
                }
                $report['value'] = 'New home number';
                $report['text'] = $homePhone;
                $result = $this->saveChangeReport($report);
                if ($result === $saved) {
                  $newPhoneNumber = [
                    'homePhone' => $homePhone,
                    'homePhoneId' => NULL
                  ];
                  $this->votersObj->updateVoterPhone($vanid,$newPhoneNumber);
                }
                $this->noteResult($vanid, $result, 'New home number ' . $homePhone);
                break;

              case 'NE': // New email.
                $messenger = Drupal::messenger();
                $email = $action['updateContact']['newContact'];
                $voterName = $action['firstName'] . ' ' . $action['lastName'];

                if(empty($email))  {
                  $messenger->addWarning('You must enter a new email, Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New email (enter the email)');
                  break;
                }
                if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                  $messenger->addWarning('You must enter a valid email address , Voter is '.$voterName);
                  $this->noteResult($vanid, $notSaved, 'New email ' . $email . ' (not a valid email address)');
                  break;
                }

                $report['contactType'] = 'Walk';
                $report['value'] = 'New email';
                $report['text'] = $email;
                $this->noteResult($vanid, $this->saveChangeReport($report), 'New email ' . $email);
                break;

              case 'LS': // Wants lawn sign.
                $messenger = Drupal::messenger();
                $voterName = $action['firstName'] . ' ' . $action['lastName'];
                $candidates = $action['updateContact']['newContact'];
                if(empty($candidates)) {
                  $messenger->addWarning(t('You must enter candidate name(s) for the lawn sign request, Voter is :name', [':name' => $voterName]));
                  $this->noteResult($vanid, $notSaved, 'Wants lawn sign (enter the candidates)');
                  break;
                }
                $report['contactType'] = 'Walk';
                $report['value'] = 'Wants lawn sign';
                $report['text'] = $candidates;
                $this->noteResult($vanid, $this->saveChangeReport($report), 'Wants lawn sign: ' . $candidates);
                break;

              case 'RTP': // Needs a ride to polls.
                $report['contactType'] = 'Walk';
                $report['value'] = 'Needs ride to polls';
                $report['text'] = '';
                $this->noteResult($vanid, $this->saveChangeReport($report), 'Needs ride to polls');
                break;
            }
            break;
          case 'note':
            $action['reportIndex'] = $voterArrays[$vanid]['reportIndex'];
            $this->dataEntryReportsObj->addNote($report, $action);
            $this->noteResult($vanid, $saved, trim((string)($action['note'] ?? '')) === '' ? 'Note cleared' : 'Note');
            break;
        }
      }
    }
    $this->finishSubmission($submissionKey);
  }

  /**
   * saveChangeReport
   *
   * Save a report of a change to a voter's contact information or a request
   * (new phone or email, opt out, lawn sign, ride), unless the NL already
   * reported the same thing for this date.
   *
   * @param array $report - the report, with its contactType, value and text.
   * @return string - NlpDataEntryReports::SAVED or DUPLICATE.
   */
  private function saveChangeReport(array $report): string
  {
    $report['cycle'] = $this->nlpConfigurationObj->getCycle();
    $report['type'] = 'Change';
    if ($this->reportsObj->isDuplicateReport($report)) {
      return NlpDataEntryReports::DUPLICATE;
    }
    $this->reportsObj->setNlReport($report);
    return NlpDataEntryReports::SAVED;
  }

  /**
   * pledgeResponseName
   *
   * The name of a response to the pledge to vote question, with the voter's
   * name in it, e.g. "Yes, Bob will vote".
   *
   * @param int|string $responseId
   * @param string $firstName
   * @return string
   */
  private function pledgeResponseName(int|string $responseId, string $firstName): string
  {
    $responses = $this->nlpConfigurationObj->getPledgeSurveyQuestion()['responses'] ?? [];
    return str_replace(':fn', $firstName, (string)($responses[$responseId]['name'] ?? ''));
  }

  /**
   * changeVoterReport
   *
   * Delete a report, or save its new text, for a Delete or Save button in a
   * voter's Edit list.  The page only lists reports the user may change, but
   * the button's report index comes from the browser, so check again: the
   * report must be for a voter on this page, and an NL may change only their
   * own reports from this election cycle (NlpReports::canEditReport()).
   *
   * @param FormStateInterface $form_state
   * @param string $change - 'report_delete' or 'report_save'.
   * @param int $reportIndex
   * @return void
   */
  private function changeVoterReport(FormStateInterface $form_state, string $change, int $reportIndex): void
  {
    $messenger = Drupal::messenger();
    $report = ($reportIndex > 0) ? $this->reportsObj->getNlpReport($reportIndex) : [];
    if (empty($report)) {
      $messenger->addWarning('That report is no longer there.  It may have been deleted already.');
      return;
    }
    $mcid = (int) $form_state->get('mcid');
    $impersonating = $this->impersonationObj->isImpersonating();
    $voterArrays = $form_state->get('voterArrays') ?? [];
    $mayChange = isset($voterArrays[$report['vanid']])
      && $this->reportsObj->canEditReport($report, $mcid, $impersonating);
    if ($mayChange && $change === 'report_save') {
      $mayChange = $this->reportsObj->isTextEditable($report);
    }
    if (!$mayChange) {
      Drupal::logger('nlpservices')->warning('MCID @mcid was refused a change (@change) to report @index.', [
        '@mcid' => $mcid, '@change' => $change, '@index' => $reportIndex]);
      $messenger->addError('You cannot change that report.');
      return;
    }

    $logContext = [
      '@index' => $reportIndex,
      '@vanid' => $report['vanid'],
      '@report' => trim($report['type'] . ' ' . $report['value'] . ' ' . $report['text']),
      '@mcid' => $mcid,
      '@who' => $impersonating ? ' (impersonated by a coordinator or admin)' : '',
    ];
    if ($change === 'report_delete') {
      $this->dataEntryReportsObj->deleteVoterReport($report);
      Drupal::logger('nlpservices')->notice(
        'Report @index for voter @vanid (@report) was deleted by MCID @mcid@who.', $logContext);
      $messenger->addStatus('The report was deleted.');
      $this->noteResult($report['vanid'], self::CHANGED, 'Deleted: ' . $this->reportsObj->formatNlReport($report));
      return;
    }

    // The new text is in the box beside the Save button.
    $parents = $form_state->getTriggeringElement()['#parents'] ?? [];
    array_pop($parents);
    $parents[] = 'text';
    $text = (string) $form_state->getValue($parents, '');
    $problem = $this->dataEntryReportsObj->reportTextProblem($report, $text);
    if ($problem !== '') {
      $messenger->addWarning($problem);
      return;
    }
    $this->dataEntryReportsObj->editReportText($report, $text);
    Drupal::logger('nlpservices')->notice(
      'Report @index for voter @vanid (@report) was edited by MCID @mcid@who.', $logContext);
    $messenger->addStatus('The report was changed.');
    $this->noteResult($report['vanid'], self::CHANGED, 'Corrected: ' . trim($text));
  }

  /**
   * submissionKey
   *
   * Identifies one submission of the data entry page.  Both copies of a
   * double-clicked submit post the same form build id and values, so they
   * get the same key.  A later submit comes from a rebuilt page with a new
   * form build id, so it gets a new key.
   *
   * @param FormStateInterface $form_state
   * @return string - The key, or '' if the post has no form build id.
   */
  private function submissionKey(FormStateInterface $form_state): string
  {
    $input = $form_state->getUserInput();
    if (empty($input['form_build_id'])) {
      return '';
    }
    return hash('sha256', serialize($input));
  }

  /**
   * claimSubmission
   *
   * Claim the right to save the reports of this submission.  If another copy
   * of the submission is saving now, wait for it to finish.  A submission
   * is marked saved only after its reports are saved (finishSubmission), so a
   * copy that died partway through doesn't stop the other copy from saving.
   *
   * @param string $submissionKey - From submissionKey(), '' skips the check.
   * @return bool - TRUE if the caller should save the reports.
   */
  private function claimSubmission(string $submissionKey): bool
  {
    if (empty($submissionKey)) {
      return TRUE;
    }
    $lockName = self::SAVED_SUBMISSIONS . ':' . $submissionKey;
    if (!$this->lock->acquire($lockName, self::SAVE_LOCK_SECS)) {
      $this->lock->wait($lockName, self::SAVE_LOCK_SECS);
      if (!$this->lock->acquire($lockName, self::SAVE_LOCK_SECS)) {
        Drupal::messenger()->addWarning(self::SAVE_IN_PROGRESS_MSG);
        return FALSE;
      }
    }
    if ($this->savedSubmissions()->get($submissionKey)) {
      $this->lock->release($lockName);
      Drupal::messenger()->addStatus(self::ALREADY_SAVED_MSG);
      return FALSE;
    }
    // The browser drops the first copy of a double-click; finish saving anyway.
    ignore_user_abort(TRUE);
    return TRUE;
  }

  /**
   * finishSubmission
   *
   * Remember that this submission's reports are saved and release its lock.
   *
   * @param string $submissionKey - From submissionKey(), '' does nothing.
   */
  private function finishSubmission(string $submissionKey): void
  {
    if (empty($submissionKey)) {
      return;
    }
    $this->savedSubmissions()->setWithExpire($submissionKey, TRUE, self::SAVED_SUBMISSION_SECS);
    $this->lock->release(self::SAVED_SUBMISSIONS . ':' . $submissionKey);
  }

  /**
   * savedSubmissions
   *
   * The store that remembers which submissions have been saved.
   *
   * @return KeyValueStoreExpirableInterface
   */
  private function savedSubmissions(): KeyValueStoreExpirableInterface
  {
    return $this->keyValueExpirable->get(self::SAVED_SUBMISSIONS);
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
    $form['canvassDate'] = NlpContactDate::dateElement([
      '#type' => 'date',
      '#title' => t('Date cards were mailed'),
      '#prefix' => '<div class="canvass_date">',
      '#suffix' => '</div>',
    ]);
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
   * @param string $submissionKey - From submissionKey(), see claimSubmission().
   */
  private function processPostcardsSent(int $turfIndex, array $voters,
    int $mcid, string $canvassDate, string $submissionKey = ''): void
  {
    $nls = $this->nlsObj->getNlById($mcid);
    if (empty($nls)) {
      Drupal::messenger()->addWarning(self::NL_MISSING_MSG);
      return;
    }
    if (!$this->claimSubmission($submissionKey)) {
      return;
    }
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
      'lastName' => $nls['lastName'],
      'reportIp' => $this->getRequest()->getClientIp(),
    ];
    foreach ($voters as $vanid => $selected) {
      if(empty($selected)) {continue;}  // Card already reported as sent.
      $report['vanid'] = $vanid;
      $report['cd'] = $this->votersObj->getVoterCd($vanid);
      $this->noteResult($vanid, $this->dataEntryReportsObj->noContactResponse($report,$action),
        '[Postcard] Mailed');
      // The postcard list has the whole turf, not just the page's voters.
      $voter = $this->votersObj->getVoterById((int) $vanid);
      $this->voterNames[$vanid] = trim(($voter['firstName'] ?? '') . ' ' . ($voter['lastName'] ?? ''))
        ?: 'Voter ' . $vanid;
    }
    $this->finishSubmission($submissionKey);
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
    return preg_match(NlpDataEntryReports::PHONE_PATTERN, $phone);
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
  function formCallback(array $form, FormStateInterface $form_state): array {
    return $form['dataEntryTable'];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * selectionCallback
   *
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  function selectionCallback(array $form, FormStateInterface $form_state): array {
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
