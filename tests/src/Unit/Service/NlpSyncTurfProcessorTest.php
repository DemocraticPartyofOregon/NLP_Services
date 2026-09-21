<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiExportJobs;
  use Drupal\nlpservices\ApiSavedLists;
  use Drupal\nlpservices\ApiVoter;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpSyncTurfProcessor;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\nlpservices\NlpTurfStatus;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpSyncTurfProcessorTest extends UnitTestCase {

    private ApiExportJobs     $exportJobsMock;
    private ApiSavedLists     $savedListsMock;
    private ApiVoter          $apiVoterMock;
    private NlpNls            $nlsMock;
    private ApiActivistCodes  $activistCodesMock;
    private NlpVoters         $votersMock;
    private NlpTurfs          $turfsMock;
    private NlpConfiguration  $configMock;
    private NlpAwards         $awardsMock;
    private NlpReports        $reportsMock;
    private NlpPaths          $pathsMock;
    private NlpTurfAssignments $turfAssignmentsMock;
    private NlpTurfStatus $turfStatusMock;
    private MessengerInterface $messengerMock;

    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->exportJobsMock      = $this->createMock(ApiExportJobs::class);
      $this->savedListsMock      = $this->createMock(ApiSavedLists::class);
      $this->apiVoterMock        = $this->createMock(ApiVoter::class);
      $this->nlsMock             = $this->createMock(NlpNls::class);
      $this->activistCodesMock   = $this->createMock(ApiActivistCodes::class);
      $this->votersMock          = $this->createMock(NlpVoters::class);
      $this->turfsMock           = $this->createMock(NlpTurfs::class);
      $this->configMock          = $this->createMock(NlpConfiguration::class);
      $this->awardsMock          = $this->createMock(NlpAwards::class);
      $this->reportsMock         = $this->createMock(NlpReports::class);
      $this->pathsMock           = $this->createMock(NlpPaths::class);
      $this->turfAssignmentsMock = $this->createMock(NlpTurfAssignments::class);
      $this->turfStatusMock      = $this->createMock(NlpTurfStatus::class);
      $this->messengerMock       = $this->createMock(MessengerInterface::class);

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      foreach ($this->tempFiles as $path) {
        if (file_exists($path)) { unlink($path); }
      }
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildProcessor(): NlpSyncTurfProcessor {
      return new NlpSyncTurfProcessor(
        $this->exportJobsMock,
        $this->savedListsMock,
        $this->apiVoterMock,
        $this->nlsMock,
        $this->activistCodesMock,
        $this->votersMock,
        $this->turfsMock,
        $this->configMock,
        $this->awardsMock,
        $this->reportsMock,
        $this->pathsMock,
        $this->turfAssignmentsMock,
        $this->messengerMock,
        $this->turfStatusMock,
      );
    }

    /**
     * Write a turf voter CSV (header + one row per VANID) and return the path.
     * Column 0 is a dummy; column 1 is the VANID, matching how processBatch reads it.
     */
    private function writeTurfCsv(array $vanids): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_turf_');
      $fh   = fopen($path, 'w');
      fputcsv($fh, ['Header', 'VanID'], escape: '');
      foreach ($vanids as $vanid) {
        fputcsv($fh, ['', $vanid], escape: '');
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    private function baseArg(string $downloadUrl): array {
      return [
        'county'        => 'Denver',
        'folderId'      => 5,
        'folderName'    => 'Test Folder',
        'turfId'        => 10,
        'turfName'      => 'Elm Street',
        'mcid'          => 42,
        'showLists'     => FALSE,
        'mapRegionId'   => 3,
        'mapRegionName' => 'Region A',
        'voterCount'    => 1,
        'doorCount'     => 1,
        'walksheetInfo' => [],
        'minivan'       => FALSE,
        'downloadUrl'   => $downloadUrl,
      ];
    }

    private function baseVoter(int $vanid, array $overrides = []): array {
      return array_merge([
        'vanid'         => $vanid,
        'address'       => ['precinct' => '6.1', 'hd' => '6', 'cd' => 1],
        'activistCodes' => [],
        'firstName'     => 'Voter',
        'lastName'      => 'Test',
      ], $overrides);
    }

    private function makeVoters(array $vanids): array {
      $voters = [];
      foreach ($vanids as $vanid) {
        $voters[$vanid] = $this->baseVoter((int) $vanid);
      }
      return $voters;
    }

    private function baseNlRecord(): array {
      return [
        'mcid'      => 42,
        'firstName' => 'Alice',
        'lastName'  => 'Smith',
        'nickname'  => 'Alice',
        'vfid'      => 50,
      ];
    }

    /**
     * Stub all mocks needed for the per-voter processing loop and finalizeTurf.
     * Tests that verify error paths before this code runs do NOT need this.
     */
    private function stubForSuccess(): void {
      $this->turfsMock->method('createTurf')->willReturn(7);
      $this->apiVoterMock->method('getVoters')
        ->willReturnCallback(fn($ids) => $this->makeVoters($ids));
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('getVoterStatus')->willReturn([]);
      $this->configMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNlRecord());
      $this->nlsMock->method('getNlsStatus')->willReturn([]);
      $this->nlsMock->method('setNlsStatus')->willReturn(TRUE);
      $this->turfAssignmentsMock->method('getTurfAssignmentByTurfName')->willReturn([]);
    }

    // -------------------------------------------------------------------------
    // processBatch — no downloadUrl, recovery fails
    // -------------------------------------------------------------------------

    public function testProcessBatchExportJobRecoveryFailsSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $this->savedListsMock->method('recoverTurfId')
        ->willReturn(['result' => 'error', 'message' => 'List not found.']);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(''), $context);

      $this->assertSame('error', $context['results']['result']);
    }

    // -------------------------------------------------------------------------
    // processBatch — no downloadUrl, recovery succeeds but second job still empty
    // -------------------------------------------------------------------------

    public function testProcessBatchRecoveredJobHasNoDownloadUrlSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $this->savedListsMock->method('recoverTurfId')
        ->willReturn(['result' => 'ok', 'turfId' => 99]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(''), $context);

      $this->assertSame('error', $context['results']['result']);
    }

    // -------------------------------------------------------------------------
    // processBatch — downloadUrl points to a file that cannot be opened
    // -------------------------------------------------------------------------

    public function testProcessBatchFileNotOpenableSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => '/nonexistent/missing.csv']);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(''), $context);

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(1, $context['finished']);
    }

    // -------------------------------------------------------------------------
    // processBatch — voters span multiple precincts → abort
    // -------------------------------------------------------------------------

    public function testProcessBatchMixedPrecinctVotersAbortsWithErrorResult(): void {
      $uri = $this->writeTurfCsv([101, 102]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->turfsMock->method('createTurf')->willReturn(7);

      $this->apiVoterMock->method('getVoters')->willReturn([
        101 => $this->baseVoter(101, ['address' => ['precinct' => '6.1', 'hd' => '6', 'cd' => 1]]),
        102 => $this->baseVoter(102, ['address' => ['precinct' => '7.2', 'hd' => '7', 'cd' => 1]]),
      ]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(7, $context['results']['turfIndex']);
    }

    // -------------------------------------------------------------------------
    // processBatch — duplicate voter: deleteVoterInTurf is called
    // -------------------------------------------------------------------------

    public function testProcessBatchDuplicateVoterIsDeletedFromOldTurf(): void {
      $uri = $this->writeTurfCsv([101]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $this->votersMock->expects($this->once())->method('duplicateVoters')
        ->willReturn([101 => ['mcid' => 55, 'turfIndex' => 3, 'county' => 'Denver']]);
      $this->votersMock->method('getVotersInList')
        ->willReturn([101 => ['vanid' => 101, 'firstName' => 'Bob', 'lastName' => 'Jones']]);
      $this->turfsMock->method('getTurf')
        ->willReturn([
          'turfName'      => 'Oak Ave',
          'nlFirstName'   => 'Carol',
          'nlLastName'    => 'Davis',
          'folderName'    => 'Denver Folder',
          'mapRegionName' => 'Denver Region',
        ]);

      $this->votersMock->expects($this->once())->method('deleteVoterInTurf')->with(101);

      $context    = [];
      $processor  = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — duplicate voter: NLPVoterAC is removed before re-apply
    // -------------------------------------------------------------------------

    public function testProcessBatchDuplicateVoterActivistCodeRemovedThenReapplied(): void {
      $uri = $this->writeTurfCsv([101]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $this->votersMock->method('duplicateVoters')
        ->willReturn([101 => ['mcid' => 55, 'turfIndex' => 3, 'county' => 'Denver']]);
      $this->votersMock->method('getVotersInList')
        ->willReturn([101 => ['vanid' => 101, 'firstName' => 'Bob', 'lastName' => 'Jones']]);
      $this->turfsMock->method('getTurf')
        ->willReturn([
          'turfName'      => 'Oak Ave',
          'nlFirstName'   => 'Carol',
          'nlLastName'    => 'Davis',
          'folderName'    => 'Denver Folder',
          'mapRegionName' => 'Denver Region',
        ]);

      $acSetActions = [];
      $this->activistCodesMock->method('setApiActivistCodeBatch')
        ->willReturnCallback(function (array $vanids, string $codeName, string $action = 'Apply') use (&$acSetActions): void {
          $acSetActions[] = $action;
        });

      $context   = [];
      $processor = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);

      $this->assertContains('Remove', $acSetActions, 'setApiActivistCodeBatch Remove should be called to clear old turf AC');
      $this->assertContains('Apply',  $acSetActions, 'setApiActivistCodeBatch Apply should be called to set new turf AC');
      $removePos = array_search('Remove', $acSetActions);
      $applyPos  = array_search('Apply',  $acSetActions);
      $this->assertLessThan($applyPos, $removePos, 'Remove must precede Apply');
    }

    // -------------------------------------------------------------------------
    // processBatch — single batch, happy path → success result
    // -------------------------------------------------------------------------

    public function testProcessBatchSingleBatchSuccessHasCorrectResults(): void {
      $uri = $this->writeTurfCsv([101]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $context   = [];
      $processor = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);

      $this->assertSame('success',    $context['results']['result']);
      $this->assertSame(1,            $context['results']['turfCnt']);
      $this->assertSame('Elm Street', $context['results']['turfName']);
      $this->assertSame('Alice',      $context['results']['nickname']);
      $this->assertSame(7,            $context['results']['turfIndex']);
    }

    // -------------------------------------------------------------------------
    // processBatch — moved voter appears in success results
    // -------------------------------------------------------------------------

    public function testProcessBatchMovedVoterCarriedIntoSuccessResults(): void {
      $uri = $this->writeTurfCsv([101]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $this->votersMock->method('duplicateVoters')
        ->willReturn([101 => ['mcid' => 55, 'turfIndex' => 3, 'county' => 'Denver']]);
      $this->votersMock->method('getVotersInList')
        ->willReturn([101 => ['vanid' => 101, 'firstName' => 'Bob', 'lastName' => 'Jones']]);
      $this->turfsMock->method('getTurf')
        ->willReturn([
          'turfName'      => 'Oak Ave',
          'nlFirstName'   => 'Carol',
          'nlLastName'    => 'Davis',
          'folderName'    => 'Denver Folder',
          'mapRegionName' => 'Denver Region',
        ]);

      $context   = [];
      $processor = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);

      $this->assertSame('success', $context['results']['result']);
      $this->assertArrayHasKey(101, $context['results']['movedVoters']);
      $moved = $context['results']['movedVoters'][101];
      $this->assertSame('Bob',     $moved['firstName']);
      $this->assertSame('Oak Ave', $moved['oldTurfName']);
      $this->assertSame(55,        $moved['oldMcid']);
    }

    // -------------------------------------------------------------------------
    // processBatch — createTurfVoter called once per voter on completion
    // -------------------------------------------------------------------------

    public function testProcessBatchCreatesTurfVoterRecordForEachVoter(): void {
      $uri = $this->writeTurfCsv([101, 102]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $this->votersMock->expects($this->exactly(2))->method('createTurfVoter');

      $context   = [];
      $processor = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);

      $this->assertSame(2, $context['results']['turfCnt']);
    }

    // -------------------------------------------------------------------------
    // processBatch — nlParticipation called on successful completion
    // -------------------------------------------------------------------------

    public function testProcessBatchCallsNlParticipationOnSuccess(): void {
      $uri = $this->writeTurfCsv([101]);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $this->awardsMock->expects($this->once())->method('nlParticipation')
        ->with(42, 'Turf synced');

      $context   = [];
      $processor = $this->buildProcessor();
      $processor->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — multi-batch: first call reports fractional progress
    // -------------------------------------------------------------------------

    public function testProcessBatchMultiBatchFirstCallReportsFractionalProgress(): void {
      // 18 voters → batch 0 gets 17, batch 1 gets 1 → batchCount = 2
      $vanids = range(101, 118);
      $uri    = $this->writeTurfCsv($vanids);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      // After processing batch 0 of 2, finished = 1/2
      $this->assertSame(0.5, $context['finished']);
      $this->assertArrayNotHasKey('result', $context['results'] ?? [],
        'Success result must not be set until the final batch completes');
    }

    // -------------------------------------------------------------------------
    // processBatch — multi-batch: second call finalises and sets success
    // -------------------------------------------------------------------------

    public function testProcessBatchMultiBatchSecondCallFinalisesSuccessfully(): void {
      $vanids = range(101, 118);
      $uri    = $this->writeTurfCsv($vanids);
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => $uri]);
      $this->stubForSuccess();

      $context   = [];
      $processor = $this->buildProcessor();

      $processor->processBatch($this->baseArg($uri), $context);
      $this->assertSame(0.5, $context['finished']);

      $processor->processBatch($this->baseArg($uri), $context);
      $this->assertSame(1, $context['finished'], 'Second call should finalise');
      $this->assertSame('success', $context['results']['result']);
    }

  }

}
