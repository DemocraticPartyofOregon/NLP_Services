<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpEmailConfiguration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class EnableCronTasksForm extends FormBase {

  protected NlpEmailConfiguration $emailConfigurationObj;

  public function __construct($config_factory, $nlpEmailConfigurationObj) {
    $this->configFactory = $config_factory;
    $this->emailConfigurationObj = $nlpEmailConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.email_configuration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'enable_cron_tasks_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $cronConfiguration = $this->config('nlpservices.configuration')
      ->get('nlpservices-cron-configuration');
    if (!is_array($cronConfiguration)) {
      $cronConfiguration = [];
    }

    $enableMinivan       = $cronConfiguration['enableMinivan'] ?? 0;
    $enableMatchback     = $cronConfiguration['enableMatchback'] ?? 0;
    $enableEmailRedirect = $cronConfiguration['enableEmailRedirect'] ?? 0;
    $enableTurfResync    = $cronConfiguration['enableTurfResync'] ?? 0;
    $enableVoterAcCheck  = $cronConfiguration['enableVoterAcCheck'] ?? 0;
    $enableTurfAcCheck   = $cronConfiguration['enableTurfAcCheck'] ?? 0;

    if ($this->emailConfigurationObj->emailCredentialsValidated('minivan')) {
      $form['enableMinivan'] = [
        '#type' => 'checkbox',
        '#id' => 'enableMinivan',
        '#title' => $this->t('Enable processing of minivan emails for this election.'),
        '#default_value' => $enableMinivan,
        '#description' => $this->t('When enabled, NLP Services will process the minivan emails.'),
      ];
    } else {
      $form['enableMinivan'] = [
        '#markup' => '<p>The email credentials for minivan email processing have not yet been configured.</p>'
      ];
    }

    if ($this->emailConfigurationObj->emailCredentialsValidated('matchback')) {
      $form['enableMatchback'] = [
        '#type' => 'checkbox',
        '#id' => 'enableMatchback',
        '#title' => $this->t('Enable processing of matchback emails for this election.'),
        '#default_value' => $enableMatchback,
        '#description' => $this->t('When enabled, NLP Services will process the matchback emails.'),
      ];
    } else {
      $form['enableMatchback'] = [
        '#markup' => '<p>The email credentials for matchback email processing have not yet been configured.</p>'
      ];
    }

    if ($this->emailConfigurationObj->emailCredentialsValidated('notifications')) {
      $form['enableEmailRedirect'] = [
        '#type' => 'checkbox',
        '#id' => 'enableEmailRedirect',
        '#title' => $this->t('Enable forwarding of emails to coordinators for this election.'),
        '#default_value' => $enableEmailRedirect,
        '#description' => $this->t('When enabled, NLP Services will redirect emails to the coordinators.'),
      ];
    } else {
      $form['enableEmailRedirect'] = [
        '#markup' => '<p>The email credentials for redirection of emails to notifications have not yet been configured.</p>'
      ];
    }

    $form['enableTurfResync'] = [
      '#type' => 'checkbox',
      '#id' => 'enableTurfResync',
      '#title' => $this->t('Enable turf resync for this election.'),
      '#default_value' => $enableTurfResync,
      '#description' => $this->t('When enabled, NLP Services will resync turf nightly.'),
    ];

    $form['enableVoterAcCheck'] = [
      '#type' => 'checkbox',
      '#id' => 'enableVoterAcCheck',
      '#title' => $this->t('Enable nightly check for missing NLP Voter activist codes.'),
      '#default_value' => $enableVoterAcCheck,
      '#description' => $this->t('When enabled, NLP Services will check for and apply missing NLP Voter activist codes nightly.'),
    ];

    $form['enableTurfAcCheck'] = [
      '#type' => 'checkbox',
      '#id' => 'enableTurfAcCheck',
      '#title' => $this->t('Enable turf activist code completion check.'),
      '#default_value' => $enableTurfAcCheck,
      '#description' => $this->t('When enabled, NLP Services will queue expired turf status records and verify that activist codes have been fully applied or removed, logging any incomplete vanids.'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();

    $cronConfiguration = $this->config('nlpservices.configuration')
      ->get('nlpservices-cron-configuration');
    if (!is_array($cronConfiguration)) {
      $cronConfiguration = [];
    }

    $cronConfiguration['enableMinivan']       = $values['enableMinivan'] ?? 0;
    $cronConfiguration['enableMatchback']     = $values['enableMatchback'] ?? 0;
    $cronConfiguration['enableEmailRedirect'] = $values['enableEmailRedirect'] ?? 0;
    $cronConfiguration['enableTurfResync']    = $values['enableTurfResync'] ?? 0;
    $cronConfiguration['enableVoterAcCheck']  = $values['enableVoterAcCheck'] ?? 0;
    $cronConfiguration['enableTurfAcCheck']   = $values['enableTurfAcCheck'] ?? 0;

    $this->configFactory->getEditable('nlpservices.configuration')
      ->set('nlpservices-cron-configuration', $cronConfiguration)
      ->save();

    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

}
