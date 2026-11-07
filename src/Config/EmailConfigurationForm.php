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
    $form['#attributes']['style'] = 'width:600px;';

    $form['testFirst'] = [
      '#markup' => "<p>
Whenever you set up an email for the first time or change any of the credentials, it is
a good idea to test them to be sure NLP Services can read the email account.</p>"
    ];

    $form['notifications'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Setup for the Notifications email'),
      'purpose' => [
        '#markup' => "<p>
This email account is required.  NLP Services both sends from it and reads it.</p>
<p><b>Sending.</b>  Every email NLP Services sends, whether started by a user or sent automatically
to warn of a correctable error, goes out from this address through the SMTP module.  The SMTP
server, port, username and password are not entered here.  They are configured on the
<a href=\"/admin/config/system/smtp\">SMTP Authentication Support</a> page, which must use this same
account.  The site email in Basic site settings should also be this address.</p>
<p><b>Reading.</b>  The settings below are the account's incoming IMAP settings, not its SMTP settings.
NLP Services uses them to read the inbox and forward messages from NLs to their coordinator.
For Gmail, use imap.gmail.com and port 993, not smtp.gmail.com and port 587.</p>",
      ],
    ];
    $siteMail = $this->getSiteMail();
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('notifications');
    if (empty($emailConfiguration['email'])) {
      $emailConfiguration['email'] = $siteMail;
    }
    $this->warnIfNotSiteMail($emailConfiguration['email'], $siteMail);
    $form['notifications'] += $this->buildEmailInfo('notifications', $emailConfiguration);

    $form['minivan'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Setup for the MiniVAN email'),
      'purpose' => [
        '#markup' => "<p>
MiniVAN users report their voter contacts directly to VoteBuilder, and these reports are not visible to
NLP Services.  To make voter contacts visible to the NLs with the display of the turf by NLP Services,
the state party has a script that sends voter contact reports to an email address.   NLP Services can
read emails in the miniVAN account and update NLP voter contacts for the NL.  The script sends an email
during the night.   When this email account is set up and enabled, NLP Services will periodically look for
emails and update the NLP Services database.</p>",
      ],
    ];
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('minivan');
    $form['minivan'] += $this->buildEmailInfo('minivan', $emailConfiguration);

    $form['matchback'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Setup for the matchbacks email'),
      'purpose' => [
        '#markup' => '<p>
The state party also sends email with information about voting results.   When enabled, NLP Services will look
for these emails and update the database.  Since voting is at the very end, this function needs not be enabled until
voting starts.</p>',
      ],
    ];
    $emailConfiguration = $this->emailConfigurationObj->getEmailConfiguration('matchback');
    $form['matchback'] += $this->buildEmailInfo('matchback', $emailConfiguration);

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

    if (isset($emailInfo['notifications'])) {
      $this->warnIfNotSiteMail((string) ($emailInfo['notifications']['email'] ?? ''), $this->getSiteMail());
    }

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

  /**
   * getSiteMail
   *
   * The site email from Basic site settings.  Drupal core puts it in the
   * Sender and Return-Path headers of every email, so it must be the same
   * account the SMTP module sends from.
   *
   * @return string
   */
  private function getSiteMail(): string
  {
    return trim((string) $this->config('system.site')->get('mail'));
  }

  /**
   * warnIfNotSiteMail
   *
   * Warn when the site email isn't set up, or when the notifications email
   * isn't the site email.  Called from both build and submit with the same
   * messages; the messenger drops repeats, so a warning shows only once.
   *
   * @param string $notificationEmail
   * @param string $siteMail
   * @return void
   */
  private function warnIfNotSiteMail(string $notificationEmail, string $siteMail): void
  {
    if ($siteMail === '') {
      $this->messenger()->addWarning($this->t('The site email has not been set up. Set it in <a href="/admin/config/system/site-information">Basic site settings</a> to the notifications email address.'));
      return;
    }
    $notificationEmail = trim($notificationEmail);
    if ($notificationEmail !== '' && strcasecmp($notificationEmail, $siteMail) !== 0) {
      $this->messenger()->addWarning($this->t('The notifications email (@email) is not the same as the site email (@site). Emails sent by NLP Services may be rejected unless both are the account configured in the SMTP module.', [
        '@email' => $notificationEmail,
        '@site' => $siteMail,
      ]));
    }
  }

  /**
   * buildEmailInfo
   *
   * @param string $type
   * @param array $emailConfiguration
   * @return array
   */
  private function buildEmailInfo(string $type, array $emailConfiguration): array
  {
    $form_element['info_' . $type . '_server'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_server',
      '#title' => $this->t('IMAP incoming server for @type', ['@type' => $type]),
      '#default_value' => $emailConfiguration['server'],
      '#size' => 40,
      '#maxlength' => 120,
    ];
    $form_element['desc_' . $type . '_server'] = [
      '#markup' => '<p><small>' . $this->t('Incoming server for reading @type emails.', ['@type' => $type]) . '</small></p>',
    ];

    $form_element['info_' . $type . '_port'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_port',
      '#title' => $this->t('IMAP port for reading @type email.', ['@type' => $type]),
      '#default_value' => $emailConfiguration['port'],
      '#size' => 40,
      '#maxlength' => 120,
    ];
    $form_element['desc_' . $type . '_port'] = [
      '#markup' => '<p><small>' . $this->t('IMAP port (typically 993). Not the SMTP port used for sending.') . '</small></p>',
    ];

    $form_element['info_' . $type . '_email'] = [
      '#type' => 'textfield',
      '#id' => 'info_' . $type . '_email',
      '#title' => $this->t('Email address'),
      '#default_value' => $emailConfiguration['email'],
      '#size' => 40,
      '#maxlength' => 60,
    ];
    $emailHelp = ($type == 'notifications')
      ? $this->t('Email address to read. Also the From address for every email NLP Services sends, so it must be the account configured in the SMTP module.')
      : $this->t('Email address to read.');
    $form_element['desc_' . $type . '_email'] = [
      '#markup' => '<p><small>' . $emailHelp . '</small></p>',
    ];

    $form_element['info_' . $type . '_password'] = [
      '#type' => 'password',
      '#id' => 'info_' . $type . '_password',
      '#title' => $this->t('Password for the email'),
      '#size' => 20,
      '#maxlength' => 20,
    ];
    $form_element['desc_' . $type . '_password'] = [
      '#markup' => '<p><small>' . $this->t('Password for accessing the mail address (for Gmail, an app password). Leave blank to keep the existing password.') . '</small></p>',
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
    ];
    $form_element['desc_' . $type . '_enable'] = [
      '#markup' => '<p><small>' . $this->t('When enabled, NLP Services will process @type emails.', ['@type' => $type]) . '</small></p>',
    ];

    return $form_element;
  }

}
