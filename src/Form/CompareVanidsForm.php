<?php

/** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\File\FileExists;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CompareVanidsForm extends FormBase
{
  public function __construct($requestStack)
  {
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): CompareVanidsForm
  {
    return new static(
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'compare_vanids_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (!empty($form_state->get('reenter'))) {
      $outputUri = $form_state->get('outputUri');
      $missingCount = $form_state->get('missingCount');
      if (!empty($outputUri)) {
        $url = Drupal::service('file_url_generator')->generateAbsoluteString($outputUri);
        $form['result'] = [
          '#markup' => '<p>' . t('Found @count VANIDs in the first file that are missing from the second file.', ['@count' => $missingCount]) . '</p>'
            . '<p><a href="' . $url . '">' . t('Right-click to download the results file.') . '</a></p>',
        ];
      } else {
        $form['result'] = [
          '#markup' => '<p>' . t('No VANIDs in the first file were missing from the second file.') . '</p>',
        ];
      }
      return $form;
    }

    $form['file_1'] = [
      '#type' => 'file',
      '#title' => t('First CSV file'),
      '#description' => t('CSV file with a VANID column. VANIDs in this file will be compared against the second file.'),
    ];

    $form['file_2'] = [
      '#type' => 'file',
      '#title' => t('Second CSV file'),
      '#description' => t('CSV file with a VANID column. VANIDs present here are excluded from the output.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Compare files'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);

    $uploaded1 = $files['file_1'] ?? NULL;
    $uploaded2 = $files['file_2'] ?? NULL;

    if (empty($uploaded1)) {
      $form_state->setErrorByName('file_1', t('Please upload the first CSV file.'));
      return;
    }
    if (empty($uploaded2)) {
      $form_state->setErrorByName('file_2', t('Please upload the second CSV file.'));
      return;
    }

    $path1 = $uploaded1->getPathname();
    $path2 = $uploaded2->getPathname();

    $fh1 = fopen($path1, 'r');
    if (!$fh1) {
      $form_state->setErrorByName('file_1', t('Failed to open the first file.'));
      return;
    }
    $vanidPos1 = $this->findVanidColumn($fh1, 'vanid');
    fclose($fh1);
    if ($vanidPos1 === NULL) {
      $form_state->setErrorByName('file_1', t('No "vanid" column found in the first file.'));
      return;
    }

    $fh2 = fopen($path2, 'r');
    if (!$fh2) {
      $form_state->setErrorByName('file_2', t('Failed to open the second file.'));
      return;
    }
    $vanidPos2 = $this->findVanidColumn($fh2, 'Voter File VANID');
    fclose($fh2);
    if ($vanidPos2 === NULL) {
      $form_state->setErrorByName('file_2', t('No "Voter File VANID" column found in the second file.'));
      return;
    }

    $form_state->set('path1', $path1);
    $form_state->set('path2', $path2);
    $form_state->set('vanidPos1', $vanidPos1);
    $form_state->set('vanidPos2', $vanidPos2);
  }

  /**
   * Reads the header row and returns the zero-based column index of the column
   * whose name matches $columnTitle (case-insensitive), or NULL if not found.
   *
   * @param resource $fh
   * @param string $columnTitle
   * @return int|null
   */
  private function findVanidColumn($fh, string $columnTitle): ?int
  {
    $header = fgetcsv($fh);
    if (empty($header)) {
      return NULL;
    }
    $normalized = array_map('strtolower', array_map('trim', $header));
    $flipped = array_flip($normalized);
    return $flipped[strtolower($columnTitle)] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();

    $path1 = $form_state->get('path1');
    $path2 = $form_state->get('path2');
    $vanidPos1 = $form_state->get('vanidPos1');
    $vanidPos2 = $form_state->get('vanidPos2');

    // Load every VANID from file 2 into a lookup set. Array keys give O(1) lookup
    // without duplicating values, keeping memory proportional to file 2's row count.
    $file2Vanids = [];
    $fh2 = fopen($path2, 'r');
    if (!$fh2) {
      Drupal::messenger()->addError(t('Failed to re-open the second file.'));
      return;
    }
    fgetcsv($fh2); // skip header
    do {
      $row = fgetcsv($fh2);
      if ($row === FALSE) { break; }
      $vanid = trim($row[$vanidPos2] ?? '');
      if ($vanid !== '') {
        $file2Vanids[$vanid] = TRUE;
      }
    } while (TRUE);
    fclose($fh2);

    // Create the output-managed file before streaming file 1 so we can write
    // results incrementally without buffering them all in memory.
    $timestamp = date('Y-m-d-H-i-s');
    $outputUri = 'public://temp/missing_vanid-' . $timestamp . '.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $outputUri, FileExists::Replace);
      $file->setTemporary();
      $file->save();
    } catch (Exception $e) {
      nlp_debug_msg('CompareVanidsForm', $e->getMessage());
      Drupal::messenger()->addError(t('Failed to create the output file.'));
      return;
    }

    $fhOut = fopen($file->getFileUri(), 'w');
    if (!$fhOut) {
      Drupal::messenger()->addError(t('Failed to write the output file.'));
      return;
    }
    fputcsv($fhOut, ['vanid']);

    // Stream file 1 and write each VANID that is absent from file 2 directly
    // to the output, avoiding any large in-memory accumulation.
    $missingCount = 0;
    $fh1 = fopen($path1, 'r');
    if (!$fh1) {
      fclose($fhOut);
      Drupal::messenger()->addError(t('Failed to re-open the first file.'));
      return;
    }
    fgetcsv($fh1); // skip header
    do {
      $row = fgetcsv($fh1);
      if ($row === FALSE) { break; }
      if (count($row) === 1 && $row[0] === NULL) { continue; } // skip blank lines
      $vanid = trim($row[$vanidPos1] ?? '');
      if ($vanid !== '' && !isset($file2Vanids[$vanid])) {
        fputcsv($fhOut, [$vanid]);
        $missingCount++;
      }
    } while (TRUE);
    fclose($fh1);
    fclose($fhOut);

    $form_state->set('missingCount', $missingCount);
    if ($missingCount > 0) {
      $form_state->set('outputUri', $outputUri);
    }
  }
}
