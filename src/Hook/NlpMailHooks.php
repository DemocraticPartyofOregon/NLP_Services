<?php

namespace Drupal\nlpservices\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Builds the emails NLP Services sends.
 *
 * The account activation email that Drupal sends is turned off in the user
 * settings, see nlpservices_install().
 */
class NlpMailHooks
{
  const string LIST_UNSUBSCRIBE = '<mailto: notifications@nlpservices.org?subject=unsubscribe>';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_mail().
   *
   * Builds a message with both a plain text and an HTML version.  Every
   * message gets the unsubscribe header.
   *
   * @param string $key
   * @param array $message
   * @param array $params
   * @return void
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void
  {
    switch ($key) {
      case 'deliver_turf':
      case 'account_reminder':
      case 'forward_nl_reply':
      case 'help_request':
      case 'turf_deletion_notification':
      case 'missing_nl_notification':
      case 'nlp_admin_notice':
        $boundary = uniqid('np');
        $message['headers']['Content-Type'] = 'multipart/alternative; boundary="'. $boundary.'"';
        if(!empty($params['headers'])) {
          $message['headers'] = array_merge($message['headers'], $params['headers']);
        }
        $message['subject'] = $params['subject'];
        if(empty($params['from'])) {
          $message['from'] = $this->configFactory->get('system.site')->get('mail');
        } else {
          $message['from'] = $params['from'];
        }
        $msgPlain = "--" . $boundary . "\n";
        $msgPlain .= 'Content-Type: text/plain; charset=UTF-8'."\n";
        $msgPlain .= $params['plainText'];
        $msgHtml = "--" . $boundary . "\n";
        $msgHtml .= "Content-Type: text/html; charset=utf-8\n";
        $msgHtml .= $params['message'];
        $message['body'] = [$msgPlain.$msgHtml."\n--" . $boundary . "--"];
        break;
    }
    // Set last, so headers passed in $params can't replace it.
    $message['headers']['List-Unsubscribe'] = self::LIST_UNSUBSCRIBE;
  }

}
