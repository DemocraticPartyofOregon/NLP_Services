<?php

namespace Drupal\nlpservices;

use Drupal;
use DateTime;
use IMAP\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpImap
{

  private array $messageType = [
    0 => 'Text',
    1 => 'Multipart',
    2 => 'Message',
    3 => 'Application',
    4 => 'Audio',
    5 => 'Image',
    6 => 'Video',
    7 => 'Other',
  ];

  public array $minivanSubjects = [
    'nlpresponsesexport' => 'survey' ,
    'nlpcontactsexport' => 'canvass',
    'nlpactivistcodesexport' => 'activist',
    'nlp survey' => 'survey' ,
    'nlp contacts' => 'canvass',
    'nlp activist' => 'activist',
  ];

  public array $matchbackSubject = ['matchback' => 'matchback',];

  /**
   * Don't retrieve a message part larger than this size in bytes, as it's
   * unlikely to be text, and we're only interested in text.
   */
  const int MAX_MAIL_PART_SIZE_BYTES = 20480;

  private ?Connection $connection = null;

  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct( $nlpConfigurationObj ) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpImap
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * getImapConnection
   *
   * Get the IMAP connection to the email server for a specific email.  The connection is
   * saved in the service for other functions.
   *
   * There are three types of emails, and each is configured in the NLP setup. The keys are:
   *
   * 1. `minivan` - the email address for minivan email
   * 2. `matchback` - the email address for the matchback email
   * 3. `notification` - the email address for the notification email
   *
   * @param string $emailName
   * @return bool
   */
  public function getImapConnection(string $emailName): bool
  {
    $emailInfo = $this->nlpConfigurationObj->getEmailConfiguration($emailName);
    if(empty($emailInfo)) {return false;}
    $hostname = '{'.$emailInfo['server'].':'.$emailInfo['port'].'/imap/ssl/novalidate-cert}INBOX';
    $connection = imap_open($hostname,$emailInfo['email'],$emailInfo['password']);
    if($connection === false) {
      $error = imap_last_error();
      // Clear c-client's error queue, or PHP prints it as notices at shutdown.
      imap_errors();
      Drupal::logger('nlpservices')->notice('getImapConnection: '.$error.', '.$emailName);
      return false;
    }
    $this->connection = $connection;
    return true;
  }

  /**
   * closeConnection
   *
   * Close the saved connection.
   *
   * @return void
   */
  public function closeConnection(): void
  {
    if($this->connection !== null) {
      imap_close($this->connection);
    }
  }

  /**
   * moveEmailToTrash
   *
   * Remove the email we just forwarded from the inbox.
   *
   * @param int $emailNumber
   * @return void
   */
  public function moveEmailToTrash(int $emailNumber): void
  {
    if($this->connection === null) {return;}
    imap_mail_move($this->connection,$emailNumber,'Trash');
    imap_expunge($this->connection);
  }

  /**
   * testImapConnection
   *
   * Test the IMAP connection to the email server for a specific email.
   *
   * @param array $emailInfo
   * @return array
   */
  public function testImapConnection(array $emailInfo): array
  {
    $hostname = '{'.$emailInfo['server'].':'.$emailInfo['port'].'/imap/ssl/novalidate-cert}INBOX';
    $connection = imap_open($hostname,$emailInfo['email'],$emailInfo['password']);
    if($connection === false) {
      $error = imap_last_error();
      // Clear c-client's error queue, or PHP prints it as notices at shutdown.
      imap_errors();
      return ['result'=>'error', 'message'=>$error, ];
    }
    imap_close($connection);
    return  ['result'=>'success', ];
  }

  /**
   * getAttachment
   *
   * Get the attachment from the email.  The matchback and minivan emails have an
   * attachment with the information to update the NLP database.
   *
   * @param array $parameters
   * @param string $attribute - one of two types of attributes: 'name' or 'filename'
   * @param int $emailNumber
   * @return array
   */
  private function getAttachment(array $parameters,string $attribute,int $emailNumber): array
  {
    $attachment = [];
    foreach($parameters as $object) {
      if(strtolower($object->attribute) == $attribute) {
        if(!empty($object->value)) {
          $attachment['is_attachment'] = true;
          $attachment['filename'] = $object->value;
          $attachment['emailNumber'] = $emailNumber;
        }
      }
    }
    return $attachment;
  }

  /**
   * getNewEmailsToProcess
   *
   * New emails are searched for a match with the expected subject content.
   * If the subject does not contain the expected content, the email is ignored.
   *
   * Returns an array indexed by emailNumber with keys:
   *   fileType - the type of email (minivan or matchback)
   *   day - the day of the month the email was sent
   *   month - the month the email was sent
   *   subject - the subject of the email
   *
   * @param string $emailType - either 'minivan' or 'matchback'
   * @return array
   */
  public function getNewEmailsToProcess(string $emailType): array
  {
    if($emailType == 'minivan') {
      $subjects = $this->minivanSubjects;
    } else {
      $subjects = $this->matchbackSubject;
    }
    $emails = imap_search($this->connection,'UNSEEN');
    $emailsToProcess = [];
    if(!empty($emails)) {
      foreach($emails as $emailNumber) {
        $overview = imap_fetch_overview($this->connection,$emailNumber);
        if(empty($overview)) {continue;}
        if($overview[0]->seen) {continue;}
        $subject = strtolower($overview[0]->subject);
        try {
          $dateObj = new DateTime($overview[0]->date);
        } catch (Exception $e) {
          nlp_debug_msg('getNewEmailsToProcess', $e);
          Drupal::logger('nlpservices')->notice(
            'getNewEmailsToProcess: Failed to parse date for email number = %id, error = %error',
            ['%id' => $emailNumber, '%error' => $e->getMessage()]
          );
          continue;
        }
        $day = $dateObj->format('j');
        $month = $dateObj->format('M');

        $fileType = '';
        foreach ($subjects as $emailSubject => $fileTypeName) {
          $pos = strpos($subject, $emailSubject);
          if($pos !== FALSE) {
            $fileType = $fileTypeName;
            break;
          }
        }
        if(!empty($fileType)) {
          $emailsToProcess[$emailNumber] = [
            'fileType' => $fileType,
            'day' => $day,
            'month' => $month,
            'subject' => $subject
          ];
        }
      }
    }
    return $emailsToProcess;
  }

  /**
   * getNewNotificationReplies
   *
   * Get the information about any new emails in the notification inbox.
   *
   * Return an array of email numbers and the day and month they were sent. The index is the
   * email number, and the value is an array with the day and month of the email.  The keys are:
   * day - the day of the month the email was sent
   * month - the month the email was sent
   * subject - the subject of the email
   *
   * @return array
   * @noinspection PhpUnused
   */
  public function getNewNotificationReplies(): array
  {
    $emails = imap_search($this->connection,'UNSEEN');
    $emailsToProcess = [];
    if(!empty($emails)) {
      foreach($emails as $emailNumber) {
        $overview = imap_fetch_overview($this->connection,$emailNumber);
        if(empty($overview)) {continue;}
        if($overview[0]->seen) {continue;}
        if(empty($overview[0]->subject)) {continue;}
        $subject = $overview[0]->subject;
        try {
          $dateObj = new DateTime($overview[0]->date);
        } catch (Exception $e) {
          nlp_debug_msg('getNewNotificationReplies', $e);
          Drupal::logger('nlpservices')->notice('getNewNotificationReplies: Failed to parse date for email number = %id, error = %error',
            ['%id' => $emailNumber, '%error' => $e->getMessage()]);
          continue;
        }
        $day = $dateObj->format('j');
        $month = $dateObj->format('M');
        $emailsToProcess[$emailNumber] = [
          'day' => $day,
          'month' => $month,
          'subject' => $subject
        ];
      }
    }
    return $emailsToProcess;
  }

  /**
   * getNewEmailAttachments
   *
   * Get the expected attachments from the target emails.  These emails originate from
   * a script created and run by the state party.  They contain information needed to update
   * the NLP database, but VoteBuilder does not provide a method available to the API to
   * get the desired information.   Specifically, the minivan emails contain canvass and survey data,
   * and the matchback emails contain ballot received dates.
   *
   * Returns an array with information about the attachments.  The keys are:
   *   `is_attachment` - true if the attachment is found
   *   `filename` - the name of the attachment
   *   `name` - the name of the attachment
   *   `attachment` - the attachment itself
   *
   * @param int $emailNumber
   * @return array
   */
  public function getNewEmailAttachments(int $emailNumber): array
  {
    $structure = imap_fetchstructure($this->connection,$emailNumber);
    $attachments = [];
    if(isset($structure->parts) && count($structure->parts)) {
      $partCount = count($structure->parts);
      for($i = 0; $i < $partCount; $i++) {
        $attachments[$i] = [
          'is_attachment' => false,
          'filename' => '',
          'name' => '',
          'attachment' => '',
        ];
        if($structure->parts[$i]->ifdparameters) {
          $attachment = $this->getAttachment($structure->parts[$i]->dparameters,'filename',$emailNumber);
          if(!empty($attachment)) {
            $attachments[$i] = $attachment;
          }
        }
        if($structure->parts[$i]->ifparameters) {
          $attachment = $this->getAttachment($structure->parts[$i]->parameters,'name',$emailNumber);
          if(!empty($attachment)) {
            $attachments[$i] = $attachment;
          }
        }
        if($attachments[$i]['is_attachment']) {
          $attachments[$i]['attachment'] = imap_fetchbody($this->connection, $emailNumber, $i+1);
          if($structure->parts[$i]->encoding == 3) { // 3 = BASE64
            $attachments[$i]['attachment'] = base64_decode($attachments[$i]['attachment']);
          }
          elseif($structure->parts[$i]->encoding == 4) { // 4 = QUOTED-PRINTABLE
            $attachments[$i]['attachment'] = quoted_printable_decode($attachments[$i]['attachment']);
          }
        }
      }
    }
    return $attachments;
  }

  /**
   * setUnread
   *
   * Mark the email as unread.
   *
   * @param int $emailNumber
   * @return bool
   */
  public function setUnread(int $emailNumber): bool
  {
    return imap_clearflag_full($this->connection, $emailNumber, '\\Seen');
  }

  /**
   * getMessage
   *
   * Return an array containing data from the various message parts.
   *
   * The results look much like this for a single-part email, with the
   * message headers in raw and parsed form contained in the first array field.
   *
   * Array (
   *   [0] => Array (
   *     [charset] => us-ascii
   *     [raw] => Return-Path:
   *               Delivered-To: account@domain.com
   *               Reply-To:
   *               From: "That Guy"
   *               To:
   *               Subject: test 1
   *               Date: Mon, 7 Feb 2011 19:37:07 -0800
   *               Message-ID:
   *               MIME-Version: 1.0
   *               Content-Type: text/plain;
   *               charset="us-ascii"
   *               Content-Transfer-Encoding: 7bit
   *               Content-Language: en-us
   *     [data] => Array(
   *       [Return-Path] =>
   *       [Delivered-To] => account@domain.com
   *       [Reply-To] =>
   *       [From] => "That Guy"
   *       [To] =>
   *       [Subject] => test 1
   *       [Date] => Mon, 7 Feb 2011 19:37:07 -0800
   *       [Message-ID] =>
   *       [MIME-Version] => 1.0
   *       [Content-Type] => text/plain;charset="us-ascii"
   *       [Content-Transfer-Encoding] => 7bit
   *     )
   *   )
   *   [1] => Array (
   *     [charset] => us-ascii
   *     [data] => example mail body, probably much longer in reality
   *   )
   * )
   *
   * Multipart mails will contain more array entries of data, one for each
   * part or file attachment.
   *
   * @param  int $messageNumber
   *   The message identifier.
   *
   * @return array
   *   The email.
   */
  public function getMessage(int $messageNumber): array
  {

    $overview = imap_fetch_overview($this->connection,$messageNumber);
    if(empty($overview)) {return [];}
    $subject = $overview[0]->subject;

    $mail = imap_fetchstructure($this->connection, $messageNumber);
    if (!is_object($mail)) {
      nlp_debug_msg('imap_fetchstructure',t('Failed to retrieve message structure for message number = %id',
        ['%id' => $messageNumber]));
      return [];
    }

    // $this->mailGetParts() will chase down all the components of a
    // multipart email but only return the headers of a single-part email.
    $mail = $this->mailGetParts($messageNumber, $mail, '0');
    $mail[0]['raw'] = $mail[0]['data'];
    $mail[0]['data'] = $this->parseMailHeadersIntoArray($mail[0]['raw']);
    if (!isset($mail[0]['charset'])) {
      // Probably wrong, but better than nothing.
      $mail[0]['charset'] = 'utf-8';
    }

    // So if it is only a single-part email, we have to go and fetch the body.
    if (count($mail) == 1) {
      $body = imap_body($this->connection, $messageNumber);
      if (!is_string($body)) {
        nlp_debug_msg('imap_body',t('Failed to retrieve message body for message number = %id',
          ['%id' => $messageNumber]));
      }
      // The body has the same type as the message itself.
      $mail[] = [
        'type' => $mail[0]['type'],
        'subtype' => $mail[0]['subtype'] ?? '',
        'data' => $body,
        'charset' => $mail[0]['charset'],
      ];
    }
    $mail['subject'] = $subject;
    return $mail;
  }

  /**
   * mailGetParts
   *
   * Return the parts of a multipart email.
   *
   * Recursively walk through the parts, get the content for each part,
   * and return the whole as nested arrays.
   *
   * @param  int $messageNumber
   *   The identifier for the message.
   * @param  object $part
   *   A message part.
   * @param  string $prefix
   *   Describing the position in the structure.
   *
   * @return array
   *   The parts of the mail as an array.
   */
  protected function mailGetParts(int $messageNumber, object $part, string $prefix): array
  {
    $attachments = [];
    $attachments[$prefix] = $this->mailDecodePart($messageNumber, $part, $prefix);
    if (isset($part->parts)) {
      // This is multipart.
      $prefix = ($prefix == '0') ? '' : $prefix . '.';
      foreach ($part->parts as $index => $subpart) {
        $attachments = array_merge(
          $attachments,
          // The $index below should be 0-based, but what needs to be passed
          // into the server is 1-based, hence the + 1.
          $this->mailGetParts($messageNumber, $subpart, $prefix . ($index + 1))
        );
      }
    }
    return $attachments;
  }

  /**
   * mailDecodePart
   *
   * Decode a part of a multipart email.
   *
   * @param int $messageNumber
   * @param object $part
   * @param string $prefix
   * @return array
   */
  protected function mailDecodePart(int $messageNumber, object $part, string $prefix): array
  {
    $attachment = [];

    $type = $part->type;
    $attachment['type'] = (!empty($this->messageType[$type]))?$this->messageType[$type]:$part->type;
    if(!empty($part->ifsubtype)) {
      $attachment['subtype'] = $part->subtype;
    }
    if(!empty($part->ifdescription)){
      $attachment['description'] = $part->description;
    }

    if (isset($part->ifdparameters) && $part->ifdparameters) {
      foreach ($part->dparameters as $object) {
        $attachment[mb_strtolower($object->attribute)] = $object->value;
        if (mb_strtolower($object->attribute) == 'filename') {
          $attachment['is_attachment'] = TRUE;
          $attachment['filename'] = $object->value;
        }
      }
    }

    if (isset($part->ifparameters) && $part->ifparameters) {
      foreach ($part->parameters as $object) {
        $attachment[mb_strtolower($object->attribute)] = $object->value;
        if (mb_strtolower($object->attribute) == 'name') {
          $attachment['is_attachment'] = TRUE;
          $attachment['name'] = $object->value;
        }
      }
    }

    // If this thing is large, return a string saying it is large.
    // Large items are generally not what we are looking for when searching for
    // bounce-related information.
    //
    // Note that if it has subparts, the byte count should be a sum of subpart
    // byte counts, so ignore it.
    if (!isset($part->parts) && isset($part->bytes) && $part->bytes > self::MAX_MAIL_PART_SIZE_BYTES) {
      $attachment['data'] = t('Mail part too large to consider: @bytes bytes', ['@bytes' => $part->bytes]);
      return $attachment;
    }

    $data = imap_fetchbody($this->connection, $messageNumber, $prefix);
    if (!is_string($data)) {
      nlp_debug_msg(t('Failed to retrieve message structure for message number = %id',
        ['%id' => $messageNumber]),'');
      $attachment['data'] = '';
    }
    else {
      if (isset($part->encoding)) {
        // 3 = BASE64.
        if ($part->encoding == 3) {
          $data = base64_decode($data);
        }
        // 4 = QUOTED-PRINTABLE.
        elseif ($part->encoding == 4) {
          $data = quoted_printable_decode($data);
        }
      }
      $attachment['data'] = $data;
    }
    return $attachment;
  }

  /**
   * parseMailHeadersIntoArray
   *
   * Parse a message header into an associative array of name-value pairs.
   *
   * @param  string $headers
   *   The email headers as a string.
   *
   * @return array
   *   An associated array of name-value pairs.
   */
  protected function parseMailHeadersIntoArray(string $headers): array
  {
    $headers = preg_replace('/\r\n\s+/m', '', $headers);
    /** @noinspection RegExpUnnecessaryNonCapturingGroup */
    preg_match_all('/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)?\r\n/m', $headers, $matches);
    $result = [];
    foreach ($matches[1] as $key => $value) {
      $result[$value] = $matches[2][$key];
    }
    return $result;
  }

  /**
   * checkEmail
   *
   * Check if there are any new emails.  There will be three each day sent by a script to
   * send the latest reports from a MiniVAN user for an NLP voter.  The email will contain
   * new reports, but there will be repeats to catch reports that were not immediately synced by
   * the NL.
   *
   * THe first email found will be retrieved, and the attachment converted to an array to be returned
   * to the caller. Check the email for the specified type. The type can be 'notifications', 'minivan'
   * or 'matchback'.   There will be only one attachment.  However, the format of emails allows multiple
   * attachments, so the code is written to handle multiple attachments.
   *
   * Note: the caller is responsible for calling moveEmailToTrash() after processing the returned items.
   *
   * @param string $type
   * @return array
   */
  public function checkEmail(string $type): array
  {
    $ok = $this->getImapConnection($type);
    if (!$ok) {
      Drupal::logger('nlpservices')->notice("Can't connect to email: $type");
      return [];
    }

    $emailsToProcess = $this->getNewEmailsToProcess($type);
    if(empty($emailsToProcess)) {return [];}

    $emailNumber = array_key_first($emailsToProcess);
    $emailToProcess =  $emailsToProcess[$emailNumber];

    $items = [];
    $attachmentsToProcess = $this->getNewEmailAttachments($emailNumber);
    foreach ($attachmentsToProcess as $attachment) {
      if (!empty($attachment['is_attachment'])) {
        $reports = str_getcsv($attachment['attachment'], "\n", escape: '');
        $headerRaw = $reports[0];
        $subject = $emailToProcess['subject'];
        $day = $emailToProcess['day'];
        $month = $emailToProcess['month'];
        $fileType = $emailToProcess['fileType'];

        unset($reports[0]);

        $item = [
          'header' => $headerRaw,
          'reports' => $reports,
          'fileType' => $fileType,
          'subject' => $subject,
          'month' => $month,
          'day' => $day,
          'emailCount' => count($emailsToProcess),
        ];
        $items[] = $item;
        }
      }
    imap_close($this->connection);
    return $items;
  }

}
