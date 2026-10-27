<?php

namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpAcHistory;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use PHPUnit\Framework\MockObject\MockObject;

  class NlpAcHistoryTest extends UnitTestCase {

    private Connection&MockObject $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    private function buildService(): NlpAcHistory {
      return new NlpAcHistory($this->connectionMock);
    }

    private function mockSelect(array $rows, array &$conditions): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchCol')->willReturn(array_column($rows, 'vanid'));
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [FALSE]));
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('condition')
        ->willReturnCallback(function ($field, $value, $operator = '=') use (&$conditions, &$selectMock) {
          $conditions[$field] = [$value, $operator];
          return $selectMock;
        });
      $selectMock->method('execute')->willReturn($stmtMock);
      $this->connectionMock->method('select')->willReturn($selectMock);
    }

    public function testRecordInsertsOneRowPerVoter(): void {
      $rows = [];
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->with(['vanid', 'event', 'source', 'created'])->willReturnSelf();
      $insertMock->method('values')
        ->willReturnCallback(function (array $values) use (&$rows, $insertMock) {
          $rows[] = $values;
          return $insertMock;
        });
      $insertMock->expects($this->once())->method('execute');
      $this->connectionMock->method('insert')->with(NlpAcHistory::AC_HISTORY_TBL)->willReturn($insertMock);

      $this->buildService()->record([111, '222'], NlpAcHistory::REMOVE, 'turf delete (turfIndex 7)');

      $this->assertCount(2, $rows);
      $this->assertSame([111, 'Remove', 'turf delete (turfIndex 7)'], array_slice($rows[0], 0, 3));
      $this->assertSame(222, $rows[1][0]);
      $this->assertEqualsWithDelta(time(), $rows[1][3], 5);
    }

    public function testRecordWithNoVotersDoesNothing(): void {
      $this->connectionMock->expects($this->never())->method('insert');

      $this->buildService()->record([], NlpAcHistory::APPLY, 'turf sync');
    }

    public function testRecordDatabaseErrorIsNotThrown(): void {
      $this->connectionMock->method('insert')->willThrowException(new Exception('no table'));

      $this->buildService()->record([111], NlpAcHistory::APPLY, 'turf sync');
      $this->addToAssertionCount(1);
    }

    public function testVanidsWithEventSinceReturnsIntVanids(): void {
      $conditions = [];
      $this->mockSelect([['vanid' => '222']], $conditions);

      $vanids = $this->buildService()->vanidsWithEventSince([111, 222], [NlpAcHistory::REMOVE], 1700000000);

      $this->assertSame([222], $vanids);
      $this->assertSame([[NlpAcHistory::REMOVE], 'IN'], $conditions['h.event']);
      $this->assertSame([1700000000, '>='], $conditions['h.created']);
    }

    public function testVanidsWithEventSinceWithNoVotersSkipsQuery(): void {
      $this->connectionMock->expects($this->never())->method('select');

      $this->assertSame([], $this->buildService()->vanidsWithEventSince([], [NlpAcHistory::APPLY], 0));
    }

    public function testDescribeListsEachVotersEventsInOrder(): void {
      $conditions = [];
      $t1 = mktime(10, 2, 15, 10, 27, 2026);
      $t2 = mktime(10, 4, 40, 10, 27, 2026);
      $this->mockSelect([
        ['vanid' => '222', 'event' => 'Remove', 'source' => 'turf delete (turfIndex 7)', 'created' => (string) $t1],
        ['vanid' => '222', 'event' => 'Apply', 'source' => 'turf sync of Oak Ave (turfIndex 9)', 'created' => (string) $t2],
      ], $conditions);

      $text = $this->buildService()->describe([222, 333], $t1 - 60);

      $this->assertSame('222: Remove from turf delete (turfIndex 7) at 10:02:15, '
        . 'Apply from turf sync of Oak Ave (turfIndex 9) at 10:04:40; 333: no history', $text);
    }

    public function testDescribeLimitsTheVotersDescribed(): void {
      $conditions = [];
      $this->mockSelect([], $conditions);
      $vanids = range(1, NlpAcHistory::MAX_DESCRIBED + 3);

      $text = $this->buildService()->describe($vanids, 0);

      $this->assertCount(NlpAcHistory::MAX_DESCRIBED, $conditions['h.vanid'][0]);
      $this->assertStringEndsWith('; and 3 more', $text);
    }

    public function testPurgeDeletesHistoryOlderThanKeepTime(): void {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->expects($this->once())->method('condition')
        ->with('created', $this->callback(fn($time) => abs($time - (time() - NlpAcHistory::KEEP_SECS)) <= 5), '<')
        ->willReturnSelf();
      $deleteMock->expects($this->once())->method('execute');
      $this->connectionMock->method('delete')->with(NlpAcHistory::AC_HISTORY_TBL)->willReturn($deleteMock);

      $this->buildService()->purge();
    }

  }

}
