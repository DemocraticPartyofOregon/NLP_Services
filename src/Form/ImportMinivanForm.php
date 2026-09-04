<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\Batch\ImportMinivanBatch;
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
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Process the email inbox for new minivan reports.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    do {
      $queueItems = $this->minivanObj->minivanEmailCheck();
      if (empty($queueItems)) { break; }
      foreach ($queueItems as $queueItem) {
        if (empty($queueItem['reportCount'])) { continue; }
        $args = [
          'records'  => $queueItem['reports'],
          'fileType' => $queueItem['fileType'],
          'subject'  => $queueItem['subject'],
          'month'    => $queueItem['month'],
          'day'      => $queueItem['day'],
        ];
        $batch_builder = (new BatchBuilder())
          ->setTitle(t('Processing import_minivan upload.'))
          ->setFinishCallback([ImportMinivanBatch::class, 'finished'])
          ->setInitMessage(t('MiniVAN import is starting.'))
          ->setProgressMessage(t('Processed @percentage % of minivan reports file.'))
          ->setErrorMessage(t('import_minivan has encountered an error.'))
          ->addOperation([ImportMinivanBatch::class, 'process'], [$args]);
        batch_set($batch_builder->toArray());
      }
    } while (TRUE);
  }

}
