<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpEncryption;

/**
 * @noinspection PhpUnused
 */
class ApiConfigurationForm extends ConfigFormBase {
  
  protected NlpEncryption $nlpEncrypt;
  protected ApiKeyManagement $apiKeyManagementObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($config_factory, $nlpEncrypt, $apiKeyManagementObj, $nlpConfigurationObj) {
    parent::__construct($config_factory);
    $this->nlpEncrypt = $nlpEncrypt;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.api_key_management'),
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
    return 'api_configuration_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {

    // Retrieve existing secret_key and secret_iv from Key management system
    $secret_key = $this->apiKeyManagementObj->getKey('secret_key');
    $secret_iv = $this->apiKeyManagementObj->getKey('secret_iv');

    // Generate random suggestions
    $suggested_key = '';
    try {
      $suggested_key = $this->generateRandomSecretKey();
    } catch (RandomException $e) {
      nlp_debug_msg('ApiConfigurationForm::buildForm',
          'Error generating); suggested secret key: ' . $e->getMessage());
    }
    $suggested_iv = '';
    try {
      $suggested_iv = $this->generateRandomIV();
    } catch (RandomException $e) {
      nlp_debug_msg('ApiConfigurationForm::buildForm',
          'Error generating); suggested iv: ' . $e->getMessage());
    }

    // Check if secret_key already exists
    $secret_key_required = empty($secret_key);

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AES-256-CBC Secret Key'),
      '#description' => $secret_key_required
        ? $this->t('Enter the secret key for AES-256-CBC encryption (minimum 32 characters recommended). 
This field is required as no secret key has been saved yet. Use a mix of uppercase, lowercase, numbers, and special 
characters for better security. <strong>Suggested value: <code>@suggested_key</code></strong>',
              ['@suggested_key' => $suggested_key])
        : $this->t('Enter the secret key for AES-256-CBC encryption (minimum 32 characters recommended). 
Use a mix of uppercase, lowercase, numbers, and special characters. 
<strong>Suggested value: <code>@suggested_key</code></strong>', ['@suggested_key' => $suggested_key]),
      '#default_value' => $secret_key,
      '#maxlength' => 128,
      '#size' => 40,
      '#required' => $secret_key_required,
    ];

    // Check if secret_iv already exists
    $secret_iv_required = empty($secret_iv);

    $form['secret_iv'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AES-256-CBC Secret IV'),
      '#description' => $secret_iv_required
        ? $this->t('Enter the secret initialization vector for AES-256-CBC encryption (16+ characters 
recommended). This field is required as no secret IV has been saved yet. Use a mix of uppercase, lowercase, numbers, 
and special characters. <strong>Suggested value: <code>@suggested_iv</code></strong>',
              ['@suggested_iv' => $suggested_iv])
        : $this->t('Enter the secret initialization vector for AES-256-CBC encryption (16+ characters 
recommended). Use a mix of uppercase, lowercase, numbers, and special characters. 
<strong>Suggested value: <code>@suggested_iv</code></strong>', ['@suggested_iv' => $suggested_iv]),
      '#default_value' => $secret_iv,
      '#maxlength' => 128,
      '#size' => 40,
      '#required' => $secret_iv_required,
    ];



    $form['api_keys'] = [
      '#type' => 'file',
      '#title' => $this->t('YAML file with API keys'),
      '#description' => $this->t('Please provide a file of API keys for the participating counties.'),
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $secret_key = $form_state->getValue('secret_key');
    $secret_iv = $form_state->getValue('secret_iv');

    // Validate secret_key if provided
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

    // Validate secret_iv if provided
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

    $uploadedFile = ($this->getRequest()->files->get('files') ?? [])['api_keys'] ?? null;
    if ($uploadedFile === null || !$uploadedFile->isValid()) {
      $form_state->setErrorByName('api_keys', $this->t('Please select a valid file to upload.'));
      return;
    }
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    if (!in_array($extension, ['yml', 'yaml'])) {
      $form_state->setErrorByName('api_keys',
        $this->t('The API keys file must be a yml or yaml type.'));
    }
    parent::validateForm($form, $form_state);
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {

    $secret_key = $form_state->getValue('secret_key');
    $secret_iv = $form_state->getValue('secret_iv');

    // Check if secret_key exists and delete it before saving the new one.
    $existingSecretKey = $this->apiKeyManagementObj->getKey('secret_key');
    if (!empty($existingSecretKey)) {
      $this->apiKeyManagementObj->deleteKey('secret_key');
    }

    // Create and save the new secret_key if provided.
    if (!empty($secret_key)) {
      $this->apiKeyManagementObj->createKey('secret_key', 'AES-256-CBC Secret Key', $secret_key);
    }

    // Check if secret_iv exists and delete it before saving the new one.
    $existingSecretIv = $this->apiKeyManagementObj->getKey('secret_iv');
    if (!empty($existingSecretIv)) {
      $this->apiKeyManagementObj->deleteKey('secret_iv');
    }

    // Create and save the new secret_iv if provided.
    if (!empty($secret_iv)) {
      $this->apiKeyManagementObj->createKey('secret_iv', 'AES-256-CBC Secret IV', $secret_iv);
    }

    $uploadedFile = ($this->getRequest()->files->get('files') ?? [])['api_keys'] ?? null;
    if ($uploadedFile === null || !$uploadedFile->isValid()) {
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
    foreach ($committees as $committee) {
      $apiCredentials = $this->apiKeyManagementObj->getKeyValues($committee);
      if (empty($apiCredentials)) { continue; }
      $this->apiKeyManagementObj->deleteKey($committee);
    }

    foreach ($apiKeys as $committee => $committeeKey) {
      if (empty($committeeKey['API Key'])) { continue; }
      $this->apiKeyManagementObj->createKeyWithValues($committee, $committee , $committeeKey );
    }

    $this->config('nlpservices.configuration')
      ->set('nlpservices-api-keys', $apiKeys)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * generateRandomIV
   *
   * Generates a random 16-character string with a mix of uppercase, lowercase,
   * numbers, and special characters to be used as a suggested IV value.
   *
   * @return string
   *   A random 16-character string suitable for AES-256-CBC IV.
   * @throws RandomException
   */
  private function generateRandomIV(): string
  {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*_+-=';

    $all_chars = $uppercase . $lowercase . $numbers . $special;
    $iv = '';

    // Ensure at least one character from each category
    $iv .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $iv .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $iv .= $numbers[random_int(0, strlen($numbers) - 1)];
    $iv .= $special[random_int(0, strlen($special) - 1)];

    // Fill the rest with random characters
    for ($i = 4; $i < 16; $i++) {
      $iv .= $all_chars[random_int(0, strlen($all_chars) - 1)];
    }

    // Shuffle the result to avoid predictable patterns
    return str_shuffle($iv);
  }

  /**
   * generateRandomSecretKey
   *
   * Generates a random 32-character string with a mix of uppercase, lowercase,
   * numbers, and special characters to be used as a suggested secret key value.
   *
   * @return string
   *   A random 32-character string suitable for AES-256-CBC secret key.
   * @throws RandomException
   */
  private function generateRandomSecretKey(): string
  {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*_+-=';

    $all_chars = $uppercase . $lowercase . $numbers . $special;
    $key = '';

    // Ensure at least one character from each category
    $key .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $key .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $key .= $numbers[random_int(0, strlen($numbers) - 1)];
    $key .= $special[random_int(0, strlen($special) - 1)];

    // Fill the rest with random characters to reach 32 characters
    for ($i = 4; $i < 32; $i++) {
      $key .= $all_chars[random_int(0, strlen($all_chars) - 1)];
    }

    // Shuffle the result to avoid predictable patterns
    return str_shuffle($key);
  }

  /**
   * hasMinimumSecurityRequirements
   *
   * Checks if a string meets minimum security requirements by containing
   * at least two of the following categories: uppercase, lowercase, numbers,
   * or special characters.
   *
   * @param string $value
   *   The string to validate.
   *
   * @return bool
   *   TRUE if the string meets security requirements, FALSE otherwise.
   */
  private function hasMinimumSecurityRequirements(string $value): bool
  {
    $categories = 0;

    // Check for uppercase letters
    if (preg_match('/[A-Z]/', $value)) {
      $categories++;
    }

    // Check for lowercase letters
    if (preg_match('/[a-z]/', $value)) {
      $categories++;
    }

    // Check for numbers
    if (preg_match('/[0-9]/', $value)) {
      $categories++;
    }

    // Check for special characters
    if (preg_match('/[!@#$%^&*_+\-=]/', $value)) {
      $categories++;
    }

    // Require at least 2 different character categories
    return $categories >= 2;
  }

}
