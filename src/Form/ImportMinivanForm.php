<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpMinivan;

use Symfony\Component\DependencyInjection\ContainerInterface;

/** @noinspection PhpUnused */
class ImportMinivanForm extends FormBase
{
  protected NlpMinivan $minivanObj;
  
  public function __construct($minivanObj)
  {
    $this->minivanObj = $minivanObj;
  }

  public static function create(ContainerInterface $container): ImportMinivanForm
  {
    return new static(
      $container->get('nlpservices.minivan'),
    );
  }

  public function getFormId(): string
  {
    return 'nlpservices_import_minivan_form';
  }
  
  /**
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => 'Process the email inbox for new minivan reports.',
    );
    return $form;
  }
  
  /**
   * @param array $form
   * @param FormStateInterface $form_state
   * @return void
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
  
  do {
    $queueItems = $this->minivanObj->minivanEmailCheck();  // Get the next email attachments.
    if(empty($queueItems)) {break;}
    foreach ($queueItems as $queueItem) {
      if(empty($queueItem['reportCount'])) {continue;}  // An empty email.
      $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
      // Set up the call to start a batch operation.
      $args = [
        'records' => $queueItem['reports'],
        'fileType' => $queueItem['fileType'],
        'subject' => $queueItem['subject'],
        'month' => $queueItem['month'],
        'day' => $queueItem['day'],
      ];
      
      $batch_builder = (new BatchBuilder())
        ->setTitle(t('Processing import_minivan upload.'))
        ->setFile($modulePath . '/src/Form/ImportMinivanBatch.php')
        ->setFinishCallback('importMinivanFinished')
        ->setInitMessage(t('MiniVAN import is starting.'))
        ->setProgressMessage('Processed @percentage % of minivan reports file.')
        ->setErrorMessage('import_minivan has encountered an error.')
        ->addOperation('importMinivanBatch', [$args,]);
      batch_set($batch_builder->toArray());
      
      }
    } while (TRUE);

  }

}

