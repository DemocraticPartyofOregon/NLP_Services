<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\Tests\UnitTestCase;

  class NlpCoordinatorsTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCoordinators(): NlpCoordinators {
      return new NlpCoordinators($this->connectionMock);
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function buildInsertMock(): Insert {
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('execute')->willReturn('1');
      return $insertMock;
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    private function baseCoordinator(): array {
      return [
        'cIndex'       => 5,
        'county'       => 'Denver',
        'hd'           => 6,
        'scope'        => 'county',
        'precinctList' => '',
        'mcid'         => 42,
      ];
    }

    // -------------------------------------------------------------------------
    // createCoordinator
    // -------------------------------------------------------------------------

    public function testCreateCoordinatorCallsInsert(): void {
      $insertMock = $this->buildInsertMock();
      $insertMock->expects($this->once())->method('execute')->willReturn('1');
      $this->connectionMock->method('insert')->willReturn($insertMock);
      $this->buildCoordinators()->createCoordinator(['county' => 'Denver', 'hd' => 6]);
      $this->assertTrue(TRUE);
    }

    public function testCreateCoordinatorExceptionHandledGracefully(): void {
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));
      $this->buildCoordinators()->createCoordinator(['county' => 'Denver']);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getCoordinators
    // -------------------------------------------------------------------------

    public function testGetCoordinatorsReturnsCoordinatorsKeyedByCIndex(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getCoordinators('Denver');
      $this->assertArrayHasKey(5, $result);
      $this->assertSame('Denver', $result[5]['county']);
    }

    public function testGetCoordinatorsEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildCoordinators()->getCoordinators('Denver'));
    }

    public function testGetCoordinatorsExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCoordinators()->getCoordinators('Denver'));
    }

    // -------------------------------------------------------------------------
    // deleteCoordinator
    // -------------------------------------------------------------------------

    public function testDeleteCoordinatorCallsDelete(): void {
      $deleteMock = $this->buildDeleteMock();
      $deleteMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('delete')->willReturn($deleteMock);
      $this->buildCoordinators()->deleteCoordinator(5);
      $this->assertTrue(TRUE);
    }

    public function testDeleteCoordinatorExceptionHandledGracefully(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->buildCoordinators()->deleteCoordinator(5);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // duplicateScope
    // -------------------------------------------------------------------------

    public function testDuplicateScopeCountyExistsReturnsTrue(): void {
      $countyCoordinators = ['county' => ['cIndex' => 5]];
      $this->assertTrue($this->buildCoordinators()->duplicateScope('county', '', $countyCoordinators));
    }

    public function testDuplicateScopeCountyMissingReturnsFalse(): void {
      $this->assertFalse($this->buildCoordinators()->duplicateScope('county', '', []));
    }

    public function testDuplicateScopeHdExistsReturnsTrue(): void {
      $countyCoordinators = ['hd' => [6 => ['cIndex' => 5]]];
      $this->assertTrue($this->buildCoordinators()->duplicateScope('hd', '6', $countyCoordinators));
    }

    public function testDuplicateScopeHdMissingReturnsFalse(): void {
      $this->assertFalse($this->buildCoordinators()->duplicateScope('hd', '6', []));
    }

    public function testDuplicateScopePrecinctExistsReturnsTrue(): void {
      $countyCoordinators = ['pct' => ['6.1' => ['cIndex' => 5]]];
      $this->assertTrue($this->buildCoordinators()->duplicateScope('precinct', '6.1', $countyCoordinators));
    }

    public function testDuplicateScopePrecinctMissingReturnsFalse(): void {
      $this->assertFalse($this->buildCoordinators()->duplicateScope('precinct', '6.1', []));
    }

    // -------------------------------------------------------------------------
    // getAllCoordinators
    // -------------------------------------------------------------------------

    public function testGetAllCoordinatorsCountyScopeOrganizesUnderCountyKey(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAllCoordinators('Denver');
      $this->assertArrayHasKey('Denver', $result);
      $this->assertArrayHasKey('county', $result['Denver']);
    }

    public function testGetAllCoordinatorsHdScopeOrganizesUnderHdKey(): void {
      $coordinator = array_merge($this->baseCoordinator(), ['scope' => 'hd', 'hd' => 6]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAllCoordinators('Denver');
      $this->assertArrayHasKey('hd', $result['Denver']);
      $this->assertArrayHasKey(6, $result['Denver']['hd']);
    }

    public function testGetAllCoordinatorsPrecinctScopeOrganizesUnderPctKey(): void {
      $coordinator = array_merge($this->baseCoordinator(), [
        'scope'        => 'precinct',
        'precinctList' => '6.1,6.2',
      ]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAllCoordinators('Denver');
      $this->assertArrayHasKey('pct', $result['Denver']);
      $this->assertArrayHasKey('6.1', $result['Denver']['pct']);
      $this->assertArrayHasKey('6.2', $result['Denver']['pct']);
    }

    public function testGetAllCoordinatorsFirstEntryWinsForDuplicatePrecinct(): void {
      $first  = array_merge($this->baseCoordinator(), ['cIndex' => 5, 'scope' => 'precinct', 'precinctList' => '6.1']);
      $second = array_merge($this->baseCoordinator(), ['cIndex' => 9, 'scope' => 'precinct', 'precinctList' => '6.1']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($first, $second, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAllCoordinators('Denver');
      $this->assertSame(5, $result['Denver']['pct']['6.1']['cIndex']);
    }

    public function testGetAllCoordinatorsNoCounyFilterReturnsAll(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->expects($this->never())->method('condition');
      $selectMock->method('execute')->willReturn($stmtMock);
      $this->connectionMock->method('select')->willReturn($selectMock);
      $result = $this->buildCoordinators()->getAllCoordinators();
      $this->assertArrayHasKey('Denver', $result);
    }

    public function testGetAllCoordinatorsExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCoordinators()->getAllCoordinators('Denver'));
    }

    // -------------------------------------------------------------------------
    // getAssignedCoordinator
    // -------------------------------------------------------------------------

    public function testGetAssignedCoordinatorMatchesByPrecinct(): void {
      $coordinator = array_merge($this->baseCoordinator(), ['scope' => 'precinct', 'precinctList' => '6.1']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAssignedCoordinator([
        'county' => 'Denver', 'hd' => 6, 'precinct' => '6.1',
      ]);
      $this->assertSame(5, $result['cIndex']);
    }

    public function testGetAssignedCoordinatorFallsBackToHd(): void {
      $coordinator = array_merge($this->baseCoordinator(), ['scope' => 'hd', 'hd' => 6]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAssignedCoordinator([
        'county' => 'Denver', 'hd' => 6, 'precinct' => '9.9',
      ]);
      $this->assertSame(5, $result['cIndex']);
    }

    public function testGetAssignedCoordinatorFallsBackToCounty(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAssignedCoordinator([
        'county' => 'Denver', 'hd' => 99, 'precinct' => '9.9',
      ]);
      $this->assertSame(5, $result['cIndex']);
    }

    public function testGetAssignedCoordinatorNoMatchReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getAssignedCoordinator([
        'county' => 'Denver', 'hd' => 6, 'precinct' => '6.1',
      ]);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // getCoordinator
    // -------------------------------------------------------------------------

    public function testGetCoordinatorRemapsRegionKeysAndReturnsCoordinator(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getCoordinator([
        'county' => 'Denver', 'hd' => 6, 'pct' => '6.1',
      ]);
      $this->assertSame(5, $result['cIndex']);
    }

    // -------------------------------------------------------------------------
    // getCoordinatorById
    // -------------------------------------------------------------------------

    public function testGetCoordinatorByIdReturnsCoordinatorsKeyedByCIndex(): void {
      $coordinator = $this->baseCoordinator();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getCoordinatorById(42);
      $this->assertArrayHasKey(5, $result);
      $this->assertSame(42, $result[5]['mcid']);
    }

    public function testGetCoordinatorByIdSkipsRowsWithEmptyMcid(): void {
      $coordinator = array_merge($this->baseCoordinator(), ['mcid' => 0]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($coordinator, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCoordinators()->getCoordinatorById(0);
      $this->assertSame([], $result);
    }

    public function testGetCoordinatorByIdExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCoordinators()->getCoordinatorById(42));
    }

  }

}
