<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\Routing\RouteMatchInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiExportJobs;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiSavedLists;
  use Drupal\nlpservices\ApiVoter;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpResyncTurf;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpSyncTurfAddVoter;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpResyncTurfTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpTurfs $turfsMock;
    private NlpTurfAssignments $turfAssignmentsMock;
    private ApiExportJobs $exportJobsMock;
    private ApiSavedLists $savedListsMock;
    private NlpSyncTurfAddVoter $syncTurfAddVoterMock;
    private ApiVoter $apiVoterMock;
    private NlpConfiguration $nlpConfigurationMock;
    private ApiFolders $apiFoldersMock;
    private ApiActivistCodes $activistCodesMock;
    private NlpCoordinators $coordinatorsMock;
    private DatabaseFunctions $databaseFunctionsMock;
    private NlpNls $nlsMock;
    private NlpSendEmail $sendEmailMock;
    private NlpSessionData $sessionDataMock;
    private object $messengerMock;
    private object $routeMatchMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock = $this->createMock(NlpVoters::class);
      $this->turfsMock = $this->createMock(NlpTurfs::class);
      $this->turfAssignmentsMock = $this->createMock(NlpTurfAssignments::class);
      $this->exportJobsMock = $this->createMock(ApiExportJobs::class);
      $this->savedListsMock = $this->createMock(ApiSavedLists::class);
      $this->syncTurfAddVoterMock = $this->createMock(NlpSyncTurfAddVoter::class);
      $this->apiVoterMock = $this->createMock(ApiVoter::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
      $this->apiFoldersMock = $this->createMock(ApiFolders::class);
      $this->activistCodesMock = $this->createMock(ApiActivistCodes::class);
      $this->coordinatorsMock = $this->createMock(NlpCoordinators::class);
      $this->databaseFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->nlsMock = $this->createMock(NlpNls::class);
      $this->sendEmailMock = $this->createMock(NlpSendEmail::class);
      $this->sessionDataMock = $this->createMock(NlpSessionData::class);

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $this->messengerMock = $this->createMock(MessengerInterface::class);
      $this->routeMatchMock = $this->createMock(RouteMatchInterface::class);
      $this->routeMatchMock->method('getRouteName')->willReturn('some.route');

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('messenger', $this->messengerMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpResyncTurf {
      return new NlpResyncTurf(
        $this->votersMock,
        $this->turfsMock,
        $this->exportJobsMock,
        $this->savedListsMock,
        $this->syncTurfAddVoterMock,
        $this->apiVoterMock,
        $this->nlpConfigurationMock,
        $this->apiFoldersMock,
        $this->activistCodesMock,
        $this->coordinatorsMock,
        $this->databaseFunctionsMock,
        $this->nlsMock,
        $this->sendEmailMock,
        $this->sessionDataMock,
        $this->turfAssignmentsMock,
        $this->routeMatchMock,
      );
    }

    private function buildPartialService(array $methods): NlpResyncTurf {
      return $this->getMockBuilder(NlpResyncTurf::class)
        ->setConstructorArgs([
          $this->votersMock,
          $this->turfsMock,
          $this->exportJobsMock,
          $this->savedListsMock,
          $this->syncTurfAddVoterMock,
          $this->apiVoterMock,
          $this->nlpConfigurationMock,
          $this->apiFoldersMock,
          $this->activistCodesMock,
          $this->coordinatorsMock,
          $this->databaseFunctionsMock,
          $this->nlsMock,
          $this->sendEmailMock,
          $this->sessionDataMock,
          $this->turfAssignmentsMock,
          $this->routeMatchMock,
        ])
        ->onlyMethods($methods)
        ->getMock();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateReturnsInstance(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.voters', $this->votersMock);
      $container->set('nlpservices.turfs', $this->turfsMock);
      $container->set('nlpservices.export_jobs', $this->exportJobsMock);
      $container->set('nlpservices.saved_lists', $this->savedListsMock);
      $container->set('nlpservices.sync_turf_add_voter', $this->syncTurfAddVoterMock);
      $container->set('nlpservices.api_voter', $this->apiVoterMock);
      $container->set('nlpservices.configuration', $this->nlpConfigurationMock);
      $container->set('nlpservices.api_folders', $this->apiFoldersMock);
      $container->set('nlpservices.activist_codes', $this->activistCodesMock);
      $container->set('nlpservices.coordinators', $this->coordinatorsMock);
      $container->set('nlpservices.database_functions', $this->databaseFunctionsMock);
      $container->set('nlpservices.nls', $this->nlsMock);
      $container->set('nlpservices.send_email', $this->sendEmailMock);
      $container->set('nlpservices.session_data', $this->sessionDataMock);
      $container->set('nlpservices.turf_assignments', $this->turfAssignmentsMock);
      $container->set('current_route_match', $this->routeMatchMock);

      $service = NlpResyncTurf::create($container);

      $this->assertInstanceOf(NlpResyncTurf::class, $service);
    }

    // -------------------------------------------------------------------------
    // displayVotersChanged()
    // -------------------------------------------------------------------------

    public function testDisplayVotersChangedReturnsEmptyArrayForNoVoters(): void {
      $result = $this->buildService()->displayVotersChanged([]);
      $this->assertSame([], $result);
    }

    public function testDisplayVotersChangedFormatsVoterLineWithoutAddress(): void {
      $voters = [
        ['vanid' => 101, 'firstName' => 'Alice', 'lastName' => 'Smith', 'age' => 45, 'party' => 'D', 'sex' => 'F'],
      ];
      $result = $this->buildService()->displayVotersChanged($voters);
      $this->assertCount(1, $result);
      $this->assertStringContainsString('[101]', $result[0]);
      $this->assertStringContainsString('Alice Smith', $result[0]);
      $this->assertStringContainsString('Age 45', $result[0]);
      $this->assertStringContainsString('Party D', $result[0]);
      $this->assertStringContainsString('Gender F', $result[0]);
    }

    public function testDisplayVotersChangedIncludesAddressWhenPresent(): void {
      $voters = [
        [
          'vanid' => 202, 'firstName' => 'Bob', 'lastName' => 'Jones',
          'age' => 30, 'party' => 'R', 'sex' => 'M',
          'address' => [
            'streetNo' => '123', 'streetPrefix' => 'N', 'streetName' => 'Main',
            'streetType' => 'St', 'streetSuffix' => '', 'aptType' => '',
            'aptNo' => '', 'city' => 'Springfield',
          ],
        ],
      ];
      $result = $this->buildService()->displayVotersChanged($voters);
      $this->assertCount(1, $result);
      $this->assertStringContainsString('Main', $result[0]);
      $this->assertStringContainsString('Springfield', $result[0]);
    }

    public function testDisplayVotersChangedReturnsOneEntryPerVoter(): void {
      $voters = [
        ['vanid' => 1, 'firstName' => 'A', 'lastName' => 'B', 'age' => 20, 'party' => 'D', 'sex' => 'F'],
        ['vanid' => 2, 'firstName' => 'C', 'lastName' => 'D', 'age' => 30, 'party' => 'R', 'sex' => 'M'],
        ['vanid' => 3, 'firstName' => 'E', 'lastName' => 'F', 'age' => 40, 'party' => 'I', 'sex' => 'F'],
      ];
      $result = $this->buildService()->displayVotersChanged($voters);
      $this->assertCount(3, $result);
    }

    // -------------------------------------------------------------------------
    // turfsToResyncCheck()
    // -------------------------------------------------------------------------

    public function testTurfsToResyncCheckReturnsEmptyForNoTurfs(): void {
      $this->turfsMock->method('getCountyTurfIndexes')->willReturn([]);
      $result = $this->buildService()->turfsToResyncCheck('King');
      $this->assertSame([], $result);
    }

    public function testTurfsToResyncCheckProducesSingleChunkUnderBatchSize(): void {
      $indexes = range(1, 5);
      $this->turfsMock->method('getCountyTurfIndexes')->willReturn($indexes);
      $result = $this->buildService()->turfsToResyncCheck('King');
      $this->assertCount(1, $result);
      $this->assertSame('King', $result[0]['county']);
      $this->assertSame($indexes, $result[0]['turfIndexes']);
    }

    public function testTurfsToResyncCheckChunksAtBatchSize(): void {
      $indexes = range(1, 11); // 10 + 1 → 2 chunks
      $this->turfsMock->method('getCountyTurfIndexes')->willReturn($indexes);
      $result = $this->buildService()->turfsToResyncCheck('King');
      $this->assertCount(2, $result);
      $this->assertCount(10, $result[0]['turfIndexes']);
      $this->assertCount(1, $result[1]['turfIndexes']);
    }

    public function testTurfsToResyncCheckIncludesCountyInEveryChunk(): void {
      $indexes = range(1, 25);
      $this->turfsMock->method('getCountyTurfIndexes')->willReturn($indexes);
      $result = $this->buildService()->turfsToResyncCheck('Pierce');
      foreach ($result as $chunk) {
        $this->assertSame('Pierce', $chunk['county']);
      }
    }

    // -------------------------------------------------------------------------
    // turfResyncCheck() — early exits (file I/O paths are not unit-testable)
    // -------------------------------------------------------------------------

    public function testTurfResyncCheckReturnsErrorWhenNoListId(): void {
      $this->turfsMock->method('getListId')->willReturn(0);
      $result = $this->buildService()->turfResyncCheck('King', 42);
      $this->assertSame('error', $result['result']);
    }

    public function testTurfResyncCheckReturnsErrorWhenNoVotersInTurf(): void {
      $this->turfsMock->method('getListId')->willReturn(999);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Elm Street', 'folderId' => 5]);
      $result = $this->buildService()->turfResyncCheck('King', 42);
      $this->assertSame('error', $result['result']);
      $this->assertStringContainsString('Elm Street', $result['message']);
    }

    public function testTurfResyncCheckReturnsErrorWhenTurfNotFoundByName(): void {
      $this->turfsMock->method('getListId')->willReturn(999);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([101 => ['vanid' => 101]]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'My Turf', 'folderId' => 5]);
      // First getSavedLists call (by listId) returns empty; second (folder search) returns non-matching list.
      $this->savedListsMock->method('getSavedLists')
        ->willReturnOnConsecutiveCalls(
          [],
          [['name' => 'Different Turf', 'savedListId' => 111]]
        );
      $result = $this->buildService()->turfResyncCheck('King', 42);
      $this->assertSame('error', $result['result']);
    }

    public function testTurfResyncCheckReturnsTransientErrorWhenFopenFails(): void {
      $this->turfsMock->method('getListId')->willReturn(999);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([101 => ['vanid' => 101]]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'My Turf', 'folderId' => 5]);
      // First getSavedLists returns empty (listId changed), second finds matching turf by name.
      $this->savedListsMock->method('getSavedLists')
        ->willReturnOnConsecutiveCalls(
          [],
          [['name' => 'My Turf', 'savedListId' => 777]]
        );
      // Export job found — method will attempt fopen on a non-existent URL.
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['downloadUrl' => 'file:///nonexistent/path/that/does/not/exist.csv']);
      $result = $this->buildService()->turfResyncCheck('King', 42);
      $this->assertSame('transient_error', $result['result']);
    }

    // -------------------------------------------------------------------------
    // resyncTurfDeleteNotify()
    // -------------------------------------------------------------------------

    public function testResyncTurfDeleteNotifyDoesNothingWhenTurfEmpty(): void {
      $this->turfsMock->method('getTurf')->willReturn([]);
      $this->turfsMock->expects($this->never())->method('deleteTurf');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->resyncTurfDeleteNotify(1);
      $this->addToAssertionCount(1);
    }

    public function testResyncTurfDeleteNotifyDeletesTurfEvenWhenNlMissing(): void {
      $this->turfsMock->method('getTurf')->willReturn([
        'county' => 'King', 'mcid' => 42, 'turfName' => 'Test Turf',
        'nlFirstName' => 'Jane', 'nlLastName' => 'Doe',
      ]);
      $this->turfsMock->expects($this->once())->method('deleteTurf');
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->resyncTurfDeleteNotify(1);
      $this->addToAssertionCount(1);
    }

    public function testResyncTurfDeleteNotifySkipsEmailWhenNoCoordinator(): void {
      $this->turfsMock->method('getTurf')->willReturn([
        'county' => 'King', 'mcid' => 42, 'turfName' => 'Test Turf',
        'nlFirstName' => 'Jane', 'nlLastName' => 'Doe',
      ]);
      $this->nlsMock->method('getNlById')->willReturn([
        'firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'j@e.com',
      ]);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->resyncTurfDeleteNotify(1);
      $this->addToAssertionCount(1);
    }

    public function testResyncTurfDeleteNotifySendsEmailToCoordinator(): void {
      $this->turfsMock->method('getTurf')->willReturn([
        'county' => 'King', 'mcid' => 42, 'turfName' => 'Elm Street',
        'nlFirstName' => 'Jane', 'nlLastName' => 'Doe',
      ]);
      $this->nlsMock->method('getNlById')->willReturn([
        'firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'j@e.com',
      ]);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([
        'firstName' => 'Coord', 'email' => 'coord@example.com',
      ]);
      $this->sendEmailMock->expects($this->once())
        ->method('sendEmail')
        ->with('turf_deletion_notification', 'coord@example.com', $this->anything(), $this->anything());

      $this->buildService()->resyncTurfDeleteNotify(1);
    }

    // -------------------------------------------------------------------------
    // fixMissingAC()
    // -------------------------------------------------------------------------

    public function testFixMissingAcAppliesActivistCodeToEachVanid(): void {
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Oak Ave']);
      $this->activistCodesMock->expects($this->exactly(2))
        ->method('setApiActivistCode')
        ->with($this->callback(fn($r) => $r['action'] === 'Apply' && $r['codeName'] === 'NLPVoterAC'));

      $this->buildService()->fixMissingAC(5, [301, 302], 'King');
    }

    public function testFixMissingAcDoesNotCallActivistCodeForEmptyVanids(): void {
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Oak Ave']);
      $this->activistCodesMock->expects($this->never())->method('setApiActivistCode');

      $this->buildService()->fixMissingAC(5, [], 'King');
    }

    // -------------------------------------------------------------------------
    // processTurfResync()
    // -------------------------------------------------------------------------

    public function testProcessTurfResyncStartSetsQueueStartTime(): void {
      $this->sessionDataMock->expects($this->once())
        ->method('set')
        ->with('queueStart', $this->isType('int'));

      // Empty turfIndexes so no resyncTurf call is needed.
      $this->buildService()->processTurfResync(['queueStart' => 'start', 'county' => 'King', 'turfIndexes' => []]);
    }

    public function testProcessTurfResyncEndDisplaysElapsedTime(): void {
      $this->sessionDataMock->method('get')->with('queueStart')->willReturn(time() - 90);
      $this->messengerMock->expects($this->once())
        ->method('addStatus')
        ->with($this->stringContains('Turf resync queue time:'));

      $service = $this->buildPartialService(['resyncTurf']);
      $service->processTurfResync(['queueStart' => 'end', 'county' => 'King', 'turfIndexes' => []]);
    }

    public function testProcessTurfResyncEndResetsSessionData(): void {
      $this->sessionDataMock->method('get')->with('queueStart')->willReturn(time() - 10);
      $callIndex = 0;
      $this->sessionDataMock->expects($this->exactly(2))
        ->method('set')
        ->willReturnCallback(function (string $key, mixed $value) use (&$callIndex): void {
          $callIndex++;
          if ($callIndex === 1) {
            $this->assertSame('queueInProgress', $key);
            $this->assertFalse($value);
          } else {
            $this->assertSame('queueStart', $key);
            $this->assertSame(0, $value);
          }
        });

      $service = $this->buildPartialService(['resyncTurf']);
      $service->processTurfResync(['queueStart' => 'end', 'county' => 'King', 'turfIndexes' => []]);
    }

    public function testProcessTurfResyncCallsResyncTurfForEachIndex(): void {
      $service = $this->buildPartialService(['resyncTurf']);
      $service->expects($this->exactly(3))
        ->method('resyncTurf')
        ->with('King', $this->anything(), TRUE);

      $service->processTurfResync(['county' => 'King', 'turfIndexes' => [10, 20, 30]]);
    }

    // -------------------------------------------------------------------------
    // resyncTurf() — using partial mocks to control turfResyncCheck output
    // -------------------------------------------------------------------------

    public function testResyncTurfSkipsAllProcessingOnTransientError(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'transient_error', 'message' => 'Export job failed',
      ]);
      $this->turfsMock->expects($this->never())->method('updateTurf');
      $this->turfsMock->expects($this->never())->method('deleteTurf');

      $service->resyncTurf('King', 1, FALSE);
      $this->addToAssertionCount(1);
    }

    public function testResyncTurfOnErrorCallsDeleteNotify(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'resyncTurfDeleteNotify']);
      $service->method('turfResyncCheck')->willReturn(['result' => 'error', 'message' => 'No voters']);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Test Turf']);
      $service->expects($this->once())->method('resyncTurfDeleteNotify')->with(42);

      $service->resyncTurf('King', 42, FALSE);
    }

    public function testResyncTurfOnErrorWithEmptyTurfNameLogsAndContinues(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'resyncTurfDeleteNotify']);
      $service->method('turfResyncCheck')->willReturn(['result' => 'error', 'message' => 'No voters']);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => '']);
      $service->method('resyncTurfDeleteNotify');  // void — no return value needed

      $service->resyncTurf('King', 42, FALSE);
      $this->addToAssertionCount(1);
    }

    public function testResyncTurfNoChangeUpdatesSyncDate(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'noChange', 'newTurfId' => 0, 'previousTurfId' => 0, 'missingNlpVoterAc' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $this->turfsMock->expects($this->once())
        ->method('updateTurf')
        ->with(5, 'syncDate', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'));

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfUpdatesTurfIdWhenItChanged(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'noChange', 'newTurfId' => 888, 'previousTurfId' => 999, 'missingNlpVoterAc' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $this->turfAssignmentsMock->expects($this->once())->method('fixTurfAssignment')->with(999, 888);
      $this->turfsMock->expects($this->once())
        ->method('updateTurfFields')
        ->with(5, ['listId' => 888]);

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfChangesAddsNewVoters(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [], 'addedVoters' => [101],
      ]);
      $this->turfsMock->method('getTurf')->willReturn([
        'mcid' => 10, 'turfName' => 'Elm', 'changeHistory' => '',
      ]);
      $this->apiVoterMock->method('getVoters')->willReturn([
        101 => ['vanid' => 101, 'firstName' => 'Alice', 'lastName' => 'Smith'],
      ]);
      $this->databaseFunctionsMock->method('getColumnSize')->willReturn(1000);
      $this->syncTurfAddVoterMock->expects($this->once())->method('syncAddVoter');

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfChangesDeletesRemovedVotersAndRemovesActivistCode(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [201], 'addedVoters' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn([
        'mcid' => 10, 'turfName' => 'Elm', 'changeHistory' => '',
      ]);
      $this->votersMock->method('getVoterById')->willReturn(['firstName' => 'Bob', 'lastName' => 'Jones']);
      $this->databaseFunctionsMock->method('getColumnSize')->willReturn(1000);
      $this->votersMock->expects($this->once())->method('deleteVoterInTurf')->with(201);
      $this->votersMock->expects($this->once())->method('deleteAddressInTurf')->with(201);
      $this->activistCodesMock->expects($this->once())
        ->method('setApiActivistCode')
        ->with($this->callback(fn($r) => $r['vanid'] === 201 && $r['action'] === 'Remove'));

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfDeletesWhenTooManyVotersAdded(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'resyncTurfDeleteNotify']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [], 'addedVoters' => [1,2,3,4,5,6,7],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $service->expects($this->once())->method('resyncTurfDeleteNotify');

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfDeletesWhenTooManyVotersRemoved(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'resyncTurfDeleteNotify']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [1,2,3,4,5,6,7], 'addedVoters' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $service->expects($this->once())->method('resyncTurfDeleteNotify');

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfDeletesWhenChangeHistoryExceedsColumnSize(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'resyncTurfDeleteNotify']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [201], 'addedVoters' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn([
        'mcid' => 10, 'turfName' => 'Elm', 'changeHistory' => '',
      ]);
      $this->votersMock->method('getVoterById')->willReturn(['firstName' => 'Bob', 'lastName' => 'Jones']);
      // Column size of 1 ensures encoded history exceeds it → delete notify.
      $this->databaseFunctionsMock->method('getColumnSize')->willReturn(1);
      $service->expects($this->once())->method('resyncTurfDeleteNotify');

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfChangesUpdatesChangeHistoryAndTurfFields(): void {
      $service = $this->buildPartialService(['turfResyncCheck']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'changes', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [], 'deletedVoters' => [], 'addedVoters' => [],
      ]);
      $this->turfsMock->method('getTurf')->willReturn([
        'mcid' => 10, 'turfName' => 'Elm', 'changeHistory' => '',
      ]);
      $this->databaseFunctionsMock->method('getColumnSize')->willReturn(1000);
      $this->turfsMock->expects($this->once())
        ->method('updateTurfFields')
        ->with(5, $this->callback(fn($f) => isset($f['unreportedChanges']) && isset($f['changeHistory'])));

      $service->resyncTurf('King', 5, FALSE);
    }

    public function testResyncTurfCallsFixMissingAcWhenFixAcIsTrue(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'fixMissingAC']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'noChange', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [301 => 301],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $this->nlsMock->method('getNlById')->willReturn(['firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->votersMock->method('getVotersInList')->willReturn([]);
      $service->expects($this->once())->method('fixMissingAC')->with(5, [301 => 301], 'King');

      $service->resyncTurf('King', 5, TRUE);
    }

    public function testResyncTurfDoesNotCallFixMissingAcWhenFixAcIsFalse(): void {
      $service = $this->buildPartialService(['turfResyncCheck', 'fixMissingAC']);
      $service->method('turfResyncCheck')->willReturn([
        'result' => 'noChange', 'newTurfId' => 0, 'previousTurfId' => 0,
        'missingNlpVoterAc' => [301 => 301],
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['mcid' => 10, 'turfName' => 'Elm']);
      $this->nlsMock->method('getNlById')->willReturn(['firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->votersMock->method('getVotersInList')->willReturn([]);
      $service->expects($this->never())->method('fixMissingAC');

      $service->resyncTurf('King', 5, FALSE);
    }

  }

}
