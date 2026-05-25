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
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\ApiFolders;

/**
 * @noinspection PhpUnused
 */
class DeleteTurfForm extends FormBase
{
 
  protected NlpTurfs $turfsObj;
  
  protected NlpAwards $awardsObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpTurfDisplay $turfDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected ApiFolders $foldersObj;
  
  
  public function __construct(   $turfsObj, $awardsObj, $searchBarObj, $turfDisplayObj,
                                 $sessionDataObj, $foldersObj) {
    $this->turfsObj = $turfsObj;
    $this->awardsObj = $awardsObj;
    $this->searchBarObj = $searchBarObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
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
      $container->get('nlpservices.api_folders'),
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
      $county = $this->sessionDataObj->getCounty();
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $form_state->set('county',$county);
      $hdOptions = $this->turfsObj->getTurfHD($county); // HDs with turfs.
      $turfCount = $this->turfsObj->getCountyTurfCount($county);
      if($turfCount<$this->turfsObj::TURF_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }
    
    $county = $form_state->get('county');

    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHd = $this->sessionDataObj->getSavedHd();
    
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $turfArray = $this->turfsObj->getTurfs($county,'All');
      $form_state->set('search-needle', '');
      $turfArray = $this->searchBarObj->searchInfoArray($needle,$turfArray);
    } else {
      $turfArray = (!empty($currentHd))?$this->turfsObj->getTurfs($county,$currentHd):[];
    }

    // Update the latest minivan information.
    // $this->foldersObj->updateMinivanInfo($county, $turfArray);

    // Create the table data for building the list of turfs.
    $turfTableArray = $this->turfDisplayObj->buildTurfTableArray($turfArray);

    // If there is a sort request, sort the turf array.
    $sort = Drupal::request()->query->get('sort', '');
    if(!empty($sort)) {
      $order = Drupal::request()->query->get('order', '');
      $this->turfDisplayObj->sortTurfTable($turfTableArray,$sort,$order);
    }
    $form_state->set('turfArray',$turfArray);

    $turfCount = count($turfTableArray);
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
    
    $hdOptions = $this->sessionDataObj->getHdOptions();
    $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
    $form['hd-change']['options_display'] = $this->searchBarObj->searchAndSortBar($hdOptions, $currentHdIndex);
    $form['hd-change']['turf-table'] = $this->turfDisplayObj->displayTurfs($turfTableArray);
    
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
    $elementClicked = empty($triggeringElement['#name'])?'':$triggeringElement['#name'];

    if ($elementClicked=='delete_submit') {
      $values = $form_state->getValues();
      $selections = $this->turfDisplayObj->validateTurfSelection($values['contacts']);
      $form_state->set('selections', $selections);

      if (count($selections) == 0) {
        $form_state->setErrorByName('submit', 'You must select at least one turf to delete.');
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
    $form_state->setRebuild();  // form_state will persist
    
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    //nlp_debug_msg('$elementClicked',$elementClicked);
    switch ($elementClicked) {

      // The user changed the HD to display.
      case 'hd_submit':
        $newHdIndex = $form_state->getValue('hd-select');
        $this->sessionDataObj->saveHdIndex($newHdIndex);
        break;

      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        //nlp_debug_msg('$needle',$needle);
        $form_state->set('search-needle', $needle);
        return;
      
      case 'delete_submit':
        $county = $form_state->get('county');
        // From the list of turfs in the list, find the ones to be deleted.
        $turfArray = $form_state->get('turfArray');
        $selections = $form_state->get('selections');
        if(empty($selections)) {return;}
        
        foreach ($selections as $turfIndex => $selection) {

          if(empty($turfArray[$turfIndex])) {
            $msg = var_export($selections, true);
            Drupal::logger('nlpservices')->notice('Delete turf error: '.$msg);
            continue;
          }
          
            $turfChoice = $turfArray[$turfIndex];
            $nickname = $turfChoice['nickname'];
            $lastName = $turfChoice['nlLastName'];
            $turfName = $turfChoice['turfName'];
            $mcid = $turfChoice['mcid'];
            //$turfIndex = $turfChoice['turfIndex'];
            
            $turf['mcid'] = $mcid;
            $turf['county'] = $county;
            $turf['turfIndex'] = $turfIndex;
            $status = $this->turfsObj->deleteTurf($turf);
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
}
