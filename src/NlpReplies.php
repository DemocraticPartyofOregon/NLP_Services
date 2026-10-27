<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpReplies
{
  protected ConfigFactoryInterface $configObj;
  protected NlpImap $imapObj;
  protected LanguageManagerInterface $languageManagerObj;
  protected MailManagerInterface $mailManagerObj;
  protected HtmlText $htmlText;
  protected NlpSendEmail $sendEmailObj;

  protected array $specialMessages = [
    'bounce' => [
      'failed'=>['subject'=>'Mail delivery failed','bodyParts'=>['Notification','Delivery report'],],
      'undelivered'=>['subject'=>'Undelivered Mail Returned','bodyParts'=>['Notification','Delivery report', 'Original email text'],],
      'undeliverable'=>['subject'=>'Undeliverable:','bodyParts'=>['Notification','Delivery report', 'Original email text'],],
      'reminder' => ['subject'=>'account login reminder','bodyParts'=>['Notification','Delivery report'],],
      ],
  ];

  public function __construct( $configObj, $imapObj, $languageManagerObj, $mailManagerObj, $htmlText, $sendEmailObj ) {
    $this->configObj = $configObj;
    $this->imapObj = $imapObj;
    $this->languageManagerObj = $languageManagerObj;
    $this->mailManagerObj = $mailManagerObj;
    $this->htmlText = $htmlText;
    $this->sendEmailObj = $sendEmailObj;
  }

  public static function create(ContainerInterface $container): NlpReplies
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.imap'),
      $container->get('language_manager'),
      $container->get('plugin.manager.mail'),
      $container->get('nlpservices.html_text'),
      $container->get('nlpservices.send_email'),
    );
  }

  /**
   * emailForward
   *
   * Check for any new emails in the notification email inbox.  Then check if any are target
   * emails for which the reply should have gone to the coordinator.  This condition should be
   * rare as the "reply-to" field is set to the coordinator.  But NLs can figure out how to
   * send to notifications.
   *
   * @return void
   * @noinspection PhpUnused
   * Used in module for Cron.
   */
  public function emailForward(): void
  {
    $messenger = Drupal::messenger();

    $ok = $this->imapObj->getImapConnection('notifications');
    if (!$ok) {
      Drupal::logger('nlpservices')->error("emailForward: Can't connect to email server.");
      return;
    }
    $newNotificationReplies = $this->imapObj->getNewNotificationReplies();
    if (empty($newNotificationReplies)) {
      return;
    }

    foreach ($newNotificationReplies as $emailNumber => $info) {
      $message = $this->imapObj->getMessage($emailNumber);
      if (empty($message)) { continue; }

      $targetType = '';
      $targetMessage = NULL;
      if(!str_contains($message['subject'], 'NLP Notification: ')) {
        foreach ($this->specialMessages as $targetType=>$targetInfo) {
          foreach ($targetInfo as $targetId=>$target) {
            if(str_contains($message['subject'], $target['subject'])) {
              $targetMessage = $targetId;
              break 2;
            }
          }
        }
      }
      if(empty($targetMessage)) {
        $messenger->addMessage('Not a target message, Subject: '.$message['subject']);
        $this->setAsideForReview($emailNumber);
        continue;
      }
      $email = $recipientEmail = $body = $forwardText = '';
      switch ($targetMessage) {

        case 'reminder':
        case 'undeliverable':
        case 'failed':
        case 'undelivered':
          $email = $this->senderSearch($message);
          $recipientEmail = $this->recipientSearch($message);
          $body = $this->getBodyParts($message, $this->specialMessages[$targetType][$targetMessage]['bodyParts']);
          $forwardText = 'This is an automated message sent from the NLP Services website.  An email you sent has
bounced, you may need to contact the NL to get a <br>valid email or add the NLP Admin to their address book.';
          break;

        default:
          break;
      }

      if (empty($email)) {
        $messenger->addMessage('No email found. , Subject: '.$message['subject']);
        $this->setAsideForReview($emailNumber);
        continue;
      }
      if(empty($recipientEmail)) {
        $messenger->addMessage('Email forwarded to: ' . $email.', Subject: '.$message['subject']);
      } else {
        $messenger->addMessage('Email sent to: ' . $email . ', Bounced email was: '.$recipientEmail.', Subject: '.$message['subject']);
      }
      $to = $email;

      $subject = 'NLP Notification: '.$message['subject'];

      $forwardMsg = "<p>".$forwardText."<br />NLP Admin<br /><hr></p>";
      $message = $forwardMsg . $body;

      $this->sendEmailObj->sendEmail('forward_nl_reply',$to, $subject, $message);
    }
    $messenger->addMessage("All emails processed.");
  }

  /**
   * setAsideForReview
   *
   * Marks an email cron can't forward as read and flagged. Read keeps later
   * cron runs from fetching it again; the flag leaves it easy to spot in the
   * inbox for a person to ignore or answer.
   *
   * @param int $emailNumber
   * @return void
   */
  private function setAsideForReview(int $emailNumber): void
  {
    if(!$this->imapObj->setReadAndFlagged($emailNumber)) {
      Drupal::logger('nlpservices')->warning('emailForward: Could not mark email @number as read and flagged.',
        ['@number' => $emailNumber]);
    }
  }

  /**
   * senderSearch
   *
   * Search the message parts for the coordinator email address.
   *
   * @param array $message
   * @return string
   */
  private function senderSearch(array $message): string
  {
    foreach ($message as $parts) {
      if(!empty($parts['data'])) {
        if(!is_string($parts['data'])) {
          continue;
        }
        $sender = $this->senderPresent($parts['data']);
        if(!empty($sender)) {
          return $sender;
        }
      }
    }
    return '';
  }

  /**
   * senderPresent
   *
   * Search the email for the information identifying the coordinator.
   *
   * @param string $plainText
   * @return string
   */
  private function senderPresent(string $plainText): string
  {
    $posEmail = strrpos($plainText, 'Email:');
    if ($posEmail === FALSE) { return ''; }
    $endPos = strpos($plainText, "?", $posEmail + 6);
    if ($endPos === FALSE) { return ''; }
    $emailFragment = substr($plainText, $posEmail + 6, $endPos - $posEmail - 6);
    $emailStart = strpos($emailFragment, 'mailto:');
    if ($emailStart === FALSE) { return ''; }

    $email = substr($emailFragment, $emailStart + 7);
    if(filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
      return $email;
    }

    $textStart = strpos($plainText,'>',$posEmail + 7);
    $endPos = strpos($plainText, "<", $textStart);
    $email = substr($plainText, $textStart+1,$endPos-$textStart-1);
    if(filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
      return $email;
    }
    return '';
  }

  /**
   * getBodyParts
   *
   * Get the original text bounced email.  Depending on the email service,
   * the bounce format is different.
   *
   * @param array $message
   * @param array $partDescriptions
   * @return string
   */
  private function getBodyParts(array $message, array $partDescriptions): string
  {
    $body = '';
    foreach ($message as $parts) {
      if(empty($parts['data']) || !is_string($parts['data'])) { continue;}
      $description = (!empty($parts['description']))?$parts['description']:'';
      // When an email has only one part, its body may come without a type or subtype.
      $type = $parts['type'] ?? '';
      $subType = strtolower($parts['subtype'] ?? '');
      if (empty($description)) {
        if($type == 'Text' && $subType == 'plain') {
          $description = 'Notification';
        } elseif ($type == 'Plain' && $subType == 'delivery-status') {
          $description = 'Delivery report';
        } elseif ($type == 'Text' && $subType == 'html') {
          if(in_array($subType,$partDescriptions)) {
            $description = 'html';
          }
        } elseif( $type == 'Multipart' && $subType == 'alternative') {
          $description = 'Original email text';
        }
      }
      if($description == 'html') {
        $body .= $parts['data'];
      } else {
        if(in_array($description,$partDescriptions)) {
          $body .= "<p><b>".$description."</b></p><p>".
            str_replace(["\r\n", "\n", "\r"], ' <br>', $parts['data'])."</p>";
        }
      }
    }
    return $body;
  }

  /**
   * recipientSearch
   *
   * Check the various parts of the email message for the original recipient.
   *
   * @param array $message
   * @return string
   */
  private function recipientSearch(array $message): string
  {
    foreach ($message as $parts) {
      if(!empty($parts['data'])) {
        if(!is_string($parts['data'])) { continue; }
        $recipient = $this->recipientPresent($parts['data']);
        if(!empty($recipient)) {
          return $recipient;
        }
      }
    }
    return '';
  }

  /**
   * recipientPresent
   *
   * Get the email address of the original recipient from the bounce message.
   *
   * @param string $plainText
   * @return string
   */
  private function recipientPresent(string $plainText): string
  {
    $recipientPos = strrpos($plainText, 'Original-Recipient:');
    $lengthId = 19;
    if ($recipientPos === FALSE) {
      $recipientPos = strrpos($plainText, 'Final-Recipient:');
      $lengthId = 16;
      if ($recipientPos === FALSE) {
        return '';
      }
    }
    $emailPos = strpos($plainText, ";", $recipientPos + $lengthId)+1;

    $endPos = strpos($plainText, "\r", $emailPos);
    if ($endPos === FALSE) { return ''; }
    $email = substr($plainText, $emailPos, $endPos-$emailPos);
    $email = trim($email);

    if(filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
      return '';
    }
    return $email;
  }

}
