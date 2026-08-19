<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ElectionConfigurationForm extends FormBase {

  public function __construct($config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('config.factory'),
    );
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
    if (empty($electionConfiguration)) {
      $electionConfiguration = [
        'nlp_election_cycle'   => '',
        'nlp_cycle_name'       => '',
        'nlp_election_date'    => '',
        'nlp_early_voting_start_date' => '',
      ];
    }

    $form_state->set('electionConfiguration', $electionConfiguration);

    $form['nlp_election_cycle'] = [
      '#type' => 'textfield',
      '#id' => 'election_cycle',
      '#title' => $this->t('Election cycle title'),
      '#default_value' => $electionConfiguration['nlp_election_cycle'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => $this->t('A text value to identify this election cycle
      in the form yyyy-mm-t (t can be G, P, S, or U)'),
      '#required' => TRUE,
    ];

    $form['nlp_cycle_name'] = [
      '#type' => 'textfield',
      '#id' => 'cycle_name',
      '#title' => $this->t('Election cycle name'),
      '#default_value' => $electionConfiguration['nlp_cycle_name'],
      '#size' => 30,
      '#maxlength' => 120,
      '#description' => $this->t('A descriptive name for this election cycle.'),
      '#required' => TRUE,
    ];

    $form['nlp_election_date'] = [
      '#type' => 'textfield',
      '#id' => 'election_date',
      '#title' => $this->t('Election Date'),
      '#default_value' => $electionConfiguration['nlp_election_date'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => $this->t('Date of the election:  yyyy-mm-dd format'),
      '#required' => TRUE,
    ];

    $form['nlp_early_voting_start_date'] = [
      '#type' => 'textfield',
      '#id' => 'early_voting_start_date',
      '#title' => $this->t('Start of Early Voting Date'),
      '#default_value' => $electionConfiguration['nlp_early_voting_start_date'],
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => $this->t('Start date of early voting:  yyyy-mm-dd format'),
      '#required' => TRUE,
    ];

    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $electionCycleCheck = $this->validateCycle($values['nlp_election_cycle']);
    if ($electionCycleCheck != 'ok') {
      $form_state->setErrorByName('nlp_election_cycle', $this->t($electionCycleCheck));
      return;
    }
    if (!$this->checkDate($values['nlp_election_date'])) {
      $form_state->setErrorByName('nlp_election_date',
        $this->t('The date must be in the form:  yyyy-mm-dd '));
    }
    if (!empty($values['nlp_early_voting_start_date']) && !$this->checkDate($values['nlp_early_voting_start_date'])) {
      $form_state->setErrorByName('nlp_early_voting_start_date',
        $this->t('The date must be in the form:  yyyy-mm-dd '));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $electionDates = [
      'nlp_election_cycle'   => $values['nlp_election_cycle'],
      'nlp_election_date'    => $values['nlp_election_date'],
      'nlp_early_voting_start_date' => $values['nlp_early_voting_start_date'],
      'nlp_cycle_name'       => $values['nlp_cycle_name'],
    ];

    $this->configFactory->getEditable('nlpservices.configuration')
      ->set('nlpservices-election-configuration', $electionDates)
      ->save();
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  private function validateCycle($cycle): string
  {
    $cycleFields = explode('-', $cycle);
    if (count($cycleFields) != 3) {
      return 'The cycle must be in the form yyyy-mm-t.';
    }
    $eYear = $cycleFields[0];
    if (!is_numeric($eYear)) {
      return 'The year field must be numeric.';
    }
    if (strlen($eYear) != 4) {
      return 'The year field must be 4 digits.';
    }
    if ($eYear <= 2017) {
      return 'The year must be greater than 2017.';
    }
    $month = $cycleFields[1];
    if (!is_numeric($month)) {
      return 'The month field of the election cycle must be a numeric value.';
    }
    if ($month < 1 || $month > 12) {
      return 'The month field of the election cycle must be a number between 1 and 12.';
    }
    $cycleType = $cycleFields[2];
    if ($cycleType == 'G' || $cycleType == 'P' || $cycleType == 'S' || $cycleType == 'U') {
      return 'ok';
    }
    return 'The cycle type must be G, P, S or U.';
  }

  private function checkDate($date): bool
  {
    $dateFields = explode('-', $date);
    if (count($dateFields) != 3) { return FALSE; }
    if (strlen($dateFields[0]) != 4) { return FALSE; }
    if (strlen($dateFields[1]) != 2) { return FALSE; }
    if (strlen($dateFields[2]) != 2) { return FALSE; }
    return checkdate($dateFields[1], $dateFields[2], $dateFields[0]);
  }

}
