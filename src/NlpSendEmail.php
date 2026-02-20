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
   * @param $key
   * @param $to
   * @param $subject
   * @param $message
   * @param null $reply
   * @return boolean
   */
    public function sendEmail($key,$to, $subject, $message, $reply=NULL): bool
    {
      $messenger = Drupal::messenger();
      $languageCode = $this->languageManager->getDefaultLanguage()->getId();
      $this->htmlText->setHtml($message);
      $plainText = $this->htmlText->getText();
      $from = $this->nlpConfigurationObj->getNotificationEmail();
      
      $params['subject'] =  $subject;
      $params['List-Unsubscribe'] = "<mailto:$from?subject=unsubscribe>";
      $params['message'] = t($message);
      $params['plainText'] = $plainText;
      $params['from'] = $from;
      
      $result = $this->mailManager->mail(NLP_MODULE, $key, $to, $languageCode, $params, $reply);
      if (!$result['result']) {
        $messenger->addMessage('There was a problem sending your message and it was not sent.',
          $messenger::TYPE_ERROR,TRUE);
        return FALSE;
      }
      return TRUE;
    }
  
}