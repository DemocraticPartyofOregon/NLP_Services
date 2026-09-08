<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

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
use Drupal\nlpservices\NlpTurfAssignments;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpNls;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReassignMcidForm extends FormBase
{
  protected DrupalUser $drupalUserObj;
  protected ApiNls $apiNlsObj;
  protected NlpEncryption $nlpEncryptObj;
  protected NlpAwards $awardsObj;
  protected NlpCoordinators $coordinatorObj;
  protected NlpLegislativeFixes $legFixesObj;
  protected NlpReports $reportsObj;
  protected NlpTurfAssignments $turfAssignmentsObj;
  protected NlpTurfs $turfsObj;

  protected NlpNls $nlsObj;
  protected MagicWord $magicWordObj;


  public function __construct( $drupalUserObj, $apiNlsObj, $nlpEncryptObj, $awardsObj,
                               $coordinatorObj, $legFixesObj, $reportsObj, $turfsObj,
                               $nlsObj, $magicWordObj, $turfAssignmentsObj)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->apiNlsObj = $apiNlsObj;
    $this->nlpEncryptObj = $nlpEncryptObj;
    $this->awardsObj = $awardsObj;
    $this->coordinatorObj = $coordinatorObj;
    $this->legFixesObj = $legFixesObj;
    $this->reportsObj = $reportsObj;
    $this->turfsObj = $turfsObj;
    $this->nlsObj = $nlsObj;
    $this->magicWordObj = $magicWordObj;
    $this->turfAssignmentsObj = $turfAssignmentsObj;
  }

  public static function create(ContainerInterface $container): ReassignMcidForm
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
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.turf_assignments'),
    );
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'reassign_mcid_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['previousNote'] = [
      '#markup' => t('<p>Duplicate records in MyCampaign for an NL can be removed by merging them in
VoteBuilder. The merge will retain one record as the primary and destroy the other. If the destroyed vanid
in MyCampaign is the one known to NLP Services as the MCID, the remaining record appears to be a new NL. To
transfer the known information about the NL, this function can be used to update the NLP database. The Previous
vanid in MyCampaign must be non-existent, i.e., destroyed for this function to work correctly.</p>
<p>It is highly recommended that merging duplicate records be avoided during an election. If a merge must be done,
then be sure to identify the vanid in MyCampaign in use by NLP Services as the primary. If you do a merge and fail
to correctly identify the primary, then use this function.</p>'),
    ];

    $form['previousId'] = [
      '#type' => 'number',
      '#title' => t('Previous MCID (vanid in MyCampaign) for the NL.'),
      '#maxlength' => 12,
      '#required' => TRUE,
    ];

    $form['newNote'] = [
      '#markup' => t('<p>The new MCID (vanid in MyCampaign) must be a valid number for a volunteer in the
MyCampaign database.</p>'),
    ];

    $form['newId'] = [
      '#type' => 'number',
      '#title' => t('New MCID for the NL.'),
      '#maxlength' => 12,
      '#required' => TRUE,
    ];


    $form['repairMcid'] = [
      '#type' => 'submit',
      '#value' => t('Repair MCID.'),
      '#name' => 'repair-mcid',];


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $previousId = $form_state->getValue('previousId');
    $newId = $form_state->getValue('newId');
    $form_state->set('previousId',$previousId);
    $form_state->set('newId',$newId);

    $previousIdLen = strlen($previousId);
    if( $previousIdLen != 9 ) {
      $form_state->setErrorByName('previousId', t('A vanid is 9 digits but the previous ID entered
      only has @count.', ['@count' => $previousIdLen]));
      return;
    }

    $newIdLen = strlen($newId);
    if( $newIdLen != 9 ) {
      $form_state->setErrorByName('newId', t('A vanid is 9 digits but the new ID entered only has
      @count.', ['@count' => $newIdLen]));
    } else {
      $newNl = $this->apiNlsObj->getApiNls('',$newId);
      if(empty($newNl)) {
        $form_state->setErrorByName('newId', t('The new vanid must exist in the MyCampaign database.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {

    $previousId = $form_state->get('previousId');
    $newId = $form_state->get('newId');

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
    $turfAssignments = $this->turfAssignmentsObj->getTurfAssignmentsById($previousId);
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
