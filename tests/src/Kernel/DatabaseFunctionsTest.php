<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\DatabaseFunctions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests DatabaseFunctions against a real MySQL/MariaDB schema.
 *
 * verifySchema(), repairSchema() and getColumnNames() run raw MySQL-only SQL
 * (SHOW COLUMNS, information_schema.COLUMNS) that a mocked Connection can
 * never validate - a mock happily returns whatever it's told regardless of
 * whether the real query would parse, let alone whether drupalTypeToMysql()'s
 * type-mapping actually matches what MySQL reports back. This test suite
 * therefore requires SIMPLETEST_DB to point at a real MySQL/MariaDB
 * connection (Drupal's KernelTestBase default of SQLite doesn't understand
 * this class's raw SQL) - see CLAUDE.md for the exact command.
 */
#[CoversClass(DatabaseFunctions::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class DatabaseFunctionsTest extends KernelTestBase {

  protected static $modules = ['user', 'file', 'nlpservices'];

  private DatabaseFunctions $databaseFunctions;
  private Connection $connection;

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('nlpservices', 'install');
    $allTables = array_keys(nlpservices_schema());
    $this->installSchema('nlpservices', $allTables);
    $this->databaseFunctions = $this->container->get('nlpservices.database_functions');
    $this->connection = $this->container->get('database');
  }

  /**
   * The real payoff test: installs every table nlpservices_schema() declares
   * and confirms drupalTypeToMysql()'s type mapping matches what MySQL
   * actually reports for every field type/size combination in use (serial,
   * int at every size, varchar+length, char, mysql_type=>date, unsigned,
   * not null) across all 23 tables in one pass.
   */
  public function testVerifySchemaAgainstInstalledSchemaReturnsNoErrors(): void {
    $errors = $this->databaseFunctions->verifySchema();
    $this->assertSame([], $errors);
  }

  public function testRepairSchemaCreatesMissingTableAndAddsMissingField(): void {
    $schema = $this->connection->schema();
    $schema->dropField('nlp_documents', 'description');
    $schema->dropTable('nlp_awards');

    $this->assertFalse($schema->fieldExists('nlp_documents', 'description'));
    $this->assertFalse($schema->tableExists('nlp_awards'));

    $log = $this->databaseFunctions->repairSchema();

    $this->assertTrue($schema->fieldExists('nlp_documents', 'description'));
    $this->assertTrue($schema->tableExists('nlp_awards'));
    $this->assertContains('Added field: nlp_documents.description', $log);
    $this->assertContains('Created table: nlp_awards', $log);

    // The repair should leave the schema fully matching again.
    $this->assertSame([], $this->databaseFunctions->verifySchema());
  }

  public function testRepairSchemaFixesMissingAndMismatchedIndexes(): void {
    $schema = $this->connection->schema();

    // Remove the primary key and the secondary index entirely.
    $schema->dropPrimaryKey('nlp_ballot_count');
    $schema->dropIndex('nlp_ballot_count', 'county');

    // Recreate the secondary index with the wrong columns, so repair has
    // to drop and recreate it rather than just add it.
    $schema->addIndex('nlp_ballot_count', 'county', ['county'], nlpservices_schema()['nlp_ballot_count']);

    $this->assertNotSame([], $this->databaseFunctions->verifySchema());

    $log = $this->databaseFunctions->repairSchema();

    $this->assertContains('Added primary key: nlp_ballot_count primary key PRIMARY', $log);
    $this->assertContains('Repaired index: nlp_ballot_count index county', $log);
    $this->assertSame([], $this->databaseFunctions->verifySchema());
  }

  public function testMergeRecordInsertsThenUpdatesRealRow(): void {
    $inserted = $this->databaseFunctions->mergeRecord(
      'nlp_documents',
      ['name' => 'testdoc'],
      ['name' => 'testdoc', 'weight' => 1, 'createDate' => '2026-01-01'],
    );
    $this->assertTrue($inserted);

    $row = $this->connection->select('nlp_documents', 'd')
      ->fields('d', ['weight'])
      ->condition('name', 'testdoc')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('1', (string) $row['weight']);

    $updated = $this->databaseFunctions->mergeRecord(
      'nlp_documents',
      ['name' => 'testdoc'],
      ['name' => 'testdoc', 'weight' => 2, 'createDate' => '2026-01-01'],
    );
    $this->assertTrue($updated);

    $count = (int) $this->connection->select('nlp_documents', 'd')
      ->condition('name', 'testdoc')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame(1, $count, 'Merge must update the existing row, not insert a duplicate.');

    $row = $this->connection->select('nlp_documents', 'd')
      ->fields('d', ['weight'])
      ->condition('name', 'testdoc')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('2', (string) $row['weight']);
  }

  public function testGetColumnNamesReturnsRealColumnsInOrder(): void {
    $columns = $this->databaseFunctions->getColumnNames('nlp_documents');
    $this->assertSame(
      ['name', 'weight', 'pdfFileName', 'docFileName', 'description', 'createDate', 'missing'],
      $columns,
    );
  }

}
