<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpEmailConfiguration;
use Drupal\nlpservices\NlpImap;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class EmailConfigurationForm extends FormBase {

  protected NlpImap $imapObj;
  protected NlpEmailConfiguration $emailConfigurationObj;

  public function __construct($config_factory, $imapObj, $emailConfigurationObj) {
    $this->configFactory = $config_factory;
    $this->imapObj = $imapObj;
    $this->emailConfigurationObj = $emailConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
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

    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('notifications');
    $form['notifications'] = $this->buildEmailInfo('notifications', $emailConfiguration);

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
    $form['minivan'] = $this->buildEmailInfo('minivan', $emailConfiguration);

    $form['matchbackPurpose'] = [
      '#markup' => '<hr><h2>Setup for the matchbacks email.</h2><p>
The state party also sends email with information about voting results.   When enabled, NLP Services will look </br>
for these emails and update the database.  Since voting is at the very end, this function needs not be enabled until </br>
voting starts.'
    ];

    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('matchback');
    $form['matchback'] = $this->buildEmailInfo('matchback', $emailConfiguration);

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
    $emailInfo = [];

    foreach ($values as $valueId => $value) {
      $valueIdParts = explode('_', $valueId);
      if ($valueIdParts[0] != 'info') { continue; }
      $type  = $valueIdParts[1];
      $field = $valueIdParts[2];
      if ($type == 'test' || $type == 'enable') {
        $emailInfo[$type][$field] = !empty($value);
      } else {
        $emailInfo[$type][$field] = $value;
      }
    }

    // Preserve existing password for any account whose field was left blank.
    foreach ($emailInfo as $type => &$configuration) {
      if (empty($configuration['password'])) {
        $existing = $this->emailConfigurationObj->getEmailConfiguration($type);
        $configuration['password'] = $existing['password'] ?? '';
      }
    }
    unset($configuration);

    $emailConfiguration = $form_state->get('emailConfiguration') ?? [];
    foreach ($emailInfo as $type => $configuration) {
      $errorMessage = $this->emailConfigurationObj->verifyConfiguration($configuration);
      if (!empty($errorMessage)) {
        $this->messenger()->addError($errorMessage);
        continue;
      }
      if (empty($configuration['server'])) { continue; }

      $test = $this->emailConfigurationObj->testEmailCredentials(
        $configuration['email'],
        $configuration['password'],
        (int) $configuration['port'],
        $configuration['server']
      );
      if (empty($test['result'])) {
        $this->messenger()->addError($this->t('Credential failure for @type: @message', [
          '@type' => $type,
          '@message' => strip_tags(str_replace('<br>', ' ', $test['message'] ?? '')),
        ]));
        continue;
      }
      $configuration['validated'] = TRUE;
      $emailConfiguration[$type] = $configuration;
    }

    $cronConfiguration = $this->config('nlpservices.configuration')
      ->get('nlpservices-cron-configuration');

    foreach ($emailConfiguration as $type => $configuration) {
      $cronType = NlpEmailConfiguration::CRON_TYPES[$type];
      $cronConfiguration[$cronType] = !empty($configuration['enable']);
      unset($configuration['enable']);
      $this->emailConfigurationObj->saveEmailConfiguration($type, $configuration);
    }

    $this->configFactory->getEditable('nlpservices.configuration')
      ->set('nlpservices-cron-configuration', $cronConfiguration)
      ->save();

    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  private function buildEmailInfo(string $type, array $emailConfiguration): array
  {
    $form_element['info_' . $type . '_server'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_server',
      '#title' => $this->t('IMAP incoming server for @type', ['@type' => $type]),
      '#default_value' => $emailConfiguration['server'],
      '#size' => 40,
      '#maxlength' => 120,
      '#description' => $this->t('Incoming server for reading @type emails.', ['@type' => $type]),
    ];

    $form_element['info_' . $type . '_port'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_port',
      '#title' => $this->t('IMAP port for reading @type email.', ['@type' => $type]),
      '#default_value' => $emailConfiguration['port'],
      '#size' => 40,
      '#maxlength' => 120,
      '#description' => $this->t('Port (typically 993).'),
    ];

    $form_element['info_' . $type . '_email'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_email',
      '#title' => $this->t('Email address'),
      '#default_value' => $emailConfiguration['email'],
      '#size' => 40,
      '#maxlength' => 60,
      '#description' => $this->t('Email address to read.'),
    ];

    $form_element['info_' . $type . '_password'] = [
      '#type' => 'password',
      '#id' => 'info_' . $type . '_password',
      '#title' => $this->t('Password for the email'),
      '#size' => 20,
      '#maxlength' => 20,
      '#description' => $this->t('Password for accessing the mail address. Leave blank to keep the existing password.'),
    ];

    if (!empty($emailConfiguration['validated'])) {
      $form_element['info_' . $type . '_validated'] = [
        '#markup' => '<b>The credentials have been validated.</b>',
      ];
    }

    $cronConfiguration = $this->config('nlpservices.configuration')
      ->get('nlpservices-cron-configuration');
    $cronType = NlpEmailConfiguration::CRON_TYPES[$type];
    $enableDefault = !empty($cronConfiguration[$cronType]);

    $form_element['info_' . $type . '_enable'] = [
      '#type' => 'checkbox',
      '#id' => 'info_' . $type . '_enable',
      '#title' => $this->t('Enable @type for this election.', ['@type' => $type]),
      '#default_value' => $enableDefault,
      '#description' => $this->t('When enabled, NLP Services will process @type emails.', ['@type' => $type]),
    ];

    return $form_element;
  }

}
