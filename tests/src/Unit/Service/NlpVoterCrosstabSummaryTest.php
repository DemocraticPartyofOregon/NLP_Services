<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoterCrosstabSummary;
  use Drupal\Tests\UnitTestCase;

  class NlpVoterCrosstabSummaryTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpTurfs $turfsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->turfsMock      = $this->createMock(NlpTurfs::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildSummary(): NlpVoterCrosstabSummary {
      return new NlpVoterCrosstabSummary($this->connectionMock, $this->turfsMock);
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturn('c_county');
      $selectMock->method('addExpression')->willReturn('totalPeople');
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('groupBy')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function baseRow(): array {
      return [
        'county'      => 'Denver',
        'totalPeople' => '500',
        'accepted'    => '300',
      ];
    }

    // -------------------------------------------------------------------------
    // getCountiesWithTurfs
    // -------------------------------------------------------------------------

    public function testGetCountiesWithTurfsDelegatesToTurfs(): void {
      $this->turfsMock->method('getCountyNames')->willReturn(['Denver', 'Boulder']);
      $result = $this->buildSummary()->getCountiesWithTurfs();
      $this->assertSame(['Denver', 'Boulder'], $result);
    }

    public function testGetCountiesWithTurfsReturnsEmptyWhenNone(): void {
      $this->turfsMock->method('getCountyNames')->willReturn([]);
      $this->assertSame([], $this->buildSummary()->getCountiesWithTurfs());
    }

    // -------------------------------------------------------------------------
    // getCountySummary
    // -------------------------------------------------------------------------

    public function testGetCountySummarySingleRowReturnsCountyAndStateTotals(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($this->baseRow(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildSummary()->getCountySummary('nlp_target_voter_crosstab');

      $this->assertSame(500, $result['counties']['Denver']['totalPeople']);
      $this->assertSame(300, $result['counties']['Denver']['accepted']);
      $this->assertSame(500, $result['state']['totalPeople']);
      $this->assertSame(300, $result['state']['accepted']);
    }

    public function testGetCountySummaryMultipleRowsSumsStateTotal(): void {
      $row1     = $this->baseRow();
      $row2     = ['county' => 'Boulder', 'totalPeople' => '200', 'accepted' => '100'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row1, $row2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildSummary()->getCountySummary('nlp_target_voter_crosstab');

      $this->assertSame(500, $result['counties']['Denver']['totalPeople']);
      $this->assertSame(200, $result['counties']['Boulder']['totalPeople']);
      $this->assertSame(700, $result['state']['totalPeople']);
      $this->assertSame(400, $result['state']['accepted']);
    }

    public function testGetCountySummaryEmptyResultReturnsZeroTotals(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildSummary()->getCountySummary('nlp_target_voter_crosstab');

      $this->assertSame([], $result['counties']);
      $this->assertSame(0, $result['state']['totalPeople']);
      $this->assertSame(0, $result['state']['accepted']);
    }

    public function testGetCountySummaryExceptionReturnsEmptyResult(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturn('c_county');
      $selectMock->method('addExpression')->willReturn('totalPeople');
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('groupBy')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);

      $result = $this->buildSummary()->getCountySummary('nlp_target_voter_crosstab');

      $this->assertSame([], $result['counties']);
      $this->assertSame(0, $result['state']['totalPeople']);
      $this->assertSame(0, $result['state']['accepted']);
    }

    public function testGetCountySummaryIntegerCastsStringValues(): void {
      $row      = ['county' => 'Arapahoe', 'totalPeople' => '150', 'accepted' => '75'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($row, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildSummary()->getCountySummary('nlp_target_voter_crosstab');

      $this->assertIsInt($result['counties']['Arapahoe']['totalPeople']);
      $this->assertIsInt($result['counties']['Arapahoe']['accepted']);
    }

    // -------------------------------------------------------------------------
    // buildCountySummaryDisplay
    // -------------------------------------------------------------------------

    public function testBuildCountySummaryDisplayReturnsTableRenderArray(): void {
      $allVoters    = ['totalPeople' => 1000, 'accepted' => 600];
      $targetVoters = ['totalPeople' => 400,  'accepted' => 200];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertSame('table', $result['#type']);
      $this->assertSame('Denver', $result['#caption']);
    }

    public function testBuildCountySummaryDisplayWithoutTurfStatsTwoRows(): void {
      $allVoters    = ['totalPeople' => 1000, 'accepted' => 600];
      $targetVoters = ['totalPeople' => 400,  'accepted' => 200];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertCount(2, $result['#rows']);
    }

    public function testBuildCountySummaryDisplayWithTurfStatsFiveRows(): void {
      $allVoters    = ['totalPeople' => 1000, 'accepted' => 600];
      $targetVoters = ['totalPeople' => 400,  'accepted' => 200];
      $turfStats    = [
        'total'          => 300,
        'totalVoted'     => 150,
        'contacted'      => 200,
        'contactedVoted' => 120,
        'surveyed'       => 100,
        'surveyedVoted'  => 50,
      ];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters, $turfStats);

      $this->assertCount(5, $result['#rows']);
    }

    public function testBuildCountySummaryDisplayCalculatesPercentage(): void {
      $allVoters    = ['totalPeople' => 1000, 'accepted' => 500];
      $targetVoters = ['totalPeople' => 200,  'accepted' => 50];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertSame('50%', $result['#rows'][0]['pct']);
      $this->assertSame('25%', $result['#rows'][1]['pct']);
    }

    public function testBuildCountySummaryDisplayZeroTotalPeopleShowsZeroPct(): void {
      $allVoters    = ['totalPeople' => 0, 'accepted' => 0];
      $targetVoters = ['totalPeople' => 0, 'accepted' => 0];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertSame('0%', $result['#rows'][0]['pct']);
      $this->assertSame('0%', $result['#rows'][1]['pct']);
    }

    public function testBuildCountySummaryDisplayFormatsNumbersWithCommas(): void {
      $allVoters    = ['totalPeople' => 10000, 'accepted' => 5000];
      $targetVoters = ['totalPeople' => 2000,  'accepted' => 1000];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertSame('10,000', $result['#rows'][0]['totalPeople']);
      $this->assertSame('5,000',  $result['#rows'][0]['accepted']);
    }

    public function testBuildCountySummaryDisplayTurfStatsZeroContactedShowsZeroPct(): void {
      $allVoters    = ['totalPeople' => 100, 'accepted' => 50];
      $targetVoters = ['totalPeople' => 50,  'accepted' => 25];
      $turfStats    = [
        'total'          => 0,
        'totalVoted'     => 0,
        'contacted'      => 0,
        'contactedVoted' => 0,
        'surveyed'       => 0,
        'surveyedVoted'  => 0,
      ];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters, $turfStats);

      $this->assertSame('0%', $result['#rows'][2]['pct']);
      $this->assertSame('0%', $result['#rows'][3]['pct']);
      $this->assertSame('0%', $result['#rows'][4]['pct']);
    }

    public function testBuildCountySummaryDisplayHeaderHasFourKeys(): void {
      $allVoters    = ['totalPeople' => 100, 'accepted' => 50];
      $targetVoters = ['totalPeople' => 50,  'accepted' => 25];
      $result = $this->buildSummary()->buildCountySummaryDisplay('Denver', $targetVoters, $allVoters);

      $this->assertArrayHasKey('type',        $result['#header']);
      $this->assertArrayHasKey('totalPeople', $result['#header']);
      $this->assertArrayHasKey('accepted',    $result['#header']);
      $this->assertArrayHasKey('pct',         $result['#header']);
    }

  }

}
