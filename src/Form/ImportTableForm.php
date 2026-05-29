<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfAssignments;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\DatabaseFunctions;

use Symfony\Component\DependencyInjection\ContainerInterface;

class ImportTableForm extends FormBase
{
  protected DatabaseFunctions $databaseFunctionsObj;
  
  protected NlpAwards $nlpAwardsObj;
  protected NlpNls $nlpNlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpTurfAssignments $turfAssignmentsObj;

  public function __construct( $databaseFunctionsObj, $nlpAwardsObj, $nlpNlsObj, $turfsObj, $turfAssignmentsObj) {
    $this->databaseFunctionsObj = $databaseFunctionsObj;
    $this->nlpAwardsObj = $nlpAwardsObj;
    $this->nlpNlsObj = $nlpNlsObj;
    $this->turfsObj = $turfsObj;
    $this->turfAssignmentsObj = $turfAssignmentsObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportTableForm
  {
    return new static(
      $container->get('nlpservices.database_functions'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.turf_assignments'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_table_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $tableName=NULL): array
  {
    $form_state->set('tableName',$tableName);
    //nlp_debug_msg('$tableName',$tableName);
    $tableInfo = [
      'nlp_awards' => [
        'title' => 'Awards file',
        'value' => 'Import the NL awards file',
      ],
      'nlp_turf_assignment' => [
        'title' => 'Turf assignment file',
        'value' => 'Import the turf assignments file',
      ]
    ];

    if($tableName == 'nlp_awards') {
      $form['merge'] = [
        '#type' => 'checkbox',
        '#title' => t('Merge file with database'),
        '#description' => t('Check this box only if you are updating the awards from the use of a different site.'),
      ];
    }
    
    $form['table_file'] = [
      '#type' => 'file',
      '#title' => t($tableInfo[$tableName]['title']),
    ];
    
    $form['upload_file'] = [
      '#type' => 'submit',
      '#value' => t($tableInfo[$tableName]['value']),
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $tableName = $form_state->get('tableName');
    // Verify we have a good export file with the necessary fields
    $tempName = $_FILES['files']['tmp_name']['table_file'];
    
    $fh = fopen($tempName, "r");
    if (!$fh) {
      $form_state->setErrorByName('table_file', 'Failed to open table file.');
      return;
    }
    $rawHeader = fgetcsv($fh);
    if (!$rawHeader) {
      $form_state->setErrorByName('table_file', 'Failed to read header.');
      return;
    }
    $headerColumns = [];
    foreach ($rawHeader as $hdrColumn) {
      $headerColumns[] = trim(strip_tags(htmlentities(stripslashes($hdrColumn),ENT_QUOTES)));
    }
    $pos = [];
    switch ($tableName) {
      case 'nlp_awards':
        $pos = $this->nlpAwardsObj->decodeAwardHeader($headerColumns);
        break;
      case 'nlp_turf_assignment':
        $pos = $this->turfAssignmentsObj->decodeTurfAssignmentHeader($headerColumns);
        break;
    }
    $fieldPos = $pos['pos'];
    if (!empty($pos['err'])) {
      foreach($pos['err'] as $err) {
        $messenger->addError($err);
      }
      $form_state->setErrorByName('table_file', 'Fix the problem before resubmit.');
      return;
    }
    $form_state->set('tempName', $tempName);
    $form_state->set('fieldPos', $fieldPos);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $tableName = $form_state->get('tableName');
  
    $merge = $form_state->getValue('merge');
    $tempName = $form_state->get('tempName');
    $fieldPos = $form_state->get('fieldPos');
    $fh = fopen($tempName, "r");
    if (empty($fh)) {
      $messenger->addError('Failed to open table file.');
      return;
    }
    fgetcsv($fh);
    
    do {
      $rawRecord = fgetcsv($fh);
      if (!$rawRecord) {break;}
      $keys = [];
      $update = FALSE;
      $tableRecord = array_map(function ($pos) use ($rawRecord) {
        return $rawRecord[$pos];
      }, $fieldPos);
      
      switch ($tableName) {
        
        case 'nlp_awards':
          $update = TRUE;
          $keys = ['mcid' => $tableRecord['mcid']];
          if($merge) {
            //$participation = $rawRecord[$fieldPos['participation']];
            $participation = (array) json_decode($tableRecord['participation']);
            $awardKeys = array_keys($participation);
            $elections = [];
            foreach ($awardKeys as $key) {
              $elections[$key] = TRUE;
            }
            
            $existingAwards = $this->nlpAwardsObj->getAward($tableRecord['mcid']);
            $existingKeys = [];
            if(!empty($existingAwards['participation'])) {
              $existingParticipation = $existingAwards['participation'];
              $existingKeys = array_keys($existingParticipation);
            }
    
            if($awardKeys == $existingKeys) {
              $update = FALSE;
            } else {
              $existingElections = [];
              foreach ($awardKeys as $key) {
                $existingElections[$key] = TRUE;
              }
              $newElections = array_merge($existingElections,$elections);
              ksort($newElections);
              $tableRecord['electionCount'] = count($newElections);
              //$tableRecord['participation'] = $newElections;
              $tableRecord['participation'] = json_encode($newElections);
              
              $keys = ['mcid' => $tableRecord['mcid'],];
            }
          }
          $tableRecord['electionCount'] = (empty($tableRecord['electionCount']))? 0 : $tableRecord['electionCount'];
          $tableRecord['vfid'] = (empty($tableRecord['vfid']))? 0 : $tableRecord['vfid'];
          break;
          
        case 'nlp_turf_assignment':
          $update = TRUE;
          $keys = ['turfId' => $tableRecord['turfId'],];
          if(empty($tableRecord['dateAssigned'])) {
            unset($tableRecord['dateAssigned']);
          }
          $tableRecord['vfid'] = (empty($tableRecord['vfid']))? 0 : $tableRecord['vfid'];
          break;
      }
      
      if($update) {
        $success = $this->databaseFunctionsObj->mergeRecord($tableName,$keys,$tableRecord);
        if(!$success) {
          nlp_debug_msg('Failing update record',$tableRecord);
          $messenger->addError("The update of the $tableName table has failed, seek help.");
          return;
        }
      }
      
    } while (TRUE);
    
    $messenger->addStatus('The table has been successfully uploaded.');
  }
}
