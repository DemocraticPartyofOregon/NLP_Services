<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpSyncTurfAddVoter;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpSyncTurfAddVoterTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private ApiActivistCodes $activistCodesMock;
    private NlpConfiguration $configurationMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock = $this->createMock(NlpVoters::class);
      $this->reportsMock = $this->createMock(NlpReports::class);
      $this->activistCodesMock = $this->createMock(ApiActivistCodes::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
    }

    private function buildService(): NlpSyncTurfAddVoter {
      return new NlpSyncTurfAddVoter(
        $this->votersMock,
        $this->reportsMock,
        $this->activistCodesMock,
        $this->configurationMock,
      );
    }

    private function baseVoter(int $vanid = 10001, bool $hostile = FALSE): array {
      return [
        'vanid' => $vanid,
        'activistCodes' => $hostile ? ['hostile' => TRUE] : [],
        'address' => ['street' => '123 Main St', 'city' => 'Portland'],
      ];
    }

    private function setupDefaultReloadMocks(): void {
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.voters', $this->votersMock);
      $container->set('nlpservices.reports', $this->reportsMock);
      $container->set('nlpservices.activist_codes', $this->activistCodesMock);
      $container->set('nlpservices.configuration', $this->configurationMock);

      $service = NlpSyncTurfAddVoter::create($container);

      $this->assertInstanceOf(NlpSyncTurfAddVoter::class, $service);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — early exit
    // -------------------------------------------------------------------------

    public function testSyncAddVoterDoesNothingWhenVanidMissing(): void {
      $this->votersMock->expects($this->never())->method('getVoterStatus');
      $this->votersMock->expects($this->never())->method('createVoter');

      $this->buildService()->syncAddVoter('Multnomah', ['activistCodes' => [], 'address' => []], 1, 42);
    }

    public function testSyncAddVoterDoesNothingWhenVanidIsZero(): void {
      $this->votersMock->expects($this->never())->method('getVoterStatus');

      $this->buildService()->syncAddVoter('Multnomah', ['vanid' => 0, 'activistCodes' => [], 'address' => []], 1, 42);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — voter status: new voter (no existing record)
    // -------------------------------------------------------------------------

    public function testSyncAddVoterSetsVanidAndCycleWhenNoExistingStatus(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn([]);
      $this->setupDefaultReloadMocks();

      $capturedStatus = NULL;
      $this->votersMock->method('setVoterStatus')
        ->willReturnCallback(function ($vanid, $status) use (&$capturedStatus): bool {
          $capturedStatus = $status;
          return TRUE;
        });
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);

      $this->assertSame(10001, $capturedStatus['vanid']);
      $this->assertSame('2024', $capturedStatus['cycle']);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — voter status: stale cycle cleared
    // -------------------------------------------------------------------------

    public function testSyncAddVoterClearsStaleStatusWhenCycleMismatch(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')
        ->willReturn(['vanid' => 10001, 'cycle' => '2022', 'pledgedToVote' => 1]);
      $this->votersMock->method('nullVoterStatus')
        ->willReturn(['vanid' => NULL, 'cycle' => '2024', 'pledgedToVote' => NULL]);
      $this->setupDefaultReloadMocks();

      $capturedStatus = NULL;
      $this->votersMock->method('setVoterStatus')
        ->willReturnCallback(function ($vanid, $status) use (&$capturedStatus): bool {
          $capturedStatus = $status;
          return TRUE;
        });
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);

      $this->assertSame(10001, $capturedStatus['vanid']);
      $this->assertSame('2024', $capturedStatus['cycle']);
      $this->assertNull($capturedStatus['pledgedToVote']); // cleared by nullVoterStatus
    }

    public function testSyncAddVoterCallsNullVoterStatusOnStaleCycle(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')
        ->willReturn(['vanid' => 10001, 'cycle' => '2022']);
      $this->votersMock->expects($this->once())->method('nullVoterStatus')
        ->willReturn(['cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testSyncAddVoterDoesNotClearStatusWhenCycleMatches(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')
        ->willReturn(['vanid' => 10001, 'cycle' => '2024', 'pledgedToVote' => 1]);
      $this->votersMock->expects($this->never())->method('nullVoterStatus');
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — hostile flag
    // -------------------------------------------------------------------------

    public function testSyncAddVoterSetsHostileFlagWhenPresent(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();

      $capturedStatus = NULL;
      $this->votersMock->method('setVoterStatus')
        ->willReturnCallback(function ($vanid, $status) use (&$capturedStatus): bool {
          $capturedStatus = $status;
          return TRUE;
        });
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001, TRUE), 5, 42);

      $this->assertSame(1, $capturedStatus['hostile']);
    }

    public function testSyncAddVoterClearsHostileFlagWhenNotPresent(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();

      $capturedStatus = NULL;
      $this->votersMock->method('setVoterStatus')
        ->willReturnCallback(function ($vanid, $status) use (&$capturedStatus): bool {
          $capturedStatus = $status;
          return TRUE;
        });
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001, FALSE), 5, 42);

      $this->assertSame(0, $capturedStatus['hostile']);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — activist code request
    // -------------------------------------------------------------------------

    public function testSyncAddVoterSendsCorrectActivistCodeRequest(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->activistCodesMock->expects($this->once())
        ->method('setApiActivistCode')
        ->with([
          'vanid' => 10001,
          'codeName' => 'NLPVoterAC',
          'action' => 'Apply',
        ]);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — voter address mutations
    // -------------------------------------------------------------------------

    public function testSyncAddVoterSetsTurfIndexOnVoterAddress(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $capturedVoter = NULL;
      $this->votersMock->method('createVoter')
        ->willReturnCallback(function ($voter) use (&$capturedVoter): bool {
          $capturedVoter = $voter;
          return TRUE;
        });

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 7, 42);

      $this->assertSame(7, $capturedVoter['address']['turfIndex']);
      $this->assertNull($capturedVoter['address']['moved']);
    }

    // -------------------------------------------------------------------------
    // syncAddVoter() — persistence calls
    // -------------------------------------------------------------------------

    public function testSyncAddVoterCallsCreateVoter(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->votersMock->expects($this->once())->method('createVoter')->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testSyncAddVoterCallsSetVoterStatus(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);

      $this->votersMock->expects($this->once())
        ->method('setVoterStatus')
        ->with(10001, $this->anything())
        ->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testSyncAddVoterCallsCreateTurfVoterWithCorrectData(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->setupDefaultReloadMocks();
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);

      $this->votersMock->expects($this->once())
        ->method('createTurfVoter')
        ->with([
          'mcid' => 42,
          'county' => 'Multnomah',
          'turfIndex' => 5,
          'vanid' => 10001,
        ])
        ->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    // -------------------------------------------------------------------------
    // reloadVoterStatus() — survey / contact report aggregation
    // -------------------------------------------------------------------------

    public function testReloadVoterStatusPicksMaxSurveyReportIndex(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->reportsMock->method('getNlpTypeReports')
        ->willReturnCallback(function ($vanid, $type) {
          if($type === 'Survey') {
            return [
              ['reportIndex' => 1],
              ['reportIndex' => 3],
              ['reportIndex' => 2],
            ];
          }
          return [];
        });

      $capturedTurfVoter = NULL;
      $this->votersMock->method('updateTurfVoter')
        ->willReturnCallback(function ($turfVoter) use (&$capturedTurfVoter): bool {
          $capturedTurfVoter = $turfVoter;
          return TRUE;
        });

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);

      $this->assertSame(3, $capturedTurfVoter['pledgedToVote']);
    }

    public function testReloadVoterStatusPicksMaxContactReportIndex(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->reportsMock->method('getNlpTypeReports')
        ->willReturnCallback(function ($vanid, $type) {
          if($type === 'contact') {
            return [
              ['reportIndex' => 4],
              ['reportIndex' => 2],
            ];
          }
          return [];
        });

      $capturedTurfVoter = NULL;
      $this->votersMock->method('updateTurfVoter')
        ->willReturnCallback(function ($turfVoter) use (&$capturedTurfVoter): bool {
          $capturedTurfVoter = $turfVoter;
          return TRUE;
        });

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);

      $this->assertSame(4, $capturedTurfVoter['attemptedContact']);
    }

    public function testReloadVoterStatusSetsZeroWhenNoSurveyOrContactReports(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $capturedTurfVoter = NULL;
      $this->votersMock->method('updateTurfVoter')
        ->willReturnCallback(function ($turfVoter) use (&$capturedTurfVoter): bool {
          $capturedTurfVoter = $turfVoter;
          return TRUE;
        });

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);

      $this->assertSame(0, $capturedTurfVoter['pledgedToVote']);
      $this->assertSame(0, $capturedTurfVoter['attemptedContact']);
    }

    public function testReloadVoterStatusSetsCorrectTurfIndexOnUpdateTurfVoter(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $capturedTurfVoter = NULL;
      $this->votersMock->method('updateTurfVoter')
        ->willReturnCallback(function ($turfVoter) use (&$capturedTurfVoter): bool {
          $capturedTurfVoter = $turfVoter;
          return TRUE;
        });

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 9, 42);

      $this->assertSame(9, $capturedTurfVoter['turfIndex']);
    }

    // -------------------------------------------------------------------------
    // reloadVoterStatus() — moved report
    // -------------------------------------------------------------------------

    public function testReloadVoterStatusCallsSetMovedStatusWhenAddressMatches(): void {
      $address = ['street' => '123 Main St', 'city' => 'Portland'];
      $voter = [
        'vanid' => 10001,
        'activistCodes' => [],
        'address' => $address,
      ];

      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->reportsMock->method('getReport')
        ->willReturn(['text' => json_encode($address)]);
      $this->votersMock->method('addressCompare')->willReturn(TRUE);

      $this->votersMock->expects($this->once())
        ->method('setMovedStatus')
        ->with(5, 10001, 1);

      $this->buildService()->syncAddVoter('Multnomah', $voter, 5, 42);
    }

    public function testReloadVoterStatusDoesNotCallSetMovedStatusWhenAddressDoesNotMatch(): void {
      $voter = [
        'vanid' => 10001,
        'activistCodes' => [],
        'address' => ['street' => '123 Main St', 'city' => 'Portland'],
      ];

      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->reportsMock->method('getReport')
        ->willReturn(['text' => json_encode(['street' => '999 Other St', 'city' => 'Portland'])]);
      $this->votersMock->method('addressCompare')->willReturn(FALSE);

      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $this->buildService()->syncAddVoter('Multnomah', $voter, 5, 42);
    }

    public function testReloadVoterStatusDoesNotCallSetMovedStatusWhenMovedReportEmpty(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testReloadVoterStatusDoesNotCallSetMovedStatusWhenMovedReportHasInvalidJson(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn(['text' => 'not-valid-json{{{']);
      $this->reportsMock->method('getComment')->willReturn([]);

      // addressCompare is called with an empty array as existingAddress (invalid json falls back to [])
      $this->votersMock->method('addressCompare')->willReturn(FALSE);
      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testReloadVoterStatusPassesCorrectMovedReportRequest(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->reportsMock->expects($this->once())
        ->method('getReport')
        ->with([
          'vanid' => 10001,
          'type' => 'contact',
          'value' => 'moved',
          'cycle' => NULL,
        ])
        ->willReturn([]);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    // -------------------------------------------------------------------------
    // reloadVoterStatus() — comment / note
    // -------------------------------------------------------------------------

    public function testReloadVoterStatusUpdatesNoteWithCommentText(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')
        ->willReturn(['text' => 'Voter note here', 'reportIndex' => 7]);

      $this->votersMock->expects($this->once())
        ->method('updateTurfNote')
        ->with(5, 10001, 'Voter note here', 7, 0)
        ->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testReloadVoterStatusUpdatesNoteWithEmptyStringWhenNoComment(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->method('getComment')->willReturn([]);

      $this->votersMock->expects($this->once())
        ->method('updateTurfNote')
        ->with(5, 10001, '', 0, 0)
        ->willReturn(TRUE);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

    public function testReloadVoterStatusPassesCorrectCommentRequest(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->votersMock->method('getVoterStatus')->willReturn(['vanid' => 10001, 'cycle' => '2024']);
      $this->votersMock->method('createVoter')->willReturn(TRUE);
      $this->votersMock->method('setVoterStatus')->willReturn(TRUE);
      $this->votersMock->method('createTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);
      $this->votersMock->method('updateTurfNote')->willReturn(TRUE);
      $this->reportsMock->method('getNlpTypeReports')->willReturn([]);
      $this->reportsMock->method('getReport')->willReturn([]);

      $this->reportsMock->expects($this->once())
        ->method('getComment')
        ->with(['vanid' => 10001, 'mcid' => 42])
        ->willReturn([]);

      $this->buildService()->syncAddVoter('Multnomah', $this->baseVoter(10001), 5, 42);
    }

  }

}
