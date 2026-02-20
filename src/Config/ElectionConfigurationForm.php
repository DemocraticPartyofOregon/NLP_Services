<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ElectionConfigurationForm extends ConfigFormBase {
  
  public function __construct($config_factory) {
    parent::__construct($config_factory);
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ElectionConfigurationForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array
  {
    return ['nlpservices.configuration'];
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'election_configuration_form';
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $config = $this->config('nlpservices.configuration');
    $electionConfiguration = $config->get('nlpservices-election-configuration');
    if(empty($electionConfiguration)) {
      $electionConfiguration = [
        'nlp_election_cycle' => '',
        'nlp_cycle_name' => '',
        'nlp_election_date' => '',
        'nlp_ballot_drop_date' => '',
      ];
    }
  
    $form_state->set('electionConfiguration',$electionConfiguration);
  
    $form['nlp_election_cycle'] = [
      '#type' => 'textfield',
      '#id' => 'election_cycle',
      '#title' => t('Election cycle title'),
      '#default_value' => $electionConfiguration['nlp_election_cycle'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => t("A text value to identify this election cycle
      in the form yyyy-mm-t (t can be G, P, S,or U)"),
      '#required' => TRUE,
    ];
    
    $form['nlp_cycle_name'] = [
      '#type' => 'textfield',
      '#id' => 'cycle_name',
      '#title' => t('Election cycle name'),
      '#default_value' => $electionConfiguration['nlp_cycle_name'],
      '#size' => 30,
      '#maxlength' => 120,
      '#description' => t("A descriptive name for this election cycle."),
      '#required' => TRUE,
    ];
    
    $form['nlp_election_date'] = [
      '#type' => 'textfield',
      '#id' => 'election_date',
      '#title' => t('Election Date'),
      '#default_value' => $electionConfiguration['nlp_election_date'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => t("Date of the election:  yyyy-mm-dd format"),
      '#required' => TRUE,
    ];
    
    $form['nlp_ballot_drop_date'] = [
      '#type' => 'textfield',
      '#id' => 'ballot_drop_date',
      '#title' => t('Date of Ballot Drop'),
      '#default_value' => $electionConfiguration['nlp_ballot_drop_date'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => t("Date the ballots drop:  yyyy-mm-dd format"),
      '#required' => TRUE,
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlp_cycle_validate
   *
   * @param $cycle
   * @return bool
   */
  function nlp_cycle_validate($cycle): bool
  {
    $cycleFields = explode('-', $cycle);
    $eYear = $cycleFields[0];
    if (!is_numeric($eYear) ) {
      return 'The year field must be numeric.';
    }
    if(strlen($eYear) != 4 AND $eYear > 2017) {
      return 'The year field must be 4 digits.';
    }
    $month = $cycleFields[1];
    if(!is_numeric($month)) {
      return 'The month field of the election cycle must be a numeric value.';
    }
    if($month < 0 OR $month >12) {
      return 'The month field of the election cycle must be a number between 1 and 12.';
    }
    $cycleType = $cycleFields[2];
    if($cycleType == 'G' OR $cycleType == 'P' OR $cycleType == 'S' OR $cycleType == 'U') {
      return 'ok';
    }
    return 'The cycle type must be G, P, S or U.';
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlp_check_date
   *
   * Verify that the date is in the form: yyyy-mm-dd. The month is
   * between 1 and 12 inclusive.  The day is within the allowed number of days
   * for the given month. Leap years are taken into consideration. The year is
   * between 1 and 32,767 inclusive.
   *
   * @param $date
   * @return bool
   */
  function nlp_check_date($date): bool
  {
    $dateFields = explode('-',$date);
    if(count($dateFields)!=3) {return FALSE;}
    if(strlen($dateFields[0])!=4) {return FALSE;}
    if(strlen($dateFields[1])!=2) {return FALSE;}
    if(strlen($dateFields[2])!=2) {return FALSE;}
    // Check that the fields are within the legal range.
    return checkdate($dateFields[1],$dateFields[2],$dateFields[0]);
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $electionCycleCheck = $this->nlp_cycle_validate($values['nlp_election_cycle']);
    if($electionCycleCheck != 'ok') {
      $form_state->setErrorByName('nlp_election_cycle',$this->t($electionCycleCheck));
      return;
    }
    // Check the dates for ISO format.
    if (!$this->nlp_check_date($values['nlp_election_date'])) {
      $form_state->setErrorByName('nlp_election_date',
        $this->t('The date must be in the form:  yyyy-mm-dd '));
    }
    if (!empty($values['nlp_ballot_drop_date']) AND !$this->nlp_check_date($values['nlp_ballot_drop_date'])) {
      $form_state->setErrorByName('nlp_ballot_drop_date',
        $this->t('The date must be in the form:  yyyy-mm-dd '));
    }
    parent::validateForm($form, $form_state);
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $electionDates = [];
    $electionDates['nlp_election_cycle'] = $values['nlp_election_cycle'];
    $electionDates['nlp_election_date'] = $values['nlp_election_date'];
    $electionDates['nlp_ballot_drop_date'] = $values['nlp_ballot_drop_date'];
    $electionDates['nlp_cycle_name'] = $values['nlp_cycle_name'];
    $electionDates['nlp_local_election'] = $values['nlp_local_election'];

    $this->config('nlpservices.configuration')
      ->set('nlpservices-election-configuration', $electionDates)
      ->save();
    parent::submitForm($form, $form_state);
  }
}
