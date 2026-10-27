<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Keeps a history of the NLPVoterAC changes sent to VAN for each voter, and of
 * the checks that found a voter in a turf without the code.
 *
 * VoteBuilder shows only a voter's current activist codes, not when they were
 * applied or removed.  This history is used to look for a pattern in the
 * voters found missing the code, such as a Remove from a deleted turf that
 * was sent close to an Apply from a new turf.
 */
class NlpAcHistory
{
  const string AC_HISTORY_TBL = 'nlp_ac_history';

  const string APPLY = 'Apply';
  const string REMOVE = 'Remove';
  const string APPLY_REFUSED = 'Apply refused';
  const string REMOVE_REFUSED = 'Remove refused';
  const string MISSING = 'Missing';

  /**
   * How long the history is kept.
   */
  const int KEEP_SECS = 120 * 86400;

  /**
   * The most voters described in one log message.
   */
  const int MAX_DESCRIBED = 20;

  protected Connection $connection;

  public function __construct($connection)
  {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpAcHistory
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * record
   *
   * Records one event for each voter.
   *
   * @param array $vanids
   * @param string $event - one of the event constants.
   * @param string $source - what made the change or check, like 'turf sync of Oak Ave (turfIndex 7)'.
   * @return void
   */
  public function record(array $vanids, string $event, string $source): void
  {
    if (empty($vanids)) { return; }
    $now = time();
    $source = mb_substr($source, 0, 255);
    try {
      $query = $this->connection->insert(self::AC_HISTORY_TBL)
        ->fields(['vanid', 'event', 'source', 'created']);
      foreach ($vanids as $vanid) {
        $query->values([(int) $vanid, $event, $source, $now]);
      }
      $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('NlpAcHistory record', $e);
    }
  }

  /**
   * vanidsWithEventSince
   *
   * @param array $vanids
   * @param array $events
   * @param int $since - a unix time.
   * @return array - the vanids, of those given, with one of the events since that time, as ints.
   */
  public function vanidsWithEventSince(array $vanids, array $events, int $since): array
  {
    if (empty($vanids) || empty($events)) { return []; }
    try {
      $query = $this->connection->select(self::AC_HISTORY_TBL, 'h');
      $query->addField('h', 'vanid');
      $query->condition('h.vanid', $vanids, 'IN');
      $query->condition('h.event', $events, 'IN');
      $query->condition('h.created', $since, '>=');
      $query->distinct();
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('NlpAcHistory vanidsWithEventSince', $e);
      return [];
    }
    return array_map('intval', $result->fetchCol());
  }

  /**
   * describe
   *
   * Describes each voter's history since a time, for a log message.  Like:
   * "222: Remove from turf delete (turfIndex 7) at 10:02:15, Apply from turf
   * sync of Oak Ave (turfIndex 9) at 10:04:40".
   *
   * @param array $vanids
   * @param int $since - a unix time.
   * @return string
   */
  public function describe(array $vanids, int $since): string
  {
    $described = array_slice($vanids, 0, self::MAX_DESCRIBED);
    if (empty($described)) { return ''; }
    try {
      $query = $this->connection->select(self::AC_HISTORY_TBL, 'h');
      $query->fields('h', ['vanid', 'event', 'source', 'created']);
      $query->condition('h.vanid', $described, 'IN');
      $query->condition('h.created', $since, '>=');
      $query->orderBy('h.created');
      $query->orderBy('h.hIndex');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('NlpAcHistory describe', $e);
      return '';
    }
    $events = [];
    while ($row = $result->fetchAssoc()) {
      $events[(int) $row['vanid']][] = $row['event'] . ' from ' . $row['source'] . ' at ' . date('H:i:s', (int) $row['created']);
    }
    $lines = [];
    foreach ($described as $vanid) {
      $lines[] = $vanid . ': ' . (empty($events[(int) $vanid]) ? 'no history' : implode(', ', $events[(int) $vanid]));
    }
    $more = count($vanids) - count($described);
    return implode('; ', $lines) . ($more > 0 ? "; and $more more" : '');
  }

  /**
   * purge
   *
   * Deletes the history older than KEEP_SECS.
   *
   * @return void
   */
  public function purge(): void
  {
    try {
      $this->connection->delete(self::AC_HISTORY_TBL)
        ->condition('created', time() - self::KEEP_SECS, '<')
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('NlpAcHistory purge', $e);
    }
  }

}
