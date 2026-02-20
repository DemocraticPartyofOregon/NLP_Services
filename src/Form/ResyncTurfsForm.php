<?php


namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTurfs;

/**
 * @noinspection PhpUnused
 */

class ResyncTurfsForm extends FormBase
{
  
  protected ConfigFactoryInterface $config;
  protected NlpTurfs $turfsObj;
  
  public function __construct($config, $turfsObj)
  {
    $this->config = $config;
    $this->turfsObj = $turfsObj;
  
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ResyncTurfsForm
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.turfs'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_resync_turfs_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    $form['resyncTurfs'] = [
      '#type' => 'submit',
      '#value' => 'Do it.',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();
  
    $messenger = Drupal::messenger();
  
    $nlpConfig = $this->config->get('nlpservices.configuration');
    $votebuilderUpdateDate = $nlpConfig->get('nlpservices-votebuilder-update-date');
    $staleTurfs = $this->turfsObj->getStaleTurfs();
    if(empty($staleTurfs)) {
      $messenger->addWarning('There are no updatable turfs.');
      return;
    }
    
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);

    $args = [
      'votebuilderUpdateDate' => $votebuilderUpdateDate,
      'turfs' => $staleTurfs,
    ];
    $batch = [
      'operations' => [
        ['resyncTurfsBatch', [$args]]
      ],
      'file' => $modulePath . '/src/Form/ResyncTurfsBatch.php',
      'finished' => 'resyncTurfsBatchFinished',
      'title' => 'Resync existing turfs with new registration data.',
      'init_message' => 'Resync is starting.',
      'progress_message' => 'Processed @percentage % of reports turfs.',
      'error_message' => 'resync of turfs has encountered an error.',
    ];
    nlp_debug_msg('$batch',$batch);
    batch_set($batch);
  
  }
}
