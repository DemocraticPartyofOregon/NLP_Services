<?php

namespace Drupal\nlpservices\Config;
 
  use Drupal\Core\Form\ConfigFormBase;
  use Drupal\Core\Form\FormStateInterface;
  use Symfony\Component\DependencyInjection\ContainerInterface;
  
  /**
   * @noinspection PhpUnused
   */
class VoteBuilderUpdateDate extends ConfigFormBase
{
  
  public function __construct($config_factory)
  {
    parent::__construct($config_factory);
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): VoteBuilderUpdateDate|ConfigFormBase|static
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
    return 'votebuilder_update_date_form';
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $config = $this->config('nlpservices.configuration');
    $votebuilderUpdateDate = $config->get('nlpservices-votebuilder-update-date');
    $votebuilderUpdateDate = (empty($votebuilderUpdateDate))?date('Y-m-d',time()):$votebuilderUpdateDate;
    $form_state->set('votebuilderUpdateDate', $votebuilderUpdateDate);
    
    $form['votebuilderUpdateDate'] = [
      '#type' => 'textfield',
      '#id' => 'election_date',
      '#title' => t('Date of last update of VoteBuilder'),
      '#default_value' => $votebuilderUpdateDate,
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => t("Date of the last update of voter registrations:  yyyy-mm-dd format"),
      '#required' => TRUE,
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * checkDate
   *
   * Verify that the date is in the form: yyyy-mm-dd. The month is
   * between 01 and 12 inclusive.  The day is within the allowed number of days
   * for the given month. Leap years are taken into consideration. The year is
   * between 0001 and 9999 inclusive.
   *
   * @param $date
   * @return bool
   */
  function checkDate($date): bool
  {
    $dateFields = explode('-', $date);
    if ((count($dateFields) != 3) OR (strlen($dateFields[0]) != 4) OR (strlen($dateFields[1]) != 2) OR (strlen($dateFields[2]) != 2)) {
      return FALSE;
    }
    // Check that the fields are within the legal range.
    return checkdate($dateFields[1], $dateFields[2], $dateFields[0]);
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    
    // Check the dates for ISO format.
    if (!$this->checkDate($values['votebuilderUpdateDate'])) {
      $form_state->setErrorByName('votebuilderUpdateDate',
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
    $votebuilderUpdateDate = $values['votebuilderUpdateDate'];
    $this->config('nlpservices.configuration')
      ->set('nlpservices-votebuilder-update-date', $votebuilderUpdateDate)
      ->save();
    parent::submitForm($form, $form_state);
  }
}
