<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpCrosstabCounts;

/**
 * @noinspection PhpUnused
 */
class ImportCrosstabsForm extends FormBase
{
  protected NlpCrosstabCounts $crosstabsObj;

  public function __construct($crosstabsObj, $requestStack) {
    $this->crosstabsObj = $crosstabsObj;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.crosstab_counts'),
      $container->get('request_stack'),
    );
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
    $form['hint'] = [
      '#type' => 'markup',
      '#markup' => '
<p>The Demographics file is a crosstab report that counts ballots received for
each party by county. The list of voters used to create the crosstab report
should be of just active registered voters, and the crosstab file is for the
entire state.</p>
<p>The file can also have an HD column, giving the counts for each house
district in a county.  Without it, the summaries by HD show only the NLP counts.
Each import replaces all the counts from the previous file.</p>
<p>If you create the crosstab file using a formated report, the file name will
have a date.  This date is separated from the rest of the file name by blanks.
The date will be in the form mm_dd_yyyy.  If the date is missing, the current time is used.</p>',
    ];
    $form['crosstabs_file'] = [
      '#type' => 'file',
      '#title' => t('Demographics file name'),
    ];
    $form['upload_file'] = [
      '#type' => 'submit',
      '#id' => 'upload-file',
      '#value' => t('Import the Demographics file'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $files    = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $file     = $files['crosstabs_file'] ?? NULL;
    $tempName = $file?->getPathname() ?? NULL;
    $fileName = $file?->getClientOriginalName() ?? '';

    $timeStamp = $this->extractCreateDate($fileName);
    if (empty($timeStamp)) {
      $timeStamp = time();
    }
    $form_state->set('createTime', $timeStamp);

    if (!$tempName) {
      $form_state->setErrorByName('crosstabs_file', t('No file uploaded.'));
      return;
    }

    $fh = fopen($tempName, "r");
    if (!$fh) {
      $form_state->setErrorByName('crosstabs_file', t('Failed to open count export file.'));
      return;
    }
    $header1 = $this->getHeaderRecord($fh);
    if (empty($header1)) {
      $form_state->setErrorByName('crosstabs_file', t('Failed to read VAN count export header.'));
      return;
    }
    $header2 = $this->getHeaderRecord($fh);
    if (empty($header2)) {
      $form_state->setErrorByName('crosstabs_file', t('Failed to read VAN count export header.'));
      return;
    }
    $pos = $this->crosstabsObj->decodeCrosstabsHeader($header1, $header2);
    $fieldPos = $pos['pos'];
    if (!empty($pos['err'])) {
      foreach ($pos['err'] as $err) {
        $messenger->addError($err);
      }
      $form_state->setErrorByName('crosstabs_file', t('Fix the problem before resubmit.'));
      return;
    }
    $form_state->set('crosstabs_name', $tempName);
    $form_state->set('field_pos', $fieldPos);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $crosstabsName = $form_state->get('crosstabs_name');
    $fieldPos = $form_state->get('field_pos');
    $fh = fopen($crosstabsName, "r");
    if (!$fh) {
      $messenger->addError(t('Failed to open crosstab file.'));
      return;
    }
    // Discard the two header records for the counts and crosstabs export.
    fgetcsv($fh, escape: '');
    fgetcsv($fh, escape: '');

    // The HD column is optional; without it every row is HD 0.
    $hdPos = $fieldPos['hd'] ?? NULL;
    $crossTabCounts = [];
    do {
      $rawCount = fgetcsv($fh, escape: '');
      if (!$rawCount) { break; }
      $countsInfo = [];
      foreach ($rawCount as $countField) {
        $countsInfo[] = trim(strip_tags(htmlentities(stripslashes($countField), ENT_QUOTES)));
      }
      $rawCounty = $countsInfo[$fieldPos['county']] ?? '';
      $county    = ($rawCounty === 'Hood River') ? 'Hood_River' : $rawCounty;
      $cd        = (int) ($countsInfo[$fieldPos['cd']] ?? 0);
      $hd        = ($hdPos === NULL) ? 0 : $this->hdNumber($countsInfo[$hdPos] ?? '');
      if ($county !== 'Total People') {
        $party            = $countsInfo[$fieldPos['party']]   ?? '';
        $ballotsReceived  = str_replace(',', '', $countsInfo[$fieldPos['balRet']] ?? '');
        $registeredVoters = str_replace(',', '', $countsInfo[$fieldPos['total']]  ?? '');
        if ($party === 'Democrats' || $party === 'Republicans' || $party === 'Non-Affiliated') {
          $crossTabCounts[$cd][$county][$hd][$party]['br']  = $ballotsReceived;
          $crossTabCounts[$cd][$county][$hd][$party]['reg'] = $registeredVoters;
        }
        if (!isset($crossTabCounts[$cd][$county][$hd]['ALL'])) {
          $crossTabCounts[$cd][$county][$hd]['ALL']['br'] =
          $crossTabCounts[$cd][$county][$hd]['ALL']['reg'] = 0;
        }
        $crossTabCounts[$cd][$county][$hd]['ALL']['br']  += (int) $ballotsReceived;
        $crossTabCounts[$cd][$county][$hd]['ALL']['reg'] += (int) $registeredVoters;
      }
    } while (TRUE);
    fclose($fh);

    if (empty($crossTabCounts)) { return; }
    // Replace the previous file's counts so none are added in twice.
    if (!$this->crosstabsObj->deleteAllCrosstabCounts()) {
      $messenger->addError(t('Failed to remove the previous ballot counts.'));
      return;
    }
    foreach ($crossTabCounts as $cd => $cdCounts) {
      foreach ($cdCounts as $county => $countyCounts) {
        foreach ($countyCounts as $hd => $hdCounts) {
          foreach ($hdCounts as $party => $partyCounts) {
            $counts = [
              'cd'        => $cd,
              'county'    => $county,
              'hd'        => $hd,
              'party'     => $party,
              'regVoters' => $partyCounts['reg'],
              'regVoted'  => $partyCounts['br'],
            ];
            $this->crosstabsObj->updateCrosstabCounts($counts);
          }
        }
      }
    }

    $createTime = $form_state->get('createTime');
    Drupal::configFactory()->getEditable('nlpservices.configuration')
      ->set('nlpservices-crosstab-create-time', $createTime)
      ->save();

    $messenger->addStatus(t('The ballot count file has been successfully uploaded.'));
  }

  /**
   * The HD number from the HD column, which may be padded ("007") or have a
   * prefix ("HD 7").  0 if there is no number.
   */
  private function hdNumber(string $rawHd): int
  {
    return preg_match('/\d+/', $rawHd, $matches) ? (int) $matches[0] : 0;
  }

  private function getHeaderRecord($fh): array
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

  private function extractCreateDate($fileName): int
  {
    $fileNameParts = explode(' ', $fileName);
    $partsCount = count($fileNameParts);
    if ($partsCount < 4) { return 0; }
    $ampm = substr($fileNameParts[$partsCount - 1], 0, 2);
    if ($ampm !== 'AM' && $ampm !== 'PM') { return 0; }
    $time = str_replace('_', ':', $fileNameParts[$partsCount - 2]);
    $date = str_replace('_', '/', $fileNameParts[$partsCount - 3]);
    $dateTime = implode(' ', [$date, $time, $ampm]);
    return strtotime($dateTime) ?: 0;
  }

}
