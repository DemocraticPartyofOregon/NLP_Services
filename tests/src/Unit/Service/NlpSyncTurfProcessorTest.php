<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Transaction;
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
    private Connection $connectionMock;
    private Transaction $transactionMock;

    /** Order of transaction and activist code events, to check what happens when. */
    private array $events = [];

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
      $this->connectionMock      = $this->createMock(Connection::class);

      // A PHPUnit mock of Transaction still runs its real destructor, which needs a
      // real connection, so use a double that records what the processor does.
      $events = &$this->events;
      $this->transactionMock = new class($events) extends Transaction {
        private array $log;
        public function __construct(array &$log) { $this->log = &$log; }
        public function __destruct() {}
        public function commitOrRelease(): void { $this->log[] = 'commit'; }
        public function rollBack(): void { $this->log[] = 'rollback'; }
      };
      $this->connectionMock->method('startTransaction')
        ->willReturnCallback(function () {
          $this->events[] = 'begin';
          return $this->transactionMock;
        });
      $this->activistCodesMock->method('setApiActivistCodeBatch')
        ->willReturnCallback(function (array $vanids, string $codeName, string $action = 'Apply'): void {
          $this->events[] = 'ac:' . $action;
        });

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('string_translation', $this->getStringTranslationStub());
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
        $this->connectionMock,
      );
    }

    /**
     * Run processBatch until the batch reports it is finished, as the Batch API would.
     */
    private function runBatch(array $arg): array {
      $context   = [];
      $processor = $this->buildProcessor();
      for ($i = 0; $i < 50; $i++) {
        $processor->processBatch($arg, $context);
        if (($context['finished'] ?? 0) >= 1) { break; }
      }
      return $context;
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

    private function useCsv(array $vanids): void {
      $uri = $this->writeTurfCsv($vanids);
      $this->exportJobsMock->method('getExportJobs')->willReturn(['downloadUrl' => $uri]);
    }

    private function baseArg(): array {
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
     * Mocks replaced after this call must be stubbed again by the test.
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

    /**
     * The given voters are already in turf 3, assigned to another NL.
     */
    private function stubDuplicates(array $vanids): void {
      $dups = $voters = [];
      foreach ($vanids as $vanid) {
        $dups[$vanid]   = ['mcid' => 55, 'turfIndex' => 3, 'county' => 'Denver'];
        $voters[$vanid] = ['vanid' => $vanid, 'firstName' => 'Bob', 'lastName' => 'Jones'];
      }
      $this->votersMock->method('duplicateVoters')->willReturn($dups);
      $this->votersMock->method('getVotersInList')->willReturn($voters);
      $this->turfsMock->method('getTurf')
        ->willReturn([
          'turfName'      => 'Oak Ave',
          'nlFirstName'   => 'Carol',
          'nlLastName'    => 'Davis',
          'folderName'    => 'Denver Folder',
          'mapRegionName' => 'Denver Region',
        ]);
    }

    // -------------------------------------------------------------------------
    // Errors before any voter is fetched
    // -------------------------------------------------------------------------

    public function testProcessBatchExportJobRecoveryFailsSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $this->savedListsMock->method('recoverTurfId')
        ->willReturn(['result' => 'error', 'message' => 'List not found.']);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(), $context);

      $this->assertSame('error', $context['results']['result']);
    }

    public function testProcessBatchRecoveredJobHasNoDownloadUrlSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $this->savedListsMock->method('recoverTurfId')
        ->willReturn(['result' => 'ok', 'turfId' => 99]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(), $context);

      $this->assertSame('error', $context['results']['result']);
    }

    public function testProcessBatchFileNotOpenableSetsErrorResult(): void {
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => '/nonexistent/missing.csv']);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(), $context);

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(1, $context['finished']);
    }

    public function testProcessBatchEmptyTurfSetsErrorResult(): void {
      $this->useCsv([]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(), $context);

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(1, $context['finished']);
    }

    public function testAbortDeletesStagedWalksheet(): void {
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => '/nonexistent/missing.csv']);
      $walksheet = tempnam(sys_get_temp_dir(), 'nlp_ws_');
      $this->tempFiles[] = $walksheet;
      $arg = $this->baseArg();
      $arg['walksheetInfo'] = ['tempName' => $walksheet];

      $context = [];
      $this->buildProcessor()->processBatch($arg, $context);

      $this->assertFileDoesNotExist($walksheet);
    }

    // -------------------------------------------------------------------------
    // Fetch phase — nothing is written until every voter has been fetched
    // -------------------------------------------------------------------------

    public function testFetchPhaseWritesNothing(): void {
      // 18 voters → two fetch requests.
      $this->useCsv(range(101, 118));
      $this->stubForSuccess();
      $this->connectionMock->expects($this->never())->method('startTransaction');
      $this->turfsMock->expects($this->never())->method('createTurf');
      $this->votersMock->expects($this->never())->method('createVoter');
      $this->activistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg(), $context);

      $this->assertGreaterThan(0, $context['finished']);
      $this->assertLessThan(1, $context['finished']);
      $this->assertArrayNotHasKey('result', $context['results'] ?? []);
    }

    public function testMixedPrecinctInLaterChunkAbortsWithoutDatabaseWrites(): void {
      $this->useCsv(range(101, 118));
      $calls = 0;
      $this->apiVoterMock->method('getVoters')
        ->willReturnCallback(function ($ids) use (&$calls) {
          $calls++;
          $voters = $this->makeVoters($ids);
          if ($calls == 2) {
            foreach ($voters as $vanid => $voter) {
              $voters[$vanid]['address'] = ['precinct' => '7.2', 'hd' => '7', 'cd' => 1];
            }
          }
          return $voters;
        });
      $this->connectionMock->expects($this->never())->method('startTransaction');
      $this->turfsMock->expects($this->never())->method('createTurf');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame(2, $calls);
      $this->assertSame('error', $context['results']['result']);
      $this->assertArrayNotHasKey('turfIndex', $context['results']);
    }

    public function testNoVoterDataAbortsWithoutDatabaseWrites(): void {
      $this->useCsv([101]);
      $this->apiVoterMock->method('getVoters')->willReturn([]);
      $this->connectionMock->expects($this->never())->method('startTransaction');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
    }

    // -------------------------------------------------------------------------
    // Commit phase — happy path
    // -------------------------------------------------------------------------

    public function testSuccessHasCorrectResults(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();

      $context = $this->runBatch($this->baseArg());

      $this->assertSame(1,            $context['finished']);
      $this->assertSame('success',    $context['results']['result']);
      $this->assertSame(1,            $context['results']['turfCnt']);
      $this->assertSame('Elm Street', $context['results']['turfName']);
      $this->assertSame('Alice',      $context['results']['nickname']);
      $this->assertSame(7,            $context['results']['turfIndex']);
    }

    public function testMultiChunkSyncCompletes(): void {
      $this->useCsv(range(101, 118));
      $this->stubForSuccess();

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('success', $context['results']['result']);
      $this->assertSame(18, $context['results']['turfCnt']);
      // Two activist code requests of up to 17 voters each.
      $this->assertSame(['begin', 'commit', 'ac:Apply', 'ac:Apply'], $this->events);
    }

    public function testTurfRecordIsCompleteWhenCreated(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $created = [];
      $this->turfsMock = $this->createMock(NlpTurfs::class);
      $this->turfsMock->method('createTurf')
        ->willReturnCallback(function (array $turf) use (&$created) {
          $created = $turf;
          return 7;
        });

      $this->runBatch($this->baseArg());

      $this->assertSame('Alice', $created['nlFirstName']);
      $this->assertSame('Smith', $created['nlLastName']);
      $this->assertSame('6',     $created['turfHd']);
      $this->assertSame('6.1',   $created['turfPrecinct']);
      $this->assertSame(10,      $created['listId']);
      $this->assertSame(42,      $created['mcid']);
    }

    public function testCreatesTurfVoterRecordForEachVoter(): void {
      $this->useCsv([101, 102]);
      $this->stubForSuccess();
      $this->votersMock->expects($this->exactly(2))->method('createTurfVoter');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame(2, $context['results']['turfCnt']);
    }

    public function testCallsNlParticipationOnSuccess(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->awardsMock->expects($this->once())->method('nlParticipation')->with(42, 'Turf synced');

      $this->runBatch($this->baseArg());
    }

    public function testActivistCodesAreSetOnlyAfterCommit(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();

      $this->runBatch($this->baseArg());

      $this->assertSame(['begin', 'commit', 'ac:Apply'], $this->events);
    }

    public function testCommittedFlagSetBeforeActivistCodes(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();

      $context   = [];
      $processor = $this->buildProcessor();
      // Fetch, then commit.
      $processor->processBatch($this->baseArg(), $context);
      $processor->processBatch($this->baseArg(), $context);

      $this->assertTrue($context['results']['committed']);
      $this->assertArrayNotHasKey('result', $context['results']);
      $this->assertLessThan(1, $context['finished']);
    }

    public function testOrphanVoterRecordsAreCleanedForTheseVoters(): void {
      $this->useCsv([101, 102]);
      $this->stubForSuccess();
      $this->votersMock->expects($this->once())->method('deleteOrphanTurfVoters')->with([101, 102]);

      $this->runBatch($this->baseArg());
    }

    // -------------------------------------------------------------------------
    // Voters moved from another turf
    // -------------------------------------------------------------------------

    public function testDuplicateVoterIsDeletedFromOldTurf(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->stubDuplicates([101]);
      $this->votersMock->expects($this->once())->method('deleteVoterInTurf')->with(101);
      $this->votersMock->expects($this->once())->method('deleteAddressInTurf')->with(101);

      $this->runBatch($this->baseArg());
    }

    public function testDuplicateVoterActivistCodeRemovedThenReapplied(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->stubDuplicates([101]);

      $this->runBatch($this->baseArg());

      $this->assertSame(['begin', 'commit', 'ac:Remove', 'ac:Apply'], $this->events);
    }

    public function testMovedVoterCarriedIntoSuccessResults(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->stubDuplicates([101]);

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('success', $context['results']['result']);
      $moved = $context['results']['movedVoters'][101];
      $this->assertSame('Bob',     $moved['firstName']);
      $this->assertSame('Oak Ave', $moved['oldTurfName']);
      $this->assertSame(55,        $moved['oldMcid']);
    }

    public function testTooManyMovedVotersRollsBackAndChangesNothing(): void {
      $vanids = range(101, 107);
      $this->useCsv($vanids);
      $this->stubForSuccess();
      $this->stubDuplicates($vanids);
      $this->turfsMock->expects($this->never())->method('createTurf');
      $this->votersMock->expects($this->never())->method('deleteVoterInTurf');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
      $this->assertStringContainsString('Too many voters moved', (string) $context['results']['message'][0]);
      $this->assertSame(['begin', 'rollback'], $this->events);
    }

    // -------------------------------------------------------------------------
    // Failure inside the transaction
    // -------------------------------------------------------------------------

    public function testCreateVoterFailureRollsBackAndSetsNoActivistCodes(): void {
      $this->useCsv([101, 102]);
      $this->stubForSuccess();
      $this->votersMock = $this->createMock(NlpVoters::class);
      $this->votersMock->method('createVoter')->willReturnOnConsecutiveCalls(TRUE, FALSE);
      $this->votersMock->method('getVoterStatus')->willReturn([]);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
      $this->assertArrayNotHasKey('committed', $context['results']);
      $this->assertSame(['begin', 'rollback'], $this->events);
    }

    public function testCreateTurfFailureRollsBack(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->turfsMock = $this->createMock(NlpTurfs::class);
      $this->turfsMock->method('createTurf')->willReturn(0);
      $this->votersMock->expects($this->never())->method('createTurfVoter');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(['begin', 'rollback'], $this->events);
    }

    public function testUnexpectedExceptionRollsBack(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->awardsMock->method('nlParticipation')->willThrowException(new \RuntimeException('boom'));

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
      $this->assertSame(['begin', 'rollback'], $this->events);
    }

    // -------------------------------------------------------------------------
    // An earlier sync of the same turf
    // -------------------------------------------------------------------------

    public function testIncompleteTurfFromFailedSyncIsReplaced(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $residue = ['turfIndex' => 3, 'county' => 'Denver', 'mcid' => 42, 'turfName' => 'Elm Street',
        'nlFirstName' => NULL, 'nlLastName' => NULL, 'turfHd' => NULL];
      $this->turfsMock->method('getTurfsForSync')->with(5, 10, 'Elm Street')->willReturn([3 => $residue]);
      $this->turfsMock->method('isIncompleteTurf')->willReturn(TRUE);
      $this->turfsMock->expects($this->once())->method('removeTurf')->with($residue)->willReturn(TRUE);
      $this->votersMock->expects($this->once())->method('deleteVotersInTurf')->with(3);
      $this->votersMock->expects($this->once())->method('deleteAddressesInTurf')->with(3);

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('success', $context['results']['result']);
    }

    public function testCompleteTurfAlreadyAssignedIsRefused(): void {
      $this->useCsv([101]);
      $this->stubForSuccess();
      $this->turfsMock->method('getTurfsForSync')->willReturn([3 => [
        'turfIndex' => 3, 'nlFirstName' => 'Carol', 'nlLastName' => 'Davis', 'turfHd' => 6,
      ]]);
      $this->turfsMock->method('isIncompleteTurf')->willReturn(FALSE);
      $this->turfsMock->expects($this->never())->method('removeTurf');

      $context = $this->runBatch($this->baseArg());

      $this->assertSame('error', $context['results']['result']);
      $this->assertStringContainsString('already assigned to Carol Davis', (string) $context['results']['message'][0]);
      $this->assertSame(['begin', 'rollback'], $this->events);
    }

  }

}
