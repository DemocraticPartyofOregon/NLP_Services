<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\nlpservices\NlpNlsListDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiSavedLists;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSyncTurfBuilder;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpTurfListDisplayVoteBuilder;
use Drupal\nlpservices\ApiMapRegions;
use Drupal\nlpservices\Batch\SyncTurfBatch;

class SyncTurfForm extends FormBase {
  protected ApiSavedLists $savedListsObj;
  protected ApiFolders $foldersObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpSyncTurfBuilder $syncTurfBuilderObj;
  protected NlpNlsListDisplay $nlsListDisplayObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpTurfListDisplayVoteBuilder $turfListDisplayObj;
  protected ApiMapRegions $mapRegionsObj;

  public function __construct(  $savedListsObj, $foldersObj, $nlsObj, $turfsObj,
                                $syncTurfBuilderObj, $nlsListDisplayObj,
                                $nlpConfigurationObj, $nlpSessionDataObj, $turfListDisplayObj,
                                $mapRegionsObj, $requestStack ) {
    $this->savedListsObj = $savedListsObj;
    $this->foldersObj = $foldersObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->syncTurfBuilderObj = $syncTurfBuilderObj;
    $this->nlsListDisplayObj = $nlsListDisplayObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->turfListDisplayObj = $turfListDisplayObj;
    $this->mapRegionsObj = $mapRegionsObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): SyncTurfForm
  {
    return new static(
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.folders'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.sync_turf_builder'),
      $container->get('nlpservices.nls_list_display'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.turf_list_display_votebuilder'),
      $container->get('nlpservices.api_map_regions'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_sync_new_turf_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (empty($form_state->get('reenter'))) {
      $county = $this->nlpSessionDataObj->getCounty();
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $form_state->set('county',$county);
      // Set up the possible values for HD and Pct for the search for an NL.
      $hdList = $this->nlsObj->getHdList($county);
      $pctList = $this->nlsObj->getPctList($county); // All precincts in the county.
      $this->nlpSessionDataObj->setNewDistricts($hdList,$pctList);
      // POST-requests (AJAX or submit-button) must restore from session so AJAX callbacks
      // see the correct page. Sort links (GET + ?sort=) also restore from session.
      // Only a clean GET with no sort params is a true fresh load that resets to turf_select.
      $request = $this->requestStack->getCurrentRequest();
      if (!empty($request->query->get('sort', '')) || $request->isMethod('POST')) {
        $page = $this->nlpSessionDataObj->get('syncTurfPage') ?? 'turf_select';
        $form_state->set('page', $page);
        $form_state->set('showLists', $this->nlpSessionDataObj->get('showLists') ?? FALSE);
      } else {
        $form_state->set('page','turf_select');
        $this->nlpSessionDataObj->set('syncTurfPage', 'turf_select');
        $form_state->set('showLists', FALSE);
        $this->nlpSessionDataObj->set('showLists', FALSE);
      }
    }
    $county = $form_state->get('county');
    $page = $form_state->get('page');
    switch ($page) {

      // Display a page to select a turf.
      case 'turf_select':

        $showLists = $form_state->get('showLists');
        $turfTitle = "Choose a folder and map region then choose a turf from the list of map region turfs.";
        $listTitle = "Choose a folder and then choose a saved list to be used as a turf.";
        $selectTitle = (!$showLists)?$turfTitle:$listTitle;
        $form['turf_select_title'] = [
          '#markup' => '<div class="small-title" >'.$selectTitle.'</div>',
        ];

        $form['turf_select_page'] = [
          '#type' => 'fieldset',
          '#prefix' => '<div id="folder_change_wrapper" >',
          '#suffix' => '</div>',
        ];

        $form['turf_select_page']['turf_filter'] = $this->buildTurfFilter($showLists);

        $request = $this->requestStack->getCurrentRequest();
        $sort = $request->query->get('sort', '');
        if (!empty($sort)) {
          $order = $request->query->get('order', '');
          $turfOptions = $this->nlpSessionDataObj->get('turfOptions');
          $this->turfListDisplayObj->sortTurfs($turfOptions, $sort, $order);
          $this->nlpSessionDataObj->set('turfOptions', $turfOptions);
        }

        $form['turf_select_page']['turf_display'] = $this->buildTurfSelectPage($showLists);

        $form['turf_submit'] = [
          '#name' => 'turf_submit',
          '#type' => 'submit',
          '#value' => 'Use the selected turf.',
          '#prefix' => '<div>',
          '#suffix' => '</div>',
        ];

        if(!$showLists) {
          $form['saved_lists_reveal'] = [
            '#name' => 'change_to_lists',
            '#type' => 'submit',
            '#value' => 'Show lists.',
            '#prefix' => '<div>',
            '#suffix' => '</div>',
          ];
        } else {
          $form['map_region_return'] = [
            '#name' => 'change_to_turfs',
            '#type' => 'submit',
            '#value' => 'Show Map Regions.',
            '#prefix' => '<div>',
            '#suffix' => '</div>',
          ];
        }

        break;

        // Display a page to select an NL.
      case 'nl_select':
        $turfId = $form_state->get('turfId');
        // Check if the turf has been assigned to an NL in the past.  This gives the most likely option for that
        // NL to canvass a familiar turf.   Typically, the turf is the NL's own neighborhood.
        $mcid = 0;
        $previousNLForm = $this->previouslyAssignedNl($turfId,$mcid);
        if(!empty($previousNLForm)) {
          $form['previously_assigned'] = $previousNLForm;
          $form_state->set('previouslyAssignedMcid',$mcid);
        }
        $form['nl_select'] = $this->buildNlSelectPage();
        break;

      case 'nl_search_by_name':
        $needle = $form_state->get('needle') ?? '';
        $county = $this->nlpSessionDataObj->getCounty();
        $form['nl_select'] = [
          '#type' => 'fieldset',
          '#attributes' => ['class' => ['no-border-fieldset']],
          '#prefix' => " \n" . '<div class="big-ts-select-box" id="nlsFormDiv">' . " \n",
          '#suffix' => " \n" . '</div>' . " \n",
        ];

        $nlSearchList = $nlsTable = [];
        if(!empty($needle)) {
          $nlSearchList = $this->nlsObj->searchNls($needle,$county);
          $nlsTable = $this->nlsListDisplayObj->createTableArray($nlSearchList,TRUE);
        }

        $form['nl_select']['nl_search'] = $this->syncTurfBuilderObj->buildNlSearchByName($needle, $nlsTable);
        $form_state->set('needle','');
        $form_state->set('nlSearchList',$nlSearchList);
        break;

      case 'nl_search_by_district':

        $form['nl_select'] = [
          '#type' => 'fieldset',
          '#attributes' => ['class' => ['no-border-fieldset']],
          '#prefix' => " \n" . '<div class="big-ts-select-box" id="nlsFormDiv">' . " \n",
          '#suffix' => " \n" . '</div>' . " \n",
        ];
        $selectedHd = $this->nlpSessionDataObj->getSavedHd();
        $pctList = $this->nlpSessionDataObj->getPrecinctList();
        $selectedPrecinctIndex = $this->nlpSessionDataObj->getSavedPrecinctIndex();
        $selectedPrecinct = (empty($selectedPrecinctIndex))?'':$pctList[$selectedPrecinctIndex];

        $nlList = $this->nlsObj->getNls($county,$selectedHd,$selectedPrecinct);

        $nlsTable = $this->nlsListDisplayObj->createTableArray($nlList,TRUE);
        $request = $this->requestStack->getCurrentRequest();
        $sort = $request->query->get('sort', '');
        if (!empty($sort)) {
          $order = $request->query->get('order', '');
          $this->nlsListDisplayObj->sortNlsTable($nlsTable, $sort, $order, TRUE);
        }
        $form['nl_select']['nl_district'] = $this->syncTurfBuilderObj->buildNlSearchByDistrict($nlsTable);
        $form_state->set('nlList',$nlList);
        break;
      }
      $form['#attached']['library'][] = 'nlpservices/nlp-table';
      $form['#attached']['library'][] = 'nlpservices/turf-sync';
      $form['#attached']['library'][] = 'nlpservices/walksheet-form';
      $form['#attached']['library'][] = 'nlpservices/hint-note';
      $form['#attached']['library'][] = 'nlpservices/table-helper';
      return $form;
    }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $messenger = Drupal::messenger();
    $values = $form_state->getValues();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    switch ($elementClicked) {
      // A new folder was selected for the turf selection.
      case 'folder':
        $currentFolderId = $values['folder'];
        $this->nlpSessionDataObj->set('folderId',$currentFolderId);
        $mapRegionId = 0;
        $this->nlpSessionDataObj->set('mapRegionId',$mapRegionId);
        $form_state->setRebuild();
        return;
      // A Map Region was selected for the turf selection. (Narrows the display of turfs for
      // folders with lots of precincts.)
      case 'map_region_select':
        $mapRegionId = $values['map_region_select'];
        $this->nlpSessionDataObj->set('mapRegionId',$mapRegionId);
        $form_state->setRebuild();
        return;
      // Show just the saved lists and not the turfs associated with the Map Regions.
      // This should rarely be used and is only for the cases where lots of addresses have
      // the same geolocation.
      case 'change_to_lists':
        $form_state->set('showLists',TRUE);
        $this->nlpSessionDataObj->set('showLists', TRUE);
        return;
      // Go back to the display of turfs associated with the Map Regions.
      case 'change_to_turfs':
        $form_state->set('showLists',FALSE);
        $this->nlpSessionDataObj->set('showLists', FALSE);
        return;
      // A turf was selected from the list of turfs.
      case 'turf_submit':
        $selections = $this->turfListDisplayObj->
                    validateTurfSelection($form_state->getValue('contacts'));
        $turfSelection = reset($selections);
        $form_state->set('turfSelection',$turfSelection);
        if(empty($selections) OR count($selections) > 1) {
          $form_state->setErrorByName('turf_submit',"You must select one turf");
        }

        $turfOptions = $this->nlpSessionDataObj->get('turfOptions');
        $turfId = $turfSelection['turfId'];
        if(!empty($turfOptions[$turfId]['turfAssigned'])) {
          $form_state->setErrorByName('turf_submit', "The selected turf is already assigned. You must delete it before you can reassign it.");
        }
        if(empty($turfOptions[$turfId]['turfName'])) {
          $form_state->setErrorByName('turf_submit',"The turf name is missing.");
          $msg = var_export($turfOptions, true);
          Drupal::logger('nlpservices')->notice('Turf ID is wrong: '.$msg);
          return;
        }
        $voterCount = $turfOptions[$turfId]['voterCount'];
        if ($voterCount > 100) {
          $form_state->setErrorByName('turf_submit',"The turf must have 100 voters or less.");
        }
        if($voterCount > 75) {
          $messenger->addWarning("Turfs with more than 75 voters are discouraged.");
        }
        return;

      // A house district was selected.
      case 'nl_select_hd':
        $selectedHdIndex = $values['nl_select_hd'];
        if($selectedHdIndex==0) {
          $this->nlpSessionDataObj->set('hdSelected',FALSE);
          $selectedHdIndex = 0;
        } else {
          $this->nlpSessionDataObj->set('hdSelected',TRUE);
          $selectedHdIndex--;
          $this->nlpSessionDataObj->set('precinctSelected',FALSE);
          $form_state->setValue('nl_select_precinct', 0);
        }
        $this->nlpSessionDataObj->saveHdIndex($selectedHdIndex);
        $form_state->setRebuild();
        return;

      // A precinct was selected.
      case 'nl_select_precinct':
        $selectedHdPrecinct = $values['nl_select_precinct'];
        if($selectedHdPrecinct==0) {
          $this->nlpSessionDataObj->set('precinctSelected',FALSE);
        } else {
          $this->nlpSessionDataObj->set('precinctSelected',TRUE);
          $selectedHdPrecinct--;
          $this->nlpSessionDataObj->set('hdSelected',FALSE);
        }
        $this->nlpSessionDataObj->savePrecinctIndex($selectedHdPrecinct);
        $form_state->setRebuild();
        return;

      // A search for an NL by name was submitted (actually a fragment of text).
      case 'search_submit':
        $input = $form_state->getUserInput();
        $needle = trim($input['needle'] ?? '');
        if(empty($needle)) {
          $form_state->setErrorByName('needle','You have to specify a value for the search.');
        }
        $form_state->set('needle',$needle);
        return;

      // An NL was selected from the result of a search, typically by name.
      case 'search_select':
        $selections = $this->nlsListDisplayObj->
                        validateNlSelection($form_state->getValue('search') ?? []);
        $nlSelection = reset($selections);
        $form_state->set('nlSelection',$nlSelection);
        if(count($selections)!=1) {
          $form_state->setErrorByName('turf_submit',"You must select only one NL.");
        }

        $result = $this->validateWalksheet('turf_walksheet_nameSearch');
        if($result['result'] != 'ok') {
          $form_state->setErrorByName('turf_walksheet_nameSearch',$result['message']);
        }
        $form_state->set('walksheetInfo',$result['walksheetInfo']);
        return;

      // The NL previously assigned to the turf is selected.
      case 'previously_assigned_nl':
        if(!empty($values['needle'])) {
          $form_state->setErrorByName('needle', 'The name search needs to be empty to select the previous NL or click the "Search for the NL" button.');
          return;
        }
        $result = $this->validateWalksheet('turf_walksheet_previousAssignment');
        if($result['result'] != 'ok') {
          $form_state->setErrorByName('turf_walksheet_previousAssignment',$result['message']);
        }
        $form_state->set('walksheetInfo',$result['walksheetInfo']);
        return;

      // An NL was selected from the list of NLs in a district.
      case 'add_selected':
        $selections = $this->nlsListDisplayObj->validateNlSelection($form_state->getValue('nlsForm') ?? []);
        $nlSelection = reset($selections);
        $form_state->set('nlSelection',$nlSelection);
        if(count($selections)!=1) {
          $form_state->setErrorByName('turf_submit',"You must select only one NL.");
        }
        $result = $this->validateWalksheet('turf_walksheet_districtSelect');
        if($result['result'] != 'ok') {
          $form_state->setErrorByName('turf_walksheet_districtSelect',$result['message']);
        }
        $form_state->set('walksheetInfo',$result['walksheetInfo']);
        break;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
    {
      $form_state->set('reenter',TRUE);
      $form_state->setRebuild();
      $syncTurf = FALSE;
      $triggeringElement = $form_state->getTriggeringElement();
      $elementClicked = $triggeringElement['#name'];
      switch ($elementClicked) {

        case 'turf_submit':
          $form_state->set('page', 'nl_select');
          $this->nlpSessionDataObj->set('syncTurfPage', 'nl_select');
          $turfSelection = $form_state->get('turfSelection');
          $form_state->set('turfId',$turfSelection['turfId']);
          break;

        case 'previously_assigned_nl':
          $previouslyAssignedMcid = $form_state->get('previouslyAssignedMcid');
          $form_state->set('mcid',$previouslyAssignedMcid);
          $syncTurf = TRUE;
          break;

        case 'nl_search_by_name':
          $form_state->set('page', 'nl_search_by_name');
          $this->nlpSessionDataObj->set('syncTurfPage', 'nl_search_by_name');
          break;

        case 'nl_search_by_district':
          $form_state->set('page', 'nl_search_by_district');
          $this->nlpSessionDataObj->set('syncTurfPage', 'nl_search_by_district');
          break;

        case 'search_goback':
          $form_state->set('page', 'nl_select');
          $this->nlpSessionDataObj->set('syncTurfPage', 'nl_select');
          break;

        case 'search_submit':  //  Search needle provided.
          break;

        case 'search_select':  // NL selected from the result of a search, typically by name.
        case 'add_selected':  //  NL selected from the list by district.
          $nlSelection =  $form_state->get('nlSelection');
          $mcid = $nlSelection['mcid'];
          $form_state->set('mcid',$mcid);
          $syncTurf = TRUE;
          break;

      }

      //  This turf can be synced.
      if($syncTurf) {
        $mcid = $form_state->get('mcid');
        $county = $form_state->get('county');

        $folderId = $this->nlpSessionDataObj->get('folderId');
        $folderOptions = $this->nlpSessionDataObj->get('folderOptions');
        $turfOptions = $this->nlpSessionDataObj->get('turfOptions');
        $turfId = $form_state->get('turfId');

        if(empty($turfOptions[$turfId]['turfName'])) {
          $messenger = Drupal::messenger();
          $messenger->addWarning("The turf name is missing.  The turf cannot be synced.");
          $form_state->set('page', 'turf_select');
          $this->nlpSessionDataObj->set('syncTurfPage', 'turf_select');
          return;
        }
        $showLists = $form_state->get('showLists');

        $args = [
          'county' => $county,
          'folderId' => $folderId,
          'folderName' => $folderOptions[$folderId],
          'turfId' => $turfId,
          'turfName' => $turfOptions[$turfId]['turfName'],
          'mcid' => $mcid,
          'voterCount' => $turfOptions[$turfId]['voterCount'],
          'doorCount' => $turfOptions[$turfId]['doorCount'],
          'showLists' => $showLists,
          'mapRegionId' => $turfOptions[$turfId]['mapRegionId'],
          'mapRegionName' => $turfOptions[$turfId]['mapRegionName'],
          'minivanNumber' => $turfOptions[$turfId]['minivanNumber'],
          'minivanCreateDate' => $turfOptions[$turfId]['minivanCreateDate'],
          'walksheetInfo' => $form_state->get('walksheetInfo'),

        ];
        $form_state->set('page', 'turf_select');
        $this->nlpSessionDataObj->set('syncTurfPage', 'turf_select');

        $batch = [
          'operations'       => [[[SyncTurfBatch::class, 'process'], [$args]]],
          'finished'         => [SyncTurfBatch::class, 'finished'],
          'title'            => t('Processing Sync Turf Batch.'),
          'init_message'     => t('Sync Turf Batch is starting.'),
          'progress_message' => t('Processed @percentage % of Sync Turf Batch.'),
          'error_message'    => t('Sync Turf Batch has encountered an error.'),
        ];
        batch_set($batch);

      }

    }

  public function validateWalksheet(string $name): array
  {
    $emptyResult = [
      'result' => 'ok',
      'walksheetInfo' => [
        'fileName' => '',
        'tempName' => '',
        'fileNameBase' => '',
        'fileType' => '',
      ],
    ];

    $files = $_FILES['files'] ?? [];
    $fileName = $files['name'][$name] ?? '';

    if (empty($fileName)) {
      return $emptyResult;
    }

    $tempName     = $files['tmp_name'][$name] ?? '';
    $lcFileName   = strtolower($fileName);
    $parts        = explode('.', $lcFileName);
    $fileType     = end($parts);
    $allowed      = ['pdf', 'xls', 'xlsx'];
    if (!in_array($fileType, $allowed)) {
      return ['result' => 'error', 'message' => t('The walksheet must be a PDF, XLS, or XLSX type.')];
    }
    $fileNameBase = $parts[0];

    return [
      'result' => 'ok',
      'walksheetInfo' => [
        'fileName'     => $fileName,
        'tempName'     => $tempName,
        'fileNameBase' => $fileNameBase,
        'fileType'     => $fileType,
      ],
    ];
  }

  /**
   * Create the form elements for selecting the folder and map region.   This established the
   * list of turfs visible for selection.   Narrowing the turf choices reduces what can be a very
   * long list of turfs.
   *
   * @param bool $showLists - True if the user is selecting a list to sync, false if the user is selecting a turf.
   * @return array - The form array for the page.
   */
  private function buildTurfFilter(bool $showLists): array
  {
    $county = $this->nlpSessionDataObj->getCounty();
    $folderOptions = $this->foldersObj->getFolderOptions(0,$county);
    if(empty($folderOptions)) {return [];}

    $firstFolderId = key($folderOptions);
    $currentFolderId = $this->nlpSessionDataObj->get('folderId');
    if(empty($folderOptions[$currentFolderId])) {  // It appears the folder has been deleted.
      $currentFolderId = 0;
    }
    if(empty($currentFolderId)) {
      $currentFolderId = $firstFolderId;
    }
    // Remember the working folder.
    $this->nlpSessionDataObj->set('folderId',$currentFolderId);
    $this->nlpSessionDataObj->set('folderOptions',$folderOptions);

    if(!$showLists) {
      // Reveal the Map Regions.
      $mapRegionOptions = $this->mapRegionsObj->mapRegionOptions($county, $currentFolderId);
      $this->nlpSessionDataObj->set('mapRegionOptions', $mapRegionOptions);
      $mapRegionId = $this->nlpSessionDataObj->get('mapRegionId');

      if (empty($mapRegionOptions[$mapRegionId])) {  // It appears the Map Region has been deleted.
        $mapRegionId = 0;
      }
      if (empty($mapRegionId)) {
        $mapRegionId = key($mapRegionOptions);
      }
      // Remember the working Map Region and the turf choices in the map region.
      $this->nlpSessionDataObj->set('mapRegionId',$mapRegionId);
      $turfOptions = $this->mapRegionsObj->mapRegionTurfOptions($mapRegionId);
      foreach ($turfOptions as $turfId => $turfOption) {
        $this->turfsObj->checkTurfIdChange($currentFolderId, $turfOption);
        $turfInUse = $this->turfsObj->turfInUse($currentFolderId, $turfId, $turfOption['turfName']);
        $turfOptions[$turfId]['turfAssigned'] = $turfInUse['turfAssigned'];
        $turfOptions[$turfId]['nl'] = $turfInUse['nl'];
        $turfOptions[$turfId]['mcid'] = $turfInUse['mcid'];
        $turfOptions[$turfId]['minivanNumber'] = $turfOptions[$turfId]['minivanCreateDate'] = '';
        if(!empty($turfOption['printedList'])) {
          $turfOptions[$turfId]['minivanNumber'] = $turfOption['printedList']['number'];
          $turfOptions[$turfId]['minivanCreateDate'] = $turfOption['printedList']['dateCreated'];
        }
      }
      $this->nlpSessionDataObj->set('turfOptions',$turfOptions);

    } else {
      // Reveal only lists.
      $this->mapRegionsObj->getMapRegionInfo($county, $currentFolderId);
      $excludedTurfIds = $this->mapRegionsObj->getTurfIds();
      $savedLists = $this->savedListsObj->getTurfLists($county, $currentFolderId, $excludedTurfIds);
      $savedListOptions = $this->savedListsObj->savedListOptions($savedLists);

      foreach ($savedListOptions as $turfId => $savedListOption) {
        $this->turfsObj->checkTurfIdChange($currentFolderId, $savedListOption);
        $turfInUse = $this->turfsObj->turfInUse($currentFolderId, $turfId, $savedListOption['turfName']);
        $savedListOptions[$turfId]['turfAssigned'] = $turfInUse['turfAssigned'];
        $savedListOptions[$turfId]['nl'] = $turfInUse['nl'];
        $savedListOptions[$turfId]['mcid'] = $turfInUse['mcid'];
      }
      $this->nlpSessionDataObj->set('turfOptions', $savedListOptions);
    }

    // Folder choice.
    $form['folder'] = [
      '#type' => 'select',
      '#title' => 'Choose the folder.',
      '#options' => $folderOptions,
      '#default_value' => $currentFolderId,
      '#ajax' => [
        'callback' => '::folderSelectedCallback',
        'wrapper' => 'folder_change_wrapper',
      ],
    ];
    // Map region choice in the current folder.
    if(!empty($mapRegionOptions) AND !$showLists) {
      $form['map_region_select'] = [
        '#type' => 'select',
        '#title' => t('Choose the Map Region.'),
        '#options' => $mapRegionOptions,
        '#default_value' => $mapRegionId,
        '#ajax' => [
          'callback' => '::folderSelectedCallback',
          'wrapper' => 'folder_change_wrapper',
        ],
      ];
    }

    return $form;

  }

  /**
   * Builds the form for selecting a previously assigned NL.
   *
   * @param int $turfId - The turf ID.
   * @param int $mcid - The MCID of the previously assigned NL.
   * @return array - The form array for the page.
   */
  public function previouslyAssignedNl(int $turfId, int &$mcid): array
    {
      $form = [];
      $turfAssignment = $this->turfsObj->getTurfAssignment($turfId);
      if(!empty($turfAssignment)) {
        $form['previously_assigned_title'] = [
          '#markup' => '<div class="big-ts-select-box" id="turf-sync-previous-title"><hr>
                            <h2>Select the NL previously assigned to this turf.</h2></div>',
        ];
        $form['previously_assigned'] = [
          '#type' => 'fieldset',
          '#prefix' => " \n" . '<div class="big-ts-select-box" id="turf-sync-previous-box">' . " \n",
          '#suffix' => " \n" . '</div>' . " \n",
        ];
        $mcid = $turfAssignment['mcid'];
        $nl = $this->nlsObj->getNlById($mcid);
        if (!empty($nl)) {
          $msg = "&#x2714; " . Html::escape($nl['firstName']) . ' ' . Html::escape($nl['lastName'])
            . ' [' . Html::escape($nl['mcid']) . '] - '
            . ' HD: ' . Html::escape($nl['hd']) . ' Precinct: ' . Html::escape($nl['precinct']) . '<br>';
          $form['previously_assigned']['previously_assigned_nl_note'] = [
            '#markup' => Markup::create($msg),
          ];
          $form['previously_assigned']['walksheet'] = $this->syncTurfBuilderObj->walksheet('pa');
          $form['previously_assigned']['previously_assigned_nl'] = [
            '#type' => 'submit',
            '#name' => 'previously_assigned_nl',
            '#value' => 'Use the previously assigned NL',
          ];
        } else {
          $form['previously_assigned']['inactive_nl'] = [
            '#markup' => "The NL must be in the active NL list to sync a turf."
          ];
        }
      }
      return $form;
    }

  /**
   * Builds the page to select a turf to sync.
   *
   * @param bool $showLists - True if the user is selecting a list to sync,
   *         false if the user is selecting a turf.
   * @return array - The form array for the page.
   */
  public function buildTurfSelectPage(bool $showLists): array
    {

      $turfOptions = $this->nlpSessionDataObj->get('turfOptions');
      $mapRegionOptions = $this->nlpSessionDataObj->get('mapRegionOptions');
      $emptyDisplay = '';
      if($showLists) {
        if(empty($turfOptions)) {
          $emptyDisplay = "There are no saved lists in this folder.";
        }
      } elseif (empty($turfOptions)) {
        if(empty($mapRegionOptions)) {
          $emptyDisplay = "There are no map regions in the folder.";
        } else {
          $emptyDisplay = "There are no turfs in the map region.";
        }
      }
      if($emptyDisplay) {
        $form['turf_select']['turf-empty'] = [
          '#markup' => "<p><b><br>$emptyDisplay</b></p>",
        ];
      } else {
        // Display a list of turfs in VoteBuilder for the user to select one.
        $form['turf_select']['table'] =
          $this->turfListDisplayObj->displayVoteBuilderTurfs($turfOptions,$showLists);
      }

      return $form;
    }

  /**
   * Builds the page for selecting an NL to sync a turf to.
   *
   * @return array - The form array for the page.
   */
  public function buildNlSelectPage(): array
    {
      $form = [];

      $form['name_search_note'] = [
        '#markup' => '<div class="big-ts-select-box" ><hr><h2>Search by name</h2><div>For a new turf or for a turf to be assigned to a different
NL, you can search for the NL by name.  The search needle can be a fragment of text, and
the fields for first name, last name, email and MCID are searched for a match.</div>',
      ];

      $form['nl_search_by_name'] = [
        '#type' => 'submit',
        '#name' => 'nl_search_by_name',
        '#value' => 'Search for an NL by name',
        '#prefix' => '<div>',
        '#suffix' => '</div></div>',
        ];

      $form['district_search_note'] = [
        '#markup' => '<div class="big-ts-select-box" ><br><hr><h2>Search by district</h2>
<div>You can also look for an NL to assign from a list
of NLs in a House District or a Precinct.  When the lists are long, this is a good way to
find an NL when working on a specific Precinct. </div>',
      ];

      $form['nl_search_by_district'] = [
        '#type' => 'submit',
        '#name' => 'nl_search_by_district',
        '#value' => 'Search for an NL by House District or Precinct',
        '#prefix' => '<div>',
        '#suffix' => '</div></div>',
        ];

      return $form;
    }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * folderSelectedCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  public function folderSelectedCallback ($form,$unused): array
  {
    return $form['turf_select_page'];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * districtCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  public function districtCallback($form, $unused): array
  {
    return $form['nl_district']['nl_select_district'];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * selectboxCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  public function selectboxCallback ($form,$unused): array
  {
    return $form['nl_select'];
  }

}
