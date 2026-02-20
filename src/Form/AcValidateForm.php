<?php

/** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\nlpservices\NlpReplies;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AcValidateForm extends FormBase
{
  protected ConfigFactoryInterface $config_factory;
  protected NlpReplies $repliesObj;
  public function __construct($repliesObj)
  {
    $this->repliesObj = $repliesObj;
    
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AcValidateForm
  {
    return new static(
      $container->get('nlpservices.email_replies'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'ac_validate_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    $counties = $votersObj->getParticipatingCounties();
    array_unshift($counties, 'Select counties');
    unset($counties[0]);
    $form_state->set('counties', $counties);
    
    $form['counties'] = [
      '#type' => 'checkboxes',
      '#title' => 'Counties to check for missing AC',
      '#options' => $counties,
    ];
    
    $form['fix'] = [
      '#type' => 'checkbox',
      '#title' => 'Set the missing AC',
    ];
    
    $form['form_test'] = [
      '#type' => 'submit',
      '#value' => 'Check for missing AC',
    ];
    
    return $form;
  }
 
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    
    $values = $form_state->getValues();
    $counties = $form_state->get('counties');
    $countyOptions = $values['counties'];
    $selectedCounties = [];
    foreach ($countyOptions as $index => $countyOption) {
      if (!empty($countyOption)) {
        $selectedCounties[] = $counties[$index];
      }
    }
    if (empty($selectedCounties)) {
      return;
    }
    //nlp_debug_msg('$selectedCounties',$selectedCounties);
    
    $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    
    $turfCount = 0;
    foreach ($selectedCounties as $selectedCounty) {
      $turfs = $turfsObj->getCountyTurfs($selectedCounty);
      $turfCount += count($turfs);
    }
    //nlp_debug_msg('$turfCount',$turfCount);
    
    $config = $this->config('nlpservices.configuration');
    $apiKeys = $config->get('nlpservices-api-keys');
    $nlpEncrypt = Drupal::getContainer()->get('nlpservices.encryption');
    
    $nlpVoterAC = $config->get('nlpservices_voter_ac');
    
    $stateCommitteeKey = $apiKeys['State Committee'];
    $stateCommitteeKey['API Key'] = $nlpEncrypt->encrypt_decrypt('decrypt', $stateCommitteeKey['API Key']);
    $form_state->set('stateCommitteeKey', $stateCommitteeKey);
    
    $stateCommitteeKey = $form_state->get('stateCommitteeKey');
    
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    // Set up the call to start a batch operation.
    $args = [
      'counties' => $selectedCounties,
      'fix' => $values['fix'],
      'turfCount' => $turfCount,
      'nlpVoterAC' => $nlpVoterAC,
      'stateCommitteeKey' => $stateCommitteeKey,
    ];
    $batch = [
      'operations' => [
        ['awakeBatch', [$args]]
      ],
      'file' => $modulePath . '/src/Form/AcValidateBatch.php',
      'finished' => 'acValidateBatchFinished',
      'title' => 'Processing AC Validation Batch.',
      'init_message' => 'AC Validation Batch is starting.',
      'progress_message' => 'Processed @percentage % of AC Validation Batch.',
      'error_message' => 'AC Validation Batch has encountered an error.',
    ];
    batch_set($batch);
  }
}
