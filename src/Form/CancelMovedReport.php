<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\NlpSearchBar;
//use Drupal\nlpservices\NlpTurfDisplay;
//use Drupal\nlpservices\NlpVoterDisplay;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpVoterListDisplay;


/**
 * @noinspection PhpUnused
 */

class CancelMovedReport extends FormBase
{
  
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpSessionData $sessionObj;
  protected PrivateTempStoreFactory $privateTempstoreObj;
  protected NlpTurfs $turfsObj;
  protected NlpSearchBar $searchBarObj;
  //protected NlpVoterDisplay $voterDisplayObj;
  protected NlpVoterListDisplay $voterListDisplayObj;
  
  public function __construct($votersObj, $reportsObj, $sessionObj, $privateTempstoreObj,
                              $turfsObj, $searchBarObj, $voterListDisplayObj )
  {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->sessionObj = $sessionObj;
    $this->privateTempstoreObj = $privateTempstoreObj;
    $this->turfsObj = $turfsObj;
    $this->searchBarObj = $searchBarObj;
    $this->voterListDisplayObj = $voterListDisplayObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): CancelMovedReport
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.session_data'),
      $container->get('tempstore.private'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.voter_list_display'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_cancel_moved_report_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
   
    if(empty($form_state->get('reenter'))) {
      $form_state->set('reenter',TRUE);
      $form_state->set('options',[]);
      
      $factory = Drupal::service('tempstore.private');
      $store = $factory->get('nlpservices.session_data');
      $county = $store->get('County');
      $form_state->set('county', $county);
    }
    
    $county = $form_state->get('county');
    if(empty($county)) {
      $messenger = Drupal::messenger();
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    
    $voterArray = [];
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $voterArray = $this->votersObj->searchVoters($county,$needle);
      $form_state->set('search-needle', NULL);
    }
    
    $form['options_display'] = $this->searchBarObj->searchBar();
    
    if (!empty($voterArray)) {
      $voterTableArray = $this->voterListDisplayObj->buildVoterTableArray($voterArray);
      
      $form['voter-table'] = $this->voterListDisplayObj->displayListOfVoters($voterTableArray);
      
    } else {
      $form['voter-table']['turf-form'] = [
        '#markup' => '<p>No voters to display</p>',
      ];
    }
    $form_state->set('voterArray',$voterArray);
    
    if(!empty($voterArray)) {
      $form['chooseVoter'] = [
        '#type' => 'submit',
        '#value' => 'Remove the moved status for selected voters.',
        '#name' => 'chooseVoter',
      ];
    }
    
    return $form;
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $messenger = Drupal::messenger();
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {
      case 'search_submit':
        $needle = $form_state->getValue('nl-search-needle');
        $form_state->set('search-needle', $needle);
        break;
      case 'chooseVoter':
        
        $contacts = $form_state->getValue('contacts');
        $selections = $this->voterListDisplayObj->validateVoterSelection($contacts);
        if(empty($selections)) {
          $messenger->addError(t('There were no users selected.'));
          return;
        }
       
        $voterArray = $form_state->get('voterArray');
        
        foreach ($selections as $selection) {
          $vanid = $selection['vanid'];
          $voter = $voterArray[$vanid];
          if(!$voter['moved']) {
            $messenger->addError(t('This voter was not marked as having moved.'));
          } else {
            $this->correctMovedStatus($voter);
          }
        }
        break;
    }
    
  }
  
  /**
   * @param $voter
   * @return void
   */
  function correctMovedStatus($voter): void
  {
    $messenger = Drupal::messenger();
    
    if(empty($voter['moved'])) {
      $messenger->addWarning("There is no report that ".$voter['firstName'].' '. $voter['lastName'] ."has moved.");
      return;
    }
    
    $config = Drupal::config('nlpservices.configuration');
    $electionDates = $config->get('nlpservices-election-configuration');
    $cycle = $electionDates['nlp_election_cycle'];
    
    $vanid = $voter['vanid'];
      $request['vanid'] = $vanid;
      $request['type'] = 'contact';
      $request['value'] = 'Moved';
      $request['cycle'] = $cycle;
      $report = $this->reportsObj->getReport($request);
      $existingRIndex = (!empty($report['reportIndex']))?$report['reportIndex']:NULL;  // Report exists.
    
    if(!empty($existingRIndex)) {
      $this->reportsObj->deletereport($existingRIndex);
    }
    $turfIndex = $voter['turfIndex'];
    if(!empty($turfIndex)) {
      $movedStatus = 0;   // This voter did not move,
      $this->votersObj->setMovedStatus($turfIndex,$vanid,$movedStatus);
    }
    $messenger->addMessage('The report that'.$voter['firstName'].' '. $voter['lastName'] .' moved has been removed.');
    }
  
}
