<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\NlpTurfAcChecker;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpTurfStatus;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

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
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildChecker(): NlpTurfAcChecker {
      return new NlpTurfAcChecker(
        $this->turfsMock,
        $this->votersMock,
        $this->apiActivistCodesMock,
        $this->turfStatusMock,
      );
    }

    private function setupContainerWithLogger(): void {
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    private function baseRecord(array $overrides = []): array {
      return array_merge([
        'folderName' => 'TEST County',
        'folderId'   => 10,
        'turfName'   => 'Turf 1',
        'turfId'     => 5,
        'status'     => NlpTurfStatus::TURF_CREATED,
      ], $overrides);
    }

    // -------------------------------------------------------------------------
    // checkExpiredTurfAc
    // -------------------------------------------------------------------------

    public function testCheckExpiredTurfAcTurfNotFoundValidatesCreateStatus(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);
      $this->votersMock->expects($this->never())->method('getVotersInTurf');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord());
    }

    public function testCheckExpiredTurfAcTurfNotFoundValidatesDeleteStatus(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn([]);
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::TURF_DELETE_VALIDATED);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord(['status' => NlpTurfStatus::TURF_DELETED]));
    }

    public function testCheckExpiredTurfAcNoVotersInTurfSetsValidated(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->apiActivistCodesMock->expects($this->never())->method('hasApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord());
    }

    public function testCheckExpiredTurfAcCreateAllCodesAppliedSetsValidated(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->never())->method('extendTurfStatusExpiration');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::TURF_CREATE_VALIDATED);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord());
    }

    public function testCheckExpiredTurfAcCreateSomeCodesMissingRetriesApply(): void {
      $this->setupContainerWithLogger();
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->apiActivistCodesMock->expects($this->once())->method('setApiActivistCodeBatch')
        ->with([102], 'NLPVoterAC', 'Apply');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');
      $this->turfStatusMock->expects($this->once())->method('extendTurfStatusExpiration')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord());
    }

    public function testCheckExpiredTurfAcDeleteAllCodesRemovedSetsValidated(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([101, 102]);
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');
      $this->turfStatusMock->expects($this->never())->method('extendTurfStatusExpiration');
      $this->turfStatusMock->expects($this->once())->method('setTurfStatusValidated')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::TURF_DELETE_VALIDATED);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord(['status' => NlpTurfStatus::TURF_DELETED]));
    }

    public function testCheckExpiredTurfAcDeleteSomeCodesStillPresentRetriesRemove(): void {
      $this->setupContainerWithLogger();
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      // Only vanid 102 is missing the code, so 101 still has it and must be retried.
      $this->apiActivistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->apiActivistCodesMock->expects($this->once())->method('setApiActivistCodeBatch')
        ->with([101], 'NLPVoterAC', 'Remove');
      $this->turfStatusMock->expects($this->never())->method('setTurfStatusValidated');
      $this->turfStatusMock->expects($this->once())->method('extendTurfStatusExpiration')
        ->with('TEST County', 'Turf 1', NlpTurfStatus::DELETED_EXPIRY_SMALL_SECS);

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord(['status' => NlpTurfStatus::TURF_DELETED]));
    }

    public function testCheckExpiredTurfAcPassesCodeNameAndVanidsToBatchCheck(): void {
      $this->turfsMock->method('getTurfByTurfName')->willReturn(['turfIndex' => 7]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => [], 102 => []]);
      $this->apiActivistCodesMock->expects($this->once())->method('hasApiActivistCodeBatch')
        ->with([101, 102], 'NLPVoterAC')
        ->willReturn([]);
      $this->turfStatusMock->method('setTurfStatusValidated');

      $this->buildChecker()->checkExpiredTurfAc($this->baseRecord());
    }

  }

}
