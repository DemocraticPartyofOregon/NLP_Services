<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class EnableCronTasksForm extends ConfigFormBase {
  
  protected Drupal\nlpservices\NlpEmailConfiguration $emailConfigurationObj;
  
  public function __construct($config_factory, $nlpEmailConfigurationObj) {
    parent::__construct($config_factory );
    $this->emailConfigurationObj = $nlpEmailConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): EnableCronTasksForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.email_configuration'),
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
    return 'enable_cron_tasks_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $nlpConfigObj = $this->config('nlpservices.configuration');
    $cronConfiguration = $nlpConfigObj->get('nlpservices-cron-configuration');
    if (!is_array($cronConfiguration)) {
      $cronConfiguration = [];
    }

    $enableMinivan = $cronConfiguration['enableMinivan'] ?? 0;
    $enableMatchback = $cronConfiguration['enableMatchback'] ?? 0;
    $enableEmailRedirect = $cronConfiguration['enableEmailRedirect'] ?? 0;
    $enableTurfResync = $cronConfiguration['enableTurfResync'] ?? 0;

    if($this->emailConfigurationObj->emailCredentialsValidated('minivan')) {
      $form['enableMinivan'] = [
        '#type' => 'checkbox',
        '#id' => 'enableMinivan',
        '#title' => t('Enable processing of minivan emails for this election.'),
        '#default_value' => $enableMinivan,
        '#description' => t("When enabled, NLP Services will process the minivan emails."),
      ];
    } else {
      $form['enableMinivan'] = [
        '#markup' => '<p>The email credentials for minivan email processing have not yet been configured.</p>'
      ];
    }
    
    if($this->emailConfigurationObj->emailCredentialsValidated('matchback')) {
      
      $form['enableMatchback'] = [
      '#type' => 'checkbox',
      '#id' => 'enableMatchback',
      '#title' => t('Enable processing of matchback emails for this election.'),
      '#default_value' => $enableMatchback,
      '#description' => t("When enabled, NLP Services will process the matchback emails."),
    ];
    } else {
      $form['enableMatchback'] = [
        '#markup' => '<p>The email credentials for matchback email processing have not yet been configured.</p>'
      ];
    }
    
    if($this->emailConfigurationObj->emailCredentialsValidated('notifications')) {
      $form['enableEmailRedirect'] = [
        '#type' => 'checkbox',
        '#id' => 'enableEmailRedirect',
        '#title' => t('Enable forwarding of emails to coordinators for this election.'),
        '#default_value' => $enableEmailRedirect,
        '#description' => t("When enabled, NLP Services will redirect emails to the coordinators."),
      ];
    } else {
      $form['enableEmailRedirect'] = [
        '#markup' => '<p>The email credentials for redirection of emails to notifications have not yet been configured.</p>'
      ];
    }

    $form['enableTurfResync'] = [
      '#type' => 'checkbox',
      '#id' => 'enableTurfResync',
      '#title' => t('Enable turf resync for this election.'),
      '#default_value' => $enableTurfResync,
      '#description' => t("When enabled, NLP Services with resync turf nightly."),
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    
    $nlpConfigObj = $this->config('nlpservices.configuration');
    $cronConfiguration = $nlpConfigObj->get('nlpservices-cron-configuration');
    if (!is_array($cronConfiguration)) {
      $cronConfiguration = [];
    }

    $cronConfiguration['enableMinivan'] = empty($values['enableMinivan'])?0:$values['enableMinivan'];
    $cronConfiguration['enableMatchback'] = empty($values['enableMatchback'])?0:$values['enableMatchback'];
    $cronConfiguration['enableEmailRedirect'] = empty($values['enableEmailRedirect'])?0:$values['enableEmailRedirect'];
    $cronConfiguration['enableTurfResync'] = empty($values['enableTurfResync'])?0:$values['enableTurfResync'];
    
    $this->config('nlpservices.configuration')
      ->set('nlpservices-cron-configuration', $cronConfiguration)
      ->save();
    
    parent::submitForm($form, $form_state);
  }
  
}
