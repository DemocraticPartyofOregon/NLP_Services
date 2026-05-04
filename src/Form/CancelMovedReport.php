<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpVoterListDisplay;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */

class CancelMovedReport extends FormBase
{
  
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpSessionData $sessionObj;
  protected NlpTurfs $turfsObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpVoterListDisplay $voterListDisplayObj;
  protected NlpConfiguration $nlpConfigurationObj;
  
  public function __construct($votersObj, $reportsObj, $sessionObj,
                              $turfsObj, $searchBarObj, $voterListDisplayObj, $nlpConfigurationObj )
  {
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->sessionObj = $sessionObj;
    $this->turfsObj = $turfsObj;
    $this->searchBarObj = $searchBarObj;
    $this->voterListDisplayObj = $voterListDisplayObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
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
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.voter_list_display'),
      $container->get('nlpservices.configuration'),
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
      $form_state->set('search-needle', '');
    }
    
    $county = $this->sessionObj->getCounty();
    if(empty($county)) {
      $messenger = Drupal::messenger();
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    
    $voterArray = [];
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $voterArray = $this->votersObj->searchVoters($county,$needle);
      $form_state->set('search-needle', '');
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
        $needle = $form_state->getValue('search-needle');
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
          $this->correctMovedStatus($voter);
        }
        break;
    }
    
  }
  
  /**
   * correctMovedStatus
   *
   * This function will remove the moved status for a voter if the voter has moved.
   * If the voter has not moved, the moved status will be set to 0.
   * If the voter has a report, the report will be removed.
   *
   * @param array $voter
   * @return void
   */
  function correctMovedStatus(array $voter): void
  {
    $messenger = Drupal::messenger();

    $cycle = $this->nlpConfigurationObj->getCycle();
    
    $vanid = $voter['vanid'];
    $request = [
      'vanid'=>$vanid,
      'type'=>'contact',
      'value'=>'Moved',
      'cycle'=>$cycle,
    ];
    $reports = $this->reportsObj->getReport($request,FALSE);  // Get all reports this cycle.

    if( empty($reports) && empty($voter['moved']) ) {
      $messenger->addWarning("There is no report that ".$voter['firstName'].' '. $voter['lastName'] ." has moved.");
      return;
    }


    if(!empty($reports)) {
      foreach ($reports as $report) {
        $existingRIndex = (!empty($report['reportIndex']))?$report['reportIndex']:0;  // Report exists.
        if(!empty($existingRIndex)) {
          $this->reportsObj->deletereport($existingRIndex);
        }
      }
    }

    if(!empty($voter['moved'])) {
      $turfIndex = $voter['turfIndex'];
      if(!empty($turfIndex)) {
        $movedStatus = 0;   // This voter did not move,
        $this->votersObj->setMovedStatus($turfIndex,$vanid,$movedStatus);
      }
    }

    $messenger->addMessage('The report that'.$voter['firstName'].' '. $voter['lastName'] .' moved has been removed.');
    }
  
}
