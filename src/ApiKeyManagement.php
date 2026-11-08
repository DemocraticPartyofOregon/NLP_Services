<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
      nlp_debug_msg('saveApiKey', $e);
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
      $filePath = $this->writeKeyFile($id, $value);
      if ($filePath === NULL) {
        return FALSE;
      }

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
      nlp_debug_msg('createKey', $e);
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
      // The Key module returns NULL when the entity exists but its file is
      // missing or unreadable (e.g., www-data can't reach the private path).
      return $key->getKeyValue() ?? '';
    } catch (Exception $e) {
      nlp_debug_msg('getKey', $e);
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
      nlp_debug_msg('deleteKey', $e);
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
   *   Callers that are merely probing for a not-yet-configured key (e.g., the API Configuration
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
      $apiCredentials = json_decode($key->getKeyValue() ?? '', TRUE);
      if(empty($apiCredentials)) {
        Drupal::logger('nlpservices')->error('getKeyValues: key value for id @id is empty or not valid JSON.', ['@id' => $id]);
        return [];
      }
      $apiCredentials['API Key'] = $this->nlpEncryptObj->encrypt_decrypt('decrypt', $apiCredentials['API Key']);
      return $apiCredentials;
    } catch (Exception $e) {
      Drupal::logger('nlpservices')->error('getKeyValues: exception retrieving id @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
      nlp_debug_msg('getKeyValues', $e);
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

      $filePath = $this->writeKeyFile($id, json_encode($values));
      if ($filePath === NULL) {
        return FALSE;
      }

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
      nlp_debug_msg('createKeyWithValues', $e);
      return FALSE;
    }
  }

  /**
   * ensurePrivateStorage
   *
   * Makes sure the key files can be written. settings.php must set
   * $settings['file_private_path']; if the directory it names, or its keys/
   * subdirectory, is missing, it's created. Both must end up writable by the
   * web server. Run from a form, this works as the web server's own user, so
   * it also catches a directory the server can't reach (e.g., under /home
   * when the PHP service has ProtectHome set). Last, it checks that
   * private:// resolves, which needs a cache rebuild after the setting is
   * first added.
   *
   * @return TranslatableMarkup|null
   *   A description of the problem, or NULL if keys can be saved.
   */
  public function ensurePrivateStorage(): ?TranslatableMarkup
  {
    $privatePath = Settings::get('file_private_path');
    if (empty($privatePath)) {
      return $this->t("The private file path is not set. Add \$settings['file_private_path'] to settings.php, pointing to a directory outside the web root that the web server can write to.");
    }
    foreach ([$privatePath, $privatePath . '/keys'] as $dir) {
      if (!is_dir($dir)) {
        if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {
          return $this->t("The directory @path does not exist, and the web server couldn't create it. Create it and make it writable by the web server, or check that the web server can reach that location.",
            ['@path' => $dir]);
        }
        Drupal::logger('nlpservices')->notice('Created the private directory @path.', ['@path' => $dir]);
      }
      if (!is_writable($dir)) {
        return $this->t("The web server can't write to @path.", ['@path' => $dir]);
      }
    }
    // Drupal registers the private:// stream wrapper only when it builds its
    // service container, so a file_private_path added to settings.php after
    // that is visible to Settings but private:// still doesn't resolve.
    if (!$this->fileSystem->realpath('private://')) {
      return $this->t("The private file path is set in settings.php, but Drupal hasn't picked it up yet. Clear all caches (Configuration > Performance, or drush cr) and reload this page.");
    }
    return NULL;
  }

  /**
   * writeKeyFile
   *
   * Writes a key's value to private://keys/<id>.key. The key entity is only
   * created after this succeeds; an entity whose file was never written
   * reads back empty and can't be told apart from a missing key.
   *
   * @param string $id - the ID of the key, used as the file name.
   * @param string $contents - the value to write.
   * @return string|null - the absolute file path, or NULL if it couldn't be
   *   written (the reason is logged).
   */
  private function writeKeyFile(string $id, string $contents): ?string
  {
    $privatePath = $this->fileSystem->realpath('private://');
    if (!$privatePath) {
      Drupal::logger('nlpservices')->error('Key @id was not saved: the private file path is not configured or does not exist.',
        ['@id' => $id]);
      return NULL;
    }
    $dir = $privatePath . '/keys';
    if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE) && !is_dir($dir)) {
      Drupal::logger('nlpservices')->error('Key @id was not saved: could not create @dir: @reason',
        ['@id' => $id, '@dir' => $dir, '@reason' => error_get_last()['message'] ?? 'unknown error']);
      return NULL;
    }
    $filePath = $dir . '/' . $id . '.key';
    if (@file_put_contents($filePath, $contents) === FALSE) {
      Drupal::logger('nlpservices')->error('Key @id was not saved: could not write @file: @reason',
        ['@id' => $id, '@file' => $filePath, '@reason' => error_get_last()['message'] ?? 'unknown error']);
      return NULL;
    }
    return $filePath;
  }

}
