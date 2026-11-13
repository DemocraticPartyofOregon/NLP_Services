<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpCrosstabCounts
{
  protected Connection $connection;

  public function __construct( $connection) {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpCrosstabCounts
  {
    return new static(
      $container->get('database'),
    );
  }

  public const string CROSSTAB_COUNTS_TBL = 'nlp_ballot_count';

  private array $crosstabCountVanHdr1 = [
    'cd' => 'CD',
    'county' => 'County',
    'party' => 'Party',
    'voteDate' => 'Vote Return Date',
    'total' => 'Total People',
    ];

  private array $crosstabCountVanHdr2 = [
    'balRet' => 'Bal Ret',
    ];

  // Optional first-header columns.  A file without them still imports.
  private array $crosstabCountVanHdrOptional = [
    'hd' => 'HD',
  ];

  /**
   * decodeCrosstabsHeader
   *
   * Decodes the header of the crosstab export file.
   *
   * @param array $fileHdr
   * @param array $fileHdr2
   * @return array - array of column indexes for the ballot returned counts.
   *
   * The header is represented by the first two rows.  Also, it verifies we have the right file.
   * The HD column is optional; 'hd' is in the positions only when the file has it.
   */
  public function decodeCrosstabsHeader( array $fileHdr, array $fileHdr2 ): array
  {
    ['pos' => $pos1, 'err' => $err1] = $this->matchHeaderColumns($this->crosstabCountVanHdr1, $fileHdr);
    ['pos' => $pos2, 'err' => $err2] = $this->matchHeaderColumns($this->crosstabCountVanHdr2, $fileHdr2);
    ['pos' => $posOptional] = $this->matchHeaderColumns($this->crosstabCountVanHdrOptional, $fileHdr);
    $hdrPos = array_merge($pos1, $pos2, $posOptional);
    $hdrErr = array_merge($err1, $err2);
    return ['pos' => $hdrPos, 'err' => $hdrErr, 'ok' => empty($hdrErr)];
  }

  protected function matchHeaderColumns(array $vanFields, array $fileHeader): array
  {
    $hdrErr = $hdrPos = [];
    foreach ($vanFields as $nlpKey => $vanField) {
      $found = FALSE;
      foreach ($fileHeader as $fileCol => $fileColName) {
        $clean = trim(preg_replace("/[^A-Za-z0-9 ]/", '', $fileColName));
        if ($clean === $vanField) {
          $hdrPos[$nlpKey] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $hdrErr[] = 'The crosstab export header "' . $vanField . '" is missing.';
      }
    }
    return ['pos' => $hdrPos, 'err' => $hdrErr];
  }
  
  /**
   * updateCrosstabCounts
   *
   * Updates the crosstab counts table.
   * 1. If the row already exists, it updates the registered voters and registered voted counts.
   * 2. If the row does not exist, it inserts a new row.
   * @param array $counts - cd, county, party, regVoters, regVoted, and an
   *   optional hd (0 when the file has no HD column).
   * @return bool
   */
  public function updateCrosstabCounts( array $counts ): bool
  {
    try {
      $this->connection->merge(self::CROSSTAB_COUNTS_TBL)
        ->keys([
          'cd' => $counts['cd'],
          'county' => $counts['county'],
          'hd' => (int) ($counts['hd'] ?? 0),
          'party' => $counts['party']])
        ->fields([
          'registeredVoters' => $counts['regVoters'],
          'registeredVoted' => $counts['regVoted']])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateCrosstabCounts', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * deleteAllCrosstabCounts
   *
   * Removes all the counts before a new crosstab file is saved.  The file is
   * for the whole state, and rows left from an earlier file (with or without
   * HDs) would be added twice into the county and CD totals.
   *
   * @return bool
   */
  public function deleteAllCrosstabCounts(): bool
  {
    try {
      $this->connection->delete(self::CROSSTAB_COUNTS_TBL)->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteAllCrosstabCounts', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * fetchCrosstabCounts
   *
   * Fetches the crosstab counts from the database.
   * 1. The counts are grouped by congressional district.
   * 2. The counts are grouped by county.
   * 3. The counts are grouped by party.
   * @return array - array of counts from VotedBuilder to report voter turnout by county and party.
   */
  public function fetchCrosstabCounts(): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('fetchCrosstabCounts', $e);
      return [];
    }
    
    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if(empty($count)) {break;}
      // A file with HDs has a row per HD; sum them for the county.
      $partyCounts = &$counts[$count['cd']][$count['county']][$count['party']];
      $partyCounts['regVoted'] = ($partyCounts['regVoted'] ?? 0) + $count['registeredVoted'];
      $partyCounts['regVoters'] = ($partyCounts['regVoters'] ?? 0) + $count['registeredVoters'];
      unset($partyCounts);
    } while (TRUE);
    return $counts;
  }

  /**
   * getCountsByCd
   *
   * Gets the counts for a given congressional district.
   *
   * @param int $cd - the congressional district to get counts for.
   * @return array - array of counts by party for the given congressional district.
   */
  public function getCountsByCd( int $cd ): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    $query->condition('cd', $cd);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCdCounts', $e);
      return [];
    }
    return $this->aggregateCountsByParty($result);
  }

  /**
   * getCountsByCounty
   *
   * Gets the counts for a given county.
   *
   * @param string $county - the county to get counts for.
   * @return array - array of counts by party for the given county.
   */
  public function getCountsByCounty( string $county ): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    $query->condition('county', $county);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCdCountsByCounty', $e);
      return [];
    }
    return $this->aggregateCountsByParty($result);
  }

  /**
   * getCountsByHd
   *
   * Gets the counts for a house district in a county.  Empty if the crosstab
   * file had no HD column.
   *
   * @param string $county
   * @param int $hd
   * @return array - array of counts by party for the HD.
   */
  public function getCountsByHd( string $county, int $hd ): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    $query->condition('county', $county);
    $query->condition('hd', $hd);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountsByHd', $e);
      return [];
    }
    return $this->aggregateCountsByParty($result);
  }

  /**
   * aggregateCountsByParty
   * 1. Aggregates the counts by party.
   * 2. The counts are grouped by party.
   * @param $result
   * @return array - array of counts by party.
   */
  private function aggregateCountsByParty($result): array
  {
    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if (empty($count)) { break; }
      if (empty($counts[$count['party']])) {
        $counts[$count['party']]['regVoted']  = $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] = $count['registeredVoters'];
      } else {
        $counts[$count['party']]['regVoted']  += $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] += $count['registeredVoters'];
      }
    } while (TRUE);
    return $counts;
  }
  
  /**
   * getCds
   *
   * Gets the unique congressional districts from the given table.
   *
   * @param string $tableName
   * @return array - array of unique cds from the given table.
   *
   * This is used to populate the select list for the Crosstab Counts page.
   */
  public function getCds( string $tableName = self::CROSSTAB_COUNTS_TBL ): array
  {
    $query = $this->connection->select($tableName, 'c');
    $query->distinct();
    $query->addField('c', 'cd');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCds', $e);
      return [];
    }

    $cds = [];
    do {
      $cd = $result->fetchAssoc();
      if(empty($cd)) {break;}
      if($cd['cd'] == 0 ) {continue;}
      $cds[] = $cd['cd'];
    } while (TRUE);

    return $cds;
  }

}
