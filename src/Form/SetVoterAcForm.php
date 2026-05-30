<?php

/** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

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

    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $args = [
      'turfIndexes' => $turfIndexes,
    ];
    $batch = [
      'operations' => [
        ['setVoterAcBatch', [$args]]
      ],
      'file' => $modulePath . '/src/Form/SetVoterAcBatch.php',
      'finished' => 'setVoterAcBatchFinished',
      'title' => t('Setting NLP Voter Activist Code.'),
      'init_message' => t('Starting NLP Voter AC batch.'),
      'progress_message' => t('Processed @percentage % of turfs.'),
      'error_message' => t('NLP Voter AC batch encountered an error.'),
    ];
    batch_set($batch);
  }
}
