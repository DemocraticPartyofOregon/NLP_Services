<?php

namespace Drupal\nlpservices;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal;
use Drupal\Core\Mail\MailManagerInterface;

class NlpSendEmail
{
  // The mail plugin ids this module knows how to check.
  const string SMTP_PLUGIN = 'SMTPMailSystem';
  const string PHP_MAIL_PLUGIN = 'php_mail';

  protected MailManagerInterface $mailManager;
  protected HtmlText $htmlText;
  protected LanguageManagerInterface $languageManager;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ConfigFactoryInterface $configFactory;

  // Set once the plain-text warning is logged, so a batch of emails sent in
  // one request logs it only once.
  protected bool $htmlProblemLogged = FALSE;

  public function __construct(MailManagerInterface $mailManager, HtmlText $htmlText,
                              LanguageManagerInterface $languageManager,
                              NlpConfiguration $nlpConfigurationObj, ConfigFactoryInterface $configFactory)
  {
    $this->mailManager = $mailManager;
    $this->htmlText = $htmlText;
    $this->languageManager = $languageManager;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->configFactory = $configFactory;
  }

  public static function create(ContainerInterface $container): NlpSendEmail
  {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('nlpservices.html_text'),
      $container->get('language_manager'),
      $container->get('nlpservices.configuration'),
      $container->get('config.factory'),
    );
  }

  /**
   * sendEmail
   *
   * Logs a warning (once per request) when the site's mail setup will turn
   * the email into plain text; the email is still sent.
   *
   * @param string $key
   * @param string $to
   * @param string $subject
   * @param string $message
   * @param string|null $reply
   * @return bool
   */
  public function sendEmail(string $key, string $to, string $subject, string $message, ?string $reply = null): bool
  {
    $this->logHtmlMailProblem($key);
    $languageCode = $this->languageManager->getDefaultLanguage()->getId();
    $this->htmlText->setHtml($message);
    $plainText = $this->htmlText->getText();
    $from = $this->nlpConfigurationObj->getNotificationEmail();
    if (empty($from)) { return FALSE; }

    $params['subject'] = $subject;
    $params['headers']['List-Unsubscribe'] = "<mailto:$from?subject=unsubscribe>";
    $params['message'] = $message;
    $params['plainText'] = $plainText;
    $params['from'] = $from;

    $result = $this->mailManager->mail(NLP_MODULE, $key, $to, $languageCode, $params, $reply);
    if (!$result['result']) {
      Drupal::messenger()->addMessage(t('There was a problem sending your message and it was not sent.'),
        'error', TRUE);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * htmlMailProblem
   *
   * Returns why the site's mail setup will send NLP Services emails as plain
   * text, or '' if they go out as HTML (or the mailer is one this can't
   * check).  In plain text the links in an email don't work: Drupal moves
   * them to a list after the end of the message, where mail clients don't
   * show it.
   *
   * The mailer is the plugin system.mail names for this module's email key,
   * else for the module, else the default -- the same choice Drupal makes.
   *
   * @param string $key - the email key passed to sendEmail(), or '' for the
   *   module's mailer in general.
   * @return string
   */
  public function htmlMailProblem(string $key = ''): string
  {
    $interface = (array) ($this->configFactory->get('system.mail')->get('interface') ?? []);
    $plugin = ($key !== '' ? ($interface[NLP_MODULE . '_' . $key] ?? NULL) : NULL)
      ?? $interface[NLP_MODULE] ?? $interface['default'] ?? self::PHP_MAIL_PLUGIN;
    if ($plugin === self::PHP_MAIL_PLUGIN) {
      return 'NLP Services emails are sent by Drupal\'s default mailer, which sends them as plain text, '
        . 'so the links in them don\'t work. Turn on the SMTP module at Configuration > System > '
        . 'SMTP Authentication Support, and allow HTML there.';
    }
    if ($plugin === self::SMTP_PLUGIN && !$this->configFactory->get('smtp.settings')->get('smtp_allowhtml')) {
      return 'The SMTP module is set to send emails as plain text, so the links in NLP Services emails '
        . 'don\'t work. Tick "Allow to send e-mails formatted as HTML" at Configuration > System > '
        . 'SMTP Authentication Support.';
    }
    return '';
  }

  /**
   * logHtmlMailProblem
   *
   * Logs htmlMailProblem()'s warning, once per request.
   *
   * @param string $key
   * @return void
   */
  private function logHtmlMailProblem(string $key): void
  {
    if ($this->htmlProblemLogged) {
      return;
    }
    $problem = $this->htmlMailProblem($key);
    if ($problem === '') {
      return;
    }
    $this->htmlProblemLogged = TRUE;
    Drupal::logger('nlpservices')->warning('Email "@key" will be sent as plain text: @problem',
      ['@key' => $key, '@problem' => $problem]);
  }

}
