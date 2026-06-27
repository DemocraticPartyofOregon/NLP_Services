<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpCrosstabCounts;
  use Drupal\Tests\UnitTestCase;

  class NlpCrosstabCountsTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCrosstab(): NlpCrosstabCounts {
      return new NlpCrosstabCounts($this->connectionMock);
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('addField')->willReturn('c_cd');
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function baseCount(): array {
      return [
        'cd'               => 1,
        'county'           => 'Denver',
        'party'            => 'D',
        'registeredVoters' => 100,
        'registeredVoted'  => 60,
      ];
    }

    /** Full valid header row 1 matching $crosstabCountVanHdr1. */
    private function validHdr1(): array {
      return [0 => 'CD', 1 => 'County', 2 => 'Party', 3 => 'Vote Return Date', 4 => 'Total People'];
    }

    /** Full valid header row 2 matching $crosstabCountVanHdr2. */
    private function validHdr2(): array {
      return [0 => 'Bal Ret'];
    }

    // -------------------------------------------------------------------------
    // decodeCrosstabsHeader
    // -------------------------------------------------------------------------

    public function testDecodeCrosstabsHeaderAllColumnsFoundReturnsOk(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($this->validHdr1(), $this->validHdr2());
      $this->assertTrue($result['ok']);
      $this->assertSame([], $result['err']);
    }

    public function testDecodeCrosstabsHeaderPositionsMappedCorrectly(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($this->validHdr1(), $this->validHdr2());
      $pos = $result['pos'];
      $this->assertSame(0, $pos['cd']);
      $this->assertSame(1, $pos['county']);
      $this->assertSame(2, $pos['party']);
      $this->assertSame(3, $pos['voteDate']);
      $this->assertSame(4, $pos['total']);
      $this->assertSame(0, $pos['balRet']);
    }

    public function testDecodeCrosstabsHeaderMissingHdr1ColumnReturnsError(): void {
      $hdr1 = [0 => 'CD', 1 => 'Party', 2 => 'Vote Return Date', 3 => 'Total People'];
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($hdr1, $this->validHdr2());
      $this->assertFalse($result['ok']);
      $this->assertNotEmpty($result['err']);
      $this->assertStringContainsString('County', $result['err'][0]);
    }

    public function testDecodeCrosstabsHeaderMissingHdr2ColumnReturnsError(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($this->validHdr1(), []);
      $this->assertFalse($result['ok']);
      $this->assertStringContainsString('Bal Ret', $result['err'][0]);
    }

    public function testDecodeCrosstabsHeaderStripsSpecialCharactersBeforeMatching(): void {
      $hdr1 = $this->validHdr1();
      $hdr1[3] = '"Vote Return Date"';  // quotes around the value
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($hdr1, $this->validHdr2());
      $this->assertTrue($result['ok']);
    }

    public function testDecodeCrosstabsHeaderBothHeadersEmptyAllErrorsReturned(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader([], []);
      $this->assertFalse($result['ok']);
      $this->assertCount(6, $result['err']);
    }

    // -------------------------------------------------------------------------
    // updateCrosstabCounts
    // -------------------------------------------------------------------------

    public function testUpdateCrosstabCountsReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $counts = ['cd' => 1, 'county' => 'Denver', 'party' => 'D', 'regVoters' => 100, 'regVoted' => 60];
      $this->assertTrue($this->buildCrosstab()->updateCrosstabCounts($counts));
    }

    public function testUpdateCrosstabCountsReturnsFalseOnException(): void {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $counts = ['cd' => 1, 'county' => 'Denver', 'party' => 'D', 'regVoters' => 100, 'regVoted' => 60];
      $this->assertFalse($this->buildCrosstab()->updateCrosstabCounts($counts));
    }

    // -------------------------------------------------------------------------
    // fetchCrosstabCounts
    // -------------------------------------------------------------------------

    public function testFetchCrosstabCountsSingleRowKeyedByCdCountyParty(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseCount(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->fetchCrosstabCounts();
      $this->assertSame(60, $result[1]['Denver']['D']['regVoted']);
      $this->assertSame(100, $result[1]['Denver']['D']['regVoters']);
    }

    public function testFetchCrosstabCountsMultipleRowsKeyedSeparately(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['county' => 'Boulder', 'party' => 'R', 'registeredVoters' => 50, 'registeredVoted' => 20]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->fetchCrosstabCounts();
      $this->assertArrayHasKey('Denver', $result[1]);
      $this->assertArrayHasKey('Boulder', $result[1]);
    }

    public function testFetchCrosstabCountsEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildCrosstab()->fetchCrosstabCounts());
    }

    public function testFetchCrosstabCountsExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCrosstab()->fetchCrosstabCounts());
    }

    // -------------------------------------------------------------------------
    // getCountsByCd
    // -------------------------------------------------------------------------

    public function testGetCountsByCdSingleRowReturnsPartyAggregate(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseCount(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCd(1);
      $this->assertSame(60, $result['D']['regVoted']);
      $this->assertSame(100, $result['D']['regVoters']);
    }

    public function testGetCountsByCdTwoRowsSamePartyAreSummed(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['county' => 'Boulder', 'registeredVoters' => 50, 'registeredVoted' => 20]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCd(1);
      $this->assertSame(80, $result['D']['regVoted']);
      $this->assertSame(150, $result['D']['regVoters']);
    }

    public function testGetCountsByCdTwoRowsDifferentPartiesKeyedSeparately(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['party' => 'R', 'registeredVoters' => 80, 'registeredVoted' => 30]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCd(1);
      $this->assertArrayHasKey('D', $result);
      $this->assertArrayHasKey('R', $result);
    }

    public function testGetCountsByCdEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildCrosstab()->getCountsByCd(1));
    }

    public function testGetCountsByCdExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCrosstab()->getCountsByCd(1));
    }

    // -------------------------------------------------------------------------
    // getCountsByCounty
    // -------------------------------------------------------------------------

    public function testGetCountsByCountySingleRowReturnsPartyAggregate(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseCount(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCounty('Denver');
      $this->assertSame(60, $result['D']['regVoted']);
      $this->assertSame(100, $result['D']['regVoters']);
    }

    public function testGetCountsByCountyTwoRowsSamePartyAreSummed(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['cd' => 2, 'registeredVoters' => 40, 'registeredVoted' => 10]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCounty('Denver');
      $this->assertSame(70, $result['D']['regVoted']);
      $this->assertSame(140, $result['D']['regVoters']);
    }

    public function testGetCountsByCountyEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildCrosstab()->getCountsByCounty('Denver'));
    }

    public function testGetCountsByCountyExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCrosstab()->getCountsByCounty('Denver'));
    }

    // -------------------------------------------------------------------------
    // getCds
    // -------------------------------------------------------------------------

    public function testGetCdsReturnsArrayOfCds(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(['cd' => 1], ['cd' => 3], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCds();
      $this->assertSame([1, 3], $result);
    }

    public function testGetCdsSkipsRowsWithCdZero(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(['cd' => 0], ['cd' => 2], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCds();
      $this->assertSame([2], $result);
    }

    public function testGetCdsEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildCrosstab()->getCds());
    }

    public function testGetCdsExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('addField')->willReturn('c_cd');
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildCrosstab()->getCds());
    }

  }

}
