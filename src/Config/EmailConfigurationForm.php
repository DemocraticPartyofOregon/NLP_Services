<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpImap;
use Drupal\nlpservices\NlpEmailConfiguration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class EmailConfigurationForm extends ConfigFormBase {
  
  protected NlpImap $imapObj;
  protected NlpEmailConfiguration $emailConfigurationObj;
  
  private array $cronTypes = [
    'notifications' => 'enableEmailRedirect',
    'minivan' => 'enableMinivan',
    'matchback' => 'enableMatchback',
  ];
  
  
  public function __construct($config_factory, $imapObj, $emailConfigurationObj) {
    parent::__construct($config_factory );
    $this->imapObj = $imapObj;
    $this->emailConfigurationObj = $emailConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): EmailConfigurationForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.imap'),
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
    return 'email_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    
    $form['testFirst'] = [
      '#markup' => "<p>
Whenever you set up an email for the first time or change any of the credentials, it is <br/>
a good idea to test them to be sure NLP Services can read the email account."
    ];
    
    $form['notificationsPurpose'] = [
      '#markup' => "<hr><h2>Setup for the Notifications email.</h2><p>
NLP Services uses emails to communicate with NLs, and the credentials for sending emails are </br>
set up here.  This notification email is required.  In addition, you can enable NLP Services </br>
to read the email account for messages from NLs that are intended for their coordinator.   Enabling </br>
this feature permits NLP Services to periodically look for emails that should be forwarded to </br>
the coordinator of the sending NL.  Hopefully, the need for and use of this feature will be rare."
    ];
    
    // Notifications email config.
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('notifications');
    $form['notifications'] = $this->buildEmailInfo('notifications',$emailConfiguration);
    
    // MiniVan email config.
    $form['minivanPurpose'] = [
      '#markup' => "<hr><h2>Setup for the MiniVAN email.</h2><p>
MiniVAN users report their voter contacts directly to VoteBuilder, and these reports are not visible to </br>
NLP Services.  To make voter contacts visible to the NLs with the display of the turf by NLP Services, </br>
the state party has a script that sends voter contact reports to an email address.   NLP Services can </br>
read emails in the miniVAN account and update NLP voter contacts for the NL.  The script sends an email </br>
during the night.   When this email account is set up and enabled, NLP Services will periodically look for </br>
emails and update the NLP Services database."
    ];
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('minivan');
    $form['minivan'] = $this->buildEmailInfo('minivan',$emailConfiguration);
    
    // Matchback email config.
    $form['matchbackPurpose'] = [
      '#markup' => '<hr><h2>Setup for the matcbacks email.</h2><p>
The state party also sends email with information about voting results.   When enabled, NLP Services will look </br>
for these emails and update the database.  Since voting is at the very end, this function needs not be enabled until </br>
voting starts.'
    ];
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('matchback');
    $form['matchback'] = $this->buildEmailInfo('matchback',$emailConfiguration);
    
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
    $messenger = Drupal::messenger();
    
    $values = $form_state->getValues();
    $emailInfo = [];
    
    foreach ($values as $valueId=>$value) {
      $valueIdParts = explode('_',$valueId);
      if($valueIdParts[0]!='info') {continue;}
      $type = $valueIdParts[1];
      $field = $valueIdParts[2];
      if($type=='test' OR $type=='enable') {
        $emailInfo[$type][$field] = !empty($value);
      } else {
        $emailInfo[$type][$field] = $value;
      }
    }
    
    $emailConfiguration = $form_state->get('emailConfiguration');
    foreach ($emailInfo as $type => $configuration) {
      $errorMessage = $this->emailConfigurationObj->verifyConfiguration($configuration);
      if(!empty($errorMessage)) {
        $messenger->addError($errorMessage);
        continue;
      }
      if(empty($configuration['server'])) {continue; }
      
      $success = $this->emailConfigurationObj->testEmailCredentials($configuration['email'], $configuration['password'],
        (int) $configuration['port'],$configuration['server']);
      if(!$success) {
        $messenger->addError('Credential failure');
        continue;
      }
      $configuration['validated'] = TRUE;
    
      $emailConfiguration[$type] = $configuration;
    }
    
    $nlpConfigObj = $this->config('nlpservices.configuration');
    $cronConfiguration = $nlpConfigObj->get('nlpservices-cron-configuration');
    
    foreach($emailConfiguration as $type => $configuration) {
      $cronType = $this->emailConfigurationObj::CRON_TYPES[$type];
      $cronConfiguration[$cronType] = !empty($configuration['enable']);
      unset($configuration['enable']);
      $this->emailConfigurationObj->saveEmailConfiguration($type, $configuration);
    }
    
    $this->config('nlpservices.configuration')
      ->set('nlpservices-cron-configuration', $cronConfiguration)
      ->save();
    
    parent::submitForm($form, $form_state);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildEmailInfo
   *
   * @param string $type
   * @param array $emailConfiguration
   * @return array
   */
  function buildEmailInfo(string $type,array $emailConfiguration): array
  {
    $form_element['info_'.$type.'_server'] = [
      '#type' => 'textfield',
      '#id' => 'info_'.$type.'_server',
      '#title' => t('IMAP incoming server for '.$type),
      '#default_value' => $emailConfiguration['server'],
      '#size' => 40,
      '#maxlength' => 120,
      '#description' => t("Incoming server for reading $type emails."),
    ];
    
    $form_element['info_'.$type.'_port'] = [
      '#type' => 'textfield',
      '#id' => 'info_'.$type.'_port',
      '#title' => t('IMAP port for reading '.$type.' email.'),
      '#default_value' => $emailConfiguration['port'],
      '#size' => 40,
      '#maxlength' => 120,
      '#description' => t("Port (typically 993)."),
    ];
    
    $form_element['info_'.$type.'_email'] = [
      '#type' => 'textfield',
      '#id' => 'info_'.$type.'_email',
      '#title' => t('Email address'),
      '#default_value' => $emailConfiguration['email'],
      '#size' => 40,
      '#maxlength' => 60,
      '#description' => t("Email address to read."),
    ];
    
    $form_element['info_'.$type.'_password'] = [
      '#type' => 'textfield',
      '#id' => 'info_'.$type.'_password',
      '#title' => t('Password for the email'),
      '#default_value' => $emailConfiguration['password'],
      '#size' => 20,
      '#maxlength' => 20,
      '#description' => t("Password for the accessing the mail address."),
    ];
    
    if(!empty($emailConfiguration['validated'])) {
      $form_element['info_'.$type.'_validated'] = [
        '#markup' => '<b>The credentials have been validated.</b>',
      ];
    }
    
    $nlpConfigObj = $this->config('nlpservices.configuration');
    $cronConfiguration = $nlpConfigObj->get('nlpservices-cron-configuration');
    $cronType = $this->cronTypes[$type];
    $enableDefault = !empty($cronConfiguration[$cronType]);
    
    $form_element['info_'.$type.'_enable'] = [
      '#type' => 'checkbox',
      '#id' => 'info_'.$type.'_enable',
      '#title' => t('Enable '.$type.' for this election.'),
      '#default_value' => $enableDefault,
      '#description' => t("When enabled, NLP Services with process'.$type..' emails."),
    ];
    
    return $form_element;
  }
  
}
