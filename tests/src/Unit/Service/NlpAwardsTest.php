<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpNls;
  use Drupal\Tests\UnitTestCase;

  class NlpAwardsTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpConfiguration $configurationMock;
    private NlpNls $nlsMock;
    private DatabaseFunctions $dbFunctionsMock;

    private array $defaultColumns = [
      'mcid', 'nickname', 'lastName', 'electionCount',
      'participation', 'preferredContactMethod', 'notes',
    ];

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock   = $this->createMock(Connection::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->nlsMock          = $this->createMock(NlpNls::class);
      $this->dbFunctionsMock  = $this->createMock(DatabaseFunctions::class);
      $this->dbFunctionsMock->method('getColumnNames')->willReturn($this->defaultColumns);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAwards(): NlpAwards {
      return new NlpAwards(
        $this->connectionMock,
        $this->configurationMock,
        $this->nlsMock,
        $this->dbFunctionsMock,
      );
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

    private function baseAwardRow(int $mcid = 42, int $electionCount = 2): array {
      return [
        'mcid'                   => $mcid,
        'nickname'               => 'Alice',
        'lastName'               => 'Smith',
        'electionCount'          => $electionCount,
        'participation'          => json_encode(['2022' => 'Completed', '2024' => 'Completed']),
        'preferredContactMethod' => 'email',
        'notes'                  => '',
      ];
    }

    // -------------------------------------------------------------------------
    // mergeAward
    // -------------------------------------------------------------------------

    public function testMergeAwardReturnsFalseWhenMcidEmpty(): void {
      $this->connectionMock->expects($this->never())->method('merge');
      $this->assertFalse($this->buildAwards()->mergeAward(['mcid' => 0, 'participation' => []]));
    }

    public function testMergeAwardEncodesParticipationAsJson(): void {
      $captured = NULL;
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $award = ['mcid' => 42, 'participation' => ['2024' => 'Completed']];
      $this->buildAwards()->mergeAward($award);
      $this->assertSame(json_encode(['2024' => 'Completed']), $captured['participation']);
    }

    public function testMergeAwardReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $award = ['mcid' => 42, 'participation' => []];
      $this->assertTrue($this->buildAwards()->mergeAward($award));
    }

    public function testMergeAwardReturnsFalseOnException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildAwards()->mergeAward(['mcid' => 42, 'participation' => []]));
    }

    // -------------------------------------------------------------------------
    // fetchAwardRecord
    // -------------------------------------------------------------------------

    public function testFetchAwardRecordReturnsRowWhenFound(): void {
      $row = $this->baseAwardRow();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->fetchAwardRecord(42);
      $this->assertSame(42, $result['mcid']);
      $this->assertSame('Alice', $result['nickname']);
    }

    public function testFetchAwardRecordReturnsNewRecordWhenNotFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Bob', 'lastName' => 'Jones']);
      $this->nlsMock->method('getNlsStatus')->willReturn(['preferredContactMethod' => 'phone']);
      $result = $this->buildAwards()->fetchAwardRecord(99);
      $this->assertSame(99, $result['mcid']);
      $this->assertSame(0, $result['electionCount']);
      $this->assertSame('Bob', $result['nickname']);
    }

    public function testFetchAwardRecordReturnsNewRecordOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->nlsMock->method('getNlById')->willReturn([]);
      $result = $this->buildAwards()->fetchAwardRecord(42);
      $this->assertSame(42, $result['mcid']);
      $this->assertSame(0, $result['electionCount']);
    }

    // -------------------------------------------------------------------------
    // getAward
    // -------------------------------------------------------------------------

    public function testGetAwardDecodesParticipationJson(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->getAward(42);
      $this->assertIsArray($result['participation']);
      $this->assertSame('Completed', $result['participation']['2022']);
    }

    public function testGetAwardCountsLegacyBooleanTrueAsCompleted(): void {
      // The if-condition uses == (loose), so true == 'Completed' is true in PHP;
      // the boolean stays as-is in the array but is counted toward electionCount.
      $row = $this->baseAwardRow(42, 0);
      $row['participation'] = json_encode(['2022' => true]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $result = $this->buildAwards()->getAward(42);
      $this->assertSame(1, $result['electionCount']);
    }

    public function testGetAwardElectionCountMatchesCompletedEntries(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->getAward(42);
      $this->assertSame(2, $result['electionCount']);
    }

    public function testGetAwardCallsMergeWhenElectionCountMismatched(): void {
      $row = $this->baseAwardRow(42, 99);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildAwards()->getAward(42);
    }

    public function testGetAwardDoesNotCallMergeWhenElectionCountCorrect(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->expects($this->never())->method('merge');
      $this->buildAwards()->getAward(42);
    }

    // -------------------------------------------------------------------------
    // nlParticipation
    // -------------------------------------------------------------------------

    public function testNlParticipationAddsProgressForNewCycle(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->configurationMock->method('getCycle')->willReturn('2026');
      $result = $this->buildAwards()->nlParticipation(42, 'In Progress');
      $this->assertSame('In Progress', $result['participation']['2026']);
    }

    public function testNlParticipationIncrementsElectionCountWhenCompleted(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->configurationMock->method('getCycle')->willReturn('2026');
      $result = $this->buildAwards()->nlParticipation(42, 'Completed');
      $this->assertSame(3, $result['electionCount']);
    }

    public function testNlParticipationDoesNotOverwriteCompleted(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->configurationMock->method('getCycle')->willReturn('2022');
      $this->connectionMock->expects($this->never())->method('merge');
      $this->buildAwards()->nlParticipation(42, 'In Progress');
    }

    public function testNlParticipationDoesNotIncrementCountForNonCompleted(): void {
      $row = $this->baseAwardRow(42, 2);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->configurationMock->method('getCycle')->willReturn('2026');
      $result = $this->buildAwards()->nlParticipation(42, 'In Progress');
      $this->assertSame(2, $result['electionCount']);
    }

    // -------------------------------------------------------------------------
    // nlParticipationDisplay
    // -------------------------------------------------------------------------

    public function testNlParticipationDisplayFormatsEntries(): void {
      $row = $this->baseAwardRow(42, 2);
      $row['participation'] = json_encode(['2022' => 'Completed', '2024' => 'Completed']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->nlParticipationDisplay(42);
      $this->assertStringContainsString('2022 Completed', $result);
      $this->assertStringContainsString('2024 Completed', $result);
      $this->assertStringContainsString('<br>', $result);
    }

    public function testNlParticipationDisplayConvertsBooleanTrueToCompleted(): void {
      $row = $this->baseAwardRow(42, 1);
      $row['participation'] = json_encode(['2022' => true]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->nlParticipationDisplay(42);
      $this->assertStringContainsString('2022 Completed', $result);
    }

    public function testNlParticipationDisplayReturnsEmptyStringWhenNoParticipation(): void {
      $row = $this->baseAwardRow(42, 0);
      $row['participation'] = NULL;
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame('', $this->buildAwards()->nlParticipationDisplay(42));
    }

    public function testNlParticipationDisplaySingleEntryHasNoBreak(): void {
      $row = $this->baseAwardRow(42, 1);
      $row['participation'] = json_encode(['2024' => 'Completed']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($row);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildAwards()->nlParticipationDisplay(42);
      $this->assertStringNotContainsString('<br>', $result);
    }

    // -------------------------------------------------------------------------
    // decodeAwardHeader
    // -------------------------------------------------------------------------

    public function testDecodeAwardHeaderAllColumnsFoundReturnsOkTrue(): void {
      $fileHeader = array_values($this->defaultColumns);
      $result = $this->buildAwards()->decodeAwardHeader($fileHeader);
      $this->assertTrue($result['ok']);
      $this->assertEmpty($result['err']);
    }

    public function testDecodeAwardHeaderReturnsCorrectPositions(): void {
      $fileHeader = ['mcid', 'nickname', 'lastName', 'electionCount',
        'participation', 'preferredContactMethod', 'notes'];
      $result = $this->buildAwards()->decodeAwardHeader($fileHeader);
      $this->assertSame(0, $result['pos']['mcid']);
      $this->assertSame(1, $result['pos']['nickname']);
    }

    public function testDecodeAwardHeaderMissingColumnReturnsOkFalse(): void {
      $fileHeader = ['mcid', 'nickname'];
      $result = $this->buildAwards()->decodeAwardHeader($fileHeader);
      $this->assertFalse($result['ok']);
      $this->assertNotEmpty($result['err']);
    }

    public function testDecodeAwardHeaderReportsEachMissingColumnByName(): void {
      $result = $this->buildAwards()->decodeAwardHeader([]);
      foreach ($this->defaultColumns as $col) {
        $this->assertStringContainsString($col, implode(' ', $result['err']));
      }
    }

    public function testDecodeAwardHeaderTrimsWhitespaceFromFileColumnNames(): void {
      $fileHeader = array_map(fn($c) => '  ' . $c . '  ', $this->defaultColumns);
      $result = $this->buildAwards()->decodeAwardHeader($fileHeader);
      $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------------
    // deleteAwards
    // -------------------------------------------------------------------------

    public function testDeleteAwardsReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $this->assertTrue($this->buildAwards()->deleteAwards(42));
    }

    public function testDeleteAwardsReturnsFalseOnException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildAwards()->deleteAwards(42));
    }

  }

}
