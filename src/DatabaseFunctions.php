<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;

class DatabaseFunctions
{
  
  protected Connection $connection;
  
  public function __construct($connection) {
    $this->connection = $connection;
  }
  
  /**
   * getColumnNames
   *
   * Gets the column names for a given table. Returns an indexed
   * array of column names.
   *
   * @param string $tableName
   * @return array
   */
  public function getColumnNames(string $tableName): array
  {
    try {
      $select = "SHOW COLUMNS FROM  $tableName";
      $result = $this->connection->query($select);
    }
    catch (Exception $e) {
      nlp_debug_msg('getColumnNames', $e->getMessage() );
      return [];
    }
    $colNames = [];
    do {
      $name = $result->fetchAssoc();
      if(empty($name)) {break;}
      $colNames[] = $name['Field'];
    } while (TRUE);
    return $colNames;
  }
  
  /**
   * ExportTable
   *
   * Exports the contents of a table to a CSV file. The export will be:
   *  - The entire table or,
   *  - The table entries for a given county, or
   *  - The entire table sorted by county.
   *
   * @param string $tableName - name of the database table
   * @param array $columnNames - array of column names for the CSV header
   * @param string $tempFileName - file name to contain the CSV data
   * @param string $county
   * @param bool $sort
   * @return bool
   */
  public function exportTable(string $tableName, array $columnNames,
              string $tempFileName, string $county, bool $sort): bool
  {
    $fh = fopen($tempFileName,"w");
    fputcsv($fh, $columnNames);
    
    try {
      $query = $this->connection->select($tableName, 'n');
      $query->fields('n');
      if(!empty($county)) {
        $query->condition('county', $county);
      } elseif($sort) {
        $query->orderBy('county');
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('exportTable', $e->getMessage() );
      return FALSE;
    }
    
    do {
      if(!$award = $result->fetchAssoc()) {break;}
      fputcsv($fh, $award);
    } while (TRUE);
    fclose($fh);
    
    return TRUE;
  }
  
  /**
   * MergeRecord
   *
   * Merges a record into a database table.
   *
   * @param string $table
   * @param array $keys
   * @param array $fields
   * @return bool
   */
  public function mergeRecord(string $table, array $keys, array $fields): bool
  {
    try {
      $this->connection->merge($table)
        ->keys($keys)
        ->fields($fields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('mergeRecord', $e->getMessage() );
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * getColumnSize
   *
   * Gets the size of a VARCHAR column in a database table.
   *
   * @param string $tableName
   * @param string $columnName
   * @return int
   */
  function getColumnSize(string $tableName, string $columnName): int
  {
    // Query the database schema to get the column details.
    try {
      $query = $this->connection->select('information_schema.columns', 'c')
        ->fields('c', ['COLUMN_NAME', 'COLUMN_TYPE'])
        ->condition('c.table_name', $tableName)
        ->condition('c.column_name', $columnName)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getColumnSize', $e->getMessage() );
      return 0;
    }
    $result = $query->fetchAssoc();
    if ($result) {
      $columnType = $result['COLUMN_TYPE'];
      // For VARCHAR, extract the size from the type string.
      if (preg_match('/varchar\((\d+)\)/i', $columnType, $matches)) {
        return (int)  $matches[1]; // Return the column size.
      }
      // If the column is not of a VARCHAR type, return 0.
      return 0;
    }
    return 0;
  }
  
}