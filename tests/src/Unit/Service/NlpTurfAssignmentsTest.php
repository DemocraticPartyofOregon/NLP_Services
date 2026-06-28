<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\Tests\UnitTestCase;

  class NlpTurfAssignmentsTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpTurfAssignments {
      return new NlpTurfAssignments($this->connectionMock);
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
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

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    private function baseAssignment(): array {
      return [
        'turfId'   => 10,
        'mcid'     => 42,
        'folderId' => 7,
        'turfName' => 'Turf A',
      ];
    }

    // -------------------------------------------------------------------------
    // setTurfAssignment
    // -------------------------------------------------------------------------

    public function testSetTurfAssignmentMergesWithTurfIdAsKey(): void {
      $captured = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')->willReturnCallback(
        function (array $keys) use (&$captured, $mergeMock): Merge {
          $captured['keys'] = $keys;
          return $mergeMock;
        }
      );
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured['fields'] = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->setTurfAssignment($this->baseAssignment());

      $this->assertSame(['turfId' => 10], $captured['keys']);
      $this->assertArrayNotHasKey('turfId', $captured['fields']);
      $this->assertSame(42, $captured['fields']['mcid']);
    }

    public function testSetTurfAssignmentDoesNotThrowOnDbException(): void {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      // Should complete without throwing.
      $this->buildService()->setTurfAssignment($this->baseAssignment());
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getTurfAssignment
    // -------------------------------------------------------------------------

    public function testGetTurfAssignmentReturnsRowWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseAssignment());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getTurfAssignment(10);

      $this->assertSame(10, $result['turfId']);
      $this->assertSame(42, $result['mcid']);
    }

    public function testGetTurfAssignmentReturnsEmptyArrayWhenNotFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getTurfAssignment(99));
    }

    public function testGetTurfAssignmentReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getTurfAssignment(10));
    }

    // -------------------------------------------------------------------------
    // getTurfAssignmentsById
    // -------------------------------------------------------------------------

    public function testGetTurfAssignmentsByIdReturnsSingleRow(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseAssignment(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getTurfAssignmentsById(42);

      $this->assertCount(1, $result);
      $this->assertSame(10, $result[0]['turfId']);
    }

    public function testGetTurfAssignmentsByIdReturnsMultipleRows(): void {
      $row1 = $this->baseAssignment();
      $row2 = array_merge($this->baseAssignment(), ['turfId' => 11, 'turfName' => 'Turf B']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getTurfAssignmentsById(42);

      $this->assertCount(2, $result);
      $this->assertSame(11, $result[1]['turfId']);
    }

    public function testGetTurfAssignmentsByIdReturnsEmptyArrayWhenNoneFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getTurfAssignmentsById(99));
    }

    public function testGetTurfAssignmentsByIdReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getTurfAssignmentsById(42));
    }

    // -------------------------------------------------------------------------
    // getTurfAssignmentByTurfName
    // -------------------------------------------------------------------------

    public function testGetTurfAssignmentByTurfNameReturnsRowWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseAssignment());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getTurfAssignmentByTurfName(7, 'Turf A');

      $this->assertSame('Turf A', $result['turfName']);
      $this->assertSame(7, $result['folderId']);
    }

    public function testGetTurfAssignmentByTurfNameReturnsEmptyArrayWhenNotFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getTurfAssignmentByTurfName(7, 'Nonexistent'));
    }

    public function testGetTurfAssignmentByTurfNameReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getTurfAssignmentByTurfName(7, 'Turf A'));
    }

    // -------------------------------------------------------------------------
    // deleteTurfAssignment
    // -------------------------------------------------------------------------

    public function testDeleteTurfAssignmentExecutesDelete(): void {
      $this->connectionMock->expects($this->once())
        ->method('delete')
        ->with(NlpTurfAssignments::TURF_ASSIGNMENT_TBL)
        ->willReturn($this->buildDeleteMock());

      $this->buildService()->deleteTurfAssignment(10);
    }

    public function testDeleteTurfAssignmentDoesNotThrowOnException(): void {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('delete')->willReturn($deleteMock);

      $this->buildService()->deleteTurfAssignment(10);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // decodeTurfAssignmentHeader
    // -------------------------------------------------------------------------

    private function buildColumnStmt(array $columns): StatementInterface {
      $rows = array_map(fn(string $col) => ['Field' => $col], $columns);
      $rows[] = false;
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(...$rows);
      return $stmtMock;
    }

    public function testDecodeTurfAssignmentHeaderAllColumnsFoundReturnsOk(): void {
      $cols = ['turfId', 'mcid', 'folderId', 'turfName'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));
      $fileHdr = array_values($cols);

      $result = $this->buildService()->decodeTurfAssignmentHeader($fileHdr);

      $this->assertTrue($result['ok']);
      $this->assertSame([], $result['err']);
    }

    public function testDecodeTurfAssignmentHeaderPositionsMappedCorrectly(): void {
      $cols = ['turfId', 'mcid', 'folderId', 'turfName'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));
      $fileHdr = [0 => 'mcid', 1 => 'turfId', 2 => 'turfName', 3 => 'folderId'];

      $result = $this->buildService()->decodeTurfAssignmentHeader($fileHdr);

      $this->assertTrue($result['ok']);
      $this->assertSame(1, $result['pos']['turfId']);
      $this->assertSame(0, $result['pos']['mcid']);
      $this->assertSame(2, $result['pos']['turfName']);
      $this->assertSame(3, $result['pos']['folderId']);
    }

    public function testDecodeTurfAssignmentHeaderMissingColumnReturnsError(): void {
      $cols = ['turfId', 'mcid', 'folderId', 'turfName'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));
      $fileHdr = [0 => 'mcid', 1 => 'turfId', 2 => 'folderId'];

      $result = $this->buildService()->decodeTurfAssignmentHeader($fileHdr);

      $this->assertFalse($result['ok']);
      $this->assertStringContainsString('turfName', $result['err'][0]);
    }

    public function testDecodeTurfAssignmentHeaderStripsWhitespaceBeforeMatching(): void {
      $cols = ['turfId', 'mcid'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));
      $fileHdr = [0 => '  turfId  ', 1 => ' mcid '];

      $result = $this->buildService()->decodeTurfAssignmentHeader($fileHdr);

      $this->assertTrue($result['ok']);
    }

    public function testDecodeTurfAssignmentHeaderEmptyFileHdrAllErrors(): void {
      $cols = ['turfId', 'mcid', 'folderId', 'turfName'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));

      $result = $this->buildService()->decodeTurfAssignmentHeader([]);

      $this->assertFalse($result['ok']);
      $this->assertCount(4, $result['err']);
    }

    // -------------------------------------------------------------------------
    // getAssignmentColumnNames
    // -------------------------------------------------------------------------

    public function testGetAssignmentColumnNamesReturnsColumnNames(): void {
      $cols = ['turfId', 'mcid', 'folderId', 'turfName'];
      $this->connectionMock->method('query')->willReturn($this->buildColumnStmt($cols));

      $result = $this->buildService()->getAssignmentColumnNames();

      $this->assertSame($cols, $result);
    }

    public function testGetAssignmentColumnNamesReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('query')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getAssignmentColumnNames());
    }

    // -------------------------------------------------------------------------
    // fixTurfAssignment
    // -------------------------------------------------------------------------

    public function testFixTurfAssignmentDeletesOldAndInsertsNewTurfId(): void {
      $assignment = $this->baseAssignment();

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($assignment);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $capturedMergeKeys = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')->willReturnCallback(
        function (array $keys) use (&$capturedMergeKeys, $mergeMock): Merge {
          $capturedMergeKeys = $keys;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());

      $this->buildService()->fixTurfAssignment(10, 99);

      $this->assertSame(['turfId' => 99], $capturedMergeKeys);
    }

    public function testFixTurfAssignmentCallsDeleteBeforeMerge(): void {
      $assignment = $this->baseAssignment();

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($assignment);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $callOrder = [];

      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturnCallback(function() use (&$callOrder) {
        $callOrder[] = 'delete';
        return 1;
      });
      $this->connectionMock->method('delete')->willReturn($deleteMock);

      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('execute')->willReturnCallback(function() use (&$callOrder) {
        $callOrder[] = 'merge';
        return 1;
      });
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->fixTurfAssignment(10, 99);

      $this->assertSame(['delete', 'merge'], $callOrder);
    }

  }

}
