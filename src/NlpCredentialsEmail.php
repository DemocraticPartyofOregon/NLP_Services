<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpCredentialsEmail
{
  protected DrupalUser $drupalUserObj;
  protected NlpPaths $pathsObj;
  protected NlpCoordinators $coordinatorsObj;
  protected MagicWord $magicWordObj;
  
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpSendEmail $sendMailObj;
  protected  NlpNls $nlsObj;
  
  public function __construct( $drupalUserObj, $pathsObj,
                               $coordinatorsObj, $magicWordObj,
                               $nlpConfigurationObj, $nlpSessionDataObj, $sendMailObj, $nlsObj )
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->pathsObj = $pathsObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->magicWordObj = $magicWordObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->sendMailObj = $sendMailObj;
    $this->nlsObj = $nlsObj;
  }
  
 
  public static function create(ContainerInterface $container): NlpCredentialsEmail
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.send_email'),
      $container->get('nlpservices.nls'),
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
  function sendCredentialsEmail(int $mcid, string $messageTemplate, string $note='', array $miniVanArray=[]): bool
  {
    $nl = $this->nlsObj->getNlById($mcid);
    $county = $nl['county'];
    $nlCoordinator = $this->coordinatorsObj->getAssignedCoordinator($nl);
    $coordinatorContactInfo = '';
    if(!empty($nlCoordinator)) {
      $firstName = $nlCoordinator['firstName'];
      $lastName = $nlCoordinator['lastName'];
      $phone = $nlCoordinator['phone'];
      $email = $nlCoordinator['email'];
      $coordinatorContactInfo = "<p>$firstName $lastName<br>Phone: $phone<br>Email:".
'<a href="mailto:@email?subject=Request for help with turf">'."$email</a></p>";
    }
    
    $serverName = $GLOBALS['_SERVER']['SERVER_NAME'];
    $serverUrl = 'https://'.$serverName;
    
    $magicWord = $this->magicWordObj->getMagicWord($mcid);
    if(empty($magicWord)) {
      $magicWord = 'Your Password';
    }
    
    $user = $this->drupalUserObj->getUserByMcid($mcid);
    if(empty($user)) {
      Drupal::logger('nlpservices')->notice('Unknown user: '.$mcid );
      $userName = 'unknown';
    } else {
      $userName = $user['userName'];
    }
    
    $nlpLoginInfo = "<p><b>Neighborhood Leader Login: </b>".
      '<a href="'.$serverUrl.'" target="_blank">'.$serverUrl.'</a>'.
      "<br><b>Username:</b> $userName<br><b>Password:</b> $magicWord</p>";
    
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
    
    $nl = $this->nlsObj->getNlById($mcid);
    $to = $nl['email'];
    
    $message = t($paragraphsAdded, [
      '@county' => $county,
      '@electionDay' => date("F j, Y",$electionTimeStamp),
      '@ballotDropDay' => date("F j, Y",$ballotDropTimeStamp),
      '@firstName' => $nl['firstName'],
      '@lastName' => $nl['lastName'],
      '@nickname' => (!empty($nl['nickname']))?$nl['nickname']:$nl['firstName'],
    ]);
    
    $subject =  "Neighborhood Leader Materials - $county County";
    $reply = $nlCoordinator['email'];
    
    $message = (string)$message;
    $result = $this->sendMailObj->sendEmail('deliver_turf',$to, $subject, $message, $reply);
    if (!$result) {
      Drupal::logger('nlpservices')->notice('There was a problem sending your message and it was not sent.');
      return FALSE;
    }
    
    return TRUE;
  }
}