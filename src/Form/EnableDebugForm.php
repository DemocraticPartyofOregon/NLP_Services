<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class EnableDebugForm extends FormBase
{
  
  protected NlpConfiguration $nlpConfigurationObj;
  
  public function __construct($nlpConfigurationObj)
  {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): EnableDebugForm
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_enable_debug_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    
    $form['enableDebug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug'),
      '#description' => $this->t('Enable debug mode for the NLP services.'),
      '#default_value' => $this->nlpConfigurationObj->getDebug(),
    ];
    
    $form['form_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change the debug setting'),
    ];
    
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $debug = $values['enableDebug'];
    $this->nlpConfigurationObj->setDebug($debug);
  }
  
}
