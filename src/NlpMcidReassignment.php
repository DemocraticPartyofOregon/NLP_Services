<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Moves everything the NLP database knows about an NL from one MCID to another.
 *
 * Used when VoteBuilder merges a duplicate MyCampaign record into the one NLP
 * Services doesn't know about, destroying the MCID NLP Services does know.
 * The NL keeps their login, magic word, turfs, reports and history under the
 * surviving MCID.
 */
class NlpMcidReassignment {

  // Tables where many rows can carry the MCID; every one is re-pointed.
  const array SHARED_TABLES = [
    'nlp_coordinator',
    'nlp_results',
    'nlp_turf',
    'nlp_turf_assignment',
    'nlp_voter_turf',
  ];

  // Tables keyed by the MCID. A row already under the new MCID belongs to
  // the duplicate, so the old MCID's row (the NL's history) replaces it.
  const array KEYED_TABLES = [
    'nlp_awards',
    'nlp_legislative_fixes',
    'nlp_nls',
  ];

  protected Connection $connection;
  protected DrupalUser $drupalUserObj;
  protected MagicWord $magicWordObj;

  public function __construct($connection, $drupalUserObj, $magicWordObj) {
    $this->connection = $connection;
    $this->drupalUserObj = $drupalUserObj;
    $this->magicWordObj = $magicWordObj;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
    );
  }

  /**
   * reassign
   *
   * Replace every reference to the old MCID with the new one.
   *
   * Refuses when the new MCID already has a login or credentials: then both
   * MCIDs have an account and deciding which to keep is for an admin (the
   * Reassign MCID page), not something to guess at during a sync.
   *
   * @param int $oldMcid - the MCID destroyed by the merge.
   * @param int $newMcid - the surviving MCID.
   * @return array - 'ok' (bool) and, when not ok, 'message' (string).
   */
  public function reassign(int $oldMcid, int $newMcid): array {
    if ($oldMcid == $newMcid) {
      return ['ok' => TRUE];
    }
    if (!empty($this->drupalUserObj->getUserByMcid($newMcid))
      || !empty($this->magicWordObj->getCredentials($newMcid, FALSE))) {
      return ['ok' => FALSE, 'message' => 'MCID ' . $newMcid . ' already has a login.'];
    }

    $transaction = $this->connection->startTransaction();
    try {
      $oldUser = $this->drupalUserObj->getUserByMcid($oldMcid);
      if (!empty($oldUser)) {
        $updated = $this->drupalUserObj->updateUser(['uid' => $oldUser['uid'], 'mcid' => $newMcid]);
        if (($updated['status'] ?? '') == 'error') {
          throw new Exception($updated['message'] ?? 'The login could not be updated.');
        }
      }

      $this->moveMcid('nlp_magic_word', $oldMcid, $newMcid);
      foreach (self::SHARED_TABLES as $table) {
        $this->moveMcid($table, $oldMcid, $newMcid);
      }
      foreach (self::KEYED_TABLES as $table) {
        $this->connection->delete($table)->condition('mcid', $newMcid)->execute();
        $this->moveMcid($table, $oldMcid, $newMcid);
      }

      // Status is keyed by county and MCID.
      $counties = $this->connection->select('nlp_nls_status', 's')
        ->fields('s', ['county'])
        ->condition('mcid', $oldMcid)
        ->execute()
        ->fetchCol();
      if (!empty($counties)) {
        $this->connection->delete('nlp_nls_status')
          ->condition('mcid', $newMcid)
          ->condition('county', $counties, 'IN')
          ->execute();
        $this->moveMcid('nlp_nls_status', $oldMcid, $newMcid);
      }

      // Recovery keys are short-lived and issued for the old MCID; let them lapse.
      $this->connection->delete('nlp_mcid_recovery')->condition('mcid', $oldMcid)->execute();
    }
    catch (Exception $e) {
      $transaction->rollBack();
      nlp_debug_msg('reassign', $e);
      return ['ok' => FALSE, 'message' => 'The database update failed and was rolled back.'];
    }
    return ['ok' => TRUE];
  }

  private function moveMcid(string $table, int $oldMcid, int $newMcid): void {
    $this->connection->update($table)
      ->fields(['mcid' => $newMcid])
      ->condition('mcid', $oldMcid)
      ->execute();
  }

}
