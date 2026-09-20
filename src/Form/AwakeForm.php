<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\Batch\AwakeBatch;
use Exception;
//use stdClass;

class AwakeForm extends FormBase {

  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;

  public function __construct(  $nlpConfigurationObj, $nlpSessionDataObj, $requestStack ) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AwakeForm
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('request_stack'),
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
    $apiVoterObj = Drupal::service('nlpservices.api_voter');
    $apiNlsObj = Drupal::service('nlpservices.api_nls');

    $apiKeyManagementObj = Drupal::service('nlpservices.api_key_management');
    $client = Drupal::service('http_client');

    //$mcid = 102549413;
    $mcid = 102735234;
    $committee = 'Wakulla';

    $nl = $apiNlsObj->getApiNls( $committee, $mcid);
    nlp_debug_msg('nl',$nl);



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
    //$values = $form_state->getValues();
    //nlp_debug_msg('$values',$values);

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $fieldsOfInterest = ['Mapping Value','FIRST_NAME','LAST_NAME','PRECINCT_NAME','STATUS','DATE_VOTED','RES_COUNTY','VOTED_DATE'];
    $activeCounties = ['Forrest','Lamar'];
    $dateRange = ['start_date'=>'2026-3-1','end_date'=>'2026-3-30'];

    $fids = $form_state->getValue('file');
    $fid  = reset($fids);
    if (!$fid) {
      $this->messenger()->addError($this->t('No file was uploaded.'));
      return;
    }
    /** @var \Drupal\file\FileInterface $file */
    $file     = Drupal::entityTypeManager()->getStorage('file')->load($fid);
    $filePath = Drupal::service('file_system')->realpath($file->getFileUri());

    $fh = fopen($filePath, 'r');
    if (!$fh) {
      $this->messenger()->addError($this->t('Could not open the uploaded file.'));
      return;
    }

    // Build column-index map from header row; capture the byte offset after it.
    $header = fgetcsv($fh);
    if ($header === FALSE) {
      fclose($fh);
      $this->messenger()->addError($this->t('The CSV file is empty.'));
      return;
    }
    $colIndex = [];
    foreach ($header as $index => $colName) {
      $colName = trim($colName);
      if (in_array($colName, $fieldsOfInterest)) {
        $colIndex[$colName] = $index;
      }
    }
    $headerOffset = ftell($fh);
    fclose($fh);

    $startTimestamp = strtotime($dateRange['start_date']);
    $endTimestamp   = strtotime($dateRange['end_date']);

    $batchBuilder = (new BatchBuilder())
      ->setTitle(t('Processing voted file.'))
      ->setFinishCallback([AwakeBatch::class, 'finished'])
      ->setInitMessage(t('Awake processing is starting.'))
      ->setProgressMessage(t('Processed @percentage% of the voted file.'))
      ->setErrorMessage(t('Awake processing encountered an error.'))
      ->addOperation([AwakeBatch::class, 'process'], [[
        'filePath'       => $filePath,
        'fid'            => $fid,
        'headerOffset'   => $headerOffset,
        'colIndex'       => $colIndex,
        'activeCounties' => $activeCounties,
        'startTimestamp' => $startTimestamp,
        'endTimestamp'   => $endTimestamp,
      ]]);

    batch_set($batchBuilder->toArray());

  }



}
