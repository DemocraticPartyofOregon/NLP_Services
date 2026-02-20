<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Batch\BatchBuilder;

/**
 * @noinspection PhpUnused
 *  used in route.*/
class MinivanController extends ControllerBase
{
  protected Drupal\nlpservices\NlpMinivan $minivanObj;
  protected Drupal\nlpservices\NlpImap $imapObj;
  protected ConfigFactoryInterface $config;
  
  public function __construct($minivanObj,$imapObj,$config)
  {
    $this->minivanObj = $minivanObj;
    $this->imapObj = $imapObj;
    $this->config = $config;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): MinivanController
  {
    return new static(
      $container->get('nlpservices.minivan'),
      $container->get('nlpservices.imap'),
      $container->get('config.factory'),
    );
  }
  
  /**
   * @return array
   * @noinspection PhpUnused
   * used in route.
   */
  public function minivanImport(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
  
    $messenger = Drupal::messenger();
    $ok = $this->imapObj->getImapConnection('minivan');
    if (!$ok) {
      $messenger->addWarning("Can't connect to MiniVAN email.");
      Drupal::logger('nlpservices')->notice("MiniVAN import: Can't connect to MiniVAN email.");
      return ['#markup' => 'All done!',];
    }
  
    $nlpConfig = $this->config->get('nlpservices.configuration');
    $nlpHostileCode = $nlpConfig->get('nlpservices_hostile_ac');
    $emailsToProcess = $this->imapObj->getNewEmailsToProcess('minivan');
    nlp_debug_msg('$emailsToProcess',$emailsToProcess);
    if(empty($emailsToProcess)) {
      $messenger->addMessage('There are no emails to process.');
      Drupal::logger('nlpservices')->notice('MiniVAN import: There are no emails to process.');
      return ['#markup' => 'All done!',];
    }
    foreach ($emailsToProcess as $emailNumber => $emailToProcess) {
      $attachmentsToProcess = $this->imapObj->getNewEmailAttachments($emailNumber);
      foreach ($attachmentsToProcess as $attachment) {
        if ($attachment['is_attachment']) {
          $reports = str_getcsv($attachment['attachment'], "\n");
          $headerRaw = $reports[0];
          $subject = $emailToProcess['subject'];
          $day = $emailToProcess['day'];
          $month = $emailToProcess['month'];
          $fileType = $emailToProcess['fileType'];
          $headerResult = $this->minivanObj->headerValidate($fileType, $headerRaw);
          if (!$headerResult['ok']) {
            continue;
          }
          if (count($reports) <= 1) {
            $messenger->addMessage('Email is empty, subject: '.$subject);
            Drupal::logger('nlpservices')->notice('MiniVAN import: Email is empty, subject: '.$subject);
            continue;
          }
        
          $pos = $headerResult['pos'];
          unset($reports[0]);
          $reportBatch = $this->minivanObj->fetchReports($reports, $fileType, $nlpHostileCode, $pos);
          if (empty($reportBatch)) {
            continue;
          }
          foreach ($reportBatch as $reportBlock) {
            $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
            nlp_debug_msg('$modulePath',$modulePath);
            // Set up the call to start a batch operation.
            $args = [
              'records' => $reportBlock,
              'fileType' => $fileType,
              'subject' => $subject,
              'month' => $month,
              'day' => $day,
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
            batch_process('node/1');
          }
        }
      }
    }
    if (!empty($moveMessage)) {
      sort($moveMessage);
      $sorted = implode(',', $moveMessage);
      $this->imapObj->moveEmailToTrash($sorted);
    }
    $this->imapObj->closeConnection();
    
    return ['#markup' => 'All done!',];
  }
  
}
