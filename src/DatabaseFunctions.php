<?php

namespace Drupal\nlpservices;

use Drupal;
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
   * verifySchema
   *
   * Compares the live database against the schema defined in hook_schema().
   * Returns an array of error strings for any missing tables, fields, or
   * field parameter mismatches; an empty array means the database matches.
   *
   * @return array
   */
  public function verifySchema(): array
  {
    Drupal::moduleHandler()->loadInclude('nlpservices', 'install');
    $schema = nlpservices_schema();
    $dbSchema = $this->connection->schema();
    $errors = [];
    foreach ($schema as $tableName => $tableSpec) {
      if (!$dbSchema->tableExists($tableName)) {
        $errors[] = "Missing table: $tableName";
        continue;
      }
      foreach ($tableSpec['fields'] as $fieldName => $fieldSpec) {
        if (!$dbSchema->fieldExists($tableName, $fieldName)) {
          $errors[] = "Missing field: $tableName.$fieldName";
          continue;
        }
        array_push($errors, ...$this->verifyFieldParameters($tableName, $fieldName, $fieldSpec));
      }
    }
    return $errors;
  }

  /**
   * Maps a Drupal schema field spec to the expected MySQL DATA_TYPE string.
   */
  private function drupalTypeToMysql(array $spec): string
  {
    if (isset($spec['mysql_type'])) {
      return strtolower($spec['mysql_type']);
    }
    $type = $spec['type'] ?? 'varchar';
    $size = $spec['size'] ?? 'normal';
    if ($type === 'serial') {
      return 'int';
    }
    if ($type === 'int') {
      return match ($size) {
        'tiny'   => 'tinyint',
        'small'  => 'smallint',
        'medium' => 'mediumint',
        'big'    => 'bigint',
        default  => 'int',
      };
    }
    if ($type === 'float' && $size === 'big') {
      return 'double';
    }
    if ($type === 'numeric') {
      return 'decimal';
    }
    return $type;
  }

  /**
   * Checks a single live field against its schema spec.
   * Returns error strings for any parameter mismatches.
   */
  private function verifyFieldParameters(string $tableName, string $fieldName, array $spec): array
  {
    try {
      $options  = $this->connection->getConnectionOptions();
      $database = $options['database'];
      $result   = $this->connection->select('information_schema.COLUMNS', 'c')
        ->fields('c', ['DATA_TYPE', 'CHARACTER_MAXIMUM_LENGTH', 'IS_NULLABLE', 'COLUMN_TYPE'])
        ->condition('c.TABLE_SCHEMA', $database)
        ->condition('c.TABLE_NAME', $tableName)
        ->condition('c.COLUMN_NAME', $fieldName)
        ->execute()
        ->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('verifyFieldParameters', $e->getMessage());
      return ["Error checking $tableName.$fieldName: " . $e->getMessage()];
    }

    if (!$result) {
      return ["Could not retrieve column info for: $tableName.$fieldName"];
    }

    $errors     = [];
    $prefix     = "$tableName.$fieldName";
    $actualType = strtolower($result['DATA_TYPE']);
    $columnType = strtolower($result['COLUMN_TYPE']);

    $expectedType = $this->drupalTypeToMysql($spec);
    if ($actualType !== $expectedType) {
      $errors[] = "Type mismatch for $prefix: from database $expectedType, from schema $actualType";
    }

    $specType = $spec['type'] ?? '';
    if (in_array($specType, ['varchar', 'char']) && isset($spec['length'])) {
      $actualLength = (int) $result['CHARACTER_MAXIMUM_LENGTH'];
      if ($actualLength !== (int) $spec['length']) {
        $errors[] = "Length mismatch for $prefix: expected {$spec['length']}, got $actualLength";
      }
    }

    if (!empty($spec['unsigned']) && !str_contains($columnType, 'unsigned')) {
      $errors[] = "Expected unsigned for $prefix but field is signed";
    }

    if (!empty($spec['not null']) && $result['IS_NULLABLE'] !== 'NO') {
      $errors[] = "Expected NOT NULL for $prefix but field allows NULL";
    }

    return $errors;
  }

  /**
   * repairSchema
   *
   * Creates any missing tables and adds any missing fields found by verifySchema().
   * Returns an array of strings describing each action taken or any errors
   * encountered; an empty array means nothing needed repair.
   *
   * @return array
   */
  public function repairSchema(): array
  {
    Drupal::moduleHandler()->loadInclude('nlpservices', 'install');
    $schema = nlpservices_schema();
    $dbSchema = $this->connection->schema();
    $log = [];
    foreach ($schema as $tableName => $tableSpec) {
      if (!$dbSchema->tableExists($tableName)) {
        try {
          $dbSchema->createTable($tableName, $tableSpec);
          $log[] = "Created table: $tableName";
        } catch (Exception $e) {
          nlp_debug_msg('repairSchema', $e->getMessage());
          $log[] = "Error creating table $tableName: " . $e->getMessage();
        }
        continue;
      }
      foreach ($tableSpec['fields'] as $fieldName => $fieldSpec) {
        if (!$dbSchema->fieldExists($tableName, $fieldName)) {
          try {
            $dbSchema->addField($tableName, $fieldName, $fieldSpec);
            $log[] = "Added field: $tableName.$fieldName";
          } catch (Exception $e) {
            nlp_debug_msg('repairSchema', $e->getMessage());
            $log[] = "Error adding field $tableName.$fieldName: " . $e->getMessage();
          }
        }
      }
    }
    return $log;
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