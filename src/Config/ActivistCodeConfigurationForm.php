<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiActivistCodes;
use Drupal\nlpservices\NlpEncryption;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class ActivistCodeConfigurationForm extends ConfigFormBase {
  
  protected ApiActivistCodes $apiActivistCodesObj;
  protected NlpEncryption $nlpEncryptObj;
  protected NlpConfiguration $configurationObj;
  public function __construct( $config_factory, $apiActivistCodesObj, $nlpEncryptObj,
                               $configurationObj) {
    parent::__construct($config_factory);
    $this->apiActivistCodesObj = $apiActivistCodesObj;
    $this->nlpEncryptObj = $nlpEncryptObj;
    $this->configurationObj = $configurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ActivistCodeConfigurationForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array
  {
    return ['nlpservices.configuration'];
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'activist_code_configuration_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $configObj = $this->config('nlpservices.configuration');
    $apiKeys = $configObj->get('nlpservices-api-keys');
    $stateCommitteeKey = $apiKeys['State Committee'];
    $stateCommitteeKey['API Key'] = $this->nlpEncryptObj->encrypt_decrypt('decrypt', $stateCommitteeKey['API Key']);
    $activistCodes = $this->apiActivistCodesObj->getApiActivistCodes(0);
    $mcActivistCodes = $this->apiActivistCodesObj->getApiActivistCodes(1);
    
    $form_state->set('activistCodes',$activistCodes);
    
    $currentNlpHostileCode = $this->configurationObj->getActivistCode('NLPHostileAC');
    $currentNlpVoterCode = $this->configurationObj->getActivistCode('NLPVoterAC');
    $currentNlpDropoutCode = $this->configurationObj->getActivistCode('NLPDropoutAC');
    $currentNlpNotRegisteredCode = $this->configurationObj->getActivistCode('NLPNotRegisteredAC');
    
    $activistCodeList = $this->activistCodeDisplay($activistCodes);
    $mcActivistCodeList = $this->activistCodeDisplay($mcActivistCodes);
    
    $form['activist_codes'] = [
      '#markup' => '<div class="activist-code-box">',
    ];
    $request['name'] = "NLPHostile";
    $request['activistCodeList'] = $activistCodeList;
    $request['currentActivistCode'] = $currentNlpHostileCode;
    $form['activist_codes']['nlp_hostile'] = $this->activistCodeSelect($request);

    $request['name'] = "NLPVoter";
    $request['currentActivistCode'] = $currentNlpVoterCode;
    $form['activist_codes']['nlp_voter'] = $this->activistCodeSelect($request);
    
    $request['name'] = "NLPDropout";
    $request['activistCodeList'] = $mcActivistCodeList;
    $request['currentActivistCode'] = $currentNlpDropoutCode;
    $form['activist_codes']['nlp_dropout'] = $this->activistCodeSelect($request);
    
    $request['name'] = "NLPNotRegistered";
    $request['activistCodeList'] = $mcActivistCodeList;
    $request['currentActivistCode'] = $currentNlpNotRegisteredCode;
    $form['activist_codes']['nlp_not_registered'] = $this->activistCodeSelect($request);
    
    $form['activist_codes-end'] = [
      '#markup' => '</div>',
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    $form['#attached']['library'][] = 'nlpservices/form-helper';
    $form['#attached']['library'][] = 'nlpservices/info-boxes';
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $values = $form_state->getValues();
    if(!empty($values['NLPHostileRemoveAC'])) {
      $messenger->addStatus( $this->t('The current NLP hostile activist code is deselected.'));
      $this->configurationObj->setActivistCode('NLPHostileAC',0);
    }
  
    if($values['NLPHostileActivistCode'] > 1) {
      $this->configurationObj->setActivistCode('NLPHostileAC',
        (int) $values['NLPHostileActivistCode']);
    }
  
    if(!empty($values['NLPVoterRemoveAC'])) {
      $messenger->addStatus( $this->t('The current NLP Voter activist code is deselected.'));
      $this->configurationObj->setActivistCode('NLPVoterAC',0);
    }
  
    if($values['NLPVoterActivistCode'] > 1) {
      $this->configurationObj->setActivistCode('NLPVoterAC',
        (int) $values['NLPVoterActivistCode']);
    }
    
    if(!empty($values['NLPDropoutRemoveAC'])) {
      $messenger->addStatus( $this->t('The current Dropout activist code is deselected.'));
      $this->configurationObj->setActivistCode('NLPDropoutAC',0);
    }
    
    if($values['NLPDropoutActivistCode'] > 1) {
      $this->configurationObj->setActivistCode('NLPDropoutAC',
        (int) $values['NLPDropoutActivistCode']);
    }
    
    if(!empty($values['NLPNotRegisteredRemoveAC'])) {
      $messenger->addStatus( $this->t('The current Not Registered activist code is deselected.'));
      $this->configurationObj->setActivistCode('NLPNotRegisteredAC',0);
    }
    
    if($values['NLPNotRegisteredActivistCode'] > 1) {
      $this->configurationObj->setActivistCode('NLPNotRegisteredAC',
        (int) $values['NLPNotRegisteredActivistCode']);
    }
    
    parent::submitForm($form, $form_state);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * activistCodeSelect
   *
   * Returns form elements to display a list of activist codes and
   * selection of one of them.
   *
   * @param array $request
   * @return array
   */
  function activistCodeSelect(array $request): array
  {
    $name = $request['name'];
    $activistCodeList = $request['activistCodeList'];
    $currentActivistCode = $request['currentActivistCode'];
    $codeName = (empty($currentActivistCode))?'Not yet chosen':
      $activistCodeList[$currentActivistCode];
    
    $form_element['activist'] = [
      '#type' => 'fieldset',
      '#title' => '"'.$name.'" AC Select',
      '#prefix' => " \n".'<div class="activist-code-box">'." \n",
      '#suffix' => " \n".'</div>'." \n",
    ];
    $form_element['activist'][$name.'currentAC'] = [
      '#markup' => '<p><b>The currently chosen Activist code for "'.$name.'" is:</b><br>'.$codeName."</p>",
    ];
    if($currentActivistCode != 'Not chosen yet') {
      $form_element['activist'][$name.'RemoveAC'] = [
        '#type' => 'checkbox',
        '#title' => t('Remove the currently chosen activist code'),
      ];
    }
    $form_element['activist'][$name.'ActivistCode'] = [
      '#type' => 'select',
      '#title' => '"'.$name.'" activist code selection',
      '#options' => $activistCodeList,
      '#description' => t('Select the activist code to be set when a voter is declared to be "'.$name.'"'),
    ];
    return $form_element;
  }
  
  /**
   * activistCodeDisplay
   *
   * Convert an array of activist codes into a list for display and selection.
   *
   * @param array $activistCodes
   * @return array
   */
  function activistCodeDisplay(array $activistCodes): array
  {
    $activistCodeList = [];
    $activistCodeList[1] = 'Select an Activist Code';
    foreach ($activistCodes as $activistCode) {
      $activistCodeList[$activistCode['activistCodeId']] = 'ID:'.$activistCode['activistCodeId'].
        ', name:"'.$activistCode['name'].
        '", type="'.$activistCode['type'].'"';
    }
    return $activistCodeList;
  }
  
}
