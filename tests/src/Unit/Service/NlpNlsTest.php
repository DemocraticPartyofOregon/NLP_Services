<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\ImmutableConfig;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Condition;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\Core\Lock\LockBackendInterface;
  use Drupal\Core\State\StateInterface;
  use Drupal\nlpservices\NlpNls;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpNlsTest extends UnitTestCase {

    private Connection $connectionMock;
    private DatabaseFunctions $dbFunctionsMock;
    private ConfigFactoryInterface $configFactoryMock;
    private ImmutableConfig $nlpConfigMock;
    private array $surveyQuestions = [];
    private StateInterface $stateMock;
    /** Values held by the state mock, keyed by state key. */
    private array $stateValues = [];
    private LockBackendInterface $lockMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->dbFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->lockMock = $this->createMock(LockBackendInterface::class);
      $this->stateMock = $this->createMock(StateInterface::class);
      $this->stateMock->method('get')
        ->willReturnCallback(fn(string $key) => $this->stateValues[$key] ?? NULL);
      $this->stateMock->method('set')
        ->willReturnCallback(function (string $key, $value) { $this->stateValues[$key] = $value; });
      $this->stateMock->method('delete')
        ->willReturnCallback(function (string $key) { unset($this->stateValues[$key]); });
      $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $this->nlpConfigMock = $this->createMock(ImmutableConfig::class);

      $this->configFactoryMock->method('get')
        ->with('nlpservices.configuration')
        ->willReturn($this->nlpConfigMock);

      $this->nlpConfigMock->method('get')
        ->willReturnCallback(function (string $key) {
          if ($key === 'nlpservices_survey_questions') {
            return $this->surveyQuestions;
          }
          return null;
        });

      $container = new ContainerBuilder();
      $container->set('config.factory', $this->configFactoryMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpNls {
      return new NlpNls($this->connectionMock, $this->dbFunctionsMock, $this->configFactoryMock,
        $this->stateMock, $this->lockMock);
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    private function buildInsertMock(): Insert {
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('execute')->willReturn(1);
      return $insertMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function buildUpdateMock(): Update {
      $updateMock = $this->createMock(Update::class);
      $updateMock->method('fields')->willReturnSelf();
      $updateMock->method('condition')->willReturnSelf();
      $updateMock->method('execute')->willReturn(1);
      return $updateMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('addExpression')->willReturn('alias');
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('join')->willReturn('alias');
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testConstructorUsesDefaultAskOptionsWhenConfigMissingAskList(): void {
      $this->surveyQuestions = [];

      $service = $this->buildService();

      $this->assertSame(NlpNls::DASH, $service->askList['dash']);
      $this->assertSame(NlpNls::ASKED, $service->askList['asked']);
    }

    public function testConstructorUsesCustomAskListFromConfig(): void {
      $this->surveyQuestions = ['askList' => ['custom' => 'Custom Option']];

      $service = $this->buildService();

      $this->assertSame(['custom' => 'Custom Option'], $service->askList);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesDatabaseServiceFromContainer(): void {
      $container = new ContainerBuilder();
      $container->set('database', $this->connectionMock);
      $container->set('nlpservices.database_functions', $this->dbFunctionsMock);
      $container->set('config.factory', $this->configFactoryMock);
      $container->set('state', $this->stateMock);
      $container->set('lock', $this->lockMock);

      $service = NlpNls::create($container);

      $this->assertInstanceOf(NlpNls::class, $service);
    }

    // -------------------------------------------------------------------------
    // deleteNls()
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Active NL sync marks
    // -------------------------------------------------------------------------

    public function testStartActiveNlsSyncClaimsCountyAndReleasesLock(): void {
      $this->lockMock->method('acquire')->willReturn(TRUE);
      $this->lockMock->expects($this->once())->method('release')
        ->with(NlpNls::ACTIVE_NLS_SYNC_KEY . 'Denver');
      $service = $this->buildService();

      $this->assertTrue($service->startActiveNlsSync('Denver'));
      $this->assertTrue($service->activeNlsSyncInProgress('Denver'));
    }

    public function testStartActiveNlsSyncRefusedWhileCountyInProgress(): void {
      $this->lockMock->method('acquire')->willReturn(TRUE);
      $service = $this->buildService();
      $service->startActiveNlsSync('Denver');

      $this->assertFalse($service->startActiveNlsSync('Denver'));
      $this->assertTrue($service->startActiveNlsSync('Boulder'));
    }

    public function testStartActiveNlsSyncAllowedAfterStaleMark(): void {
      $this->lockMock->method('acquire')->willReturn(TRUE);
      $this->stateValues[NlpNls::ACTIVE_NLS_SYNC_KEY . 'Denver']
        = time() - NlpNls::ACTIVE_NLS_SYNC_TIMEOUT_SECS;

      $this->assertTrue($this->buildService()->startActiveNlsSync('Denver'));
    }

    public function testStartActiveNlsSyncRefusedWhenLockHeld(): void {
      $this->lockMock->method('acquire')->willReturn(FALSE);
      $this->lockMock->expects($this->never())->method('release');
      $service = $this->buildService();

      $this->assertFalse($service->startActiveNlsSync('Denver'));
      $this->assertFalse($service->activeNlsSyncInProgress('Denver'));
    }

    public function testActiveNlsSyncNotInProgressWithoutMark(): void {
      $this->assertFalse($this->buildService()->activeNlsSyncInProgress('Denver'));
    }

    public function testActiveNlsSyncInProgressAfterMark(): void {
      $service = $this->buildService();
      $service->markActiveNlsSync('Denver');
      $this->assertTrue($service->activeNlsSyncInProgress('Denver'));
    }

    public function testActiveNlsSyncMarkIsPerCounty(): void {
      $service = $this->buildService();
      $service->markActiveNlsSync('Denver');
      $this->assertFalse($service->activeNlsSyncInProgress('Boulder'));
    }

    public function testActiveNlsSyncNotInProgressAfterEnd(): void {
      $service = $this->buildService();
      $service->markActiveNlsSync('Denver');
      $service->endActiveNlsSync('Denver');
      $this->assertFalse($service->activeNlsSyncInProgress('Denver'));
    }

    public function testActiveNlsSyncStaleMarkIsIgnored(): void {
      $this->stateValues[NlpNls::ACTIVE_NLS_SYNC_KEY . 'Denver']
        = time() - NlpNls::ACTIVE_NLS_SYNC_TIMEOUT_SECS;
      $this->assertFalse($this->buildService()->activeNlsSyncInProgress('Denver'));
    }

    public function testDeleteNlsExecutesDeleteOnCounty(): void {
      $this->connectionMock->expects($this->once())->method('delete')->willReturn($this->buildDeleteMock());
      $this->buildService()->deleteNls('Denver');
    }

    public function testDeleteNlsSwallowsException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->buildService()->deleteNls('Denver');
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // deleteNl()
    // -------------------------------------------------------------------------

    public function testDeleteNlExecutesDeleteOnMcid(): void {
      $this->connectionMock->expects($this->once())->method('delete')->willReturn($this->buildDeleteMock());
      $this->buildService()->deleteNl(42);
    }

    public function testDeleteNlSwallowsException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->buildService()->deleteNl(42);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // createNl()
    // -------------------------------------------------------------------------

    public function testCreateNlRemovesZipFieldBeforeInsert(): void {
      $captured = [];
      $insertMock = $this->buildInsertMock();
      $insertMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$captured, $insertMock): Insert {
          $captured = $fields;
          return $insertMock;
        });

      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $this->connectionMock->method('insert')->willReturn($insertMock);

      $result = $this->buildService()->createNl([
        'mcid' => 42,
        'county' => 'Denver',
        'firstName' => 'Alice',
        'zip' => '80203',
      ]);

      $this->assertTrue($result);
      $this->assertArrayNotHasKey('zip', $captured);
      $this->assertSame(42, $captured['mcid']);
    }

    public function testCreateNlReturnsFalseOnInsertException(): void {
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->createNl(['mcid' => 42, 'county' => 'Denver']));
    }

    // -------------------------------------------------------------------------
    // getNlsStatus()
    // -------------------------------------------------------------------------

    public function testGetNlsStatusReturnsExistingRecordWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'mcid' => 42,
        'county' => 'Denver',
        'contact' => NlpNls::PHONE,
        'asked' => NlpNls::YES,
      ]);

      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlsStatus(42, 'Denver');

      $this->assertSame(42, $result['mcid']);
      $this->assertSame(NlpNls::PHONE, $result['contact']);
    }

    public function testGetNlsStatusBuildsDefaultRecordWhenMissing(): void {
      $statusStmt = $this->createMock(StatementInterface::class);
      $statusStmt->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($statusStmt));

      $this->dbFunctionsMock->method('getColumnNames')->willReturn(['mcid', 'county', 'contact', 'asked']);

      $result = $this->buildService()->getNlsStatus(42, 'Denver');

      $this->assertSame(42, $result['mcid']);
      $this->assertSame('Denver', $result['county']);
      $this->assertSame(NlpNls::CANVASS, $result['contact']);
      $this->assertSame(NlpNls::DASH, $result['asked']);
    }

    public function testGetNlsStatusReturnsEmptyArrayOnSelectException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getNlsStatus(42, 'Denver'));
    }

    public function testGetNlsStatusWithEmptyCountyReturnsFoundRecord(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['mcid' => 42, 'county' => '', 'contact' => NlpNls::CANVASS, 'asked' => NlpNls::DASH]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlsStatus(42);

      $this->assertSame(42, $result['mcid']);
    }

    public function testGetNlsStatusBuildsMinimalDefaultWhenColumnQueryThrows(): void {
      $statusStmt = $this->createMock(StatementInterface::class);
      $statusStmt->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($statusStmt));
      $this->dbFunctionsMock->method('getColumnNames')->willReturn([]);

      $result = $this->buildService()->getNlsStatus(42, 'Denver');

      $this->assertSame(42, $result['mcid']);
      $this->assertSame('Denver', $result['county']);
      $this->assertSame(NlpNls::CANVASS, $result['contact']);
      $this->assertSame(NlpNls::DASH, $result['asked']);
    }

    // -------------------------------------------------------------------------
    // setNlsStatus()
    // -------------------------------------------------------------------------

    public function testSetNlsStatusReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());

      $this->assertTrue($this->buildService()->setNlsStatus(['mcid' => 42, 'county' => 'Denver']));
    }

    public function testSetNlsStatusReturnsFalseOnException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->setNlsStatus(['mcid' => 42, 'county' => 'Denver']));
    }

    // -------------------------------------------------------------------------
    // deleteNlsStatus()
    // -------------------------------------------------------------------------

    public function testDeleteNlsStatusExecutesDeleteOnMcid(): void {
      $this->connectionMock->expects($this->once())->method('delete')->willReturn($this->buildDeleteMock());
      $this->buildService()->deleteNlsStatus(42);
    }

    public function testDeleteNlsStatusSwallowsException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->buildService()->deleteNlsStatus(42);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getNlsStatuses()
    // -------------------------------------------------------------------------

    public function testGetNlsStatusesReturnsEmptyArrayForNoMcids(): void {
      $this->connectionMock->expects($this->never())->method('select');
      $this->assertSame([], $this->buildService()->getNlsStatuses([], 'Denver'));
    }

    public function testGetNlsStatusesKeysRecordsByMcidAndFillsMissing(): void {
      $statusStmt = $this->createMock(StatementInterface::class);
      $statusStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver', 'contact' => NlpNls::PHONE, 'asked' => NlpNls::YES],
        false,
      );
      $this->connectionMock->expects($this->once())->method('select')
        ->willReturn($this->buildSelectMock($statusStmt));

      // The column names are read once and reused for every missing record.
      $this->dbFunctionsMock->expects($this->once())->method('getColumnNames')
        ->willReturn(['mcid', 'county', 'contact', 'asked']);

      $result = $this->buildService()->getNlsStatuses([42, 43, 44], 'Denver');

      $this->assertSame([42, 43, 44], array_keys($result));
      $this->assertSame(NlpNls::PHONE, $result[42]['contact']);
      $this->assertSame(43, $result[43]['mcid']);
      $this->assertSame(NlpNls::CANVASS, $result[43]['contact']);
      $this->assertSame(NlpNls::DASH, $result[44]['asked']);
    }

    public function testGetNlsStatusesReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlsStatuses([42], 'Denver'));
    }

    // -------------------------------------------------------------------------
    // getNls()
    // -------------------------------------------------------------------------

    public function testGetNlsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getNls('Denver', 'All'));
    }

    public function testGetNlsReturnsEmptyArrayWhenNoRows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getNls('Denver', 'All'));
    }

    public function testGetNlsReturnsRecordsWithStatusAttached(): void {
      $nlStmt = $this->createMock(StatementInterface::class);
      $nlStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver'],
        false,
      );
      $statusStmt = $this->createMock(StatementInterface::class);
      $statusStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver', 'asked' => NlpNls::DASH],
        false,
      );

      $this->connectionMock->method('select')->willReturnOnConsecutiveCalls(
        $this->buildSelectMock($nlStmt),
        $this->buildSelectMock($statusStmt),
      );

      $result = $this->buildService()->getNls('Denver', 'All');

      $this->assertCount(1, $result);
      $this->assertArrayHasKey(42, $result);
      $this->assertArrayHasKey('status', $result[42]);
      $this->assertSame(NlpNls::DASH, $result[42]['status']['asked']);
    }

    // -------------------------------------------------------------------------
    // getHdList()
    // -------------------------------------------------------------------------

    public function testGetHdListReturnsListOfHds(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['hd' => 10],
        ['hd' => 20],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([10, 20], $this->buildService()->getHdList('Denver'));
    }

    public function testGetHdListReturnsEmptyArrayWhenNoRows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getHdList('Denver'));
    }

    public function testGetHdListReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getHdList('Denver'));
    }

    // -------------------------------------------------------------------------
    // getPctList()
    // -------------------------------------------------------------------------

    public function testGetPctListReturnsList(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['precinct' => '001'],
        ['precinct' => '002'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(['001', '002'], $this->buildService()->getPctList('Denver', 10));
    }

    public function testGetPctListReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getPctList('Denver'));
    }

    // -------------------------------------------------------------------------
    // getNlById()
    // -------------------------------------------------------------------------

    public function testGetNlByIdReturnsRecordWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['mcid' => 42, 'county' => 'Denver', 'firstName' => 'Alice']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlById(42);

      $this->assertSame(42, $result['mcid']);
      $this->assertSame('Alice', $result['firstName']);
    }

    public function testGetNlByIdReturnsEmptyArrayWhenMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getNlById(42));
    }

    public function testGetNlByIdReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getNlById(42));
    }

    // -------------------------------------------------------------------------
    // getCountyNls()
    // -------------------------------------------------------------------------

    public function testGetCountyNlsReturnsRecordsKeyedByMcid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver'],
        ['mcid' => 99, 'county' => 'Denver'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCountyNls('Denver');

      $this->assertArrayHasKey(42, $result);
      $this->assertArrayHasKey(99, $result);
    }

    public function testGetCountyNlsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getCountyNls('Denver'));
    }

    // -------------------------------------------------------------------------
    // resultsReported()
    // -------------------------------------------------------------------------

    public function testResultsReportedReturnsTrueWhenAlreadyMarked(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['resultsReported' => 'Y']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->expects($this->never())->method('update');

      $this->assertTrue($this->buildService()->resultsReported(42, 'Denver'));
    }

    public function testResultsReportedUpdatesAndReturnsTrueWhenNotMarked(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('update')->willReturn($this->buildUpdateMock());

      $this->assertTrue($this->buildService()->resultsReported(42, 'Denver'));
    }

    public function testResultsReportedReturnsFalseWhenSelectThrows(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->resultsReported(42, 'Denver'));
    }

    public function testResultsReportedReturnsFalseWhenUpdateThrows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('update')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->resultsReported(42, 'Denver'));
    }

    // -------------------------------------------------------------------------
    // updateNlStatus()
    // -------------------------------------------------------------------------

    public function testUpdateNlStatusExecutesUpdate(): void {
      $this->connectionMock->expects($this->once())->method('update')->willReturn($this->buildUpdateMock());
      $this->buildService()->updateNlStatus(42, ['asked' => NlpNls::YES]);
    }

    public function testUpdateNlStatusSwallowsException(): void {
      $this->connectionMock->method('update')->willThrowException(new \Exception('DB error'));
      $this->buildService()->updateNlStatus(42, ['asked' => NlpNls::YES]);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // searchNls()
    // -------------------------------------------------------------------------

    private function buildSearchSelectMock(StatementInterface $stmtMock): Select {
      $condMock = $this->createMock(Condition::class);
      $condMock->method('condition')->willReturnSelf();

      $selectMock = $this->buildSelectMock($stmtMock);
      $selectMock->method('orConditionGroup')->willReturn($condMock);
      $selectMock->method('escapeLike')->willReturnArgument(0);
      return $selectMock;
    }

    public function testSearchNlsByCountyAttachesStatusToEachRecord(): void {
      $nlStmt = $this->createMock(StatementInterface::class);
      $nlStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver'],
        false,
      );
      $statusStmt = $this->createMock(StatementInterface::class);
      $statusStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'county' => 'Denver', 'asked' => NlpNls::DASH],
        false,
      );

      $this->connectionMock->method('select')->willReturnOnConsecutiveCalls(
        $this->buildSearchSelectMock($nlStmt),
        $this->buildSelectMock($statusStmt),
      );

      $result = $this->buildService()->searchNls('Alice', 'Denver');

      $this->assertArrayHasKey(42, $result);
      $this->assertArrayHasKey('status', $result[42]);
    }

    public function testSearchNlsByMcidsDoesNotAttachStatus(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42],
        false,
      );

      $this->connectionMock->method('select')->willReturn($this->buildSearchSelectMock($stmtMock));

      $result = $this->buildService()->searchNls('42', '', [42]);

      $this->assertArrayHasKey(42, $result);
      $this->assertArrayNotHasKey('status', $result[42]);
    }

    public function testSearchNlsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->searchNls('Alice', 'Denver'));
    }

    // -------------------------------------------------------------------------
    // getNlsByIds()
    // -------------------------------------------------------------------------

    public function testGetNlsByIdsReturnsEmptyArrayForEmptyInput(): void {
      $this->connectionMock->expects($this->never())->method('select');

      $this->assertSame([], $this->buildService()->getNlsByIds([]));
    }

    public function testGetNlsByIdsReturnsRecordsKeyedByMcid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42],
        ['mcid' => 99],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlsByIds([42, 99]);

      $this->assertArrayHasKey(42, $result);
      $this->assertArrayHasKey(99, $result);
    }

    public function testGetNlsByIdsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getNlsByIds([42]));
    }

    // -------------------------------------------------------------------------
    // getDistinctHdByMcids()
    // -------------------------------------------------------------------------

    public function testGetDistinctHdByMcidsReturnsEmptyArrayForEmptyInput(): void {
      $this->connectionMock->expects($this->never())->method('select');

      $this->assertSame([], $this->buildService()->getDistinctHdByMcids([]));
    }

    public function testGetDistinctHdByMcidsReturnsHdList(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['hd' => 10],
        ['hd' => 20],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([10, 20], $this->buildService()->getDistinctHdByMcids([42, 99]));
    }

    public function testGetDistinctHdByMcidsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getDistinctHdByMcids([42]));
    }

    // -------------------------------------------------------------------------
    // getDistinctPrecinctByMcids()
    // -------------------------------------------------------------------------

    public function testGetDistinctPrecinctByMcidsReturnsEmptyArrayForEmptyInput(): void {
      $this->connectionMock->expects($this->never())->method('select');

      $this->assertSame([], $this->buildService()->getDistinctPrecinctByMcids([]));
    }

    public function testGetDistinctPrecinctByMcidsReturnsList(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['precinct' => '001'],
        ['precinct' => '002'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(['001', '002'], $this->buildService()->getDistinctPrecinctByMcids([42, 99]));
    }

    public function testGetDistinctPrecinctByMcidsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getDistinctPrecinctByMcids([42]));
    }

    // -------------------------------------------------------------------------
    // getDistinctHdPrecinctArrayByMcids()
    // -------------------------------------------------------------------------

    public function testGetDistinctHdPrecinctArrayByMcidsReturnsEmptyArrayForEmptyInput(): void {
      $this->connectionMock->expects($this->never())->method('select');

      $this->assertSame([], $this->buildService()->getDistinctHdPrecinctArrayByMcids([]));
    }

    public function testGetDistinctHdPrecinctArrayByMcidsReturnsArrayKeyedByHdThenPrecinct(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['hd' => 10, 'precinct' => '001'],
        ['hd' => 10, 'precinct' => '002'],
        ['hd' => 20, 'precinct' => '003'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getDistinctHdPrecinctArrayByMcids([42, 99]);

      $this->assertSame([
        10 => ['001' => '001', '002' => '002'],
        20 => ['003' => '003'],
      ], $result);
    }

    public function testGetDistinctHdPrecinctArrayByMcidsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getDistinctHdPrecinctArrayByMcids([42]));
    }

    // -------------------------------------------------------------------------
    // getCountyNlsParticipation()
    // -------------------------------------------------------------------------

    public function testGetCountyNlsParticipationReturnsCountsArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['participatingNls' => 5, 'resultsReported' => 3]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCountyNlsParticipation('Denver');

      $this->assertSame(5, $result['participatingNls']);
      $this->assertSame(3, $result['resultsReported']);
    }

    public function testDecodeNoteUndoesRepeatedEncoding(): void {
      $this->assertSame("doesn't", NlpNls::decodeNote('doesn&amp;#039;t'));
      $this->assertSame("doesn't", NlpNls::decodeNote('doesn&#039;t'));
      $this->assertSame('', NlpNls::decodeNote(NULL));
    }

    public function testEncodeNoteKeepsShortNoteWhole(): void {
      $this->assertSame('Will mail &amp; call', NlpNls::encodeNote("Will mail & call"));
    }

    public function testEncodeNoteDoesNotEncodeTwice(): void {
      $this->assertSame('doesn&#039;t', NlpNls::encodeNote('doesn&#039;t'));
      $this->assertSame('doesn&#039;t', NlpNls::encodeNote('doesn&amp;#039;t'));
    }

    public function testEncodeNoteNormalizesLineEndings(): void {
      $this->assertSame("line one\nline two", NlpNls::encodeNote("line one\r\nline two"));
    }

    public function testEncodeNoteFitsColumnWhenEncodingGrowsTheText(): void {
      // 250 characters of apostrophes would encode to 1500 characters.
      $encoded = NlpNls::encodeNote(str_repeat("'", 300));
      $this->assertLessThanOrEqual(NlpNls::NOTES_COLUMN_LENGTH, mb_strlen($encoded));
      $this->assertStringEndsWith('...', $encoded);
    }

    public function testEncodeNoteFitsColumnForTheReportedNote(): void {
      $note = "11Sep26 Will mail , needs stamps. Confirmed email, prefers texts. Confirmed turf.17Oct NL doesn't live in the previous turf assigned to her. I changed it to a turf in her neighborhood GGK 2Nov text msg asking if help needed to enter contact activity, she's on it, it's done.";
      $encoded = NlpNls::encodeNote($note);
      $this->assertLessThanOrEqual(NlpNls::NOTES_COLUMN_LENGTH, mb_strlen($encoded));
      $this->assertStringEndsWith('...', $encoded);
      $this->assertStringStartsWith('11Sep26 Will mail', NlpNls::decodeNote($encoded));
    }

    public function testGetCountyNlsParticipationReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $this->assertSame([], $this->buildService()->getCountyNlsParticipation('Denver'));
    }

  }

}
