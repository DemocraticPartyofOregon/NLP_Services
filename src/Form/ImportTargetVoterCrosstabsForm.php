<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTargetVoterCrosstabCounts;

/**
 * @noinspection PhpUnused
 */
class ImportTargetVoterCrosstabsForm extends ConfigFormBase
{
  protected NlpTargetVoterCrosstabCounts $targetVoterCrosstabsObj;

  public function __construct($config_factory, $typed_config_manager, $targetVoterCrosstabsObj) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->targetVoterCrosstabsObj = $targetVoterCrosstabsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('nlpservices.target_voter_crosstab_counts'),
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
    return 'nlpservices_import_target_voter_crosstabs_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['hint'] = [
      '#type' => 'markup',
      '#markup' => '
<p>The crosstab files are crosstab reports that count ballot status
(Accepted, Rejected, Sent, etc.) for each party by county. One file covers
the target voter universe and the other covers all voters. You may import
either or both files at the same time.</p>
<p>If you create the crosstab file using a formatted report, the file name will
have a date. This date is separated from the rest of the file name by blanks.
The date will be in the form mm_dd_yyyy. If the date is missing, the current time is used.</p>',
    ];

    $form['target_voter_crosstabs_file'] = [
      '#type'  => 'file',
      '#title' => t('Target Voter Crosstab file name'),
    ];

    $form['all_voter_crosstabs_file'] = [
      '#type'  => 'file',
      '#title' => t('All Voter Crosstab file name'),
    ];

    $form['upload_file'] = [
      '#type'  => 'submit',
      '#id'    => 'upload-file',
      '#value' => t('Import Crosstab file(s)'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $targetTempName = $_FILES['files']['tmp_name']['target_voter_crosstabs_file'] ?? '';
    $allTempName    = $_FILES['files']['tmp_name']['all_voter_crosstabs_file'] ?? '';

    if (empty($targetTempName) && empty($allTempName)) {
      $form_state->setErrorByName('target_voter_crosstabs_file', 'Please select at least one crosstab file to import.');
      parent::validateForm($form, $form_state);
      return;
    }

    if (!empty($targetTempName)) {
      $fileData = $this->validateCrosstabFile(
        $targetTempName,
        $_FILES['files']['name']['target_voter_crosstabs_file'] ?? '',
        'target_voter_crosstabs_file',
        $form_state,
        $messenger,
      );
      if ($fileData !== NULL) {
        $form_state->set('target_file', $fileData);
      }
    }

    if (!empty($allTempName)) {
      $fileData = $this->validateCrosstabFile(
        $allTempName,
        $_FILES['files']['name']['all_voter_crosstabs_file'] ?? '',
        'all_voter_crosstabs_file',
        $form_state,
        $messenger,
      );
      if ($fileData !== NULL) {
        $form_state->set('all_file', $fileData);
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * Opens, reads, and validates a single crosstab CSV file.
   * Returns ['name' => string, 'pos' => array, 'time' => int] on success, NULL on failure.
   */
  private function validateCrosstabFile(string $tempName, string $fileName, string $formField,
                                        FormStateInterface $form_state, $messenger): ?array
  {
    $timeStamp = $this->extractCreateDate($fileName);
    if (empty($timeStamp)) {
      $timeStamp = time();
    }

    $fh = fopen($tempName, 'r');
    if (!$fh) {
      $form_state->setErrorByName($formField, 'Failed to open crosstab export file.');
      return NULL;
    }

    $header1 = $this->getHeaderRecord($fh);
    if (empty($header1)) {
      $form_state->setErrorByName($formField, 'Failed to read VAN crosstab export header (row 1).');
      return NULL;
    }

    $header2 = $this->getHeaderRecord($fh);
    if (empty($header2)) {
      $form_state->setErrorByName($formField, 'Failed to read VAN crosstab export header (row 2).');
      return NULL;
    }

    $pos = $this->targetVoterCrosstabsObj->decodeCrosstabsHeader($header1, $header2);
    if (!empty($pos['err'])) {
      foreach ($pos['err'] as $err) {
        $messenger->addError($err);
      }
      $form_state->setErrorByName($formField, 'Fix the problem before resubmit.');
      return NULL;
    }

    return ['name' => $tempName, 'pos' => $pos['pos'], 'time' => $timeStamp];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $targetFile = $form_state->get('target_file');
    $allFile    = $form_state->get('all_file');

    if (!empty($targetFile)) {
      $imported = $this->importCrosstabFile($targetFile, NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL, $messenger);
      if ($imported) {
        $this->config('nlpservices.configuration')
          ->set('nlpservices-target-voter-crosstab-create-time', $targetFile['time'])
          ->save();
        $messenger->addStatus('The target voter crosstab file has been successfully uploaded.');
      }
    }

    if (!empty($allFile)) {
      $imported = $this->importCrosstabFile($allFile, NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL, $messenger);
      if ($imported) {
        $this->config('nlpservices.configuration')
          ->set('nlpservices-all-voter-crosstab-create-time', $allFile['time'])
          ->save();
        $messenger->addStatus('The all voter crosstab file has been successfully uploaded.');
      }
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Reads and imports a validated crosstab CSV into the given service object.
   * Returns TRUE if at least one record was imported.
   */
  private function importCrosstabFile(array $fileData, string $tableName, $messenger): bool
  {
    $fh = fopen($fileData['name'], 'r');
    if (empty($fh)) {
      $messenger->addError('Failed to open crosstab file.');
      return FALSE;
    }
    // Discard the two header records.
    fgetcsv($fh, escape: '');
    fgetcsv($fh, escape: '');

    $fieldPos = $fileData['pos'];
    $imported = FALSE;
    do {
      $rawCount = fgetcsv($fh, escape: '');
      if (!$rawCount) {break;}

      $countsInfo = [];
      foreach ($rawCount as $countField) {
        $countsInfo[] = trim(strip_tags(htmlentities(stripslashes($countField), ENT_QUOTES)));
      }

      $rawCounty = $countsInfo[$fieldPos['county']];
      if ($rawCounty == 'Total People') {continue;}
      $county = ($rawCounty == 'Hood River') ? 'Hood_River' : $rawCounty;
      $cd     = (int) $countsInfo[$fieldPos['cd']];
      $party  = $countsInfo[$fieldPos['party']];

      $counts = [
        'cd'            => $cd,
        'county'        => $county,
        'party'         => $party,
        'total'         => (int) str_replace(',', '', $countsInfo[$fieldPos['total']]),
        'accepted'      => (int) str_replace(',', '', $countsInfo[$fieldPos['accepted']]),
        'rejected'      => (int) str_replace(',', '', $countsInfo[$fieldPos['rejected']]),
        'sent'          => (int) str_replace(',', '', $countsInfo[$fieldPos['sent']]),
        'undeliverable' => (int) str_replace(',', '', $countsInfo[$fieldPos['undeliverable']]),
        'void'          => (int) str_replace(',', '', $countsInfo[$fieldPos['void']]),
        'unknown'       => (int) str_replace(',', '', $countsInfo[$fieldPos['unknown']]),
      ];
      $this->targetVoterCrosstabsObj->updateCrosstabCounts($counts, $tableName);
      $imported = TRUE;
    } while (TRUE);

    return $imported;
  }

  function getHeaderRecord($fh): array
  {
    $rawHeader = fgetcsv($fh, escape: '');
    if (!$rawHeader) {
      return [];
    }
    $headerColumns = [];
    foreach ($rawHeader as $hdrColumn) {
      $headerColumns[] = trim(strip_tags(htmlentities(stripslashes($hdrColumn), ENT_QUOTES)));
    }
    return $headerColumns;
  }

  function extractCreateDate($fileName): int
  {
    $fileNameParts = explode(' ', $fileName);
    $partsCount = count($fileNameParts);
    if ($partsCount < 4) {return 0;}
    $ampm = substr($fileNameParts[$partsCount - 1], 0, 2);
    if ($ampm != 'AM' && $ampm != 'PM') {return 0;}
    $time = str_replace('_', ':', $fileNameParts[$partsCount - 2]);
    $date = str_replace('_', '/', $fileNameParts[$partsCount - 3]);
    $dateTimeParts = [$date, $time, $ampm];
    $dateTime = implode(' ', $dateTimeParts);
    return strtotime($dateTime);
  }

}
