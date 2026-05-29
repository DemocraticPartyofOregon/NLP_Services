<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpExportNlsStatus;

/**
 * @noinspection PhpUnused
 */
class ExportNlsParticipationStatusForm extends FormBase
{
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionObj;
  protected NlpExportNlsStatus $nlpExportNlsStatusObj;

  public function __construct( $nlpSessionObj, $nlpConfigurationObj, $nlpExportNlsStatusObj)
  {
    $this->nlpSessionObj = $nlpSessionObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpExportNlsStatusObj = $nlpExportNlsStatusObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ExportNlsParticipationStatusForm
  {
    return new static(
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.export_nls_status'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_export_nls_participation_status_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if(empty($form_state->get('reenter'))) {
      $county = $this->nlpSessionObj->getCounty();
      if (empty($county)) {
        $this->messenger()->addError($this->t('The county is missing'));
        return [];
      }
      $form_state->set('county', $county);
      $hdNames = $this->nlpConfigurationObj->getHDNames($county);
      $form["districtSelect"] = [
        '#type' => 'select',
        '#options' => $hdNames,
        '#title' => $this->t('Select the HD for export'),
      ];
      $form['save'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export NLs participation status.'),
      ];
    } else {
      $tempFileName = $form_state->get('fileName');
      $county = $form_state->get('county');
      $hd = $form_state->get('hd');
      $hd = empty($hd)?'All':$hd;
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page = '<h2>' . $this->t('Export of NLs participation for @county County, HD @hd', [
          '@county' => Html::escape($county),
          '@hd'     => $hd,
        ]) . '</h2>';
      $page .= '<a href="'.$url.'">' . $this->t('Right-click to download.') . '</a>';

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
    $form_state->set('reenter',TRUE);
    $hd = (int) $form_state->getValue('districtSelect');
    $form_state->set('hd',$hd);

    $county = $form_state->get('county');

    $tempDir = 'public://temp';
    // Use a date in the name to make the file unique, in case two people
    // are doing an export at the same time.
    $contactDate = date('Y-m-d-H-i-s');
    $tempFileName = "$tempDir/nls-participation-status-$contactDate.csv";
    $form_state->set('fileName',$tempFileName);
    $this->nlpExportNlsStatusObj->createNlsStatusFile($tempFileName,$county,$hd);
  }
}
