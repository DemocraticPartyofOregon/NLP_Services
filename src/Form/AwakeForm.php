<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Exception;
//use stdClass;

class AwakeForm extends FormBase {
  
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  
  public function __construct(  $nlpConfigurationObj, $nlpSessionDataObj ) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AwakeForm
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_sync_turf_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {

    $userObj = Drupal::service('nlpservices.drupal_user');
    $county = 'Jefferson';
    $listId = 509011;
    $exportJobsObj = Drupal::service('nlpservices.export_jobs');
    $database = 1;
    $activistCodes = ['NLPNotRegisteredAC', 'NLPDropoutAC', ];
    $exportJob = $exportJobsObj->getExportJobs( $county, $database, $listId, $activistCodes );
    nlp_debug_msg('$exportJob',$exportJob);

    $remoteUrl = $exportJob['downloadUrl'];
    $fh = fopen($remoteUrl, 'r');
    if (!$fh) {
      nlp_debug_msg('remoteUrl open failed', $remoteUrl);
    } else {
      while (($record = fgetcsv($fh)) !== FALSE) {
        nlp_debug_msg('record', $record);
      }
    }
    fclose($fh);
    
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/turf-sync';
    $form['#attached']['library'][] = 'nlpservices/walksheet-form';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    //$form_state->set('reenter', TRUE);
    //$form_state->setRebuild();
    $values = $form_state->getValues();
    nlp_debug_msg('$values',$values);
    
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter',TRUE);
    $form_state->setRebuild();
    
    $values = $form_state->getValues();
    nlp_debug_msg('$values',$values);
    
    $files = $_FILES;
    nlp_debug_msg('$files',$files);
    
      
    
  }
  
  function mergeAward($award): bool
  {
    $connection = Drupal::getContainer()->get('database');
    
    try {
      $connection->merge('nlp_awards')
        ->keys(['mcid' => $award['mcid']])
        ->fields($award)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('mergeAward', $e->getMessage() );
      return FALSE;
    }
    return TRUE;
  }
  
}
