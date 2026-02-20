<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class DataEntryDefaultMethodForm extends FormBase
{
  
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  
  public function __construct($sessionDataObj, $nlpConfigurationObj) {
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DataEntryDefaultMethodForm
  {
    return new static(
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration')
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
    // Get the list of contact methods configured for this site.
    $contactMethods = $this->nlpConfigurationObj->getContactMethods();
    // Check if a default already exists in the session data.
    $defaultVoterContactMethod = $this->sessionDataObj->get('defaultVoterContactMethod');
    $default = 0;
    if(!empty($defaultVoterContactMethod)) {
      $default = array_search($defaultVoterContactMethod['name'],$contactMethods);
    }
    // If no default is set, use 'Walk' as the default method (or something close to it).
    if(empty($default)) {
      $default = array_search('Walk', $contactMethods);
      if($default === false) {
        $default = reset($contactMethods)['code'];  // The first code.
      }
    }
    
    $form['contact_title'] = [
      '#markup' => 'Choose the most common contact method for your voter contacts',
    ];
    
    $form["contact_method"] = [
      '#type' => 'select',
      '#options' => $contactMethods,
      '#default_value' => $default,
      '#title' => t('Select default method'),
      ];
  
    $form['save'] = [
      '#type' => 'submit',
      '#value' => 'Save',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $selectedContactMethodIndex = $form_state->getValue('contact_method');
    $contactMethods = $this->nlpConfigurationObj->getContactMethods();
    $method = [
      'code' => $selectedContactMethodIndex,
      'name' => $contactMethods[$selectedContactMethodIndex],
    ];
    $this->sessionDataObj->set('defaultVoterContactMethod', $method);
    $messenger->addStatus('You selected the method: '.$method['name']);
  }
}
