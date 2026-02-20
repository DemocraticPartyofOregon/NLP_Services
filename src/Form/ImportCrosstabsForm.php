<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
//use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\Config\ElectionConfigurationForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpCrosstabCounts;

/**
 * @noinspection PhpUnused
 */
class ImportCrosstabsForm extends ConfigFormBase
{
  protected NlpCrosstabCounts $crosstabsObj;
  
  public function __construct($config_factory, $crosstabsObj ) {
    parent::__construct($config_factory);
    $this->crosstabsObj = $crosstabsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ElectionConfigurationForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.crosstab_counts'),
    );
  }
  
  protected function getEditableConfigNames(): array
  {
    return ['nlpservices.configuration'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
      return 'nlpservices_import_crosstabs_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['hint'] = array(
      '#type' => 'markup',
      '#markup' => '
<p>The Demographics file is a crosstab report that counts ballots received for
each party by county. The list of voters used to create the crosstab report
should be of just active registered voters, and the crosstab file is for the
entire state.</p>
<p>If you create the crosstab file using a formated report, the file name will
have a date.  This date is separated from the rest of the file name by blanks.
The date will be in form mm_dd_yyyy.  If the date is missing, the current time is used.</p>',
    );
    // Name of the crosstabs file to upload
    $form['crosstabs_file'] = array(
      '#type' => 'file',
      '#title' => t('Demographics file name'),
    );

    $form['upload_file'] = array(
      '#type' => 'submit',
      '#id' => 'upload-file',
      '#value' => t('Import the Demographics file'),
    );
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    // Verify we have a good VAN export file with the required fields
    $tempName = $_FILES['files']['tmp_name']['crosstabs_file'];
    
    //nlp_debug_msg('$_FILES',$_FILES);
    $timeStamp = $this->extractCreateDate($_FILES['files']['name']['crosstabs_file']);
    if(empty($timeStamp)) {
      $timeStamp = time();
    }
    //nlp_debug_msg('$timeStamp', $timeStamp);
    $form_state->set('createTime', $timeStamp);

    $fh = fopen($tempName, "r");
    if (!$fh) {
      $form_state->setErrorByName('crosstabs_file', 'Failed to open count export file.');
      parent::validateForm($form, $form_state);
    }
    $header1 = $this->getHeaderRecord($fh);
    if (empty($header1)) {
      $form_state->setErrorByName('crosstabs_file', 'Failed to read VAN count export header.');
      parent::validateForm($form, $form_state);
    }
    $header2 = $this->getHeaderRecord($fh);
    if (empty($header2)) {
      $messenger->addError('Failed to read VAN count export header.');
      $form_state->setErrorByName('crosstabs_file', 'Failed to read VAN count export header.');
      parent::validateForm($form, $form_state);
    }
    $pos = $this->crosstabsObj->decodeCrosstabsHeader($header1,$header2);
    $fieldPos = $pos['pos'];
    if (!empty($pos['err'])) {
      foreach($pos['err'] as $err) {
        $messenger->addError($err);
      }
      $form_state->setErrorByName('crosstabs_file', 'Fix the problem before resubmit.');
      parent::validateForm($form, $form_state);
    }
    $form_state->set('crosstabs_name', $tempName);
    $form_state->set('field_pos', $fieldPos);
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $crosstabsName = $form_state->get('crosstabs_name');
    $fieldPos = $form_state->get('field_pos');
    //nlp_debug_msg('$fieldPos',$fieldPos);
    $fh = fopen($crosstabsName, "r");
    if (empty($fh)) {
      $messenger->addError('Failed to open crosstab file.');
      return;
    }
    // Discard the header records.  There are two header records for the counts and crosstabs export.
    fgetcsv($fh);
    fgetcsv($fh);

    $crossTabCounts = [];
    do {
      $rawCount = fgetcsv($fh);
      if (!$rawCount) {break;} //We've processed the last count.
      // Parse the count record into the various fields.
      // Get the county name, party, and counts.
      $countsInfo = [];
      foreach ($rawCount as $countField) {
        $countsInfo[] = trim(strip_tags(htmlentities(stripslashes($countField),ENT_QUOTES)));
      }
      $rawCounty = $countsInfo[$fieldPos['county']];
      $county = ($rawCounty == "Hood River")? "Hood_River": $rawCounty;
      $cd = (int) $countsInfo[$fieldPos['cd']];
      //nlp_debug_msg('$cd',$cd);
      if ($county != 'Total People') {
        $party = $countsInfo[$fieldPos['party']];

        $ballotsReceived = str_replace(',', '', $countsInfo[$fieldPos['balRet']]);
        $registeredVoters = str_replace(',', '', $countsInfo[$fieldPos['total']]);
        // Record the numbers for a party.
        if($party == 'Democrats' OR $party == 'Republicans' OR $party == 'Non-Affiliated') {
          $crossTabCounts[$cd][$county][$party]['br'] = $ballotsReceived;
          $crossTabCounts[$cd][$county][$party]['reg'] = $registeredVoters;
        }
        
        // Sum the party numbers for the county.
        if (!isset($crossTabCounts[$cd][$county]['ALL'])) {
          $crossTabCounts[$cd][$county]['ALL']['br'] =
          $crossTabCounts[$cd][$county]['ALL']['reg'] = 0;
        }
        $crossTabCounts[$cd][$county]['ALL']['br'] += $ballotsReceived;
        $crossTabCounts[$cd][$county]['ALL']['reg'] += $registeredVoters;
      }
    } while (TRUE);
    
    $counts = [];
    foreach ($crossTabCounts as $cd=>$cdCounts) {
      foreach ($cdCounts as $county => $countyCounts) {
        foreach ($countyCounts as $party => $partyCounts) {
          $counts = [
            'cd' => $cd,
            'county' => $county,
            'party' => $party,
            'regVoters' => $partyCounts['reg'],
            'regVoted' => $partyCounts['br'],
          ];
          $this->crosstabsObj->updateCrosstabCounts($counts);
        }
      }
    }
    if (empty($counts)) {return;}
    
    $createTime = $form_state->get('createTime');
    $this->config('nlpservices.configuration')
      ->set('nlpservices-crosstab-create-time', $createTime)
      ->save();
    
    $messenger->addStatus('The ballot count file has been successfully uploaded.');
    parent::submitForm($form, $form_state);
    
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getHeaderRecord
   *
   * Get a header record from the file.
   *
   * @param $fh
   * @return array
   */
  function getHeaderRecord($fh): array
  {
    $rawHeader = fgetcsv($fh);
    if (!$rawHeader) {
      return [];
    }
    $headerColumns = [];
    foreach ($rawHeader as $hdrColumn) {
      $headerColumns[] = trim(strip_tags(htmlentities(stripslashes($hdrColumn),ENT_QUOTES)));
    }
    return $headerColumns;
  }
  
  function extractCreateDate($fileName): int
  {
    $fileNameParts = explode(' ', $fileName);
    //nlp_debug_msg('$fileNameParts',$fileNameParts);
    $partsCount = count($fileNameParts);
    if($partsCount<4) {return 0;}
    $ampm = substr($fileNameParts[$partsCount - 1],0,2);
    if($ampm!='AM' AND $ampm!='PM') {return 0;}
    $time = str_replace('_',':',$fileNameParts[$partsCount - 2]);
    //nlp_debug_msg('$time',$time);
    $date = str_replace('_','/',$fileNameParts[$partsCount - 3]);
    $dateTimeParts = [$date,$time,$ampm];
    $dateTime = implode(' ',$dateTimeParts);
    return strtotime($dateTime);
  }

}
