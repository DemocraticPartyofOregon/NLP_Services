<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Component\Datetime\TimeInterface;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpMcidRecovery;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpMcidRecoveryTest extends UnitTestCase {

    private Connection $connectionMock;
    private int $requestTime = 1700000000;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->setupContainerWithTime();
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function setupContainerWithTime(): void {
      $timeMock = $this->createMock(TimeInterface::class);
      $timeMock->method('getRequestTime')->willReturn($this->requestTime);

      $container = new ContainerBuilder();
      $container->set('datetime.time', $timeMock);
      \Drupal::setContainer($container);
    }

    private function buildService(): NlpMcidRecovery {
      return new NlpMcidRecovery($this->connectionMock);
    }

    private function buildInsertMock(): Insert {
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('execute')->willReturn(1);
      return $insertMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    public function testCreateFactoryUsesDatabaseServiceFromContainer(): void {
      $container = new ContainerBuilder();
      $container->set('database', $this->connectionMock);

      $service = NlpMcidRecovery::create($container);

      $this->assertInstanceOf(NlpMcidRecovery::class, $service);
    }

    public function testSaveRecoveryKeyReturns64CharacterKeyAndInsertsRecord(): void {
      $capturedFields = [];
      $insertMock = $this->buildInsertMock();
      $insertMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $insertMock): Insert {
          $capturedFields = $fields;
          return $insertMock;
        });

      $this->connectionMock->method('insert')->willReturn($insertMock);

      $key = $this->buildService()->saveRecoveryKey(42);

      $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
      $this->assertSame($key, $capturedFields['recovery_key']);
      $this->assertSame(42, $capturedFields['mcid']);
      $this->assertSame($this->requestTime, $capturedFields['created']);
    }

    public function testSaveRecoveryKeyReturnsEmptyStringOnInsertException(): void {
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));

      $this->assertSame('', $this->buildService()->saveRecoveryKey(42));
    }

    public function testGetMcidReturnsMcidForUnexpiredKey(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'mcid' => 42,
        'created' => $this->requestTime - 60,
      ]);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(42, $this->buildService()->getMcid('abc123'));
    }

    public function testGetMcidReturnsZeroForExpiredKey(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'mcid' => 42,
        'created' => $this->requestTime - NlpMcidRecovery::LIFETIME_SECONDS - 1,
      ]);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(0, $this->buildService()->getMcid('abc123'));
    }

    public function testGetMcidReturnsZeroWhenKeyMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(0, $this->buildService()->getMcid('abc123'));
    }

    public function testGetMcidReturnsZeroOnException(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));

      $this->connectionMock->method('select')->willReturn($selectMock);

      $this->assertSame(0, $this->buildService()->getMcid('abc123'));
    }

    public function testDeleteKeyCallsDeleteOnRecoveryTable(): void {
      $this->connectionMock->expects($this->once())
        ->method('delete')
        ->with(NlpMcidRecovery::RECOVERY_TBL)
        ->willReturn($this->buildDeleteMock());

      $this->buildService()->deleteKey('abc123');
      $this->addToAssertionCount(1);
    }

    public function testPurgeExpiredDeletesOldRecordsUsingCutoff(): void {
      $capturedCondition = [];
      $deleteMock = $this->buildDeleteMock();
      $deleteMock->method('condition')
        ->willReturnCallback(function (string $field, $value, string $operator) use (&$capturedCondition, $deleteMock): Delete {
          $capturedCondition = [$field, $value, $operator];
          return $deleteMock;
        });

      $this->connectionMock->method('delete')->willReturn($deleteMock);

      $this->buildService()->purgeExpired();

      $this->assertSame('created', $capturedCondition[0]);
      $this->assertSame($this->requestTime - NlpMcidRecovery::LIFETIME_SECONDS, $capturedCondition[1]);
      $this->assertSame('<', $capturedCondition[2]);
    }

  }

}
