<?php /** @noinspection JSUnresolvedReference */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpTurfDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\ApiFolders;

/**
 * @noinspection PhpUnused
 */
class DeleteTurfForm extends FormBase
{
 
  protected NlpTurfs $turfs;
  
  protected NlpAwards $awardsObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpTurfDisplay $turfDisplayObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiFolders $foldersObj;
  
  
  public function __construct(   $turfs, $awardsObj, $searchBarObj, $turfDisplayObj,
                                 $nlpSessionDataObj, $nlpConfigurationObj, $foldersObj) {
    $this->turfs = $turfs;
    $this->awardsObj = $awardsObj;
    $this->searchBarObj = $searchBarObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->foldersObj = $foldersObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeleteTurfForm
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.turf_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.folders'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_delete_turf_form';
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
      $hdOptions = $this->turfs->getTurfHD($county); // HDs with turfs.
      $turfCount = $this->turfs->getCountyTurfCount($county);
      if($turfCount<$this->turfs::TURF_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      //nlp_debug_msg('$hdOptions',$hdOptions);
      $this->nlpSessionDataObj->setNewDistricts($hdOptions);
    }
    
    $county = $form_state->get('county');
    
    $currentHd = $this->nlpSessionDataObj->getSavedHd();
    //nlp_debug_msg('$currentHd',$currentHd);
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $turfArray = $this->turfs->getTurfs($county,'All');
      $form_state->set('search-needle', NULL);
      $turfArray = $this->searchBarObj->searchInfoArray($needle,$turfArray);
    } else {
      $turfArray = (!empty($currentHd))?$this->turfs->getTurfs($county,$currentHd):[];
    }
    //nlp_debug_msg('$turfArray',$turfArray);
    // Update the latest minivan information.
    $this->foldersObj->updateMinivanInfo($county, $turfArray);
    
    
    $form_state->set('turfArray',$turfArray);
    //nlp_debug_msg_safe('$turfArray',$turfArray);
    
    $turfCount = count($turfArray);
    //nlp_debug_msg('$turfCount',$turfCount);
    if(empty($turfCount)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>There is no turf to display.</p>',
      ];
      return $form;
    }
    
    $form['hd-change'] = [
      '#prefix' => '<div id="selectbox-div">',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
    ];
    
    $hdOptions = $this->nlpSessionDataObj->getHdOptions();
    $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
    $form['hd-change']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
    
    $form['hd-change']['turf-table'] = $this->turfDisplayObj->displayTurfs($turfArray);
    
    // add a button to delete the selected turf or turfs.
    $form['submit'] = [
      '#type' => 'submit',
      '#name' => 'delete_submit',
      '#value' => 'Delete Selected Turf(s) >>',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
    {
    $form_state->set('reenter',TRUE);
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('$elementClicked',$elementClicked);
    if ($elementClicked=='hd-select') {
      $newHdIndex = $form_state->getValue('hd-select');
      //nlp_debug_msg('$newHdIndex',$newHdIndex);
      // The user changed the HD to display.
      $this->nlpSessionDataObj->saveHdIndex($newHdIndex);
      return;
    }
    $values = $form_state->getValues();
    //nlp_debug_msg('$values',$values);
    $selections = $this->turfDisplayObj->validateTurfSelection($values['contacts']);
    $form_state->set('selections', $selections);
    
    if (count($selections) == 0) {
      $form_state->setErrorByName('submit', 'You must select at least one turf to delete.');
    }
    
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();  // form_state will persist
    
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {
      
      case 'search_submit':
        $needle = $form_state->getValue('nl-search-needle');
        $form_state->set('search-needle', $needle);
        return;
      
      default:
        $county = $form_state->get('county');
        // From the list of turfs in the list, find the ones to be deleted.
        $turfArray = $form_state->get('turfArray');
        //nlp_debug_msg('$turfArray',$turfArray);
        $selections = $form_state->get('selections');
        //nlp_debug_msg('$selections',$selections);
        
        
        foreach ($selections as $turfIndex => $selection) {
          
            $turfChoice = $turfArray[$turfIndex];
            $nickname = $turfChoice['nickname'];
            $lastName = $turfChoice['nlLastName'];
            $turfName = $turfChoice['turfName'];
            $mcid = $turfChoice['mcid'];
            //$turfIndex = $turfChoice['turfIndex'];
            
            $turf['mcid'] = $mcid;
            $turf['county'] = $county;
            $turf['turfIndex'] = $turfIndex;
            //nlp_debug_msg('$turf',$turf);
            
            $status = $this->turfs->deleteTurf($turf);
            if(!$status) {
              $messenger->addError(t('Turf remove failed'));
              return;
            }
            
            $this->awardsObj->nlParticipation($mcid,'Yes');
            
            // successful!
            $status_msg = $turfChoice['turfIndex']." ".$turfChoice['syncDate']." "."$nickname, $lastName, $turfName successfully deleted";
            $messenger->addStatus(t($status_msg));
          
        }
        
        break;
    }
    
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
    return $form['hd-change'];
  }
  
}
