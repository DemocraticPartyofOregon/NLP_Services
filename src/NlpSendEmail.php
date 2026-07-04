<?php

namespace Drupal\nlpservices;

use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal;
use Drupal\Core\Mail\MailManagerInterface;

class NlpSendEmail
{
  protected MailManagerInterface $mailManager;
  protected HtmlText $htmlText;
  protected LanguageManagerInterface $languageManager;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($mailManager, $htmlText, $languageManager, $nlpConfigurationObj)
  {
    $this->mailManager = $mailManager;
    $this->htmlText = $htmlText;
    $this->languageManager = $languageManager;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpSendEmail
  {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('nlpservices.html_text'),
      $container->get('language_manager'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * sendEmail
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

}
