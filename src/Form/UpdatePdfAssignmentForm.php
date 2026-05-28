<?php

namespace Drupal\nlpservices\Form;


use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpNlsListDisplay;
use Drupal\nlpservices\NlpPaths;
use Drupal\nlpservices\NlpSyncTurfBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiSavedLists;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpTurfListDisplayVoteBuilder;
use Drupal\nlpservices\NlpTurfDisplay;

class UpdatePdfAssignmentForm extends FormBase

{
  
  protected ApiSavedLists $savedListsObj;
  protected ApiFolders $foldersObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpNlsListDisplay $nlsListDisplayObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpTurfListDisplayVoteBuilder $turfListDisplayObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $fileSystem;
  protected NlpSyncTurfBuilder $syncTurfBuilderObj;
  protected NlpTurfDisplay $turfDisplayObj;
  
  public function __construct(  $savedListsObj, $foldersObj, $nlsObj, $turfsObj,
                                $nlsListDisplayObj,
                                $nlpConfigurationObj, $nlpSessionDataObj, $turfListDisplayObj,
                                $pathsObj, $fileSystem, $syncTurfBuilderObj, $turfDisplayObj ) {
    $this->savedListsObj = $savedListsObj;
    $this->foldersObj = $foldersObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->nlsListDisplayObj = $nlsListDisplayObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->turfListDisplayObj = $turfListDisplayObj;
    $this->pathsObj = $pathsObj;
    $this->fileSystem = $fileSystem;
    $this->syncTurfBuilderObj = $syncTurfBuilderObj;
    $this->turfDisplayObj = $turfDisplayObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): UpdatePdfAssignmentForm
  {
    return new static(
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.api_folders'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.nls_list_display'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.turf_list_display_votebuilder'),
      $container->get('nlpservices.paths'),
      $container->get('file_system'),
      $container->get('nlpservices.sync_turf_builder'),
      $container->get('nlpservices.turf_display')
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_update_pdf_assignment_form';
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
      $mcids = $this->turfsObj->getNlsWithTurfs($county);
      $hdList = $this->nlsObj->getDistinctHdByMcids($mcids);
      $pctList = $this->nlsObj->getDistinctPrecinctByMcids($mcids);
      $this->nlpSessionDataObj->setNewDistricts($hdList,$pctList);
      $precinctArray = $this->nlsObj->getDistinctHdPrecinctArrayByMcids($mcids);
      $form_state->set('precinctArray',$precinctArray);
      $form_state->set('hdList',$hdList);
      $form_state->set('hdList',$hdList);
      $form_state->set('pctList',$pctList);
      $form_state->set('currentHd',0);
      $form_state->set('currentPct','');
      $form_state->set('page','nlSelect');
    }
    
    $county = $form_state->get('county');
    $form['county-name'] = [
      '#markup' => "<h2>" . $county . " County</h2>",
    ];
    
    // If NL has more than one turf, ask which one to use.
    if($form_state->get('page') != 'nlSelect') {
      $form_state->set('page','nlSelect');
      
      $mcid = $form_state->get('mcid');
      $turfs = $this->turfsObj->getTurfsById($mcid);
      // $turfs contains the array of turfs for the given NL
      
      $form['turf_select'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Choose an turf from the list'),
        '#prefix' => " \n".'<div class="upa_nl_select_box" id="turf-select-wrapper" >'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
      
      $form['turf_select']['turfSelect'] = $this->turfDisplayObj->displayTurfs($turfs, 'replacement');
      
      $form['turf_select']['walksheet'] = $this->syncTurfBuilderObj->walksheet('re',FALSE);
      $form['turf_select']['replacement-walksheet'] = [
        '#type' => 'submit',
        '#name' => 'turf-replacement-walksheet',
        '#value' => 'Use the selected turf',
        '#attributes' => ['class' => ['upa_submit_font_size']],
      ];
      return $form;
    }
    
    $needle = $form_state->get('needle');
    if(!empty($needle)) {
      $mcids = $this->turfsObj->getNlsWithTurfs($county);
      $nls = $this->nlsObj->searchNls($needle, '', $mcids);
      $hdList = $this->nlpSessionDataObj->getHdOptions();
      array_unshift($hdList, 'Choose...');
      $selectedHdIndex = 0;
      $pctList = $this->nlpSessionDataObj->getPrecinctList();
      array_unshift($pctList, 'Choose...');
      $selectedPctIndex = 0;
      
    } else {
      
      $hdList = $this->nlpSessionDataObj->getHdOptions();
      array_unshift($hdList, 'Choose...');
      $currentHd = $form_state->get('currentHd');
      if (empty($currentHd)) {
        $selectedHdIndex = 0;
        $form_state->set('currentHdIndex', 0);
      } else {
        $selectedHdIndex = $form_state->get('currentHdIndex');
      }
      $pctList = $this->nlpSessionDataObj->getPrecinctList();
      array_unshift($pctList, 'Choose...');
      
      $currentPct = $form_state->get('currentPct');
      if (empty($currentPct)) {
        $selectedPctIndex = 0;
        $form_state->set('currentPctIndex', 0);
      } else {
        $selectedPctIndex = $form_state->get('currentPctIndex');
      }
      
      $mcids = $this->turfsObj->getNlsWithTurfs($county);
      if (empty($currentPct)) {
        if (empty($currentHd)) {
          $nls = $this->nlsObj->getNlsByIds($mcids);
        } else {
          $nls = $this->nlsObj->getNlsByIds($mcids, $currentHd);
          $precinctArray = $form_state->get('precinctArray');
          $pctList = $precinctArray[$currentHd];
          $pctList = array_values($pctList);
          array_unshift($pctList, 'Choose...');
        }
      } else {
        $nls = $this->nlsObj->getNlsByIds($mcids, 0, $currentPct);
      }
      
    }
    
    $form['nl_select_district_title'] = [
      '#markup' => '<div class="upa_nl_select_box" id="nl_search_wrapper"><hr>
<h2>Search for an NL by HD or precinct</h2></div>',
    ];
    $nlsTable = $this->nlsListDisplayObj->createTableArray($nls,TRUE);
    
    $form['nl_select'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Choose an NL from the list'),
      '#attributes' => ['class' => ['side-by-side-fieldset']],
      '#prefix' => " \n".'<div class="upa_nl_select_box" id="nls-form-div" >'." \n",
      '#suffix' => " \n".'</div>'." \n",
    ];
    
    $form['nl_select']['hd_precinct_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['side-by-side-container']],
    ];
    
    $form['nl_select']['hd_precinct_container']['nl_select_hd'] = [
      '#type' => 'select',
      '#title' => $this->t('House district'),
      '#options' => $hdList,
      '#default_value' => $selectedHdIndex,
      '#ajax' => [
        'callback' => '::districtCallback',
        'wrapper' => 'nl-select-wrapper',
      ],
      '#attributes' => ['class' => ['side-by-side-select']],
    ];
    $form['nl_select']['hd_precinct_container']['nl_select_precinct'] = [
      '#type' => 'select',
      '#title' => $this->t('Precinct name'),
      '#options' => $pctList,
      '#default_value' => $selectedPctIndex,
      '#ajax' => [
        'callback' => '::districtCallback',
        'wrapper' => 'nl-select-wrapper',
      ],
      '#attributes' => ['class' => ['side-by-side-select']],
    ];
    
    
    $form['nl_select']['name_search_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['side-by-side-container, upa-name-search-row']],
    ];
    
    $form['nl_select']['name_search_container']['nl_name_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name search'),
      '#size' => 20,
      '#attributes' => ['class' => ['side-by-side-text']],
    ];
    
    $form['nl_select']['name_search_container']['nl_name_search_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#submit' => ['::submitForm'],
      '#name' => 'nl_name_search_submit',
      '#attributes' => ['class' => ['side-by-side-button']],
    ];
    
    $form['nl_select']['nl-list'] = $this->nlsListDisplayObj->buildNlsTable($nlsTable,TRUE);
    
    $form['walksheet'] = $this->syncTurfBuilderObj->walksheet('',FALSE);
    $form['replacement-walksheet'] = [
      '#type' => 'submit',
      '#name' => 'replacement-walksheet',
      '#value' => 'Use the selected NL',
      '#attributes' => ['class' => ['upa_submit_font_size, form-item']],
    ];
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $values = $form_state->getValues();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
   
    switch ($elementClicked) {
      case 'nl_select_hd':
        $selectedHdIndex = $values['nl_select_hd'];
        $form_state->set('currentHdIndex', $selectedHdIndex);
        $form_state->set('currentPctIndex', 0);
        $form_state->set('currentPct', '');
        
        // Drupal prioritizes the user input for the form select over the default value for an ajax rebuild.
        // So, make it forget the user input.
        $form_state->unsetValue('nl_select_precinct');
        $form_state->setValue('nl_select_precinct', 0);
        $user_input = $form_state->getUserInput();
        unset($user_input['nl_select_precinct']);
        $form_state->setUserInput($user_input);
        
        $form_state->setRebuild();
        
        if(empty($selectedHdIndex)) {
          $form_state->set('currentHd', 0);
        } else {
          $hdList =$form_state->get('hdList');
          $form_state->set('currentHd', $hdList[$selectedHdIndex-1]);
        }
        break;
      case 'nl_select_precinct':
        $selectedPctIndex = $values['nl_select_precinct'];
        $form_state->set('currentPctIndex', $selectedPctIndex);
        $pctList = $form_state->get('pctList');
        $form_state->set('currentPct', $pctList[$selectedPctIndex-1]);
        break;
      case 'nl_name_search_submit':
        // Handle the name search submission.
        $needle = $values['nl_name_search'];
        if(empty($needle)) {
          $form_state->setErrorByName('nl_name_search', $this->t('Please enter text to search for.'));
        }
        $form_state->set('needle', $needle);
        break;
        
      case 'replacement-walksheet':
        $selections = $this->nlsListDisplayObj->validateNlSelection($form_state->getValue('contacts'));
        $nlSelection = reset($selections);
        $form_state->set('mcid',$nlSelection['mcid']);
        if(count($selections)!=1) {
          $form_state->setErrorByName('turf_submit',"You must select only one NL.");
        }
        
        $form_state->set('walksheetInfo',[]);
        $result = $this->validateWalksheet();
        if($result['result'] != 'ok') {
          //Save the walksheet file info so we can use it later.
          $form_state->set('walksheetInfo',$result['walksheetInfo']);
        }
        
        $turfs = $this->turfsObj->getTurfsById($nlSelection['mcid']);
        if (count($turfs) > 1) {
          // The selected NL has more than one turf.
          $form_state->set('page','turfSelect');
          break;
        }
        
        //$result = $this->validateWalksheet();
        if($result['result'] != 'ok') {
          $form_state->setErrorByName('turf_pdf',$result['message']);
        }
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
    
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    nlp_debug_msg('$elementClicked',$elementClicked);
    if($elementClicked == 'replacement-walksheet') {
      if($form_state->get('page') == 'turfSelect') {
        return;
      }
     $nlSelection =  $form_state->get('nlSelection');
     $mcid = $nlSelection['mcid'];
     
     $county = $form_state->get('county');
     $turfIndex = $form_state->get('turfIndex');
     $walksheetInfo = $form_state->get('walksheetInfo');
     // Save the PDF where we can find it.
     if (!empty($walksheetInfo['tempName'])) {
       $uniqueWalksheetName = "MCID".$mcid."_".$walksheetInfo['fileNameBase']
         .'_TI'.$turfIndex.'.'.$walksheetInfo['fileType'];
       
       $uri = $this->pathsObj->getPath('PDF', $county) . $uniqueWalksheetName;
       $this->fileSystem->moveUploadedFile($walksheetInfo['tempName'], $uri);
       $this->turfsObj->updateTurf($turfIndex, 'turfPDF', $uniqueWalksheetName);
     }
   }
    
  }
  
  function validateWalksheet($type=''): array
  {
    if (empty($_FILES['files'])) {
      return ['result' => 'ok', 'walksheetInfo' => [
        'fileName' => '',
        'tempName' => '',
        'fileNameBase' => '',
        'fileType' => '',
      ],];
    }
    
    $typeName = (empty($type)) ? 'turf_pdf' : 'turf_pdf_' . $type;
    
    // Now check that we have the PDF for the turf.
    if (empty($_FILES['files']['name'][$typeName])) {
      $fileName = $tempName = '';
      Drupal::logger('nlpservices')->notice('Turf name is missing: ');
    } else {
      $fileName = $_FILES['files']['name'][$typeName];
      $tempName = $_FILES['files']['tmp_name'][$typeName];
    }
    
    $fileType = $fileNameBase = '';
    if (!empty($fileName)) {
      $lcFileName = strtolower($fileName);
      $lcFileNameParts = explode('.', $lcFileName);
      $fileType = end($lcFileNameParts);
      $allowed = ['pdf','xls','xlsx'];
      if (!in_array($fileType, $allowed)) {
        return ['result'=>'error', 'message'=>'turf_pdf','The walksheet must be a PDF, XLS, or XLSX type.'];
      }
      $fileNameBase = $lcFileNameParts[0];
    }
    return ['result'=>'ok', 'walksheetInfo'=>[
      'fileName' => $fileName,
      'tempName' => $tempName,
      'fileNameBase' => $fileNameBase,
      'fileType' => $fileType,
    ],];
  }
  
  /**
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnused
   * @noinspection PhpUnusedParameterInspection
   */
  function selectboxCallback ($form,$unused): array
  {
    //Rebuild the form to list the NLs in the precinct after the precinct is selected.
    return $form['nl_select'];
  }
  
}