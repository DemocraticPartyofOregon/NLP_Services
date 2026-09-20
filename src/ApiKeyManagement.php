<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;


class ApiKeyManagement
{
  use StringTranslationTrait;

  protected NlpEncryption $nlpEncryptObj;
  public function __construct(
    $nlpEncryptObj,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  )
  {
    $this->nlpEncryptObj = $nlpEncryptObj;
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.encryption'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
    );
  }

  /**
   * saveApiKey
   *
   * Saves an API key in the Drupal Key module repository.  The key is identified by the given ID.
   * The value of the key is set to the given API key.  If the key with the given ID does not exist
   * in the repository, the function returns FALSE.  If the key is successfully updated with the new API
   * key, the function returns TRUE.
   *
   * @param string $keyId - the ID of the key to be updated.
   * @param string $apiKey - the new value of the key to be saved.
   * @return bool - TRUE if the key was successfully updated, FALSE otherwise.
   */
  public function saveApiKey(string $keyId, string $apiKey): bool {
    try {
      /** @var Drupal\key\Entity\Key $key */
      $key = $this->entityTypeManager->getStorage('key')->load($keyId);
      if (!$key) {
        return FALSE;
      }
      $filePath = $key->getKeyProvider()->getConfiguration()['file_location'];
      file_put_contents($filePath, $apiKey);
      return TRUE;
    } catch (Exception $e) {
      nlp_debug_msg('saveApiKey', $e->getMessage());
      return FALSE;
    }
  }

  /**
   * createKey
   *
   * Creates a new key in the Drupal Key module repository with the given ID, label, and value.  The key is created with the key provider set to 'file' and the key input set to 'textarea'.
   *
   * @param string $id - the ID of the key to be created.
   * @param string $label - the label for the key to be created.
   * @param string $value - the value of the key to be created.
   * @return bool - TRUE if the key was successfully created, FALSE otherwise.
   */
  public function createKey(string $id, string $label, string $value): bool
  {
    try {
      // Get the real filesystem path for private files
      $privatePath = $this->fileSystem->realpath('private://');
      if (!$privatePath) {
        nlp_debug_msg('createKey', 'Private file path is not configured; see nlpservices_requirements().');
        return FALSE;
      }
      $dir = $privatePath . '/keys';

      // Ensure directory exists
      if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
      }

      $filePath = $dir . '/' . $id . '.key';
      file_put_contents($filePath, $value);

      $key = $this->entityTypeManager->getStorage('key')->create([
        'id' => $id,
        'label' => $label,
        'key_provider' => 'file',
        'key_provider_settings' => [
          'file_location' => $filePath,
        ],
        'key_input' => 'text_field',
      ]);
      $key->save();
      return TRUE;
    } catch (Exception $e) {
      nlp_debug_msg('createKey', $e->getMessage());
      return FALSE;
    }

  }

  /**
   * getKey
   *
   * Retrieves a key from the Drupal Key module repository by its ID.
   *
   * @param string $id
   * @return string - the key value, or an empty string if the key is not found.
   */
  public function getKey(string $id): string
  {
    try {
      /** @var Drupal\key\Entity\Key $key */
      $key = $this->entityTypeManager->getStorage('key')->load($id);
      if (!$key) {
        return '';
      }
      return $key->getKeyValue();
    } catch (Exception $e) {
      nlp_debug_msg('getKey', $e->getMessage());
      return '';
    }
  }

  /**
   * deleteKey
   *
   * Deletes a key from the Drupal Key module repository by its ID.
   *
   * @param string $id
   * @return bool - TRUE if the key was successfully deleted, FALSE otherwise.
   */
  public function deleteKey(string $id): bool
  {
    try {
      /** @var Drupal\key\Entity\Key $key */
      $key = $this->entityTypeManager->getStorage('key')->load($id);
      if (!$key) { return FALSE;
      }
      $key->delete();
      return TRUE;
    } catch (Exception $e) {
      nlp_debug_msg('deleteKey', $e->getMessage());
      return FALSE;
    }
  }

  /**
   * getKeyValues
   *
   * Retrieves a key from the Drupal Key module repository by its ID and parses its values.
   * This is useful for keys that store multiple values as a structured array (using 'json' key input type).
   * Usage: $values = $magicWord->getKeyValues('my_key_id');
   *
   * @param string $id - the ID of the key to be retrieved.
   * @param bool $logIfMissing - whether to log an error if no key entity exists for the given ID.
   *   Callers that are merely probing for a not-yet-configured key (e.g. the API Configuration
   *   form checking for pre-existing credentials before a first-time save) should pass FALSE,
   *   since a missing key in that case is expected, not an error.
   * @return array - the key values as an associative array, or an empty array if the key is not found.
   */
  public function getKeyValues(string $id, bool $logIfMissing = TRUE): array
  {
    $id = (empty($id))? 'State Committee' : $id;
    try {
      /** @var Drupal\key\Entity\Key $key */
      $key = $this->entityTypeManager->getStorage('key')->load($id);
      if (!$key) {
        if ($logIfMissing) {
          Drupal::logger('nlpservices')->error('getKeyValues: no key entity found for id @id.', ['@id' => $id]);
        }
        return [];
      }
      $apiCredentials = json_decode($key->getKeyValue(), TRUE);
      if(empty($apiCredentials)) {
        Drupal::logger('nlpservices')->error('getKeyValues: key value for id @id is empty or not valid JSON.', ['@id' => $id]);
        return [];
      }
      $apiCredentials['API Key'] = $this->nlpEncryptObj->encrypt_decrypt('decrypt', $apiCredentials['API Key']);
      return $apiCredentials;
    } catch (Exception $e) {
      Drupal::logger('nlpservices')->error('getKeyValues: exception retrieving id @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
      nlp_debug_msg('getKeyValues', $e->getMessage());
      return [];
    }
  }

  /**
   * createKeyWithValues
   *
   * Creates a new key in the Drupal Key module repository with multiple values.
   * Uses the 'json' key input type which allows storing structured data as an associative array.
   * The key can then be retrieved with getKeyValues, which returns the parsed array.
   * Usage: $magicWord->createKeyWithValues('state_key', 'State Committee', ['API Key' => 'xxx', 'userName' => 'yyy']);
   *
   * @param string $id - the ID of the key to be created.
   * @param string $label - the label for the key to be created.
   * @param array $values - an associative array of values to be stored in the key.
   *   Example: ['API Key' => 'secret123', 'API userName' => 'user', 'API Password' => 'pass']
   * @return bool - TRUE if the key was successfully created, FALSE otherwise.
   */
  public function createKeyWithValues(string $id, string $label, array $values): bool
  {
    try {
      if (!empty($values['API Key'])) {
        $values['API Key'] = $this->nlpEncryptObj->encrypt_decrypt('encrypt', $values['API Key']);
      }

      // Get the real filesystem path for private files
      $privatePath = $this->fileSystem->realpath('private://');
      if (!$privatePath) {
        nlp_debug_msg('createKeyWithValues', 'Private file path is not configured; see nlpservices_requirements().');
        return FALSE;
      }
      $dir = $privatePath . '/keys';

      // Ensure directory exists
      if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
      }

      $filePath = $dir . '/' . $id . '.key';
      file_put_contents($filePath, json_encode($values));

      $key = $this->entityTypeManager->getStorage('key')->create([
        'id' => $id,
        'label' => $label,
        'key_provider' => 'file',
        'key_provider_settings' => [
          'file_location' => $filePath,
        ],
        'key_input' => 'text_field',
      ]);
      $key->save();
      return TRUE;
    } catch (Exception $e) {
      nlp_debug_msg('createKeyWithValues', $e->getMessage());
      return FALSE;
    }
  }

}
