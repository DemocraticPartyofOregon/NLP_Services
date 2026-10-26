<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\NlpTurfAcChecker;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpTurfStatus;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;

  class NlpTurfAcCheckerTest extends UnitTestCase {

    private NlpTurfs $turfsMock;
    private NlpVoters $votersMock;
    private ApiActivistCodes $apiActivistCodesMock;
    private NlpTurfStatus $turfStatusMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfsMock            = $this->createMock(NlpTurfs::class);
      $this->votersMock           = $this->createMock(NlpVoters::class);
      $this->apiActivistCodesMock = $this->createMock(ApiActivistCodes::class);
      $this->turfStatusMock       = $this->createMock(NlpTurfStatus::class);

      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($this->createMock(LoggerInterface::class));
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpTurfAcChecker {
      return new NlpTurfAcChecker(
        $this->turfsMock,
        $this->votersMock,
        $this->apiActivistCodesMock,
        $this->turfStatusMock,
      );
    }

    private function record(array $overrides = []): array {
      return array_replace([
        'folderName' => 'HD24',
        'folderId'   => 10,
        'turfName'   => 'Ds Turf 19',
        'turfId'     => 20,
        'createTime' => '2026-10-15T17:00:00-07:00',
        'status'     => NlpTurfStatus::TURF_CREATED,
      ], $overrides);
    }

    private function turfWithVoters(array $vanids): void {
      $this->turfsMock->method('getTurfByTurfName')->with(10, 'Ds Turf 19')->willReturn(['turfIndex' => 3492]);
      $this->votersMock->method('getVotersInTurf')->with(3492)->willReturn(array_fill_keys($vanids, []));
    }

    // -------------------------------------------------------------------------
    // Stale queue items
    // -------------------------------------------------------------------------

    public function testStaleDeleteItemDoesNotRemoveCodeFromNewTurfWithSameName(): void {
      // The turf was deleted, cron queued the expired record, then a new turf
      // with the same name was synced before the worker ran.
      $queued = $this->record(['status' => NlpTurfStatus::TURF_DELETED]);
      $this->turfStatusMock->method('getTurfStatus')
        ->willReturn($this->record(['createTime' => '2026-10-15T17:34:06-07:00']));
      $this->turfWithVoters([101, 102]);
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');

      $this->buildService()->checkExpiredTurfAc($queued);
    }

    public function testStaleItemIsSkippedWhenTurfWasSyncedAgainSinceQueued(): void {
      $this->turfStatusMock->method('getTurfStatus')
        ->willReturn($this->record(['createTime' => '2026-10-15T18:00:00-07:00']));
      $this->apiActivistCodesMock->expects($this->never())->method('hasApiActivistCodeBatch');
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

    public function testItemIsSkippedWhenStatusRecordIsGone(): void {
      $this->turfStatusMock->method('getTurfStatus')->willReturn([]);
      $this->apiActivistCodesMock->expects($this->never())->method('hasApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

    // -------------------------------------------------------------------------
    // Deleted turfs
    // -------------------------------------------------------------------------

    public function testCurrentDeleteIsValidatedWithoutTouchingTurfFoundByName(): void {
      $record = $this->record(['status' => NlpTurfStatus::TURF_DELETED]);
      $this->turfStatusMock->method('getTurfStatus')->willReturn($record);
      $this->turfsMock->expects($this->never())->method('getTurfByTurfName');
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('HD24', 'Ds Turf 19', NlpTurfStatus::TURF_DELETE_VALIDATED);

      $this->buildService()->checkExpiredTurfAc($record);
    }

    // -------------------------------------------------------------------------
    // Synced turfs
    // -------------------------------------------------------------------------

    public function testCreateIsValidatedWhenEveryVoterHasCode(): void {
      $this->turfStatusMock->method('getTurfStatus')->willReturn($this->record());
      $this->turfWithVoters([101, 102]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('HD24', 'Ds Turf 19', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

    public function testCreateRetriesApplyForVotersMissingCode(): void {
      $this->turfStatusMock->method('getTurfStatus')->willReturn($this->record());
      $this->turfWithVoters([101, 102, 103]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->apiActivistCodesMock->expects($this->once())->method('setApiActivistCodeBatch')
        ->with([102], 'NLPVoterAC', 'Apply', 'expired turf check of HD24/Ds Turf 19');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');
      $this->turfStatusMock->expects($this->once())->method('extendTurfStatusExpiration')
        ->with('HD24', 'Ds Turf 19', NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

    public function testCreateIsCheckedAgainLaterWhenSomeVotersCouldNotBeRead(): void {
      $this->turfStatusMock->method('getTurfStatus')->willReturn($this->record());
      $this->turfWithVoters([101, 102]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')
        ->willReturnCallback(function (array $vanids, string $codeName, ?array &$unread = NULL) {
          $unread = [102];
          return [];
        });
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');
      $this->turfStatusMock->expects($this->once())->method('extendTurfStatusExpiration')
        ->with('HD24', 'Ds Turf 19', NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

    public function testCreateIsValidatedWhenTurfIsGone(): void {
      $this->turfStatusMock->method('getTurfStatus')->willReturn($this->record());
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('HD24', 'Ds Turf 19', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->buildService()->checkExpiredTurfAc($this->record());
    }

  }

}
