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
    $pathsObj = Drupal::getContainer()->get('nlpservices.paths');
    //$path = $pathsObj->getPath('DOCS', '',  TRUE);
    //nlp_debug_msg('$path',$path);
    $path = $pathsObj->getPath('DEFAULT_DOCS', '',  TRUE);
    //nlp_debug_msg('$path',$path);
    
    $fileFunctionsObj = Drupal::getContainer()->get('nlpservices.file_functions');
    
    // Example usage:
    $files = $fileFunctionsObj->getFilesInDirectory($path);
    
    $documentsObj = Drupal::getContainer()->get('nlpservices.documents');
    
    $availableDocuments = $documentsObj->buildDefaultFilesInfo($files);
    //nlp_debug_msg('availableDocuments',$availableDocuments);
    
    $path = $pathsObj->getPath('DEFAULT_DOCS', '',  TRUE);
    
    foreach ($availableDocuments as $key => $document) {
      $createDate = $fileFunctionsObj->getFileCreateDate($path.$document['docFileName']);
      //nlp_debug_msg('createDate: '.$document['docFileName'],$createDate);
      $availableDocuments[$key]['createDate'] = $createDate;
    }
    nlp_debug_msg('availableDocuments',$availableDocuments);
    
    $destinationPath = $pathsObj->getPath('DOCS');
    nlp_debug_msg('$destinationPath',$destinationPath);
    foreach ($availableDocuments as $document) {
      $docFileName = $document['docFileName'];
      if (!@copy($path.$docFileName, $destinationPath.$docFileName)) {
        nlp_debug_msg('oops 1!');
      }
      
      $pdfFileName = $document['pdfFileName'];
      if (!@copy($path.$pdfFileName, $destinationPath.$pdfFileName)) {
        nlp_debug_msg('oops 2!');
      }
      
      $req = $document;
      //$req['docFileName'] = $docDestinationFileName;
      //$req['pdfFileName'] = $pdfDestinationFileName;
      $documentsObj->createDocument($req);
      
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
