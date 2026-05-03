<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpTargetVoterCrosstabCounts
{
  protected Connection $connection;

  const TARGET_VOTER_CROSSTAB_TBL = 'nlp_target_voter_count';
  const ALL_VOTER_CROSSTAB_TBL    = 'nlp_all_voter_count';

  public function __construct($connection) {
    $this->connection = $connection;
  }

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
  public function verifyTable(string $tableName): array
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
   * @param $fileHdr
   * @param $fileHdr2
   * @return array - array of column indexes for the crosstab ballot status counts.
   *
   * The header is represented by the first two rows. Also verifies we have the right file.
   */
  public function decodeCrosstabsHeader($fileHdr, $fileHdr2): array
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
   * @param array $counts
   * @param string $tableName
   * @return bool
   */
  public function updateCrosstabCounts(array $counts, string $tableName): bool
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
   * @param string $tableName
   * @return array - all crosstab counts from the given table.
   */
  public function fetchCrosstabCounts(string $tableName): array
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
   * @param $cd - the congressional district to get counts for.
   * @param string $tableName
   * @return array - counts summed by party for the given congressional district.
   */
  public function getCountsByCd($cd, string $tableName): array
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
   * @param $county - the county to get counts for.
   * @param string $tableName
   * @return array - counts summed by party for the given county.
   */
  public function getCountsByCounty($county, string $tableName): array
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

  /**
   * getCds
   *
   * @param string $tableName
   * @return array - unique congressional districts from the given table.
   */
  public function getCds(string $tableName): array
  {
    $query = $this->connection->select($tableName, 'c');
    $query->distinct();
    $query->addField('c', 'cd');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCds', $e->getMessage());
      return [];
    }

    $cds = [];
    do {
      $cd = $result->fetchAssoc();
      if (empty($cd)) {break;}
      if ($cd['cd'] == 0) {continue;}
      $cds[] = $cd['cd'];
    } while (TRUE);
    return $cds;
  }

}
