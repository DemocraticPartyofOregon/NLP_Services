<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class VoteBuilderUpdateDate extends FormBase
{
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($nlpConfigurationObj)
  {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
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
    //$config = $this->configFactory->get('nlpservices.configuration');
    //$votebuilderUpdateDate = $config->get('nlpservices-votebuilder-update-date');
    //$votebuilderUpdateDate = empty($votebuilderUpdateDate) ? date('Y-m-d') : $votebuilderUpdateDate;

    $votebuilderUpdateDate = $this->nlpConfigurationObj->getVoterBuilderUpdateDate();

    $form['votebuilderUpdateDate'] = [
      '#type' => 'textfield',
      '#id' => 'election_date',
      '#title' => $this->t('Date of last update of VoteBuilder'),
      '#default_value' => $votebuilderUpdateDate,
      '#size' => 16,
      '#maxlength' => 16,
      '#description' => $this->t("Date of the last update of voter registrations:  yyyy-mm-dd format"),
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
    //$this->configFactory->getEditable('nlpservices.configuration')
    //  ->set('nlpservices-votebuilder-update-date', $values['votebuilderUpdateDate'])
    //  ->save();
    $this->nlpConfigurationObj->setVotebuilderUpdateDate($values['votebuilderUpdateDate']);
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * checkDate
   *
   * Validates the provided date string.
   *
   * @param string $date
   *   The date string to validate, expected in the format 'YYYY-MM-DD'.
   *
   * @return bool
   *   TRUE if the date is valid, FALSE otherwise.
   */
  private function checkDate(string $date): bool
  {
    $dateFields = explode('-', $date);
    if ((count($dateFields) != 3) || (strlen($dateFields[0]) != 4) || (strlen($dateFields[1]) != 2) || (strlen($dateFields[2]) != 2)) {
      return FALSE;
    }
    return checkdate($dateFields[1], $dateFields[2], $dateFields[0]);
  }

}
