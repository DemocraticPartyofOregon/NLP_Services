<?php

/** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\Batch\SetVoterAcBatch;

class SetVoterAcForm extends FormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'set_voter_ac_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $votersObj = Drupal::service('nlpservices.voters');
    $counties = $votersObj->getParticipatingCounties();
    array_unshift($counties, 'Select counties');
    unset($counties[0]);
    $form_state->set('counties', $counties);

    $form['counties'] = [
      '#type' => 'checkboxes',
      '#title' => t('Counties'),
      '#options' => $counties,
    ];

    $form['stop_at_limit'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Stop after 100 errors are found'),
      '#default_value' => FALSE,
    ];

    $form['report_only'] = [
      '#type'          => 'checkbox',
      '#title'         => t('Report only (do not set missing activist code)'),
      '#default_value' => FALSE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Set NLP Voter Activist Code'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $counties = $form_state->get('counties');
    $countyOptions = $values['counties'];

    $selectedCounties = [];
    foreach ($countyOptions as $index => $countyOption) {
      if (!empty($countyOption)) {
        $selectedCounties[] = $counties[$index];
      }
    }
    if (empty($selectedCounties)) {
      return;
    }

    $turfsObj = Drupal::service('nlpservices.turfs');
    $turfIndexes = [];
    foreach ($selectedCounties as $county) {
      $turfs = $turfsObj->getCountyTurfs($county);
      foreach ($turfs as $turfIndex => $turf) {
        $turfIndexes[] = $turfIndex;
      }
    }

    if (empty($turfIndexes)) {
      Drupal::messenger()->addWarning(t('No turfs found for the selected counties.'));
      return;
    }

    $stopAtLimit = !empty($values['stop_at_limit']);
    $reportOnly  = !empty($values['report_only']);

    $operations = [];
    foreach (array_chunk($turfIndexes, 20) as $chunk) {
      $operations[] = [[SetVoterAcBatch::class, 'process'], [['turfIndexes' => $chunk, 'stopAtLimit' => $stopAtLimit, 'reportOnly' => $reportOnly]]];
    }
    $batch = [
      'operations'       => $operations,
      'finished'         => [SetVoterAcBatch::class, 'finished'],
      'title'            => t('Setting NLP Voter Activist Code.'),
      'init_message'     => t('Starting NLP Voter AC batch.'),
      'progress_message' => t('Processed @current of @total turf groups.'),
      'error_message'    => t('NLP Voter AC batch encountered an error.'),
    ];
    batch_set($batch);
  }
}
