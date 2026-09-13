<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Exception;

class DatabaseFunctions
{

  protected Connection $connection;

  public function __construct(
    $connection,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
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
    fputcsv($fh, $columnNames, escape: '');

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
      fputcsv($fh, $award, escape: '');
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
    $this->moduleHandler->loadInclude('nlpservices', 'install');
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
      array_push($errors, ...$this->verifyIndexes($tableName, $tableSpec));
    }
    return $errors;
  }

  /**
   * drupalTypeToMysql
   *
   * Maps a Drupal schema field spec to the expected MySQL DATA_TYPE string.
   *
   * @param array $spec
   *   The schema specification for the field.
   *
   * @return string
   *   The corresponding MySQL DATA_TYPE string.
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
   * physicalTableName
   *
   * Resolves a schema-declared table name to the physical table name
   * (including the connection's table prefix, if any) for use in
   * information_schema lookups, which aren't prefixed automatically the
   * way the query builder's {table} syntax is.
   * @param string $tableName
   *   The name of the table to resolve.
   *
   * @return string
   *   The physical table name including any connection prefix.
   */
  private function physicalTableName(string $tableName): string
  {
    return $this->connection->getPrefix() . $tableName;
  }

  /**
   * verifyFieldParameters
   * @param string $tableName
   *   The name of the table containing the field.
   * @param string $fieldName
   *   The name of the field to verify.
   * @param array $spec
   *   The schema specification for the field.
   *
   * @return array
   *   An array of error messages describing any mismatches found.
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
        ->condition('c.TABLE_NAME', $this->physicalTableName($tableName))
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
   * verifyIndexes
   *
   * Checks a table's primary key, unique keys, and indexes against its
   * schema spec. Returns error strings for any mismatches.
   *
   * @param string $tableName
   *   The name of the table to verify.
   * @param array $tableSpec
   *   The schema specification for the table.
   *
   * @return array
   *   An array of error messages describing any mismatches found.
   */
  private function verifyIndexes(string $tableName, array $tableSpec): array
  {
    $actual = $this->getIndexColumns($tableName);
    $errors = [];

    if (!empty($tableSpec['primary key'])) {
      array_push($errors, ...$this->compareIndex($tableName, 'PRIMARY', $tableSpec['primary key'], $actual, TRUE, 'primary key'));
    }

    foreach ($tableSpec['unique keys'] ?? [] as $keyName => $fields) {
      array_push($errors, ...$this->compareIndex($tableName, $keyName, $fields, $actual, TRUE, 'unique key'));
    }

    foreach ($tableSpec['indexes'] ?? [] as $indexName => $fields) {
      array_push($errors, ...$this->compareIndex($tableName, $indexName, $fields, $actual, FALSE, 'index'));
    }

    return $errors;
  }

  /**
   * getIndexColumns
   *
   * Fetches the live index/key structure for a table, keyed by index name.
   *
   * @param string $tableName
   *   The name of the table to inspect.
   * @return array
   *   An array of ['columns' => string[], 'unique' => bool] keyed by
   *   MySQL index name (the primary key is always named 'PRIMARY').
   */
  private function getIndexColumns(string $tableName): array
  {
    try {
      $options  = $this->connection->getConnectionOptions();
      $database = $options['database'];
      $result   = $this->connection->select('information_schema.STATISTICS', 's')
        ->fields('s', ['INDEX_NAME', 'COLUMN_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE'])
        ->condition('s.TABLE_SCHEMA', $database)
        ->condition('s.TABLE_NAME', $this->physicalTableName($tableName))
        ->orderBy('s.INDEX_NAME')
        ->orderBy('s.SEQ_IN_INDEX')
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getIndexColumns', $e->getMessage());
      return [];
    }

    $indexes = [];
    do {
      $row = $result->fetchAssoc();
      if (empty($row)) {break;}
      $indexes[$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
      $indexes[$row['INDEX_NAME']]['unique'] = ((int) $row['NON_UNIQUE']) === 0;
    } while (TRUE);

    return $indexes;
  }

  /**
   * compareIndex
   *
   * Compares one expected key/index against the live index structure.
   * Returns error strings for a missing key, mismatched column order, or
   * a uniqueness mismatch.
   * @param string $tableName
   *   The name of the table containing the index.
   * @param string $indexName
   *   The name of the index to compare.
   * @param array $fields
   *   The expected columns for the index.
   * @param array $actualIndexes
   *   The actual indexes for the table, as returned by getIndexColumns().
   * @param bool $expectUnique
   *   Whether the index is expected to be unique.
   * @param string $label
   *   A human-readable label for the index, e.g., 'primary key', 'unique key', or 'index'.
   *
   * @return array
   *   An array of error messages describing any mismatches found.
   */
  private function compareIndex(string $tableName, string $indexName, array $fields, array $actualIndexes,
                                bool $expectUnique, string $label): array
  {
    $prefix = "$tableName $label $indexName";

    if (!isset($actualIndexes[$indexName])) {
      return ["Missing $label: $prefix"];
    }

    $errors           = [];
    $expectedColumns  = array_map(fn ($field) => is_array($field) ? $field[0] : $field, $fields);
    $actualColumns    = $actualIndexes[$indexName]['columns'];

    if ($expectedColumns !== $actualColumns) {
      $errors[] = "Column mismatch for $prefix: expected [" . implode(', ', $expectedColumns) .
        "], got [" . implode(', ', $actualColumns) . "]";
    }

    if ($expectUnique && !$actualIndexes[$indexName]['unique']) {
      $errors[] = "Expected $prefix to be unique but it is not";
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
    $this->moduleHandler->loadInclude('nlpservices', 'install');
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
      array_push($log, ...$this->repairIndexes($dbSchema, $tableName, $tableSpec));
    }
    return $log;
  }

  /**
   * repairIndexes
   *
   * Creates any missing primary key/unique keys/indexes for a table and
   * recreates any that exist but whose columns or uniqueness don't match
   * the schema. Returns an array of strings describing each action taken
   * or any errors encountered.
   *
   * @param Schema $dbSchema
   * @param string $tableName
   * @param array $tableSpec
   * @return array
   */
  private function repairIndexes(Schema $dbSchema, string $tableName, array $tableSpec): array
  {
    $actual = $this->getIndexColumns($tableName);
    $log = [];

    if (!empty($tableSpec['primary key'])) {
      array_push($log, ...$this->repairIndex($dbSchema, $tableName, 'PRIMARY', $tableSpec['primary key'], $actual, TRUE, 'primary key', $tableSpec));
    }

    foreach ($tableSpec['unique keys'] ?? [] as $keyName => $fields) {
      array_push($log, ...$this->repairIndex($dbSchema, $tableName, $keyName, $fields, $actual, TRUE, 'unique key', $tableSpec));
    }

    foreach ($tableSpec['indexes'] ?? [] as $indexName => $fields) {
      array_push($log, ...$this->repairIndex($dbSchema, $tableName, $indexName, $fields, $actual, FALSE, 'index', $tableSpec));
    }

    return $log;
  }

  /**
   * repairIndex
   *
   * Adds one missing key/ index or drops and recreates one whose live
   * columns or uniqueness don't match the schema. Leaves a key/index that
   * already matches untouched.
   *
   * @param Schema $dbSchema
   * @param string $tableName
   * @param string $indexName
   * @param array $fields
   * @param array $actualIndexes
   * @param bool $expectUnique
   * @param string $label
   *   A human-readable label for the index, e.g., 'primary key', 'unique key', or 'index'.
   * @param array $tableSpec
   *   The full schema definition for the table, as provided to the repairIndexes method.
   * @return array
   *   An array of strings describing the action taken or any errors encountered.
   */
  private function repairIndex(Schema $dbSchema, string $tableName, string $indexName, array $fields,
                               array $actualIndexes, bool $expectUnique, string $label, array $tableSpec): array
  {
    $prefix          = "$tableName $label $indexName";
    $expectedColumns = array_map(fn ($field) => is_array($field) ? $field[0] : $field, $fields);
    $exists          = isset($actualIndexes[$indexName]);
    $matches         = $exists
      && $actualIndexes[$indexName]['columns'] === $expectedColumns
      && (!$expectUnique || $actualIndexes[$indexName]['unique']);

    if ($matches) {
      return [];
    }

    try {
      if ($exists) {
        if ($indexName === 'PRIMARY') {
          $dbSchema->dropPrimaryKey($tableName);
        } else {
          $dbSchema->dropIndex($tableName, $indexName);
        }
      }
      if ($indexName === 'PRIMARY') {
        $dbSchema->addPrimaryKey($tableName, $fields);
      } elseif ($expectUnique) {
        $dbSchema->addUniqueKey($tableName, $indexName, $fields);
      } else {
        $dbSchema->addIndex($tableName, $indexName, $fields, $tableSpec);
      }
    } catch (Exception $e) {
      nlp_debug_msg('repairIndexes', $e->getMessage());
      return ["Error repairing $label $prefix: " . $e->getMessage()];
    }

    return [$exists ? "Repaired $label: $prefix" : "Added $label: $prefix"];
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
