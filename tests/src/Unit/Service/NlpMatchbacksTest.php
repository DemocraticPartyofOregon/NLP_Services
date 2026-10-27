<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Database\Transaction;
  use Drupal\nlpservices\NlpImap;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpMatchbacksTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpImap $imapMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->imapMock = $this->createMock(NlpImap::class);
    }

    private function buildService(): NlpMatchbacks {
      return new NlpMatchbacks($this->connectionMock, $this->imapMock);
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

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('database', $this->connectionMock);
      $container->set('nlpservices.imap', $this->imapMock);

      $service = NlpMatchbacks::create($container);
      $this->assertInstanceOf(NlpMatchbacks::class, $service);
    }

    public function testDecodeMatchbackHdrFindsRequiredColumns(): void {
      $result = $this->buildService()->decodeMatchbackHdr([
        'VoterFileVANID',
        'BallotReceived',
        'EarlyVoted',
      ]);

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['vanid']);
      $this->assertSame(1, $result['pos']['ballotReceived']);
    }

    public function testDecodeMatchbackHdrAcceptsEarlyVotedFallback(): void {
      $result = $this->buildService()->decodeMatchbackHdr([
        'VoterFileVANID',
        'EarlyVoted',
      ]);

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['vanid']);
      $this->assertSame(1, $result['pos']['earlyVoted']);
    }

    public function testHeaderValidateReturnsFieldPositionsForValidHeader(): void {
      $result = $this->buildService()->headerValidate([
        'VoterFileVANID',
        'BallotReceived',
      ]);

      $this->assertTrue($result['ok']);
      $this->assertArrayHasKey('vanid', $result['pos']);
      $this->assertArrayHasKey('ballotReceived', $result['pos']);
    }

    public function testMatchbackExistsReturnsTrueWhenRowExists(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['vanid' => 101]);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertTrue($this->buildService()->matchbackExists(101));
    }

    public function testMatchbackExistsReturnsFalseWhenRowMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertFalse($this->buildService()->matchbackExists(101));
    }

    public function testGetMatchbackDateReturnsStoredDate(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['date' => '2024-10-20']);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame('2024-10-20', $this->buildService()->getMatchbackDate(101));
    }

    public function testGetMatchbackDateReturnsEmptyStringWhenMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame('', $this->buildService()->getMatchbackDate(101));
    }

    public function testSetLatestMatchbackDateCallsMergeWithVanidZero(): void {
      $capturedKeys = [];
      $capturedFields = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')
        ->willReturnCallback(function (array $keys) use (&$capturedKeys, $mergeMock): Merge {
          $capturedKeys = $keys;
          return $mergeMock;
        });
      $mergeMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $mergeMock): Merge {
          $capturedFields = $fields;
          return $mergeMock;
        });

      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->setLatestMatchbackDate('2024-10-20');

      $this->assertSame(['vanid' => 0], $capturedKeys);
      $this->assertSame(['date' => '2024-10-20'], $capturedFields);
    }

    public function testMatchbackTransactionReturnsConnectionTransaction(): void {
      $transaction = new class extends Transaction {
        public function __construct() {}
        public function __destruct() {}
      };

      $this->connectionMock->method('startTransaction')->willReturn($transaction);

      $this->assertSame($transaction, $this->buildService()->matchbackTransaction());
    }

    /**
     * Mock the queries processMatchbackFile() makes and collect the vanids
     * it inserts.
     */
    private function mockMatchbackImport(array &$insertedVanids): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());

      $insertMock = $this->createMock(StatementInterface::class);
      $insertMock->method('execute')->willReturnCallback(function (array $args) use (&$insertedVanids): bool {
        foreach ($args as $key => $value) {
          if (str_starts_with($key, ':vanid')) { $insertedVanids[] = $value; }
        }
        return true;
      });
      $insertMock->method('rowCount')->willReturnCallback(fn() => 1);
      $this->connectionMock->method('prepareStatement')->willReturn($insertMock);
    }

    private function writeMatchbackFile(int $rows): string {
      $filePath = tempnam(sys_get_temp_dir(), 'nlp_matchback_test');
      $lines = ['VoterFileVANID,BallotReceived'];
      for ($vanid = 1; $vanid <= $rows; $vanid++) {
        $lines[] = "$vanid,10/20/2026";
      }
      file_put_contents($filePath, implode("\n", $lines) . "\n");
      return $filePath;
    }

    public function testProcessMatchbackFileImportsWholeFileWithoutLimit(): void {
      $inserted = [];
      $this->mockMatchbackImport($inserted);
      $filePath = $this->writeMatchbackFile(5);

      $counts = $this->buildService()->processMatchbackFile($filePath);

      $this->assertSame(5, $counts['recordCount']);
      $this->assertSame(0, $counts['nextOffset']);
      $this->assertSame([1, 2, 3, 4, 5], $inserted);
      $this->assertFileDoesNotExist($filePath);
    }

    public function testProcessMatchbackFileImportsInChunks(): void {
      $inserted = [];
      $this->mockMatchbackImport($inserted);
      $filePath = $this->writeMatchbackFile(5);
      $service = $this->buildService();

      $first = $service->processMatchbackFile($filePath, 0, 2);
      $this->assertSame(2, $first['recordCount']);
      $this->assertGreaterThan(0, $first['nextOffset']);
      $this->assertFileExists($filePath);

      $second = $service->processMatchbackFile($filePath, $first['nextOffset'], 2);
      $this->assertSame(2, $second['recordCount']);
      $this->assertGreaterThan($first['nextOffset'], $second['nextOffset']);

      $third = $service->processMatchbackFile($filePath, $second['nextOffset'], 2);
      $this->assertSame(1, $third['recordCount']);
      $this->assertSame(0, $third['nextOffset']);

      $this->assertSame([1, 2, 3, 4, 5], $inserted);
      $this->assertFileDoesNotExist($filePath);
    }

    public function testProcessMatchbackFileFinishesWhenChunkEndsAtLastRecord(): void {
      $inserted = [];
      $this->mockMatchbackImport($inserted);
      $filePath = $this->writeMatchbackFile(4);

      $counts = $this->buildService()->processMatchbackFile($filePath, 0, 4);

      $this->assertSame(4, $counts['recordCount']);
      $this->assertSame(0, $counts['nextOffset']);
      $this->assertFileDoesNotExist($filePath);
    }

  }

}
