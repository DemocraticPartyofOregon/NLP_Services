<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\File\FileExists;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use Drupal\nlpservices\NlpReports;

/**
 * @noinspection PhpUnused
 */
class ExportVoterContactReportsForm extends FormBase
{

  const string NR_NLS_REPORTS = 'voter_contact_reports';

  protected NlpReports $reports;

  public function __construct( $reports) {
    $this->reports = $reports;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ExportVoterContactReportsForm
  {
    return new static(
      $container->get('nlpservices.reports'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_export_reports_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (!empty($form_state->get('reenter'))) {
      $args = $form_state->get('args');
      if(empty($args)) {
        $form['empty'] = [
          '#markup' => 'The current cycle has no reports.',
        ];
      } else {
        $form['count'] = [
          '#markup' => 'Count of voter contact reports in database: '.$args['countOfRecords'],
        ];
        $url = Drupal::service('file_url_generator')->generateAbsoluteString($args['uri']);
        $form['file'] = [
          '#markup' =>  '<p><a  href='.$url.'>Right-click here</a> to download the NLs reports. </p>',
        ];
      }
      
    } else {
      
      $cycleStartOptions = $this->reports->getKnownCycles();
      $cycleEndOptions = $cycleStartOptions;
      
      $form['currentCycle'] = [
        '#type' => 'checkbox',
        '#title' => 'Export only the current election.',
      ];
      
      $form['removeInactive'] = [
        '#type' => 'checkbox',
        '#title' => 'Remove inactive voters from export.',
      ];
      
      
      array_unshift($cycleStartOptions , 'Select starting cycle');
      $form_state->set('cycleOptions',$cycleStartOptions);
      
      $form['startCycle'] = [
        '#type' => 'select',
        '#options' => $cycleStartOptions,
        
        '#title' => 'Starting cycle to export.',
      ];
      
      array_unshift($cycleEndOptions , 'Select ending cycle');
      
      $form['endCycle'] = [
        '#type' => 'select',
        '#options' => $cycleEndOptions,
        
        '#title' => 'Ending cycle to export.',
      ];
      
      $form['button_link'] = [
        '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
      ];
      
      $form['upload_file'] = [
        '#type' => 'submit',
        '#id' => 'export-file',
        '#value' => 'Export the voter contact reports database',
      ];
      
      
    }

    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $currentCycleOnly = $form_state->getValue('currentCycle');
    $startCycle = $form_state->getValue('startCycle');
    $endCycle = $form_state->getValue('endCycle');
    
    $config = Drupal::config('nlpservices.configuration');
    $electionConfiguration = $config->get('nlpservices-election-configuration');
    $cycle = $electionConfiguration['nlp_election_cycle'];
    if($currentCycleOnly) {
      $form_state->set('cycleOptions',[1=>$cycle]);
      $form_state->set('startCycle',1);
      $form_state->set('endCycle',1);
    } else {
      
      if($startCycle==0) {
        $form_state->setErrorByName('startCycle',t('You must select a start cycle.'));
        return;
      }
      if($endCycle==0) {
        $endCycle = $startCycle;
      } elseif ($endCycle < $startCycle) {
        $form_state->setErrorByName('$endCycle',t('You must select equal to or after the start cycle.'));
        return;
      }
      $form_state->set('startCycle',$startCycle);
      $form_state->set('endCycle',$endCycle);
    }
    
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();
    $currentCycleOnly = $form_state->getValue('currentCycle');
    $startCycle = $form_state->get('startCycle');
    $endCycle = $form_state->get('endCycle');
    $cycleOptions = $form_state->get('cycleOptions');
    $removeInactive = $form_state->getValue('removeInactive');
    
    $args = $this->exportVoterContactReports($currentCycleOnly,$startCycle,$endCycle,$cycleOptions,$removeInactive);
    $form_state->set('args', $args);
  }
  
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * exportVoterContactReports
   *
   * Export the NL reports for a county and put them in a tab-delimited file
   * suitable for download.
   *
   * The file can be used for an archive of an election
   * cycle or for import to the VoteBuilder.
   *
   * The fields in the nlpservices results table are selected and written to a file.
   * The VANID is moved to the first field in the file to make import to the
   * VoteBuilder easier.
   * The last field is called EOR and will contain the EOR
   * text to meet the VoteBuilder requirements that the last field always has
   * information.
   * The nickname and last name of the NL are included to make the
   * export file a little easier to read.
   *
   * @param $currentCycleOnly
   * @param $startCycle
   * @param $endCycle
   * @param $cycleOptions
   * @param $removeInactive
   * @return array
   */
  function exportVoterContactReports($currentCycleOnly,$startCycle,$endCycle,$cycleOptions,$removeInactive): array
  {
    // Use the public folder for saving temp files.
    $tempDir = 'public://temp';
    $createDate = date('Y-m-d-H-i-s',time());
  
    if($endCycle==$startCycle) {
      $rangeName = $cycleOptions[$startCycle];
    } else {
      $rangeName = $cycleOptions[$startCycle].'-'.$cycleOptions[$endCycle];
    }
    
    // Open a temp file for receiving the records.
    $fileName = self::NR_NLS_REPORTS.'_'.$rangeName.'_date'.'-'.$createDate.'.csv';
    $tempUri = $tempDir.'/'.$fileName;
    // Create a managed file for temporary use.  Drupal will delete it after 6 hours.
    $file = Drupal::service('file.repository')->writeData('', $tempUri, FileExists::Replace);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('error', $e->getMessage() );
      return [];
    }
    // Open the new temp file for writing by PHP functions.
    $fh = fopen($tempUri,"w");
    // Get the column names for the export and add the NL name to ease editing.
    $columnNames = $this->reports->getColumnNames();
    // Write the header as the first record in this tab-delimited file.
    $string = implode(",", $columnNames)."\n";
    fwrite($fh,$string);
    fclose($fh);
   
    $countOfRecords = 0;
    for ($index=$startCycle; $index<=$endCycle; $index++) {
      $cycleToCount = $cycleOptions[$index];
      $cycleCount =  $this->reports->getReportCount($cycleToCount);
      $countOfRecords += $cycleCount;
    }
    if(empty($countOfRecords)) {
      return [];
    }
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $args = [
      'uri' => $tempUri,
      'columnNames' => $columnNames,
      'startCycle' => $startCycle,
      'endCycle' => $endCycle,
      'currentCycleOnly' => $currentCycleOnly,
      'countOfRecords' => $countOfRecords,
      'cycleOptions' => $cycleOptions,
      'removeInactive' => $removeInactive,
    ];
    $batch = [
      'operations' => [
        ['exportVoterContactReportsBatch', [$args]]
      ],
      'file' => $modulePath.'/src/Form/ExportVoterContactReportsBatch.php',
      'finished' => 'exportVoterContactReportsBatchFinished',
      'title' => 'Processing export reports.',
      'init_message' => 'Reports export is starting.',
      'progress_message' => 'Processed @percentage % of '.$rangeName.' reports',
      'error_message' => 'Export reports has encountered an error.',
    ];
    batch_set($batch);
    return $args;
  }
}
