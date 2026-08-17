<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\ApiKeyManagement;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpFolderSetup;
use Exception;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ApiConfigurationForm extends FormBase {

  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;
  protected NlpFolderSetup $folderSetupObj;

  public function __construct( $nlpConfigurationObj, $apiKeyManagementObj, $folderSetupObj ) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
    $this->folderSetupObj = $folderSetupObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
      $container->get('nlpservices.folder_setup'),
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

    $form['encryption_keys'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Encryption Keys'),
      '#attached' => ['library' => ['nlpservices/info-boxes']],
      '#prefix' => '<div class="form-box-medium">',
      '#suffix' => '</div>',
    ];

    $form['encryption_keys']['secret_key_note'] = [
      '#type' => 'markup',
      '#markup' => $secret_key_exists
        ? '<p>' . $this->t('A secret key and iv have already saved. Leave both blank to keep the current values 
        or enter new values to replace them.') . '</p>'
        : '<p>' . $this->t('These fields are both required.') . '</p>'
    ];

    $form['encryption_keys']['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('AES-256-CBC Secret Key'),
      '#maxlength' => 128,
      '#size' => 40,
    ];
    $form['encryption_keys']['secret_key_description'] = [
      '#type' => 'markup',
      '#markup' =>
        $this->t('Enter the secret key for AES-256-CBC encryption (minimum 32 characters recommended). 
        Use a mix of uppercase, lowercase, numbers, and special characters for better security.') . '</p>'
          . '<p><strong>' . $this->t('Suggested value:') . '</strong> <code>' .
        htmlspecialchars($suggested_key, ENT_QUOTES, 'UTF-8') . '</code></p>',
    ];
    $form['encryption_keys']['secret_iv'] = [
      '#type' => 'password',
      '#title' => $this->t('AES-256-CBC Secret IV'),
      '#maxlength' => 128,
      '#size' => 40,
    ];
    $form['encryption_keys']['secret_iv_description'] = [
      '#type' => 'markup',
      '#markup' =>
        $this->t('Enter the secret initialization vector for AES-256-CBC encryption (16+ characters recommended). 
        Use a mix of uppercase, lowercase, numbers, and special characters.') . '</p>'
          . '<p><strong>' . $this->t('Suggested value:') . '</strong> <code>' .
        htmlspecialchars($suggested_iv, ENT_QUOTES, 'UTF-8') . '</code></p>',
    ];

    $form['api_keys'] = [
      '#type' => 'file',
      '#title' => $this->t('YAML file with API keys for the participating counties'),
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

    $key_empty = empty($secret_key);
    $iv_empty  = empty($secret_iv);

    if ($key_empty !== $iv_empty) {
      $form_state->setErrorByName('secret_key',
        $this->t('The secret key and secret IV must both be provided or both left blank.'));
      $form_state->setErrorByName('secret_iv',
        $this->t('The secret key and secret IV must both be provided or both left blank.'));
      return;
    }

    if ($key_empty && $iv_empty) {
      $key_saved = !empty($this->apiKeyManagementObj->getKey('secret_key'));
      if (!$key_saved) {
        $form_state->setErrorByName('secret_key',
          $this->t('No secret key or IV is saved yet. Both must be provided.'));
        $form_state->setErrorByName('secret_iv',
          $this->t('No secret key or IV is saved yet. Both must be provided.'));
        return;
      }
    }

    if (!empty($secret_key)) {
      if (strlen($secret_key) < 16) {
        $form_state->setErrorByName('secret_key',
          $this->t('Secret key must be at least 16 characters long for security.'));
        return;
      }
      if (!$this->hasMinimumSecurityRequirements($secret_key)) {
        $form_state->setErrorByName('secret_key',
          $this->t('Secret key should contain a mix of uppercase letters, lowercase letters, numbers, and special characters for better security.'));
        return;
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
      $this->apiKeyManagementObj->createKeyWithValues('secret_iv', 'AES-256-CBC Secret IV', ['value' => $secret_iv]);
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
    foreach ($committees as $committee) {
      $apiCredentials = $this->apiKeyManagementObj->getKeyValues($committee);
      if (empty($apiCredentials)) { continue; }
      $this->apiKeyManagementObj->deleteKey($committee);
    }

    $countyNames = [];
    foreach ($apiKeys as $committee => $committeeKey) {
      if (empty($committeeKey['API Key'])) { continue; }
      if (!preg_match('/^[a-zA-Z0-9_\- ]+$/', $committee)) { continue; }
      if ($committee === 'State Committee') {
        $stateName = $committeeKey['Name'] ?? '';
        $this->nlpConfigurationObj->setStateName($stateName);
        continue;
      }
      $this->apiKeyManagementObj->deleteKey($committee);
      $this->apiKeyManagementObj->createKeyWithValues($committee, $committee, $committeeKey);
      $countyNames[] = $committee;
    }
    sort($countyNames);
    $iConfig = Drupal::configFactory()->getEditable('nlpservices.configuration');
    $iConfig->set('nlpservices-county-names', $countyNames)->save();

    $this->folderSetupObj->initializeFolders($countyNames);

    $this->messenger()->addStatus($this->t('The API configuration has been saved.'));
  }

  /**
   * generateRandomIV
   *
   * Generates a random initialization vector (IV) of 16 characters
   * using a predefined set of characters.
   *
   * @return string The generated random initialization vector.
   */
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

  /**
   * generateRandomSecretKey
   *
   * Generates a random secret key consisting of alphanumeric characters
   * and special symbols.
   *
   * @return string The generated random secret key.
   */
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

  /**
   * hasMinimumSecurityRequirements
   *
   * Checks if the given string meets the minimum security requirements.
   *
   * The string must contain at least two of the following character categories:
   * - Uppercase letters
   * - Lowercase letters
   * - Numbers
   * - Special characters (!@#$%^&*_+-=)
   *
   * @param string $value
   *   The string to evaluate.
   *
   * @return bool
   *   TRUE if the string meets the minimum security requirements, FALSE otherwise.
   */
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
