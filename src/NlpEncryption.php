<?php

namespace Drupal\nlpservices;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\Entity\Key;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpEncryption
{

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * encrypt_decrypt
   *
   * Encrypt or decrypt a string using AES-256-CBC.
   * 1. Load the secret key and IV from the Drupal Key module.
   * 2. Encrypt or decrypt the string using AES-256-CBC.
   * 3. Return the encrypted or decrypted string.
   * @param string $action
   * @param string $string
   * @return string - the decrypted string.
   */
  public function encrypt_decrypt( string $action, string $string ): string
  {
    if(empty($string)) {return '';}
    $output = '';
    $method = "AES-256-CBC";

    try {
      $storage = $this->entityTypeManager->getStorage('key');
      /** @var Key $keyEntity */
      $keyEntity = $storage->load('secret_key');
      /** @var Key $ivEntity */
      $ivEntity  = $storage->load('secret_iv');
      if (!$keyEntity || !$ivEntity) { return ''; }
      $key    = hash('sha256', $keyEntity->getKeyValue());
      $rawIv  = $ivEntity->getKeyValue();
      $decoded = json_decode($rawIv, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['value'])) {
        $rawIv = $decoded['value'];
      }
      $iv = substr(hash('sha256', $rawIv), 0, 16);
    } catch (Exception) {
      return '';
    }

    if ( $action == 'encrypt' ) {
      $output = openssl_encrypt($string, $method, $key, 0, $iv);
      $output = base64_encode($output);
    } else if( $action == 'decrypt' ) {
      $output = openssl_decrypt(base64_decode($string), $method, $key, 0, $iv);
    }
    return $output;
  }

}
