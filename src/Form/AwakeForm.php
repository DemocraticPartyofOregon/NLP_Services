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
    $nlpConfigurationObj = Drupal::getContainer()->get('nlpservices.configuration');
    //$encryptionObj = Drupal::getContainer()->get('nlpservices.encryption');
    $clientObj = Drupal::getContainer()->get('http_client');

    $stateName = $nlpConfigurationObj->getStateName();
    nlp_debug_msg('$stateName', $stateName);

    $stateCommitteeKey = $nlpConfigurationObj->getStateKey();
    nlp_debug_msg('$stateCommitteeKey', $stateCommitteeKey);

    $key = $nlpConfigurationObj->getCountyKey('Yellowstone');
    nlp_debug_msg('$key', $key);
    $apiKey = $key['API Key'];
    nlp_debug_msg('$apiKey', $apiKey);

    $postUrl = "https://MT.000003.565:$apiKey|1@api.securevan.com/v4/people/100335562/canvassResponses";

    $dataJson = '{"canvassContext":{"dateCanvassed":"2026-04-22T21:31:45-07:00","contactTypeId":"2","inputTypeId":11},"resultCodeId":null,"responses":[{"type":"SurveyResponse","surveyQuestionId":734451,"surveyResponseId":2946227}]}';

    $headers = ['Content-Type' => 'application/json', "Accept: application/json, Expect:"];
    try {
      $clientObj->post($postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('setApiSurveyResponse', $e->getMessage());
      nlp_debug_msg('setApiSurveyResponse', "postUrl: $postUrl");
      nlp_debug_msg('setApiSurveyResponse', "dataJson: $dataJson");
    }



    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];
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
