<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Extension\ModuleHandlerInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\Tests\UnitTestCase;

  class DatabaseFunctionsTest extends UnitTestCase {

    private Connection $connectionMock;
    private object $moduleHandlerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->moduleHandlerMock = $this->createMock(ModuleHandlerInterface::class);
    }

    private function buildDatabaseFunctions(): DatabaseFunctions {
      return new DatabaseFunctions($this->connectionMock, $this->moduleHandlerMock);
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    // -------------------------------------------------------------------------
    // getColumnNames
    // -------------------------------------------------------------------------

    public function testGetColumnNamesReturnsColumnArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['Field' => 'id'],
        ['Field' => 'name'],
        [],
      );
      $this->connectionMock->method('query')->willReturn($stmtMock);
      $result = $this->buildDatabaseFunctions()->getColumnNames('test_table');
      $this->assertSame(['id', 'name'], $result);
    }

    public function testGetColumnNamesEmptyTableReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([]);
      $this->connectionMock->method('query')->willReturn($stmtMock);
      $this->assertSame([], $this->buildDatabaseFunctions()->getColumnNames('test_table'));
    }

    public function testGetColumnNamesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('query')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildDatabaseFunctions()->getColumnNames('test_table'));
    }

    // -------------------------------------------------------------------------
    // exportTable
    // -------------------------------------------------------------------------

    public function testExportTableWritesHeaderAndRowsToFile(): void {
      $row = ['id' => '1', 'name' => 'Alice'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $tempFile = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $result = $this->buildDatabaseFunctions()->exportTable(
        'test_table', ['id', 'name'], $tempFile, '', false
      );
      $csv = file_get_contents($tempFile);
      unlink($tempFile);

      $this->assertTrue($result);
      $this->assertStringContainsString('id', $csv);
      $this->assertStringContainsString('Alice', $csv);
    }

    public function testExportTableWithCountyAppliesCondition(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->expects($this->once())->method('condition')->with('county', 'Denver')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      $this->connectionMock->method('select')->willReturn($selectMock);

      $tempFile = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $this->buildDatabaseFunctions()->exportTable('test_table', [], $tempFile, 'Denver', false);
      unlink($tempFile);
    }

    public function testExportTableWithSortAppliesOrderBy(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->expects($this->once())->method('orderBy')->with('county')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      $this->connectionMock->method('select')->willReturn($selectMock);

      $tempFile = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $this->buildDatabaseFunctions()->exportTable('test_table', [], $tempFile, '', true);
      unlink($tempFile);
    }

    public function testExportTableExceptionReturnsFalse(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $tempFile = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $result = $this->buildDatabaseFunctions()->exportTable('test_table', [], $tempFile, '', false);
      unlink($tempFile);

      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // mergeRecord
    // -------------------------------------------------------------------------

    public function testMergeRecordReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->assertTrue(
        $this->buildDatabaseFunctions()->mergeRecord('test_table', ['id' => 1], ['name' => 'Alice'])
      );
    }

    public function testMergeRecordCallsMergeWithCorrectTable(): void {
      $mergeMock = $this->buildMergeMock();
      $this->connectionMock->expects($this->once())->method('merge')->with('test_table')->willReturn($mergeMock);
      $this->buildDatabaseFunctions()->mergeRecord('test_table', ['id' => 1], ['name' => 'Alice']);
    }

    public function testMergeRecordReturnsFalseOnException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->assertFalse(
        $this->buildDatabaseFunctions()->mergeRecord('test_table', ['id' => 1], ['name' => 'Alice'])
      );
    }

    // -------------------------------------------------------------------------
    // getColumnSize
    // -------------------------------------------------------------------------

    public function testGetColumnSizeVarcharReturnsSize(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['COLUMN_NAME' => 'name', 'COLUMN_TYPE' => 'varchar(128)']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(128, $this->buildDatabaseFunctions()->getColumnSize('test_table', 'name'));
    }

    public function testGetColumnSizeLargeVarcharReturnsCorrectSize(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['COLUMN_NAME' => 'body', 'COLUMN_TYPE' => 'varchar(65535)']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(65535, $this->buildDatabaseFunctions()->getColumnSize('test_table', 'body'));
    }

    public function testGetColumnSizeNonVarcharReturnsZero(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int(11)']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(0, $this->buildDatabaseFunctions()->getColumnSize('test_table', 'id'));
    }

    public function testGetColumnSizeNotFoundReturnsZero(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(0, $this->buildDatabaseFunctions()->getColumnSize('test_table', 'missing'));
    }

    public function testGetColumnSizeExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildDatabaseFunctions()->getColumnSize('test_table', 'name'));
    }

  }

}
