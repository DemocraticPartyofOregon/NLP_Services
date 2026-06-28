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
      $transaction = $this->createMock(Transaction::class);

      $this->connectionMock->method('startTransaction')->willReturn($transaction);

      $this->assertSame($transaction, $this->buildService()->matchbackTransaction());
    }

  }

}
