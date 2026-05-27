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

  public function __construct( $drupalUserObj, $coordinatorsObj, $mcidRecoveryObj,
                               $nlpConfigurationObj, $sendMailObj, $nlsObj, $requestStack )
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->mcidRecoveryObj = $mcidRecoveryObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->sendMailObj = $sendMailObj;
    $this->nlsObj = $nlsObj;
    $this->requestStack = $requestStack;
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
   * @return bool
   */
  public function sendCredentialsEmail(int $mcid, string $messageTemplate, string $note='', array $miniVanArray=[]): bool
  {
    $nl = $this->nlsObj->getNlById($mcid);
    if(empty($nl)) {
      Drupal::logger('nlpservices')->notice('sendCredentialsEmail: NL not found for mcid: '.$mcid);
      return FALSE;
    }
    $county = $nl['county'];
    $nlCoordinator = $this->coordinatorsObj->getAssignedCoordinator($nl);
    $coordinatorContactInfo = '';
    if(!empty($nlCoordinator)) {
      $firstName = $nlCoordinator['firstName'];
      $lastName = $nlCoordinator['lastName'];
      $phone = $nlCoordinator['phone'];
      $email = $nlCoordinator['email'];
      $coordinatorContactInfo = "<p>$firstName $lastName<br>Phone: $phone<br>Email:".
        '<a href="mailto:'.$email.'?subject=Request for help with turf">'."$email</a></p>";
    }

    $serverUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();

    $user = $this->drupalUserObj->getUserByMcid($mcid);
    if(empty($user)) {
      Drupal::logger('nlpservices')->notice('Unknown user: '.$mcid );
      $userName = 'unknown';
    } else {
      $userName = $user['userName'];
    }

    $key = $this->mcidRecoveryObj->saveRecoveryKey($mcid);
    $revealUrl = $serverUrl . '/nlp-mcid-recovery?key=' . $key;

    $nlpLoginInfo = "<p><b>Neighborhood Leader Login: </b>".
      '<a href="'.$serverUrl.'" target="_blank">'.$serverUrl.'</a>'.
      "<br><b>Username:</b> $userName".
      '<br><a href="' . $revealUrl . '">Click here to retrieve your password</a> (this link can only be used once)</p>';

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

    $to = $nl['email'];

    $message = t($paragraphsAdded, [
      '@county' => $county,
      '@electionDay' => date("F j, Y",$electionTimeStamp),
      '@ballotDropDay' => date("F j, Y",$ballotDropTimeStamp),
      '@firstName' => $nl['firstName'],
      '@lastName' => $nl['lastName'],
      '@nickname' => (!empty($nl['nickname']))?$nl['nickname']:$nl['firstName'],
    ]);

    $subject = "Neighborhood Leader Materials - $county County";
    $reply = $nlCoordinator['email'] ?? '';

    $message = (string)$message;
    $result = $this->sendMailObj->sendEmail('deliver_turf',$to, $subject, $message, $reply);
    if (!$result) {
      Drupal::logger('nlpservices')->notice('There was a problem sending your message and it was not sent.');
      return FALSE;
    }

    return TRUE;
  }
}