<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class NlpCredentialsEmail
{
  protected DrupalUser $drupalUserObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpMcidRecovery $mcidRecoveryObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSendEmail $sendMailObj;
  protected NlpNls $nlsObj;
  protected RequestStack $requestStack;
  protected MagicWord $magicWordObj;

  public function __construct( $drupalUserObj, $coordinatorsObj, $mcidRecoveryObj,
                               $nlpConfigurationObj, $sendMailObj, $nlsObj, $requestStack, $magicWordObj )
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->mcidRecoveryObj = $mcidRecoveryObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->sendMailObj = $sendMailObj;
    $this->nlsObj = $nlsObj;
    $this->requestStack = $requestStack;
    $this->magicWordObj = $magicWordObj;
  }


  public static function create(ContainerInterface $container): NlpCredentialsEmail
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.mcid_recovery'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.send_email'),
      $container->get('nlpservices.nls'),
      $container->get('request_stack'),
      $container->get('nlpservices.magic_word'),
    );
  }

  /**
   * sendCredentialsEmail
   *
   * Notify the user of the credentials to log into NLP Services to access the turf and
   * other information.
   *
   * The messageTemplate can be any plain or HTML text.  There can be keys inserted in
   * the text to be replaced by expanded information.  Specifically:
   * @nlpLogin - the login information for the user.
   * @coordinatorContactInfo - the contact information for the NL coordinator.
   * @plainNote - the note provided for this specific user.
   *
   * @param int $mcid - array with information about the selected turf.
   * @param string $messageTemplate
   * @param string $note - plain text to be included as a paragraph
   * @param array $miniVanArray
   * @param array|null $coordinator - contact info to use for @coordinatorContactInfo; if NULL,
   *   the coordinator assigned to the NL is looked up automatically. If no coordinator is found
   *   (or an empty array is passed), the NLP Admin notification address is used instead.
   * @param string $mailKey - the key passed to NlpSendEmail::sendEmail() for hook_mail().
   * @param string|null $subject - the email subject; if NULL, a default turf-delivery subject is used.
   * @param string|null $to - the recipient email address; if NULL, the NL's email from nlp_nls is used.
   * @return bool
   */
  public function sendCredentialsEmail(int $mcid, string $messageTemplate, string $note='', array $miniVanArray=[],
                                        ?array $coordinator=null, string $mailKey='deliver_turf', ?string $subject=null,
                                        ?string $to=null): bool
  {
    $nl = $this->nlsObj->getNlById($mcid);
    if(empty($nl)) {
      Drupal::logger('nlpservices')->notice('sendCredentialsEmail: NL not found for mcid: '.$mcid);
      return FALSE;
    }
    $county = $nl['county'];
    $nlCoordinator = $coordinator ?? $this->coordinatorsObj->getAssignedCoordinator($nl);
    if (empty($nlCoordinator)) {
      $nlCoordinator = [
        'firstName' => NLP_ADMIN_ROLE_LABEL,
        'lastName' => '',
        'phone' => '',
        'email' => $this->nlpConfigurationObj->getNotificationEmail(),
      ];
    }
    $coordinatorContactInfo = $this->coordinatorContactInfo($nlCoordinator, 'Request for help with turf');

    $serverUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();

    $nlpLoginInfo = "<p><b>Neighborhood Leader Login: </b>".
      '<a href="'.$serverUrl.'" target="_blank">'.$serverUrl.'</a>';
    $user = $this->drupalUserObj->getUserByMcid($mcid);
    if(empty($user) OR empty($this->magicWordObj->getMagicWord($mcid))) {
      // Without an account (the NL isn't active) or a saved password, there is
      // no login to send.
      Drupal::logger('nlpservices')->notice('sendCredentialsEmail: no account or saved password for mcid: '.$mcid);
      $nlpLoginInfo .= "<br>Ask your coordinator for your username and password.</p>";
    } else {
      $key = $this->mcidRecoveryObj->saveRecoveryKey($mcid);
      $revealUrl = $serverUrl . '/nlp-mcid-recovery?key=' . $key;
      $nlpLoginInfo .= "<br><b>Username:</b> ".$user['userName'].
        '<br><a href="' . $revealUrl . '">Click here to retrieve your password</a> (this link can only be used once)</p>';
    }

    $miniVanInfo = '';
    if(!empty($miniVanArray)) {
      $minivanNumber = $miniVanArray['minivanNumber'];
      $minivanCreateDate = $miniVanArray['minivanCreateDate'];
      $miniVanInfo = "<p><b>MiniVan Number: </b>$minivanNumber<br>MiniVan was created $minivanCreateDate</p>";
    }

    $plainNote = '';
    if (!empty($note)) {
      $plainNote = '<p>' . $note . '</p>';
    }

    $needles = ['@nlpLogin','@coordinatorContactInfo','@plainNote', '@miniVanInfo'];
    $replace = [$nlpLoginInfo,$coordinatorContactInfo,$plainNote, $miniVanInfo];
    $paragraphsAdded = str_replace($needles, $replace ,$messageTemplate);

    $electionDate = $this->nlpConfigurationObj->getElectionDate();
    $ballotDropDate = $this->nlpConfigurationObj->getBallotDropDate();
    $electionTimeStamp = strtotime($electionDate);
    $ballotDropTimeStamp = strtotime($ballotDropDate);

    $to ??= $nl['email'];

    $message = t($paragraphsAdded, [
      '@county' => $county,
      '@electionDay' => date("F j, Y",$electionTimeStamp),
      '@ballotDropDay' => date("F j, Y",$ballotDropTimeStamp),
      '@firstName' => $nl['firstName'],
      '@lastName' => $nl['lastName'],
      '@nickname' => (!empty($nl['nickname']))?$nl['nickname']:$nl['firstName'],
    ]);

    $subject ??= "Neighborhood Leader Materials - $county County";
    $reply = $nlCoordinator['email'] ?? null;

    $message = (string)$message;
    $result = $this->sendMailObj->sendEmail($mailKey,$to, $subject, $message, $reply);
    if (!$result) {
      Drupal::logger('nlpservices')->notice('sendCredentialsEmail failed for mcid: @mcid to: @to',
        ['@mcid' => $mcid, '@to' => $to]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * hasKnownLogin
   *
   * An NL has a login we can send when they have an account (they're active)
   * and a saved password.  Drupal keeps only a hash of the password, so a
   * blank saved one is unknown.
   *
   * @param int $mcid
   * @return bool
   */
  public function hasKnownLogin(int $mcid): bool
  {
    return !empty($this->drupalUserObj->getUserByMcid($mcid))
      && !empty($this->magicWordObj->getMagicWord($mcid));
  }

  /**
   * sendLoginHelpEmail
   *
   * Emails an NL who asked for help logging in their username and a one-time
   * link to their password, along with their coordinator's contact information.
   *
   * @param array $user - the NL's Drupal user array.
   * @return bool
   */
  public function sendLoginHelpEmail(array $user): bool
  {
    $mcid = (int) $user['mcid'];
    $key = $this->mcidRecoveryObj->saveRecoveryKey($mcid);
    if(empty($key)) {
      return FALSE;
    }
    $serverUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
    $revealUrl = $serverUrl . '/nlp-mcid-recovery?key=' . $key;

    $nl = $this->nlsObj->getNlById($mcid);
    $nlCoordinator = (!empty($nl)) ? $this->coordinatorsObj->getAssignedCoordinator($nl) : [];
    $firstName = (!empty($user['firstName'])) ? $user['firstName'] : 'Neighborhood Leader';

    $message = "<p>$firstName,</p>".
      "<p>Here is your login for NLP Services, as you asked.</p>".
      "<p><b>Neighborhood Leader Login: </b>".
      '<a href="'.$serverUrl.'" target="_blank">'.$serverUrl.'</a>'.
      "<br><b>Username:</b> ".$user['userName'].
      '<br><a href="' . $revealUrl . '">Click here to see your password</a> (this link can only be used once)</p>'.
      "<p>Type the username and password exactly as shown, or copy and paste them.  ".
      "You can also type your email address in place of the username.</p>";
    if(!empty($nlCoordinator)) {
      $message .= "<p>If you still can't log in, contact your coordinator:</p>".
        $this->coordinatorContactInfo($nlCoordinator, 'Request for help with login');
    }
    $message .= "<p>If you didn't ask for this email, you can ignore it.</p>";

    $subject = 'Your Neighborhood Leader login';
    $result = $this->sendMailObj->sendEmail('account_reminder', $user['email'], $subject, $message,
      $nlCoordinator['email'] ?? NULL);
    if (!$result) {
      Drupal::logger('nlpservices')->notice('sendLoginHelpEmail failed for: @userName',
        ['@userName' => $user['userName']]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * coordinatorContactInfo
   *
   * Returns a paragraph with the coordinator's name, phone and email, or '' if
   * the NL has no coordinator.
   *
   * @param array $nlCoordinator
   * @param string $subject - the subject for a reply by email.
   * @return string
   */
  private function coordinatorContactInfo(array $nlCoordinator, string $subject): string
  {
    if(empty($nlCoordinator)) {
      return '';
    }
    $firstName = $nlCoordinator['firstName'];
    $lastName = $nlCoordinator['lastName'];
    $phone = $nlCoordinator['phone'];
    $email = $nlCoordinator['email'];
    return "<p>$firstName $lastName<br>Phone: $phone<br>Email:".
      '<a href="mailto:'.$email.'?subject='.$subject.'">'."$email</a></p>";
  }
}