<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpExportNlsStatus;
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
    return 'nlpservices_default_method_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $messenger = Drupal::messenger();
    
    if(empty($form_state->get('reenter'))) {
      $county = $this->nlpSessionObj->getCounty();
      if (empty($county)) {
        $messenger->addError('The county is missing');
        return [];
      }
      $form_state->set('county', $county);
      $hdNames = $this->nlpConfigurationObj->getHDNames($county);
      $form["districtSelect"] = [
        '#type' => 'select',
        '#options' => $hdNames,
        '#title' => t('Select the HD for export'),
      ];
      $form['save'] = [
        '#type' => 'submit',
        '#value' => 'Export NLs participation status.',
      ];
    } else {
      $tempFileName = $form_state->get('fileName');
      $county = $form_state->get('county');
      $hd = $form_state->get('hd');
      
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page = "<h2>Export of NLs participation for $county County, HD $hd </h2>";
      $page .= '<a href="'.$url.'">Right-click to download.  </a>';
      
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
    
    $county  = $form_state->get('county');
    
    $tempDir = 'public://temp';
    // Use a date in the name to make the file unique., just in case two people
    // are doing an export at the same time.
    $contactDate = date('Y-m-d-H-i-s',time());
    $tempFileName = "$tempDir/nls-participation-status-$contactDate.csv";
    $form_state->set('fileName',$tempFileName);
    $this->nlpExportNlsStatusObj->createNlsStatusFile($tempFileName,$county,$hd);
    
  }
}