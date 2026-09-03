<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpEarlyVoting;
  use Drupal\Tests\UnitTestCase;

  class NlpEarlyVotingTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildEarlyVoting(): NlpEarlyVoting {
      return new NlpEarlyVoting($this->connectionMock);
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('join')->willReturn('a');
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function buildStatementMock(array $rows): StatementInterface {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [FALSE]));
      return $stmtMock;
    }

    // -------------------------------------------------------------------------
    // matchEarlyVoters
    // -------------------------------------------------------------------------

    public function testMatchEarlyVotersSingleMatchReturnsVanid(): void {
      $stmtMock = $this->buildStatementMock([['vanid' => 101, 'firstName' => 'Jane']]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jane', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);

      $this->assertSame([
        ['vanid' => 101, 'name' => 'Doe, Jane', 'precinct' => '12', 'date' => '2024-10-15'],
      ], $result);
    }

    public function testMatchEarlyVotersAmbiguousMatchReturnsZeroVanidAndBlankDate(): void {
      $stmtMock = $this->buildStatementMock([
        ['vanid' => 101, 'firstName' => 'Jane'],
        ['vanid' => 102, 'firstName' => 'Janet'],
      ]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jan', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);

      $this->assertSame([
        ['vanid' => 0, 'name' => 'Doe, Jan', 'precinct' => '12', 'date' => ''],
      ], $result);
    }

    public function testMatchEarlyVotersNoCandidatesOmitsRow(): void {
      $stmtMock = $this->buildStatementMock([]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jane', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);

      $this->assertSame([], $result);
    }

    public function testMatchEarlyVotersFirstNameMismatchOmitsRow(): void {
      $stmtMock = $this->buildStatementMock([['vanid' => 101, 'firstName' => 'Bob']]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jane', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);

      $this->assertSame([], $result);
    }

    public function testMatchEarlyVotersMissingCommaOmitsRow(): void {
      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Jane Doe', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);
      $this->assertSame([], $result);
    }

    public function testMatchEarlyVotersEmptyPrecinctOmitsRow(): void {
      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jane', 'precinct' => '', 'receiveDate' => '2024-10-15'],
      ]);
      $this->assertSame([], $result);
    }

    public function testMatchEarlyVotersEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildEarlyVoting()->matchEarlyVoters([]));
    }

    public function testMatchEarlyVotersQueryExceptionOmitsRow(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $result = $this->buildEarlyVoting()->matchEarlyVoters([
        ['name' => 'Doe, Jane', 'precinct' => '12', 'receiveDate' => '2024-10-15'],
      ]);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // decodeStateEarlyVotingHdr
    // -------------------------------------------------------------------------

    public function testDecodeStateEarlyVotingHdrAllFieldsPresentReturnsOk(): void {
      $result = $this->buildEarlyVoting()->decodeStateEarlyVotingHdr([
        'AssignedVoterID', 'BallotReceivedDate',
      ]);

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['stateFileId']);
      $this->assertSame(1, $result['pos']['date']);
      $this->assertSame([], $result['err']);
    }

    public function testDecodeStateEarlyVotingHdrMissingFieldReturnsError(): void {
      $result = $this->buildEarlyVoting()->decodeStateEarlyVotingHdr(['AssignedVoterID']);

      $this->assertFalse($result['ok']);
      $this->assertSame(0, $result['pos']['stateFileId']);
      $this->assertArrayNotHasKey('date', $result['pos']);
      $this->assertSame('Ballot Received Date missing.', $result['err']['date']);
    }

    public function testDecodeStateEarlyVotingHdrEmptyHdrReturnsBothErrors(): void {
      $result = $this->buildEarlyVoting()->decodeStateEarlyVotingHdr([]);

      $this->assertFalse($result['ok']);
      $this->assertSame('Assigned Voter ID missing.', $result['err']['stateFileId']);
      $this->assertSame('Ballot Received Date missing.', $result['err']['date']);
    }

    public function testDecodeStateEarlyVotingHdrIgnoresNonAlphanumericChars(): void {
      $result = $this->buildEarlyVoting()->decodeStateEarlyVotingHdr([
        ' Assigned Voter#ID ', 'Ballot Received$Date',
      ]);

      $this->assertTrue($result['ok']);
    }

  }

}
