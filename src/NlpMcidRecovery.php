<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpMcidRecovery {

  const string RECOVERY_TBL = 'nlp_mcid_recovery';
  const int LIFETIME_SECONDS = 1209600; // 14 days

  protected Connection $connection;

  public function __construct($connection) {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpMcidRecovery
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * saveRecoveryKey
   *
   * Saves an MCID under a new secure random key and returns that key.
   *
   * @param int $mcid
   * @return string  Hex-encoded 32-byte random key (64 characters).
   */
  public function saveRecoveryKey(int $mcid): string
  {
    try {
      $key = bin2hex(random_bytes(32));
    } catch (RandomException $e) {
      nlp_debug_msg('saveRecoveryKey', $e->getMessage());
      // Fall back to something non-random but still unique.  The key will be
      // valid but less resistant to guessing, so this should only be used in
      // extreme circumstances.
      $key = hash('sha256', $mcid . ':' . Drupal::time()->getRequestTime());
    }
    try {
      $this->connection->insert(self::RECOVERY_TBL)
        ->fields([
          'recovery_key' => $key,
          'mcid'         => $mcid,
          'created'      => Drupal::time()->getRequestTime(),
        ])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('saveRecoveryKey', $e->getMessage());
    }
    return $key;
  }

  /**
   * getMcid
   *
   * Returns the MCID for a key if the record exists and is within the 14-day window.
   *
   * Returns NULL when the key is unknown or the record has expired.
   *
   * @param string $key
   * @return int
   */
  public function getMcid(string $key): int
  {
    try {
      $query = $this->connection->select(self::RECOVERY_TBL, 'r');
      $query->fields('r', ['mcid', 'created']);
      $query->condition('recovery_key', $key);
      $record = $query->execute()->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getMcid', $e->getMessage());
      return 0;
    }

    if (empty($record)) {
      return 0;
    }

    $age = Drupal::time()->getRequestTime() - (int) $record['created'];
    if ($age > self::LIFETIME_SECONDS) {
      return 0;
    }

    return (int) $record['mcid'];
  }

  /**
   * deleteKey
   *
   * Deletes a single recovery record by key.  Call after successful retrieval
   * so keys cannot be replayed.
   *
   * @param string $key
   */
  public function deleteKey(string $key): void
  {
    try {
      $this->connection->delete(self::RECOVERY_TBL)
        ->condition('recovery_key', $key)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteKey', $e->getMessage());
    }
  }

  /**
   * Deletes expired records.  Call this from a cron hook to keep the table tidy.
   */
  public function purgeExpired(): void
  {
    $cutoff = Drupal::time()->getRequestTime() - self::LIFETIME_SECONDS;
    try {
      $this->connection->delete(self::RECOVERY_TBL)
        ->condition('created', $cutoff, '<')
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('purgeExpired', $e->getMessage());
    }
  }

}
