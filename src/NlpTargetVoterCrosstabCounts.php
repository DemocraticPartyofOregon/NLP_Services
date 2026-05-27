<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpTargetVoterCrosstabCounts extends NlpCrosstabCounts
{
  public const string TARGET_VOTER_CROSSTAB_TBL = 'nlp_target_voter_count';
  public const string ALL_VOTER_CROSSTAB_TBL    = 'nlp_all_voter_count';

  public static function create(ContainerInterface $container): NlpTargetVoterCrosstabCounts
  {
    return new static(
      $container->get('database'),
    );
  }

  private array $targetVoterVanHdr1 = [
    'cd'    => 'US Congressional District',
    'county' => 'County',
    'party'  => 'Likely Party',
    'total'  => 'Total People',
  ];

  private array $targetVoterVanHdr2 = [
    'accepted'      => 'Accepted',
    'rejected'      => 'Rejected',
    'sent'          => 'Sent',
    'undeliverable' => 'Undeliverable',
    'void'          => 'Void',
    'unknown'       => 'Unknown',
  ];

  private array $expectedColumns = [
    'cd', 'county', 'party', 'totalPeople',
    'accepted', 'rejected', 'sent', 'undeliverable', 'void', 'unknown',
  ];

  /**
   * verifyTable
   *
   * Verifies that the given crosstab table exists and contains all expected columns.
   *
   * @param string $tableName
   * @return array - ['ok' => bool, 'err' => string[]]
   */
  public function verifyTable( string $tableName ): array
  {
    $errors = [];
    $schema = $this->connection->schema();

    if (!$schema->tableExists($tableName)) {
      return ['ok' => FALSE, 'err' => ['Table "' . $tableName . '" does not exist.']];
    }
    foreach ($this->expectedColumns as $column) {
      if (!$schema->fieldExists($tableName, $column)) {
        $errors[] = 'Column "' . $column . '" is missing from "' . $tableName . '".';
      }
    }
    return ['ok' => empty($errors), 'err' => $errors];
  }

  /**
   * decodeCrosstabsHeader
   *
   * @param string $fileHdr
   * @param string $fileHdr2
   * @return array - array of column indexes for the crosstab ballot status counts.
   *
   * The header is represented by the first two rows. Also verifies we have the right file.
   */
  public function decodeCrosstabsHeader( string $fileHdr, string $fileHdr2): array
  {
    $hdrErr = $hdrPos = [];
    foreach ($this->targetVoterVanHdr1 as $nlpKey => $vanField) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol => $fileColName) {
        $fileColName = preg_replace("/[^A-Za-z0-9 ]/", '', $fileColName);
        if (trim($fileColName) == $vanField) {
          $hdrPos[$nlpKey] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $hdrErr[] = 'The crosstab export header "' . $vanField . '" is missing.';
      }
    }
    foreach ($this->targetVoterVanHdr2 as $nlpKey => $vanField) {
      $found = FALSE;
      foreach ($fileHdr2 as $fileCol => $fileColName) {
        if (trim($fileColName) == $vanField) {
          $hdrPos[$nlpKey] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $hdrErr[] = 'The crosstab export header "' . $vanField . '" is missing.';
      }
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }

  /**
   * updateCrosstabCounts
   *
   * Updates the crosstab counts table.
   * 1. If the row already exists, it updates the registered voters and registered voted counts.
   * 2. If the row does not exist, it inserts a new row.
   *
   * @param array $counts
   * @param string $tableName
   * @return bool
   */
  public function updateCrosstabCounts( array $counts, string $tableName ): bool
  {
    try {
      $this->connection->merge($tableName)
        ->keys([
          'cd'     => $counts['cd'],
          'county' => $counts['county'],
          'party'  => $counts['party'],
        ])
        ->fields([
          'totalPeople'   => $counts['total'],
          'accepted'      => $counts['accepted'],
          'rejected'      => $counts['rejected'],
          'sent'          => $counts['sent'],
          'undeliverable' => $counts['undeliverable'],
          'void'          => $counts['void'],
          'unknown'       => $counts['unknown'],
        ])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateCrosstabCounts', $e->getMessage());
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
   * @param string $tableName
   * @return array - all crosstab counts from the given table.
   */
  public function fetchCrosstabCounts( string $tableName ): array
  {
    $query = $this->connection->select($tableName, 'c');
    $query->fields('c');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('fetchCrosstabCounts', $e->getMessage());
      return [];
    }

    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if (empty($count)) {break;}
      $counts[$count['cd']][$count['county']][$count['party']] = [
        'total'         => $count['totalPeople'],
        'accepted'      => $count['accepted'],
        'rejected'      => $count['rejected'],
        'sent'          => $count['sent'],
        'undeliverable' => $count['undeliverable'],
        'void'          => $count['void'],
        'unknown'       => $count['unknown'],
      ];
    } while (TRUE);
    return $counts;
  }

  /**
   * getCountsByCd
   *
   * Gets the counts for a given congressional district.
   * 1. The counts are grouped by party.
   * 2. The counts are summed.
   *
   * @param int $cd - the congressional district to get counts for.
   * @param string $tableName
   * @return array - counts summed by party for the given congressional district.
   */
  public function getCountsByCd( int $cd, string $tableName): array
  {
    $query = $this->connection->select($tableName, 'c');
    $query->fields('c');
    $query->condition('cd', $cd);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountsByCd', $e->getMessage());
      return [];
    }

    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if (empty($count)) {break;}
      $party = $count['party'];
      if (empty($counts[$party])) {
        $counts[$party] = [
          'total'         => $count['totalPeople'],
          'accepted'      => $count['accepted'],
          'rejected'      => $count['rejected'],
          'sent'          => $count['sent'],
          'undeliverable' => $count['undeliverable'],
          'void'          => $count['void'],
          'unknown'       => $count['unknown'],
        ];
      } else {
        $counts[$party]['total']         += $count['totalPeople'];
        $counts[$party]['accepted']      += $count['accepted'];
        $counts[$party]['rejected']      += $count['rejected'];
        $counts[$party]['sent']          += $count['sent'];
        $counts[$party]['undeliverable'] += $count['undeliverable'];
        $counts[$party]['void']          += $count['void'];
        $counts[$party]['unknown']       += $count['unknown'];
      }
    } while (TRUE);
    return $counts;
  }

  /**
   * getCountsByCounty
   *
   * Gets the counts for a given county.
   * 1. The counts are grouped by party.
   * 2. The counts are summed.
   *
   * @param string $county - the county to get counts for.
   * @param string $tableName
   * @return array - counts summed by party for the given county.
   */
  public function getCountsByCounty( string $county, string $tableName ): array
  {
    $query = $this->connection->select($tableName, 'c');
    $query->fields('c');
    $query->condition('county', $county);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountsByCounty', $e->getMessage());
      return [];
    }

    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if (empty($count)) {break;}
      $party = $count['party'];
      if (empty($counts[$party])) {
        $counts[$party] = [
          'total'         => $count['totalPeople'],
          'accepted'      => $count['accepted'],
          'rejected'      => $count['rejected'],
          'sent'          => $count['sent'],
          'undeliverable' => $count['undeliverable'],
          'void'          => $count['void'],
          'unknown'       => $count['unknown'],
        ];
      } else {
        $counts[$party]['total']         += $count['totalPeople'];
        $counts[$party]['accepted']      += $count['accepted'];
        $counts[$party]['rejected']      += $count['rejected'];
        $counts[$party]['sent']          += $count['sent'];
        $counts[$party]['undeliverable'] += $count['undeliverable'];
        $counts[$party]['void']          += $count['void'];
        $counts[$party]['unknown']       += $count['unknown'];
      }
    } while (TRUE);
    return $counts;
  }

}
