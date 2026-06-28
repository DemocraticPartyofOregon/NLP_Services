<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Schema;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpTargetVoterCrosstabCounts;
  use Drupal\Tests\UnitTestCase;

  class NlpTargetVoterCrosstabCountsTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildCrosstab(): NlpTargetVoterCrosstabCounts {
      return new NlpTargetVoterCrosstabCounts($this->connectionMock);
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
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
        'cd'            => 1,
        'county'        => 'Denver',
        'party'         => 'D',
        'totalPeople'   => 100,
        'accepted'      => 60,
        'rejected'      => 5,
        'sent'          => 20,
        'undeliverable' => 3,
        'void'          => 2,
        'unknown'       => 10,
      ];
    }

    /** Valid header row 1 matching $targetVoterVanHdr1. */
    private function validHdr1(): array {
      return [0 => 'US Congressional District', 1 => 'County', 2 => 'Likely Party', 3 => 'Total People'];
    }

    /** Valid header row 2 matching $targetVoterVanHdr2. */
    private function validHdr2(): array {
      return [0 => 'Accepted', 1 => 'Rejected', 2 => 'Sent', 3 => 'Undeliverable', 4 => 'Void', 5 => 'Unknown'];
    }

    private function buildSchemaMock(bool $tableExists, bool $allColumnsExist): Schema {
      $schemaMock = $this->createMock(Schema::class);
      $schemaMock->method('tableExists')->willReturn($tableExists);
      $schemaMock->method('fieldExists')->willReturn($allColumnsExist);
      return $schemaMock;
    }

    // -------------------------------------------------------------------------
    // verifyTable
    // -------------------------------------------------------------------------

    public function testVerifyTableReturnsTrueWhenTableAndColumnsExist(): void {
      $this->connectionMock->method('schema')->willReturn($this->buildSchemaMock(TRUE, TRUE));
      $result = $this->buildCrosstab()->verifyTable(NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL);
      $this->assertTrue($result['ok']);
      $this->assertSame([], $result['err']);
    }

    public function testVerifyTableReturnsFalseWhenTableMissing(): void {
      $this->connectionMock->method('schema')->willReturn($this->buildSchemaMock(FALSE, TRUE));
      $result = $this->buildCrosstab()->verifyTable('missing_table');
      $this->assertFalse($result['ok']);
      $this->assertStringContainsString('missing_table', $result['err'][0]);
    }

    public function testVerifyTableReturnsFalseWhenColumnMissing(): void {
      $schemaMock = $this->createMock(Schema::class);
      $schemaMock->method('tableExists')->willReturn(TRUE);
      $schemaMock->method('fieldExists')->willReturnCallback(
        function(string $table, string $column): bool {
          return $column !== 'accepted';
        }
      );
      $this->connectionMock->method('schema')->willReturn($schemaMock);
      $result = $this->buildCrosstab()->verifyTable(NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL);
      $this->assertFalse($result['ok']);
      $this->assertStringContainsString('accepted', $result['err'][0]);
    }

    public function testVerifyTableReportsAllMissingColumns(): void {
      $schemaMock = $this->createMock(Schema::class);
      $schemaMock->method('tableExists')->willReturn(TRUE);
      $schemaMock->method('fieldExists')->willReturn(FALSE);
      $this->connectionMock->method('schema')->willReturn($schemaMock);
      $result = $this->buildCrosstab()->verifyTable(NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL);
      $this->assertFalse($result['ok']);
      $this->assertCount(10, $result['err']);
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
      $this->assertSame(3, $pos['total']);
      $this->assertSame(0, $pos['accepted']);
      $this->assertSame(1, $pos['rejected']);
      $this->assertSame(2, $pos['sent']);
      $this->assertSame(3, $pos['undeliverable']);
      $this->assertSame(4, $pos['void']);
      $this->assertSame(5, $pos['unknown']);
    }

    public function testDecodeCrosstabsHeaderMissingHdr1ColumnReturnsError(): void {
      $hdr1 = [0 => 'County', 1 => 'Likely Party', 2 => 'Total People'];
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($hdr1, $this->validHdr2());
      $this->assertFalse($result['ok']);
      $this->assertStringContainsString('US Congressional District', $result['err'][0]);
    }

    public function testDecodeCrosstabsHeaderMissingHdr2ColumnReturnsError(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($this->validHdr1(), []);
      $this->assertFalse($result['ok']);
      $this->assertCount(6, $result['err']);
    }

    public function testDecodeCrosstabsHeaderStripsSpecialCharactersBeforeMatching(): void {
      $hdr1 = $this->validHdr1();
      $hdr1[0] = '"US Congressional District"';
      $result = $this->buildCrosstab()->decodeCrosstabsHeader($hdr1, $this->validHdr2());
      $this->assertTrue($result['ok']);
    }

    public function testDecodeCrosstabsHeaderBothEmptyReturnsAllErrors(): void {
      $result = $this->buildCrosstab()->decodeCrosstabsHeader([], []);
      $this->assertFalse($result['ok']);
      $this->assertCount(10, $result['err']);
    }

    // -------------------------------------------------------------------------
    // updateCrosstabCounts
    // -------------------------------------------------------------------------

    public function testUpdateCrosstabCountsReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $counts = [
        'cd' => 1, 'county' => 'Denver', 'party' => 'D',
        'total' => 100, 'accepted' => 60, 'rejected' => 5,
        'sent' => 20, 'undeliverable' => 3, 'void' => 2, 'unknown' => 10,
      ];
      $this->assertTrue($this->buildCrosstab()->updateCrosstabCounts($counts));
    }

    public function testUpdateCrosstabCountsReturnsFalseOnException(): void {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $counts = [
        'cd' => 1, 'county' => 'Denver', 'party' => 'D',
        'total' => 100, 'accepted' => 60, 'rejected' => 5,
        'sent' => 20, 'undeliverable' => 3, 'void' => 2, 'unknown' => 10,
      ];
      $this->assertFalse($this->buildCrosstab()->updateCrosstabCounts($counts));
    }

    public function testUpdateCrosstabCountsUsesAllVoterTableWhenSpecified(): void {
      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL)
        ->willReturn($this->buildMergeMock());
      $counts = [
        'cd' => 1, 'county' => 'Denver', 'party' => 'D',
        'total' => 100, 'accepted' => 60, 'rejected' => 5,
        'sent' => 20, 'undeliverable' => 3, 'void' => 2, 'unknown' => 10,
      ];
      $this->assertTrue($this->buildCrosstab()->updateCrosstabCounts($counts, NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL));
    }

    // -------------------------------------------------------------------------
    // fetchCrosstabCounts
    // -------------------------------------------------------------------------

    public function testFetchCrosstabCountsSingleRowKeyedByCdCountyParty(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseCount(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->fetchCrosstabCounts();
      $this->assertSame(100, $result[1]['Denver']['D']['total']);
      $this->assertSame(60,  $result[1]['Denver']['D']['accepted']);
      $this->assertSame(5,   $result[1]['Denver']['D']['rejected']);
      $this->assertSame(20,  $result[1]['Denver']['D']['sent']);
      $this->assertSame(3,   $result[1]['Denver']['D']['undeliverable']);
      $this->assertSame(2,   $result[1]['Denver']['D']['void']);
      $this->assertSame(10,  $result[1]['Denver']['D']['unknown']);
    }

    public function testFetchCrosstabCountsMultipleRowsKeyedSeparately(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['county' => 'Boulder', 'party' => 'R']);
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

    public function testFetchCrosstabCountsUsesAllVoterTableWhenSpecified(): void {
      $this->connectionMock->expects($this->once())
        ->method('select')
        ->with(NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL, 'c')
        ->willReturn($this->buildSelectMock($this->createMock(StatementInterface::class)));
      $this->createMock(StatementInterface::class)->method('fetchAssoc')->willReturn(false);
      $this->buildCrosstab()->fetchCrosstabCounts(NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL);
    }

    // -------------------------------------------------------------------------
    // getCountsByCd
    // -------------------------------------------------------------------------

    public function testGetCountsByCdSingleRowReturnsPartyAggregate(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseCount(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCd(1);
      $this->assertSame(100, $result['D']['total']);
      $this->assertSame(60,  $result['D']['accepted']);
    }

    public function testGetCountsByCdTwoRowsSamePartyAreSummed(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['county' => 'Boulder', 'totalPeople' => 50, 'accepted' => 30]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCd(1);
      $this->assertSame(150, $result['D']['total']);
      $this->assertSame(90,  $result['D']['accepted']);
    }

    public function testGetCountsByCdTwoRowsDifferentPartiesKeyedSeparately(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['party' => 'R']);
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
      $this->assertSame(100, $result['D']['total']);
      $this->assertSame(60,  $result['D']['accepted']);
    }

    public function testGetCountsByCountyTwoRowsSamePartyAreSummed(): void {
      $row1 = $this->baseCount();
      $row2 = array_merge($this->baseCount(), ['cd' => 2, 'totalPeople' => 40, 'sent' => 10]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildCrosstab()->getCountsByCounty('Denver');
      $this->assertSame(140, $result['D']['total']);
      $this->assertSame(30,  $result['D']['sent']);
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

  }

}
