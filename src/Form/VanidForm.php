<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

//use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\ApiNls;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpEncryption;
use Drupal\nlpservices\NlpLegislativeFixes;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpNls;
use Symfony\Component\DependencyInjection\ContainerInterface;

class VanidForm extends FormBase
{
  protected DrupalUser $drupalUserObj;
  protected ApiNls $apiNlsObj;
  protected NlpEncryption $nlpEncryptObj;
  protected NlpAwards $awardsObj;
  protected NlpCoordinators $coordinatorObj;
  protected NlpLegislativeFixes $legFixesObj;
  protected NlpReports $reportsObj;
  protected NlpTurfs $turfsObj;
  protected NlpVoters $votersObj;
  
  protected NlpNls $nlsObj;
  protected MagicWord $magicWordObj;
  
  
  public function __construct( $drupalUserObj, $apiNlsObj, $nlpEncryptObj, $awardsObj,
                               $coordinatorObj, $legFixesObj, $reportsObj, $turfsObj,
                               $votersObj, $nlsObj, $magicWordObj)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->apiNlsObj = $apiNlsObj;
    $this->nlpEncryptObj = $nlpEncryptObj;
    $this->awardsObj = $awardsObj;
    $this->coordinatorObj = $coordinatorObj;
    $this->legFixesObj = $legFixesObj;
    $this->reportsObj = $reportsObj;
    $this->turfsObj = $turfsObj;
    $this->votersObj = $votersObj;
    $this->nlsObj = $nlsObj;
    $this->magicWordObj = $magicWordObj;
  }
  
  public static function create(ContainerInterface $container): VanidForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.api_nls'),
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.legislative_fixes'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.magic_word'),
    );
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'vanid_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['previousNote'] = [
      '#markup' => "<p>Duplicate records for an NL can be removed by merging them in VoteBuilder.   The merge
will retain one record as the primary and destroy the other.  If the destroyed VANID is the one known to
NLP Services, the remaining record appears to be a new NL.  To transfer the known information about the NL,
this function can be used to update the NLP database.   The Previous VANID below must be non-existent, i.e., destroyed
for this function to work correctly.</p>

<p>It is highly recommended that merging duplicate records be avoided during an election.   If a merge must be done,
then be sure to identify the VANID in use by NLP Services as the primary.  If you do a merge and fail to correctly
 identify, then use this function. </p>",
    ];
    
    $form['previousId'] = [
      '#type' => 'number',
      '#title' => 'Previous VANID for the NL.',
      '#maxlength' => 12,
      '#required' => TRUE,
    ];
    
    $form['newNote'] = [
      '#markup' => "<p>The new VANID must be a valid number for a volunteer in the VoteBuilder database.</p>",
    ];
    
    $form['newId'] = [
      '#type' => 'number',
      '#title' => 'New VANID for the NL.',
      '#maxlength' => 12,
      '#required' => TRUE,
    ];
    
    
    $form['repairVanid'] = [
      '#type' => 'submit',
      '#value' => 'Repair VANID.',
      '#name' => 'repair-vanid',];
    
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $previousId = $form_state->getValue('previousId');
    $newVanid = $form_state->getValue('newId');
    $form_state->set('previousId',$previousId);
    $form_state->set('newVanid',$newVanid);
    
    $previousIdLen = strlen($previousId);
    if( $previousIdLen != 9 ) {
      $form_state->setErrorByName('previousId', "A VANID is 9 digits but the previous ID entered only has $previousIdLen.");
      return;
    } else {
      $previousNl = $this->apiNlsObj->getApiNls('',$previousId);
      if(!empty($previousNl)) {
        $form_state->setErrorByName('previousId', "This function is permitted only if the previous VANID has been destroyed.");
        return;
      }
    }
    
    $newIdLen = strlen($newVanid);
    if( $newIdLen != 9 ) {
      $form_state->setErrorByName('newId', "A VANID is 9 digits but the new ID entered only has $newIdLen.");
    } else {
      $newNl = $this->apiNlsObj->getApiNls('',$newVanid);
      if(empty($newNl)) {
        $form_state->setErrorByName('newId', "The newId must exist.");
      }
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    
    $previousId = $form_state->get('previousId');
    $newId = $form_state->get('newVanid');
    
    // Fix the login record so the NL can see their turf.
    $userPrevious = $this->drupalUserObj->getUserByMcid($previousId);
    if(!empty($userPrevious)) {
      $userNew = $this->drupalUserObj->getUserByMcid($newId);
      
      if(!empty($userNew)) {
        $this->magicWordObj->deleteMagicWord($previousId);
        $this->drupalUserObj->deleteUser($userPrevious['uid']);
      } else {
        $uid = $userPrevious['uid'];
        $editUpdate = [
          'uid' => $uid,
          'mcid' => $newId,
        ];
        $this->drupalUserObj->updateUser($editUpdate);
        $magicWord = $this->magicWordObj->getMagicWord($previousId);
        $this->magicWordObj->setMagicWord($newId,$magicWord);
        $this->magicWordObj->deleteMagicWord($previousId);
      }
    } else {
      $this->magicWordObj->deleteMagicWord($previousId);
    }
    
    // Transfer awards from old ID to new.
    $awardsPrevious = $this->awardsObj->getAward($previousId);
    if(!empty($awardsPrevious)) {
      $this->awardsObj->deleteAwards($previousId);
      $awardsPrevious['mcid'] = $newId;
      $this->awardsObj->mergeAward($awardsPrevious);
    }
    
    // Transfer the coordinator assignment.
    $coordinatorAssignments = $this->coordinatorObj->getCoordinatorById($previousId);
    if(!empty($coordinatorAssignments)) {
      foreach ($coordinatorAssignments as $coordinatorAssignment) {
        $cIndex = $coordinatorAssignment['cIndex'];
        $this->coordinatorObj->deleteCoordinator($cIndex);
        unset($coordinatorAssignment['cIndex']);
        $coordinatorAssignment['mcid'] = $newId;
        $this->coordinatorObj->createCoordinator($coordinatorAssignment);
      }
    }
    
    // Change the legislative fix for this NL.
    $legFix = $this->legFixesObj->getLegFix($previousId);
    if(!empty($legFix)) {
      $this->legFixesObj->deleteLegFix($previousId);
      $legFix['mcid'] = $newId;
      $this->legFixesObj->createLegFix($legFix);
    }
    
    // Change the reports by this NL to the new ID.
    $reports = $this->reportsObj->getReportsById($previousId);
    if(!empty($reports)) {
      foreach ($reports as $report) {
        $reportReq = [
          'reportIndex' => $report,
          'mcid' => $newId,
        ];
        $this->reportsObj->mergeReport($reportReq);
      }
    }
    
    // Reassign existing turfs associated with an old ID with the new ID.
    $turfs = $this->turfsObj->getTurfsById($previousId);
    if(!empty($turfs)) {
      foreach ($turfs as $turf) {
        $this->turfsObj->updateTurf($turf['turfIndex'],'mcid',$newId);
      }
    }
    
    // Fix the turf assignments.
    $turfAssignments = $this->turfsObj->getTurfAssignmentsById($previousId);
    if(!empty($turfAssignments)) {
      foreach ($turfAssignments as $turfAssignment) {
        $this->turfsObj->updateTurf($turfAssignment['turfId'],'mcid',$newId);
      }
    }
    
    // Convert NL record to the new ID.
    $newNlRecord = $this->nlsObj->getNlById($newId);
    if(!empty($newNlRecord)) {
      $county = $newNlRecord['county'];
      if(!empty($county)) {
        $this->nlsObj->deleteNl($previousId);
        $previousStatus = $this->nlsObj->getNlsStatus($previousId);
        if(!empty($previousStatus)) {
          $newStatus = $this->nlsObj->getNlsStatus($newId);
          $newStatus['county'] = $county;
          if($previousStatus['signedUp']) {$newStatus['signedUp'] = TRUE;}
          if($previousStatus['turfCut']) {$newStatus['turfCut'] = TRUE;}
          if($previousStatus['turfDelivered']) {$newStatus['turfDelivered'] = TRUE;}
          if($previousStatus['resultsReported']) {$newStatus['resultsReported'] = TRUE;}
          if($newStatus['asked'] == $this->nlsObj::DASH) {$newStatus['asked'] = $previousStatus['asked'];}
          if(empty($newStatus['notes'])) {$newStatus['notes'] = $previousStatus['notes'];}
          $this->nlsObj->setNlsStatus($newStatus);
          $this->nlsObj->deleteNlsStatus($previousId);
        }
      }
      
    } else {
      $previousNlRecord = $this->nlsObj->getNlById($previousId);
      if(!empty($previousNlRecord)) {
        $this->nlsObj->deleteNl($previousId);
        $previousNlRecord['mcid'] = $newId;
        $this->nlsObj->createNl($previousNlRecord);
        $county = $previousNlRecord['county'];
        $previousStatus = $this->nlsObj->getNlsStatus($previousId);
        if(empty($previousStatus['county'])) {
          $previousStatus['county'] = $county;
        }
        $previousStatus['mcid'] = $newId;
        $this->nlsObj->setNlsStatus($previousStatus);
        $this->nlsObj->deleteNlsStatus($previousId);
      }
      
    }
    
  }
}