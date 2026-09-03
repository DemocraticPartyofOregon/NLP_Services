<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Condition;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpTurfStatus;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpTurfStatusTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpTurfs $turfsMock;
    private NlpVoters $votersMock;
    private ApiActivistCodes $apiActivistCodesMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock       = $this->createMock(Connection::class);
      $this->turfsMock            = $this->createMock(NlpTurfs::class);
      $this->votersMock           = $this->createMock(NlpVoters::class);
      $this->apiActivistCodesMock = $this->createMock(ApiActivistCodes::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildTurfStatus(): NlpTurfStatus {
      return new NlpTurfStatus(
        $this->connectionMock,
        $this->turfsMock,
        $this->votersMock,
        $this->apiActivistCodesMock,
      );
    }

    private function buildConditionMock(): Condition {
      $condMock = $this->createMock(Condition::class);
      $condMock->method('condition')->willReturnSelf();
      return $condMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orConditionGroup')->willReturn($this->buildConditionMock());
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    private function stubTurfStatusRecord(array|false $record): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($record);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
    }

    private function buildUpdateMock(): Update {
      $updateMock = $this->createMock(Update::class);
      $updateMock->method('fields')->willReturnSelf();
      $updateMock->method('condition')->willReturnSelf();
      $updateMock->method('execute')->willReturn(1);
      return $updateMock;
    }

    private function buildUpdateMockExpectingStatus(string $expectedStatus): Update {
      $updateMock = $this->createMock(Update::class);
      $updateMock->expects($this->once())->method('fields')
        ->with(['status' => $expectedStatus])
        ->willReturnSelf();
      $updateMock->method('condition')->willReturnSelf();
      $updateMock->method('execute')->willReturn(1);
      return $updateMock;
    }

    private function buildCapturingMergeMock(array &$keys, array &$insertFields, array &$updateFields): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')
        ->willReturnCallback(function (array $v) use (&$keys, $mergeMock) { $keys = $v; return $mergeMock; });
      $mergeMock->method('insertFields')
        ->willReturnCallback(function (array $v) use (&$insertFields, $mergeMock) { $insertFields = $v; return $mergeMock; });
      $mergeMock->method('updateFields')
        ->willReturnCallback(function (array $v) use (&$updateFields, $mergeMock) { $updateFields = $v; return $mergeMock; });
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function setupContainerWithLogger(): void {
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    private function baseStatusRecord(array $overrides = []): array {
      return array_merge([
        'folderName'     => 'TEST County',
        'turfName'       => 'Turf 1',
        'folderId'       => 10,
        'turfId'         => 5,
        'createTime'     => '2024-01-01 00:00:00',
        'expirationTime' => date('Y-m-d H:i:s', time() + 3600),
        'status'         => NlpTurfStatus::TURF_CREATED,
      ], $overrides);
    }

    private function futureTime(): string {
      return date('Y-m-d H:i:s', time() + 3600);
    }

    private function pastTime(): string {
      return date('Y-m-d H:i:s', time() - 3600);
    }

    // -------------------------------------------------------------------------
    // createTurfStatus
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusIncludesCreateTimeWhenStatusIsCreated(): void {
      $keys = $insertFields = $updateFields = [];
      $this->connectionMock->method('merge')
        ->willReturn($this->buildCapturingMergeMock($keys, $insertFields, $updateFields));

      $this->buildTurfStatus()->createTurfStatus([
        'folderName'     => 'TEST County',
        'turfName'       => 'Turf 1',
        'folderId'       => 10,
        'turfId'         => 5,
        'createTime'     => '2024-01-01 00:00:00',
        'expirationTime' => '2024-01-01 01:00:00',
        'status'         => NlpTurfStatus::TURF_CREATED,
      ]);

      $this->assertArrayHasKey('createTime', $updateFields);
      $this->assertSame('2024-01-01 00:00:00', $updateFields['createTime']);
    }

    public function testCreateTurfStatusOmitsCreateTimeWhenStatusIsNotCreated(): void {
      $keys = $insertFields = $updateFields = [];
      $this->connectionMock->method('merge')
        ->willReturn($this->buildCapturingMergeMock($keys, $insertFields, $updateFields));

      $this->buildTurfStatus()->createTurfStatus([
        'folderName'     => 'TEST County',
        'turfName'       => 'Turf 1',
        'folderId'       => 10,
        'turfId'         => 5,
        'createTime'     => '2024-01-01 00:00:00',
        'expirationTime' => '2024-01-01 01:00:00',
        'status'         => NlpTurfStatus::TURF_DELETED,
      ]);

      $this->assertArrayNotHasKey('createTime', $updateFields);
    }

    public function testCreateTurfStatusUsesFolderNameAndTurfNameAsMergeKeys(): void {
      $keys = $insertFields = $updateFields = [];
      $this->connectionMock->method('merge')
        ->willReturn($this->buildCapturingMergeMock($keys, $insertFields, $updateFields));

      $this->buildTurfStatus()->createTurfStatus([
        'folderName'     => 'TEST County',
        'turfName'       => 'Turf 1',
        'folderId'       => 10,
        'turfId'         => 5,
        'createTime'     => '2024-01-01 00:00:00',
        'expirationTime' => '2024-01-01 01:00:00',
        'status'         => NlpTurfStatus::TURF_CREATED,
      ]);

      $this->assertSame(['folderName' => 'TEST County', 'turfName' => 'Turf 1'], $keys);
    }

    public function testCreateTurfStatusExceptionHandledGracefully(): void {
      $this->connectionMock->method('merge')->willThrowException(new Exception('DB error'));

      $this->buildTurfStatus()->createTurfStatus([
        'folderName'     => 'TEST County',
        'turfName'       => 'Turf 1',
        'folderId'       => 10,
        'turfId'         => 5,
        'createTime'     => '2024-01-01 00:00:00',
        'expirationTime' => '2024-01-01 01:00:00',
        'status'         => NlpTurfStatus::TURF_CREATED,
      ]);

      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // setTurfStatusValidated
    // -------------------------------------------------------------------------

    public function testSetTurfStatusValidatedUpdatesStatusField(): void {
      $updateMock = $this->buildUpdateMockExpectingStatus(NlpTurfStatus::TURF_DELETE_VALIDATED);
      $this->connectionMock->expects($this->once())->method('update')->willReturn($updateMock);

      $this->buildTurfStatus()->setTurfStatusValidated('TEST County', 'Turf 1', NlpTurfStatus::TURF_DELETE_VALIDATED);
    }

    public function testSetTurfStatusValidatedExceptionHandledGracefully(): void {
      $this->connectionMock->method('update')->willThrowException(new Exception('DB error'));

      $this->buildTurfStatus()->setTurfStatusValidated('TEST County', 'Turf 1', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // extendTurfStatusExpiration
    // -------------------------------------------------------------------------

    public function testExtendTurfStatusExpirationSetsExpirationSecondsInFuture(): void {
      $captured = [];
      $updateMock = $this->createMock(Update::class);
      $updateMock->method('fields')->willReturnCallback(function (array $f) use (&$captured, $updateMock) {
        $captured = $f;
        return $updateMock;
      });
      $updateMock->method('condition')->willReturnSelf();
      $updateMock->method('execute')->willReturn(1);
      $this->connectionMock->method('update')->willReturn($updateMock);

      $before = time();
      $this->buildTurfStatus()->extendTurfStatusExpiration('TEST County', 'Turf 1', 240);
      $after = time();

      $expirationTimestamp = strtotime($captured['expirationTime']);
      $this->assertGreaterThanOrEqual($before + 240, $expirationTimestamp);
      $this->assertLessThanOrEqual($after + 240, $expirationTimestamp);
    }

    public function testExtendTurfStatusExpirationExceptionHandledGracefully(): void {
      $this->connectionMock->method('update')->willThrowException(new Exception('DB error'));

      $this->buildTurfStatus()->extendTurfStatusExpiration('TEST County', 'Turf 1', 240);

      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getExpiredPendingTurfStatuses
    // -------------------------------------------------------------------------

    public function testGetExpiredPendingTurfStatusesFiltersToExpiredOnly(): void {
      $expired = ['folderName' => 'A', 'turfName' => 'T1', 'expirationTime' => $this->pastTime()];
      $notExpired = ['folderName' => 'B', 'turfName' => 'T2', 'expirationTime' => $this->futureTime()];
      $noExpiration = ['folderName' => 'C', 'turfName' => 'T3', 'expirationTime' => ''];

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls($expired, $notExpired, $noExpiration, FALSE);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildTurfStatus()->getExpiredPendingTurfStatuses();

      $this->assertSame([$expired], $result);
    }

    public function testGetExpiredPendingTurfStatusesEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(FALSE);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildTurfStatus()->getExpiredPendingTurfStatuses());
    }

    public function testGetExpiredPendingTurfStatusesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new Exception('DB error'));

      $this->assertSame([], $this->buildTurfStatus()->getExpiredPendingTurfStatuses());
    }

    // -------------------------------------------------------------------------
    // turfStatusCheck
    // -------------------------------------------------------------------------

    public function testTurfStatusCheckNoRecordReturnsTrue(): void {
      $this->stubTurfStatusRecord(FALSE);
      $this->turfsMock->expects($this->never())->method('getTurfByTurfName');

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeleteValidatedStatusReturnsTrueImmediately(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord(['status' => NlpTurfStatus::TURF_DELETE_VALIDATED]));
      $this->turfsMock->expects($this->never())->method('getTurfByTurfName');

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreateValidatedStatusReturnsTrueImmediately(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord(['status' => NlpTurfStatus::TURF_CREATE_VALIDATED]));
      $this->turfsMock->expects($this->never())->method('getTurfByTurfName');

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedTurfGoneNotExpiredReturnsFalse(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);
      $this->votersMock->expects($this->never())->method('getVotersInTurf');
      $this->connectionMock->expects($this->never())->method('update');

      $this->assertFalse($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedTurfGoneExpiredReturnsTrue(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->pastTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);
      $this->connectionMock->expects($this->never())->method('update');

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreatedTurfGoneReturnsTrueRegardlessOfExpiration(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_CREATED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedNoVotersNotExpiredReturnsFalse(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->apiActivistCodesMock->expects($this->never())->method('hasApiActivistCodeBatch');

      $this->assertFalse($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreatedNoVotersReturnsTrue(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord(['status' => NlpTurfStatus::TURF_CREATED]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([]);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedAllCodesRemovedStampsValidatedAndReturnsTrue(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([101, 102]);
      $updateMock = $this->buildUpdateMockExpectingStatus(NlpTurfStatus::TURF_DELETE_VALIDATED);
      $this->connectionMock->expects($this->once())->method('update')->willReturn($updateMock);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedPartialCodesExpiredLogsAndStampsValidated(): void {
      $this->setupContainerWithLogger();
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->pastTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $updateMock = $this->buildUpdateMockExpectingStatus(NlpTurfStatus::TURF_DELETE_VALIDATED);
      $this->connectionMock->expects($this->once())->method('update')->willReturn($updateMock);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckDeletedPartialCodesNotExpiredReturnsFalse(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_DELETED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->connectionMock->expects($this->never())->method('update');

      $this->assertFalse($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreatedAllCodesAppliedStampsValidatedAndReturnsTrue(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_CREATED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);
      $updateMock = $this->buildUpdateMockExpectingStatus(NlpTurfStatus::TURF_CREATE_VALIDATED);
      $this->connectionMock->expects($this->once())->method('update')->willReturn($updateMock);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreatedPartialCodesExpiredLogsAndStampsValidated(): void {
      $this->setupContainerWithLogger();
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_CREATED,
        'expirationTime' => $this->pastTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $updateMock = $this->buildUpdateMockExpectingStatus(NlpTurfStatus::TURF_CREATE_VALIDATED);
      $this->connectionMock->expects($this->once())->method('update')->willReturn($updateMock);

      $this->assertTrue($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckCreatedPartialCodesNotExpiredReturnsFalse(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord([
        'status'         => NlpTurfStatus::TURF_CREATED,
        'expirationTime' => $this->futureTime(),
      ]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->connectionMock->expects($this->never())->method('update');

      $this->assertFalse($this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1'));
    }

    public function testTurfStatusCheckPassesVanidsAndCodeNameToBatchCheck(): void {
      $this->stubTurfStatusRecord($this->baseStatusRecord(['status' => NlpTurfStatus::TURF_CREATED]));
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->expects($this->once())->method('hasApiActivistCodeBatch')
        ->with([101, 102], 'NLPVoterAC')
        ->willReturn([]);
      $this->connectionMock->method('update')->willReturn($this->buildUpdateMock());

      $this->buildTurfStatus()->turfStatusCheck('TEST County', 'Turf 1');
    }

  }

}
