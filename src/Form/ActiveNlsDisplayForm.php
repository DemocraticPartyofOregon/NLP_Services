<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpNlsListDisplay;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\ApiActivistCodes;

/**
 * @noinspection PhpUnused
 */
class ActiveNlsDisplayForm extends FormBase
{
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpNlsListDisplay $nlsListDisplayObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpAwards $awardsObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  public function __construct( $votersObj, $reportsObj, $nlsObj, $turfsObj,
                               $searchBarObj, $nlsListDisplayObj, $awardsObj, $sessionDataObj,
                               $nlpConfigurationObj, $apiSurveyQuestionObj, $apiActivistCodesObj,
                               $requestStack) {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlsListDisplayObj = $nlsListDisplayObj;
    $this->awardsObj = $awardsObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiSurveyQuestionObj = $apiSurveyQuestionObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ActiveNlsDisplayForm
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.nls_list_display'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.survey_question'),
      $container->get('nlpservices.activist_codes'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_active_nls_display_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if(empty($form_state->get('page'))) {
      $form_state->set('page','data_entry');

      $county = $this->sessionDataObj->getCounty();
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
    if(empty($county)) {
      $messenger = Drupal::messenger();
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    $page = $form_state->get('page');
    $form = [];
    // Select the HD to display.
    if ($page == 'data_entry') {
      $hdOptions = $this->sessionDataObj->getHdOptions();
      $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
      $currentHd = $this->sessionDataObj->getSavedHd();

      // Build the precinct list for the selected HD (0 means all precincts).
      $hdValue = ($currentHd === 'All' || empty($currentHd)) ? 0 : (int)$currentHd;
      $rawPctList = $this->nlsObj->getPctList($county, $hdValue);
      $pctOptions = array_merge(['All'], $rawPctList);
      $this->sessionDataObj->setNewDistricts($hdOptions, $pctOptions);
      $currentPctIndex = $this->sessionDataObj->getSavedPrecinctIndex();

      // Wrap the search bar and a right-aligned Save Changes button in a
      // position:relative container so the button can be pinned to the right
      // edge of the full-width table using position:absolute.
      $form['options_row'] = [
        '#type'       => 'container',
        '#attributes' => ['style' => ['position:relative;']],
      ];
      $form['options_row']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex, $pctOptions, $currentPctIndex);
      $form['options_row']['save-top'] = [
        '#type'       => 'submit',
        '#value'      => t('Save Changes'),
        '#name'       => 'save_changes',
        '#attributes' => ['style' => 'position:absolute; right:0; top:50%; transform:translateY(-50%);'],
      ];

      // Fetch the list of NL names and contact information.
      $needle = $form_state->get('search-needle');
      if(!empty($needle)) {
        $form_state->set('search-needle', NULL);
        $nlRecords = $this->nlsObj->searchNls($needle,$county);
      } else {
        $currentPct = $this->sessionDataObj->getSavedPct();
        $nlRecords = $this->nlsObj->getNls($county, $currentHd, $currentPct);
      }
      if(!empty($nlRecords)) {
        $nlKeys = array_keys($nlRecords);
        foreach ($nlKeys as $mcid) {
          $nlRecords[$mcid]['progress'] = $this->getProgress($mcid);
          $nlStatus = $nlRecords[$mcid]['status'];
          $statusNote = $nlStatus['notes'];
          $awardRecord = $this->awardsObj->getAward($mcid);
          $participationNote = $awardRecord['notes'];
          if($statusNote != $participationNote) {
            if(empty($participationNote)) {
              $awardRecord['notes'] = $statusNote;
              $this->awardsObj->mergeAward($awardRecord);
            } else {
              $nlStatus['notes'] = $participationNote;
              $nlRecords[$mcid]['status']['notes'] = $participationNote;
              $this->nlsObj->setNlsStatus($nlStatus);
            }
          }

          $contactMethod = $nlStatus['contact'];
          $preferredContactMethod = (!empty($awardRecord['preferredContactMethod']))?
            $awardRecord['preferredContactMethod']:'canvass';
          if($contactMethod != $preferredContactMethod) {
            if(empty($preferredContactMethod)) {
              $awardRecord['preferredContactMethod'] = $contactMethod;
              $this->awardsObj->mergeAward($awardRecord);
            } else {
              $nlStatus['contact'] = $preferredContactMethod;
              $nlRecords[$mcid]['status']['contact'] = $preferredContactMethod;
              $this->nlsObj->setNlsStatus($nlStatus);
            }
          }

          $nlRecords[$mcid]['nlParticipationDisplay'] = $this->awardsObj->nlParticipationDisplay($mcid);
          $awardRecord['preferredContactMethod'] = $contactMethod;

        }
      }
      $nlsTable = $this->nlsListDisplayObj->createTableArray($nlRecords);

      // Record the original field values so submitForm can skip unchanged cells.
      $originalValues = [];
      foreach ($nlsTable as $mcid => $row) {
        $orig = [];
        if (isset($row['notes'])) {
          $orig["notes-$mcid"] = $row['notes']['text'];
        }
        if (isset($row['nl'])) {
          $orig["nl-$mcid"] = $row['nl']['default'];
        }
        if (isset($row['tc'])) {
          $orig["tc-$mcid"] = ($row['tc']['default'] == 1) ? "cb_turfCut_$mcid" : '';
        }
        if (isset($row['td'])) {
          $orig["td-$mcid"] = ($row['td']['default'] == 1) ? "cb_turfDelivered_$mcid" : '';
        }
        if (isset($row['co'])) {
          $orig["co-$mcid"] = $row['co']['default'];
        }
        $originalValues[$mcid] = $orig;
      }
      $form_state->set('original_values', $originalValues);

      $request = $this->requestStack->getCurrentRequest();
      $sort = $request->query->get('sort', '');
      if(!empty($sort)) {
        $order = $request->query->get('order', '');
        $this->nlsListDisplayObj->sortNlsTable($nlsTable,$sort,$order);
      }

      $form['nlsForm'] = [
        '#prefix' => '<div id="nlsFormDiv">',
        '#suffix' => '</div>',
        '#type' => 'fieldset',
        '#attributes' => [
          'style' => ['background-image:none;border:0px;padding:0px;margin:0px;background-color:rgb(255,255,255);'],
        ],
      ];

      // Build the table of NLs names and status.
      $form['nlsForm']['nls_table'] = $this->nlsListDisplayObj->buildNlsTable($nlsTable);

      $form['nlsForm']['save'] = [
        '#type'  => 'submit',
        '#value' => t('Save Changes'),
        '#name'  => 'save_changes',
      ];

    }
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/unsaved-changes';
    $form['#attributes']['class'][] = 'js-form-changed';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    // Saving is handled in submitForm; nothing to validate here.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    if ($elementClicked == 'search_submit') {
      $needle = $form_state->getValue('search-needle');
      $form_state->set('search-needle', $needle);
      return;
    }

    if ($elementClicked == 'save_changes') {
      $county = $form_state->get('county');
      $contacts = $form_state->getValue('contacts');
      $originalValues = $form_state->get('original_values') ?? [];
      if (!empty($contacts)) {
        foreach ($contacts as $mcid => $columns) {
          $originals = $originalValues[$mcid] ?? [];
          // phpcs:ignore -- loose == intentional: int/string mismatch from form API
          $changed = array_filter($columns, fn($v, $k) => !isset($originals[$k]) || $v != $originals[$k], ARRAY_FILTER_USE_BOTH);
          if (!empty($changed)) {
            $this->saveNlRow($mcid, $county, $changed);
          }
        }
      }
      Drupal::messenger()->addStatus(t('Changes saved.'));
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * saveNlRow
   *
   * Save all editable column values for a single NL row.
   * Called from submitForm when the user clicks "Save Changes".
   *
   * @param $mcid
   * @param string $county
   * @param array $columns  Keyed by "{column}-{mcid}", values are form element values.
   */
  private function saveNlRow($mcid, string $county, array $columns): void
  {
    $status = $this->nlsObj->getNlsStatus($mcid, $county);
    foreach ($columns as $columnKey => $value) {
      $column = explode('-', $columnKey)[0];
      switch ($column) {
        case 'td':
          if ($value) {
            $this->turfsObj->setAllTurfsDelivered($mcid, $county);
          }
          $status['turfDelivered'] = $value ? 'Y' : '';
          break;
        case 'tc':
          $status['turfCut'] = $value ? 'Y' : '';
          break;
        case 'notes':
          $trunc = strlen($value) > $this->nlsObj::NOTES_MAX
            ? substr($value, 0, $this->nlsObj::NOTES_MAX) . '...'
            : $value;
          $status['notes'] = htmlentities($trunc, ENT_QUOTES);
          $awardRecord = $this->awardsObj->getAward($mcid);
          $awardRecord['notes'] = $status['notes'];
          $this->awardsObj->mergeAward($awardRecord);
          break;
        case 'co':
          $contactList = $this->nlsObj->contactList;
          $status['contact'] = $contactList[$value];
          $awardRecord = $this->awardsObj->getAward($mcid);
          $awardRecord['preferredContactMethod'] = $contactList[$value];
          $this->awardsObj->mergeAward($awardRecord);
          break;
        case 'nl':
          $askList = $this->nlsObj->askList;
          $nlProgress = $askList[$value];
          $this->processAsk($mcid, $nlProgress);
          // processAsk saves asked via its own setNlsStatus; keep local copy in sync
          $status['asked'] = $nlProgress;
          break;
      }
    }
    $this->nlsObj->setNlsStatus($status);
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * processAsk
   *
   * @param $mcid
   * @param $askStatus
   * @return void
   */
  function processAsk($mcid,$askStatus): void
  {
    //nlp_debug_msg('processAsk',"$mcid,$askStatus");
    $currentStatus = $this->nlsObj->getNlsStatus($mcid);
    $status = $currentStatus;
    $status['asked'] = $askStatus;
    $this->nlsObj->setNlsStatus($status);
    $this->awardsObj->nlParticipation($mcid,$askStatus);
    $request = [
      'vanid' => $mcid,
      'codeName' => 'NLPDropoutAC',
    ];
    if ($askStatus == 'Dropout') {
      $request['action'] = 'Apply';
      $this->apiActivistCodesObj->setApiActivistCode($request);
    } elseif($currentStatus['asked'] == 'Dropout') {
      $request['action'] = 'Remove';
      $this->apiActivistCodesObj->setApiActivistCode($request);
    }
    // '—' is the "not set" sentinel; it has no VAN survey response equivalent.
    if ($askStatus !== NlpNls::DASH) {
      $surveyResponse = [
        'type' => 'nlParticipation',
        'vanid' => $mcid,
        'responseName' => $askStatus,
        'dateCanvassed' => date(DATE_ATOM, time()),
      ];
      $county = $this->sessionDataObj->getCounty();
      $this->apiSurveyQuestionObj->reportSurveyResponse($surveyResponse,$county);
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getProgress
   *
   * Create an associate array with the progress of the NL to make contact with
   * the voters on the turf, the progress on making face-to-face contact, and
   * a judgment that they are done.
   *
   * @param $mcid
   * @return array
   */
  function getProgress($mcid): array
  {
    $progress['attempts'] = $progress['contacts'] = $progress['voterCount'] = 0;
    // Get the voters assigned to this NL for all the turfs.
    $vanids= $this->votersObj->fetchVanIdsByNl($mcid);
    if(empty($vanids)) {return $progress;}

    $turfs = $this->turfsObj->getTurfsById($mcid);
    $doorCount = 0;
    foreach ($turfs as $turf) {
      $doorCount += $turf['doorCount'];
    }

    $voterCount = count($vanids);
    if ($voterCount == 0) {return $progress;}
    // Now get all the reports from this NL for this cycle.
    $reports = $this->reportsObj->getNlReportsForVoters($vanids);

    // Flag the voters with whom the NL asked the survey question.  (There
    // could have been more than one.)
    $voters = [];
    foreach ($reports as $voterReports) {
      foreach ($voterReports as $report) {
        $vanid = $report['vanid'];
        if(!empty($vanids[$vanid])) {
          $voters[$vanid]['attempt'] = TRUE;
          if($report['type']==$this->reportsObj::SURVEY) {
            $voters[$vanid]['contact'] = TRUE;
          }
        }
      }
    }
    //  Now count the voters with contact attempts or survey responses.
    $contactCount = $attemptCount = 0;
    if(!empty($voters)) {
      foreach ($voters as $voter) {
        if(!empty($voter['attempt'])) {
          $attemptCount++;
          if(!empty($voter['contact'])) {
            $contactCount++;
          }
        }
      }
    }
    // Return the strings for display of voter contact attempts and contacts.
    $progress['attempts'] = $attemptCount;  // Voter contact attempts.
    $progress['contacts'] = $contactCount; // Voter contacts.
    $progress['voterCount'] = $voterCount;
    $progress['doorCount'] = $doorCount;
    return $progress;
  }

}
