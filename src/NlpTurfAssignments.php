<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfAssignments
{
  const string TURF_ASSIGNMENT_TBL = 'nlp_turf_assignment';

  protected Connection $connection;

  public function __construct($connection)
  {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpTurfAssignments
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * Sets or updates a turf assignment in the database.
   *
   * @param array $assignment - An associative array containing turf assignment information.
   * @return void
   */
  public function setTurfAssignment(array $assignment): void
  {
    $keyFields = ['turfId' => $assignment['turfId']];
    $dataFields = $assignment;
    unset($dataFields['turfId']);
    try {
      $this->connection->merge(self::TURF_ASSIGNMENT_TBL)
        ->keys($keyFields)
        ->fields($dataFields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setTurfAssignment', $e);
    }
  }

  /**
   * Returns a turf assignment record for a specific turfId.
   *
   * @param int $turfId
   * @return array - An associative array containing turf assignment information if it exists, otherwise an empty array.
   */
  public function getTurfAssignment(int $turfId): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.turfId', $turfId);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignment', $e);
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) { return []; }
    return $assignment;
  }

  /**
   * Returns an array of turf assignments for a specific MCID.
   *
   * @param int $mcid
   * @return array - An indexed array of turf assignments.
   */
  public function getTurfAssignmentsById(int $mcid): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignmentsById', $e);
      return [];
    }

    $assignments = [];
    do {
      $assignment = $result->fetchAssoc();
      if (empty($assignment)) { break; }
      $assignments[] = $assignment;
    } while (TRUE);
    return $assignments;
  }

  /**
   * Returns a turf assignment record for a specific folderId and turfName.
   *
   * @param int $folderId
   * @param string $turfName
   * @return array - An associative array containing turf assignment information if it exists, otherwise an empty array.
   */
  public function getTurfAssignmentByTurfName(int $folderId, string $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.folderId', $folderId);
      $query->condition('t.turfName', $turfName);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignmentByTurfName', $e);
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) { return []; }
    return $assignment;
  }

  /**
   * Deletes a turf assignment record for a specific turfId.
   *
   * @param int $turfId
   * @return void
   */
  public function deleteTurfAssignment(int $turfId): void
  {
    try {
      $this->connection->delete(self::TURF_ASSIGNMENT_TBL)
        ->condition('turfId', $turfId)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteTurfAssignment', $e);
    }
  }

  /**
   * Decodes the header of a turf assignment file and checks for required columns.
   *
   * @param array $fileHdr - An array of column names from the file header.
   * @return array - An associative array containing header positions, errors, and status.
   */
  public function decodeTurfAssignmentHeader(array $fileHdr): array
  {
    $hdrErr = $hdrPos = [];
    $awardHeader = $this->getAssignmentColumnNames();
    foreach ($awardHeader as $columnName) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol => $fileColName) {
        if (trim($fileColName) == $columnName) {
          $hdrPos[$columnName] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $hdrErr[] = 'The awards column "' . $columnName . '" is missing.';
      }
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }

  /**
   * Returns an array of column names from the turf assignment table.
   *
   * @return array - An indexed array of column names.
   */
  public function getAssignmentColumnNames(): array
  {
    try {
      $select = "SHOW COLUMNS FROM  {" . self::TURF_ASSIGNMENT_TBL . '}';
      $result = $this->connection->query($select);
    } catch (Exception $e) {
      nlp_debug_msg('getAssignmentColumnNames', $e);
      return [];
    }
    $colNames = [];
    do {
      $name = $result->fetchAssoc();
      if (empty($name)) { break; }
      $colNames[] = $name['Field'];
    } while (TRUE);
    return $colNames;
  }

  /**
   * Fixes a turf assignment by changing the turfId to a new turfId.
   *
   * @param int $turfId - The current turfId to be fixed.
   * @param int $newTurfId - The new turfId to assign.
   * @return void
   */
  public function fixTurfAssignment(int $turfId, int $newTurfId): void
  {
    $turfAssignment = $this->getTurfAssignment($turfId);
    $this->deleteTurfAssignment($turfId);
    $turfAssignment['turfId'] = $newTurfId;
    $this->setTurfAssignment($turfAssignment);
  }

}
