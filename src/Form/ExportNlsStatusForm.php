<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpFileFunctions;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpExportNlsStatus;

/**
 * @noinspection PhpUnused
 */
class ExportNlsStatusForm extends FormBase
{
  protected NlpNls $nlsObj;
  protected NlpSessionData $nlpSessionObj;
  protected NlpExportNlsStatus $nlpExportNlsStatusObj;
  protected DrupalUser $drupalUserObj;
  protected NlpFileFunctions $fileFunctionsObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($nlpSessionObj, $nlsObj, $nlpExportNlsStatusObj, $drupalUserObj, $fileFunctionsObj, $nlpConfigurationObj)
  {
    $this->nlpSessionObj = $nlpSessionObj;
    $this->nlsObj = $nlsObj;
    $this->nlpExportNlsStatusObj = $nlpExportNlsStatusObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->fileFunctionsObj = $fileFunctionsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ExportNlsStatusForm
  {
    return new static(
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.export_nls_status'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.file_functions'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_export_nls_status_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (empty($form_state->get('reenter'))) {
      if ($this->drupalUserObj->isNlpAdminUser()) {
        // An admin can export all committees or just one.
        $committeeOptions = ['all' => $this->t('All counties')];
        foreach ($this->nlpConfigurationObj->getCommitteeNames() as $committeeName) {
          $committeeOptions[$committeeName] = $committeeName;
        }
        $committee = $form_state->getValue('countySelect') ?? 'all';
        $form['countySelect'] = [
          '#type' => 'select',
          '#options' => $committeeOptions,
          '#default_value' => 'all',
          '#title' => $this->t('Select the county for export'),
          '#ajax' => [
            'callback' => '::committeeSelectedCallback',
            'wrapper' => 'hd-select-wrapper',
          ],
        ];
      } else {
        $committee = $this->nlpSessionObj->getCommittee();
        if (empty($committee)) {
          $this->messenger()->addError($this->t('The county is missing'));
          return [];
        }
      }
      $form_state->set('county', $committee);

      // The HD select is only offered when a single committee is selected.
      $form['hdSelect'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'hd-select-wrapper'],
      ];
      if ($committee !== 'all') {
        $hdNames = ['All' => 'All'];
        foreach ($this->nlsObj->getHdList($committee) as $hd) {
          $hdNames[$hd] = $hd;
        }
        $form['hdSelect']['districtSelect'] = [
          '#type' => 'select',
          '#options' => $hdNames,
          '#title' => $this->t('Select the HD for export'),
        ];
      }
      $form['save'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export NLs status.'),
      ];
    } else {
      $tempFileName = $form_state->get('fileName');
      $committee = $form_state->get('county');
      if ($committee === 'all') {
        $page = '<h2>' . $this->t('Export of NLs status for all counties.') . '</h2>';
      } else {
        $hd = $form_state->get('hd');
        $hd = empty($hd) ? 'All' : $hd;
        $page = '<h2>' . $this->t('Export of NLs status for @county County, HD @hd', [
            '@county' => Html::escape($committee),
            '@hd'     => $hd,
          ]) . '</h2>';
      }
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page .= '<a href="' . $url . '">' . $this->t('Right-click to download.') . '</a>';
      $form['result'] = [
        '#markup' => $page,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $form_state->set('reenter', TRUE);

    $committee = $this->drupalUserObj->isNlpAdminUser()
      ? ($form_state->getValue('countySelect') ?? 'all')
      : $form_state->get('county');
    $form_state->set('county', $committee);
    // The 'All' HD option casts to 0, which exports every HD in the committee.
    $hd = ($committee === 'all') ? 0 : (int) $form_state->getValue('districtSelect');
    $form_state->set('hd', $hd);

    $tempFile = $this->fileFunctionsObj->createTempCsvFile('nls-status');
    if ($tempFile === false) {
      $this->messenger()->addError($this->t("Can't create temporary file."));
      return;
    }
    fclose($tempFile['fh']);
    $tempFileName = $tempFile['uri'];
    $form_state->set('fileName', $tempFileName);

    $this->nlpExportNlsStatusObj->createNlsStatusFile($tempFileName, $committee, $hd);
  }

  /**
   * committeeSelectedCallback
   *
   * AJAX callback for the committee select; refreshes the HD select.
   */
  /** @noinspection PhpUnusedParameterInspection */
  public function committeeSelectedCallback(array $form, FormStateInterface $form_state): array
  {
    return $form['hdSelect'];
  }
}
