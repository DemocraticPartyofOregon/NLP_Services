<?php

namespace Drupal\nlpservices\Config;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\ApiKeyManagement;
use Drupal\nlpservices\NlpConfiguration;
use Exception;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ApiConfigurationForm extends FormBase {

  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;

  public function __construct( $nlpConfigurationObj, $apiKeyManagementObj ) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'api_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $secret_key_exists = !empty($this->apiKeyManagementObj->getKey('secret_key'));
    $secret_iv_exists  = !empty($this->apiKeyManagementObj->getKey('secret_iv'));

    $suggested_key = '';
    try {
      $suggested_key = $this->generateRandomSecretKey();
    } catch (Exception $e) {
      nlp_debug_msg('ApiConfigurationForm::buildForm',
        'Error generating suggested secret key: ' . $e->getMessage());
    }
    $suggested_iv = '';
    try {
      $suggested_iv = $this->generateRandomIV();
    } catch (Exception $e) {
      nlp_debug_msg('ApiConfigurationForm::buildForm',
        'Error generating suggested iv: ' . $e->getMessage());
    }

    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('AES-256-CBC Secret Key'),
      '#description' => $secret_key_exists
        ? $this->t('A secret key is already saved. Leave blank to keep it or enter a new value to replace it (minimum 32 characters recommended).
<strong>Suggested value: <code>@suggested_key</code></strong>', ['@suggested_key' => $suggested_key])
        : $this->t('Enter the secret key for AES-256-CBC encryption (minimum 32 characters recommended).
This field is required as no secret key has been saved yet. Use a mix of uppercase, lowercase, numbers, and special
characters for better security. <strong>Suggested value: <code>@suggested_key</code></strong>',
            ['@suggested_key' => $suggested_key]),
      '#maxlength' => 128,
      '#size' => 40,
      '#required' => !$secret_key_exists,
    ];

    $form['secret_iv'] = [
      '#type' => 'password',
      '#title' => $this->t('AES-256-CBC Secret IV'),
      '#description' => $secret_iv_exists
        ? $this->t('A secret IV is already saved. Leave blank to keep it or enter a new value to replace it (16+ characters recommended).
<strong>Suggested value: <code>@suggested_iv</code></strong>', ['@suggested_iv' => $suggested_iv])
        : $this->t('Enter the secret initialization vector for AES-256-CBC encryption (16+ characters
recommended). This field is required as no secret IV has been saved yet. Use a mix of uppercase, lowercase, numbers,
and special characters. <strong>Suggested value: <code>@suggested_iv</code></strong>',
            ['@suggested_iv' => $suggested_iv]),
      '#maxlength' => 128,
      '#size' => 40,
      '#required' => !$secret_iv_exists,
    ];

    $form['api_keys'] = [
      '#type' => 'file',
      '#title' => $this->t('YAML file with API keys'),
      '#description' => $this->t('Please provide a file of API keys for the participating counties.'),
    ];

    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $secret_key = $form_state->getValue('secret_key');
    $secret_iv  = $form_state->getValue('secret_iv');

    if (!empty($secret_key)) {
      if (strlen($secret_key) < 16) {
        $form_state->setErrorByName('secret_key',
          $this->t('Secret key must be at least 16 characters long for security.'));
      }
      if (!$this->hasMinimumSecurityRequirements($secret_key)) {
        $form_state->setErrorByName('secret_key',
          $this->t('Secret key should contain a mix of uppercase letters, lowercase letters, numbers, and special characters for better security.'));
      }
    }

    if (!empty($secret_iv)) {
      if (strlen($secret_iv) < 12) {
        $form_state->setErrorByName('secret_iv',
          $this->t('Secret IV must be at least 12 characters long for security.'));
      }
      if (!$this->hasMinimumSecurityRequirements($secret_iv)) {
        $form_state->setErrorByName('secret_iv',
          $this->t('Secret IV should contain a mix of uppercase letters, lowercase letters, numbers, and special characters for better security.'));
      }
    }

    $uploadedFile = ($this->getRequest()->files->get('files') ?? [])['api_keys'] ?? NULL;
    if ($uploadedFile === NULL || !$uploadedFile->isValid()) {
      $form_state->setErrorByName('api_keys', $this->t('Please select a valid file to upload.'));
      return;
    }
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    if (!in_array($extension, ['yml', 'yaml'])) {
      $form_state->setErrorByName('api_keys',
        $this->t('The API keys file must be a yml or yaml type.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $secret_key = $form_state->getValue('secret_key');
    $secret_iv  = $form_state->getValue('secret_iv');

    if (!empty($secret_key)) {
      $existing = $this->apiKeyManagementObj->getKey('secret_key');
      if (!empty($existing)) {
        $this->apiKeyManagementObj->deleteKey('secret_key');
      }
      $this->apiKeyManagementObj->createKey('secret_key', 'AES-256-CBC Secret Key', $secret_key);
    }

    if (!empty($secret_iv)) {
      $existing = $this->apiKeyManagementObj->getKey('secret_iv');
      if (!empty($existing)) {
        $this->apiKeyManagementObj->deleteKey('secret_iv');
      }
      $this->apiKeyManagementObj->createKey('secret_iv', 'AES-256-CBC Secret IV', $secret_iv);
    }

    $uploadedFile = ($this->getRequest()->files->get('files') ?? [])['api_keys'] ?? NULL;
    if ($uploadedFile === NULL || !$uploadedFile->isValid()) {
      $this->messenger()->addError($this->t('No valid file was uploaded.'));
      return;
    }
    try {
      $apiKeys = Yaml::decode(file_get_contents($uploadedFile->getRealPath()));
    } catch (Exception) {
      $this->messenger()->addError($this->t('The API keys file could not be parsed.'));
      return;
    }
    if (!is_array($apiKeys)) {
      $this->messenger()->addError($this->t('The API keys file could not be parsed.'));
      return;
    }

    $apiCredentials = $this->apiKeyManagementObj->getKeyValues('State Committee');
    if (!empty($apiCredentials)) {
      $this->apiKeyManagementObj->deleteKey('State Committee');
    }
    $committees = $this->nlpConfigurationObj->getCountyNames();
    nlp_debug_msg('$committees',$committees);
    foreach ($committees as $committee) {
      $apiCredentials = $this->apiKeyManagementObj->getKeyValues($committee);
      nlp_debug_msg('$apiCredentials',$apiCredentials);
      if (empty($apiCredentials)) { continue; }
      $this->apiKeyManagementObj->deleteKey($committee);
    }
    nlp_debug_msg('$apiKeys',$apiKeys);
    foreach ($apiKeys as $committee => $committeeKey) {
      if (empty($committeeKey['API Key'])) { continue; }
      if (!preg_match('/^[a-zA-Z0-9_\- ]+$/', $committee)) { continue; }
      $this->apiKeyManagementObj->deleteKey($committee);
      $this->apiKeyManagementObj->createKeyWithValues($committee, $committee, $committeeKey);
    }

    nlp_debug_msg('apiKeys', $apiKeys);

    $this->messenger()->addStatus($this->t('The API configuration has been saved.'));
  }

  private function generateRandomIV(): string
  {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*_+-=';
    $iv = '';
    for ($i = 0; $i < 16; $i++) {
      try {
        $iv .= $chars[random_int(0, strlen($chars) - 1)];
      } catch (RandomException $e) {
        nlp_debug_msg('ApiConfigurationForm::generateRandomIV', 'Error generating random IV: ' . $e->getMessage());
        break;
      }
    }
    return $iv;
  }

  private function generateRandomSecretKey(): string
  {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*_+-=';
    $key = '';
    for ($i = 0; $i < 32; $i++) {
      try {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
      } catch (RandomException $e) {
        nlp_debug_msg('ApiConfigurationForm::generateRandomSecretKey',
          'Error generating random secret key: ' . $e->getMessage());
        break;
      }
    }
    return $key;
  }

  private function hasMinimumSecurityRequirements(string $value): bool
  {
    $categories = 0;
    if (preg_match('/[A-Z]/', $value)) { $categories++; }
    if (preg_match('/[a-z]/', $value)) { $categories++; }
    if (preg_match('/[0-9]/', $value)) { $categories++; }
    if (preg_match('/[!@#$%^&*_+\-=]/', $value)) { $categories++; }
    return $categories >= 2;
  }

}
