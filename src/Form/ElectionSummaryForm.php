<?php

namespace Drupal\nlpservices\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpResultsSummary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Election results summary for the state, a CD, a county, or an HD.
 *
 * The user chooses the state, CD, or county.  For CD, one or all CDs.  For
 * county, one or all counties, and for one county, the county summary or one
 * or all of its HDs.  The result is a link to a PDF and a link to a CSV file
 * of the request, and a display of the top-level summary.
 *
 * @noinspection PhpUnused
 * used in route.
 */
class ElectionSummaryForm extends FormBase
{
  protected NlpResultsSummary $resultsSummaryObj;
  protected DrupalUser $drupalUserObj;

  public function __construct($resultsSummaryObj, $drupalUserObj)
  {
    $this->resultsSummaryObj = $resultsSummaryObj;
    $this->drupalUserObj = $drupalUserObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ElectionSummaryForm
  {
    return new static(
      $container->get('nlpservices.results_summary'),
      $container->get('nlpservices.drupal_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_election_summary_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $admin = $this->drupalUserObj->isNlpAdminUser();
    $form['#attached']['library'][] = 'nlpservices/info-boxes';

    // Only an NLP admin can see the state or all the counties.
    if ($admin) {
      $levelOptions = [
        NlpResultsSummary::LEVEL_STATE => $this->t('State'),
        NlpResultsSummary::LEVEL_CD => $this->t('Congressional district'),
        NlpResultsSummary::LEVEL_COUNTY => $this->t('County or house district'),
      ];
      $defaultLevel = NlpResultsSummary::LEVEL_STATE;
    } else {
      $levelOptions = [
        NlpResultsSummary::LEVEL_CD => $this->t('Congressional district'),
        NlpResultsSummary::LEVEL_COUNTY => $this->t('County or house district'),
      ];
      $defaultLevel = NlpResultsSummary::LEVEL_COUNTY;
    }
    $level = $form_state->getValue('level') ?? $defaultLevel;
    if (!isset($levelOptions[$level])) {
      $level = $defaultLevel;
    }

    $form['level'] = [
      '#type' => 'radios',
      '#title' => $this->t('Summary of the election results for'),
      '#options' => $levelOptions,
      '#default_value' => $level,
      '#ajax' => [
        'callback' => '::refreshChoicesCallback',
        'wrapper' => 'summary-choices-wrapper',
      ],
    ];

    $form['choices'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'summary-choices-wrapper'],
    ];
    $ready = TRUE;
    switch ($level) {
      case NlpResultsSummary::LEVEL_CD:
        $ready = $this->buildCdChoices($form['choices']);
        break;
      case NlpResultsSummary::LEVEL_COUNTY:
        $ready = $this->buildCountyChoices($form['choices'], $form_state, $admin);
        break;
    }

    if ($ready) {
      $form['choices']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create the summary'),
      ];
    }

    $result = $form_state->get('summaryResult');
    if (!empty($result)) {
      $form['result'] = $result;
    }
    return $form;
  }

  /**
   * The choice of one or all CDs.
   *
   * @return bool - FALSE if there are no CDs to choose.
   */
  private function buildCdChoices(array &$choices): bool
  {
    $cds = $this->resultsSummaryObj->getCdOptions();
    if (empty($cds)) {
      $choices['no_cds'] = [
        '#markup' => '<p>' . $this->t('The crosstab file needs to be loaded prior to this report.') . '</p>',
      ];
      return FALSE;
    }
    $cdOptions = [NlpResultsSummary::ALL => $this->t('All CDs')];
    foreach ($cds as $cd) {
      $cdOptions[$cd] = $this->t('CD @cd', ['@cd' => $cd]);
    }
    $choices['cdSelect'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the CD'),
      '#options' => $cdOptions,
      '#default_value' => NlpResultsSummary::ALL,
    ];
    return TRUE;
  }

  /**
   * The choice of one or all counties, and for one county, the county
   * summary or one or all HDs.
   *
   * @return bool - FALSE if there are no counties to choose.
   */
  private function buildCountyChoices(array &$choices, FormStateInterface $form_state, bool $admin): bool
  {
    $counties = $this->resultsSummaryObj->getCountyOptions();
    if (empty($counties)) {
      $message = $admin
        ? $this->t('No county has voters assigned to turfs.')
        : $this->t('The county is not specified. Use the "Manage County" tab to select a county.');
      $choices['no_counties'] = ['#markup' => '<p>' . $message . '</p>'];
      return FALSE;
    }

    if ($admin) {
      $countyOptions = [NlpResultsSummary::ALL => $this->t('All counties')];
      foreach ($counties as $countyName) {
        $countyOptions[$countyName] = $countyName;
      }
      $county = $form_state->getValue('countySelect') ?? NlpResultsSummary::ALL;
      if (!isset($countyOptions[$county])) {
        $county = NlpResultsSummary::ALL;
      }
      $choices['countySelect'] = [
        '#type' => 'select',
        '#title' => $this->t('Select the county'),
        '#options' => $countyOptions,
        '#default_value' => $county,
        '#ajax' => [
          'callback' => '::refreshChoicesCallback',
          'wrapper' => 'summary-choices-wrapper',
        ],
      ];
    } else {
      // A coordinator only sees the county they manage.
      $county = reset($counties);
      $choices['countySelect'] = [
        '#type' => 'value',
        '#value' => $county,
      ];
      $choices['countyName'] = [
        '#markup' => '<p>' . $this->t('County: @county', ['@county' => $county]) . '</p>',
      ];
    }

    if ($county !== NlpResultsSummary::ALL) {
      $hdOptions = [
        NlpResultsSummary::COUNTY_SUMMARY => $this->t('County summary'),
        NlpResultsSummary::ALL => $this->t('All HDs'),
      ];
      foreach ($this->resultsSummaryObj->getHdOptions($county) as $hd) {
        $hdOptions[$hd] = $this->t('HD @hd', ['@hd' => $hd]);
      }
      $choices['hdSelect'] = [
        '#type' => 'select',
        '#title' => $this->t('Select the county summary or the HD'),
        '#options' => $hdOptions,
        '#default_value' => NlpResultsSummary::COUNTY_SUMMARY,
      ];
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $level = (string) $form_state->getValue('level');
    $district = NlpResultsSummary::ALL;
    $hd = NlpResultsSummary::COUNTY_SUMMARY;
    if ($level === NlpResultsSummary::LEVEL_CD) {
      $district = (string) ($form_state->getValue('cdSelect') ?? NlpResultsSummary::ALL);
    } elseif ($level === NlpResultsSummary::LEVEL_COUNTY) {
      $district = (string) ($form_state->getValue('countySelect') ?? NlpResultsSummary::ALL);
      if ($district !== NlpResultsSummary::ALL) {
        $hd = (string) ($form_state->getValue('hdSelect') ?? NlpResultsSummary::COUNTY_SUMMARY);
      }
    }

    $summary = $this->resultsSummaryObj->createElectionSummary($level, $district, $hd);
    if (!empty($summary['error'])) {
      $this->messenger()->addError(Html::escape($summary['error']));
      $form_state->set('summaryResult', NULL);
      return;
    }
    $form_state->set('summaryResult', $summary['display']);
  }

  /**
   * refreshChoicesCallback
   *
   * AJAX callback for the level and county selects; refreshes the choices.
   */
  /** @noinspection PhpUnusedParameterInspection */
  public function refreshChoicesCallback(array $form, FormStateInterface $form_state): array
  {
    return $form['choices'];
  }

}
