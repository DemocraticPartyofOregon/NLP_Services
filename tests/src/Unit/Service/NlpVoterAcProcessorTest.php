<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Queue\QueueFactory;
  use Drupal\Core\Queue\QueueInterface;
  use Drupal\Core\State\StateInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\NlpAcHistory;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpFileFunctions;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoterAcProcessor;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpVoterAcProcessorTest extends UnitTestCase {

    private ApiActivistCodes $activistCodesMock;
    private NlpCoordinators $coordinatorsMock;
    private NlpConfiguration $configurationMock;
    private NlpFileFunctions $fileFunctionsMock;
    private NlpNls $nlsMock;
    private NlpSendEmail $sendEmailMock;
    private NlpTurfs $turfsMock;
    private NlpVoters $votersMock;

    protected function setUp(): void {
      parent::setUp();
      $this->activistCodesMock = $this->createMock(ApiActivistCodes::class);
      $this->coordinatorsMock  = $this->createMock(NlpCoordinators::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->fileFunctionsMock = $this->createMock(NlpFileFunctions::class);
      $this->nlsMock           = $this->createMock(NlpNls::class);
      $this->sendEmailMock     = $this->createMock(NlpSendEmail::class);
      $this->turfsMock         = $this->createMock(NlpTurfs::class);
      $this->votersMock        = $this->createMock(NlpVoters::class);

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpVoterAcProcessor {
      return new NlpVoterAcProcessor(
        $this->activistCodesMock,
        $this->coordinatorsMock,
        $this->configurationMock,
        $this->fileFunctionsMock,
        $this->nlsMock,
        $this->sendEmailMock,
        $this->turfsMock,
        $this->votersMock,
      );
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateReturnsInstance(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.activist_codes', $this->activistCodesMock);
      $container->set('nlpservices.coordinators', $this->coordinatorsMock);
      $container->set('nlpservices.configuration', $this->configurationMock);
      $container->set('nlpservices.file_functions', $this->fileFunctionsMock);
      $container->set('nlpservices.nls', $this->nlsMock);
      $container->set('nlpservices.send_email', $this->sendEmailMock);
      $container->set('nlpservices.turfs', $this->turfsMock);
      $container->set('nlpservices.voters', $this->votersMock);

      $service = NlpVoterAcProcessor::create($container);

      $this->assertInstanceOf(NlpVoterAcProcessor::class, $service);
    }

    // -------------------------------------------------------------------------
    // processTurf()
    // -------------------------------------------------------------------------

    public function testProcessTurfDoesNothingWhenNoVotersInTurf(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->activistCodesMock->expects($this->never())->method('hasApiActivistCodeBatch');

      $this->buildService()->processTurf(1);
      $this->addToAssertionCount(1);
    }

    public function testProcessTurfDoesNothingWhenNoMissingVanids(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 101]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);
      $this->activistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');

      $this->buildService()->processTurf(1);
      $this->addToAssertionCount(1);
    }

    public function testProcessTurfAppliesActivistCodeWhenVanidsMissing(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 101], ['vanid' => 102]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([101, 102]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Elm Street', 'county' => 'King', 'mcid' => 0,
      ]);
      $this->activistCodesMock->expects($this->once())
        ->method('setApiActivistCodeBatch')
        ->with([101, 102], 'NLPVoterAC');

      $this->buildService()->processTurf(5);
    }

    public function testProcessTurfUsesToIndexAsNameWhenTurfNameMissing(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 201]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([201]);
      $this->turfsMock->method('getTurf')->willReturn(['county' => 'Pierce', 'mcid' => 0]);
      $this->activistCodesMock->expects($this->once())->method('setApiActivistCodeBatch');

      $this->buildService()->processTurf(7);
    }

    public function testProcessTurfIncludesCoordinatorNameInLog(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 301]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([301]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Oak Ave', 'county' => 'Snohomish', 'mcid' => 42,
      ]);
      $this->nlsMock->method('getNlById')->with(42)->willReturn(['firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([
        'firstName' => 'Coord', 'lastName' => 'Smith',
      ]);

      $this->activistCodesMock->expects($this->once())->method('setApiActivistCodeBatch');

      $this->buildService()->processTurf(3);
    }

    public function testProcessTurfUsesEmptyCoordinatorNameWhenNlMissing(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 401]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([401]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Pine Rd', 'county' => 'Clark', 'mcid' => 99,
      ]);
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->coordinatorsMock->expects($this->never())->method('getAssignedCoordinator');

      $this->activistCodesMock->expects($this->once())->method('setApiActivistCodeBatch');

      $this->buildService()->processTurf(4);
    }

    public function testProcessTurfUsesEmptyCoordinatorNameWhenNoCoordinatorAssigned(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 501]]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([501]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Maple Dr', 'county' => 'Adams', 'mcid' => 55,
      ]);
      $this->nlsMock->method('getNlById')->willReturn(['firstName' => 'Bob', 'lastName' => 'Lee']);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);

      $this->activistCodesMock->expects($this->once())->method('setApiActivistCodeBatch');

      $this->buildService()->processTurf(6);
    }

    // -------------------------------------------------------------------------
    // processBatch()
    // -------------------------------------------------------------------------

    public function testProcessBatchInitializesSandboxOnFirstCall(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T1', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [1]], $context);

      $this->assertTrue($context['sandbox']['initialized']);
    }

    public function testProcessBatchInitializesResultsOnFirstCall(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T1', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [1]], $context);

      $this->assertSame(0, $context['results']['votersChecked']);
      $this->assertSame(0, $context['results']['votersMissingAc']);
      $this->assertSame([], $context['results']['missingAcRecords']);
    }

    public function testProcessBatchSetsFinishedWhenAllTurfsProcessed(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T1', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = ['sandbox' => ['initialized' => TRUE, 'currentTurf' => 1]];
      $this->buildService()->processBatch(['turfIndexes' => [1]], $context);

      $this->assertSame(1, $context['finished']);
    }

    public function testProcessBatchCountsVotersChecked(): void {
      $voters = [['vanid' => 101], ['vanid' => 102], ['vanid' => 103]];
      $this->votersMock->method('getVotersInTurf')->willReturn($voters);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T1', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [1]], $context);

      $this->assertSame(3, $context['results']['votersChecked']);
    }

    public function testProcessBatchRecordsMissingVanids(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 101], ['vanid' => 102]]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Elm St', 'county' => 'King', 'mcid' => 0,
      ]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([101, 102]);
      $this->activistCodesMock->expects($this->once())->method('setApiActivistCodeBatch')->with([101, 102], 'NLPVoterAC');

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [5]], $context);

      $this->assertSame(2, $context['results']['votersMissingAc']);
      $this->assertCount(2, $context['results']['missingAcRecords']);
      $this->assertSame('Elm St', $context['results']['missingAcRecords'][0]['turfName']);
      $this->assertSame('King', $context['results']['missingAcRecords'][0]['county']);
    }

    public function testProcessBatchSkipsApplyingCodesInReportOnlyMode(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 201]]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Oak', 'county' => 'Pierce', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([201]);
      $this->activistCodesMock->expects($this->never())->method('setApiActivistCodeBatch');

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [3], 'reportOnly' => TRUE], $context);

      $this->assertTrue($context['results']['reportOnly']);
    }

    public function testProcessBatchStopsEarlyWhenStopAtLimitReached(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 301]]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Pine', 'county' => 'Adams', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([301]);

      $context = [
        'results' => [
          'votersChecked'    => 0,
          'votersMissingAc'  => 100,
          'turfsProcessed'   => 5,
          'missingAcRecords' => [],
          'reportOnly'       => FALSE,
        ],
      ];
      $this->buildService()->processBatch(['turfIndexes' => [1, 2], 'stopAtLimit' => TRUE], $context);

      $this->assertSame(1, $context['finished']);
    }

    public function testProcessBatchIncrementsTurfsProcessed(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [1]], $context);

      $this->assertSame(1, $context['results']['turfsProcessed']);
    }

    public function testProcessBatchProgressFractionReflectsCompletion(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'T', 'county' => 'King', 'mcid' => 0]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [1, 2, 3, 4]], $context);

      $this->assertEqualsWithDelta(0.25, $context['finished'], 0.001);
    }

    public function testProcessBatchIncludesCoordinatorNameInMissingAcRecord(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([['vanid' => 401]]);
      $this->turfsMock->method('getTurf')->willReturn([
        'turfName' => 'Cedar', 'county' => 'Thurston', 'mcid' => 77,
      ]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([401]);
      $this->nlsMock->method('getNlById')->with(77)->willReturn(['firstName' => 'Lee', 'lastName' => 'Grant']);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([
        'firstName' => 'Coord', 'lastName' => 'Jones',
      ]);

      $context = [];
      $this->buildService()->processBatch(['turfIndexes' => [9]], $context);

      $this->assertSame('Coord Jones', $context['results']['missingAcRecords'][0]['coordinator']);
    }

    // -------------------------------------------------------------------------
    // processTurf() history and counts
    // -------------------------------------------------------------------------

    /**
     * Puts a state mock backed by $this->state in the container.
     */
    private function setStateMock(): void {
      $stateMock = $this->createMock(StateInterface::class);
      $stateMock->method('get')
        ->willReturnCallback(fn(string $key, $default = NULL) => $this->state[$key] ?? $default);
      $stateMock->method('set')
        ->willReturnCallback(function (string $key, $value) {
          $this->state[$key] = $value;
        });
      \Drupal::getContainer()->set('state', $stateMock);
    }

    private array $state = [];

    public function testProcessTurfRecordsMissingVotersAndLogsTheirHistory(): void {
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => 101, 102 => 102]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Elm Street', 'county' => 'King', 'mcid' => 0]);
      $historyMock = $this->createMock(NlpAcHistory::class);
      $historyMock->method('describe')->willReturn('102: Remove from turf delete (turfIndex 3) at 10:02:15');
      $historyMock->expects($this->once())->method('record')
        ->with([102], NlpAcHistory::MISSING, 'voter AC check of Elm Street (turfIndex 5)');
      \Drupal::getContainer()->set('nlpservices.ac_history', $historyMock);
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerMock->expects($this->once())->method('notice')
        ->with($this->anything(), $this->callback(fn(array $context) =>
          str_contains($context['@history'], 'turf delete (turfIndex 3)')));
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      \Drupal::getContainer()->set('logger.factory', $loggerFactoryMock);

      $this->buildService()->processTurf(5);
    }

    public function testProcessTurfAddsToTheCountsForThePass(): void {
      $this->setStateMock();
      $this->state[NlpVoterAcProcessor::CHECK_STATE] = ['cursor' => 4, 'passStart' => 1700000000,
        'turfs' => 2, 'voters' => 100, 'missing' => 1];
      $this->votersMock->method('getVotersInTurf')->willReturn([101 => 101, 102 => 102, 103 => 103]);
      $this->activistCodesMock->method('hasApiActivistCodeBatch')->willReturn([102]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Elm Street', 'county' => 'King', 'mcid' => 0]);

      $this->buildService()->processTurf(5);

      $this->assertEquals(['cursor' => 4, 'passStart' => 1700000000, 'passEnd' => 0, 'turfs' => 3, 'voters' => 103, 'missing' => 2],
        $this->state[NlpVoterAcProcessor::CHECK_STATE]);
    }

    // -------------------------------------------------------------------------
    // queueNextTurfs()
    // -------------------------------------------------------------------------

    /**
     * Puts a queue mock in the container and returns the items it is given.
     */
    private function setQueueMock(array &$items): void {
      $queueMock = $this->createMock(QueueInterface::class);
      $queueMock->method('createItem')
        ->willReturnCallback(function ($item) use (&$items) {
          $items[] = $item;
          return count($items);
        });
      $queueFactoryMock = $this->createMock(QueueFactory::class);
      $queueFactoryMock->method('get')->with('nlpservices_voter_ac_queue')->willReturn($queueMock);
      \Drupal::getContainer()->set('queue', $queueFactoryMock);
    }

    public function testQueueNextTurfsStartsTheFirstPass(): void {
      $this->setStateMock();
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->expects($this->once())->method('getTurfIndexesAfter')
        ->with(0, NlpVoterAcProcessor::TURFS_PER_SLICE)->willReturn([3, 5]);

      $this->assertSame(2, $this->buildService()->queueNextTurfs());

      $this->assertSame([['turfIndex' => 3], ['turfIndex' => 5]], $items);
      $check = $this->state[NlpVoterAcProcessor::CHECK_STATE];
      $this->assertSame(5, $check['cursor']);
      $this->assertEqualsWithDelta(time(), $check['passStart'], 5);
    }

    public function testQueueNextTurfsGoesOnAfterTheLastTurfQueued(): void {
      $this->setStateMock();
      $this->state[NlpVoterAcProcessor::CHECK_STATE] = ['cursor' => 5, 'passStart' => 1700000000,
        'turfs' => 2, 'voters' => 100, 'missing' => 0];
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->method('getTurfIndexesAfter')->with(5)->willReturn([8]);

      $this->buildService()->queueNextTurfs();

      $this->assertSame([['turfIndex' => 8]], $items);
      $this->assertSame(8, $this->state[NlpVoterAcProcessor::CHECK_STATE]['cursor']);
      $this->assertSame(1700000000, $this->state[NlpVoterAcProcessor::CHECK_STATE]['passStart']);
    }

    public function testQueueNextTurfsAfterLastTurfLogsThePassAndQueuesNothing(): void {
      $this->setStateMock();
      $this->state[NlpVoterAcProcessor::CHECK_STATE] = ['cursor' => 9, 'passStart' => 1700000000,
        'turfs' => 3, 'voters' => 150, 'missing' => 4];
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->expects($this->once())->method('getTurfIndexesAfter')->with(9)->willReturn([]);
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerMock->expects($this->once())->method('notice')
        ->with($this->stringContains('finished a pass'), $this->callback(fn(array $context) =>
          $context['@turfs'] === 3 && $context['@voters'] === 150 && $context['@missing'] === 4));
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      \Drupal::getContainer()->set('logger.factory', $loggerFactoryMock);

      $this->assertSame(0, $this->buildService()->queueNextTurfs());

      $this->assertSame([], $items);
      $check = $this->state[NlpVoterAcProcessor::CHECK_STATE];
      $this->assertSame(9, $check['cursor']);
      $this->assertSame(3, $check['turfs']);
      $this->assertEqualsWithDelta(time(), $check['passEnd'], 5);
    }

    public function testQueueNextTurfsAfterAPassFinishedTodayQueuesNothing(): void {
      $this->setStateMock();
      $this->state[NlpVoterAcProcessor::CHECK_STATE] = ['cursor' => 9, 'passStart' => 1700000000,
        'passEnd' => time(), 'turfs' => 3, 'voters' => 150, 'missing' => 4];
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->expects($this->never())->method('getTurfIndexesAfter');

      $this->assertSame(0, $this->buildService()->queueNextTurfs());

      $this->assertSame([], $items);
      $this->assertSame(9, $this->state[NlpVoterAcProcessor::CHECK_STATE]['cursor']);
    }

    public function testQueueNextTurfsAfterAPassFinishedOnAnEarlierDayStartsAgain(): void {
      $this->setStateMock();
      $this->state[NlpVoterAcProcessor::CHECK_STATE] = ['cursor' => 9, 'passStart' => 1700000000,
        'passEnd' => time() - 86400, 'turfs' => 3, 'voters' => 150, 'missing' => 4];
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->expects($this->once())->method('getTurfIndexesAfter')
        ->with(0, NlpVoterAcProcessor::TURFS_PER_SLICE)->willReturn([3, 5, 9]);

      $this->assertSame(3, $this->buildService()->queueNextTurfs());

      $this->assertSame([['turfIndex' => 3], ['turfIndex' => 5], ['turfIndex' => 9]], $items);
      $check = $this->state[NlpVoterAcProcessor::CHECK_STATE];
      $this->assertSame(9, $check['cursor']);
      $this->assertSame(0, $check['passEnd']);
      $this->assertSame(0, $check['turfs']);
      $this->assertSame(0, $check['missing']);
      $this->assertEqualsWithDelta(time(), $check['passStart'], 5);
    }

    public function testQueueNextTurfsWithNoTurfsQueuesNothing(): void {
      $this->setStateMock();
      $items = [];
      $this->setQueueMock($items);
      $this->turfsMock->method('getTurfIndexesAfter')->willReturn([]);

      $this->assertSame(0, $this->buildService()->queueNextTurfs());
      $this->assertSame([], $items);
    }

  }

}
