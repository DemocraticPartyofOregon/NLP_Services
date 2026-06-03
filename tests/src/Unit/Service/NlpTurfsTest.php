<?php

// nlp_debug_msg lives in nlpservices.module, which is not autoloaded in unit tests.
// Bracketed namespace syntax is required to declare a global stub alongside a named namespace.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiMapRegions;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpTurfsTest extends UnitTestCase {

    private Connection $connectionMock;
    private FileSystemInterface $fileSystemMock;
    private NlpNls $nlsMock;
    private NlpPaths $pathsMock;
    private NlpVoters $votersMock;
    private ApiActivistCodes $activistCodesMock;
    private NlpConfiguration $configurationMock;
    private NlpReports $reportsMock;
    private NlpMatchbacks $matchbacksMock;
    private ApiFolders $foldersMock;
    private ApiMapRegions $mapRegionsMock;
    private NlpTurfAssignments $turfAssignmentsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock      = $this->createMock(Connection::class);
      $this->fileSystemMock      = $this->createMock(FileSystemInterface::class);
      $this->nlsMock             = $this->createMock(NlpNls::class);
      $this->pathsMock           = $this->createMock(NlpPaths::class);
      $this->votersMock          = $this->createMock(NlpVoters::class);
      $this->activistCodesMock   = $this->createMock(ApiActivistCodes::class);
      $this->configurationMock   = $this->createMock(NlpConfiguration::class);
      $this->reportsMock         = $this->createMock(NlpReports::class);
      $this->matchbacksMock      = $this->createMock(NlpMatchbacks::class);
      $this->foldersMock         = $this->createMock(ApiFolders::class);
      $this->mapRegionsMock      = $this->createMock(ApiMapRegions::class);
      $this->turfAssignmentsMock = $this->createMock(NlpTurfAssignments::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildTurfs(): NlpTurfs {
      return new NlpTurfs(
        $this->connectionMock,
        $this->fileSystemMock,
        $this->nlsMock,
        $this->pathsMock,
        $this->votersMock,
        $this->activistCodesMock,
        $this->configurationMock,
        $this->reportsMock,
        $this->matchbacksMock,
        $this->foldersMock,
        $this->mapRegionsMock,
        $this->turfAssignmentsMock,
      );
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('join')->willReturn('alias');
      $selectMock->method('leftJoin')->willReturn('alias');
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('isNotNull')->willReturnSelf();
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
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

    private function baseTurf(): array {
      return [
        'turfIndex'    => 7,
        'mcid'         => 42,
        'county'       => 'Denver',
        'turfName'     => 'Denver-001',
        'turfHd'       => 6,
        'turfPrecinct' => '6.1',
        'listId'       => 999,
        'delivered'    => '',
        'syncDate'     => '2024-01-01',
      ];
    }

    // -------------------------------------------------------------------------
    // createTurfNames
    // -------------------------------------------------------------------------

    public function testCreateTurfNamesTwoTurfsReturnsNameArray(): void {
      $turfArray = [
        'turfs' => [
          7 => ['turfName' => 'Denver-001'],
          8 => ['turfName' => 'Denver-002'],
        ],
      ];
      $result = $this->buildTurfs()->createTurfNames($turfArray);
      $this->assertSame(['Denver-001', 'Denver-002'], array_values($result));
    }

    public function testCreateTurfNamesEmptyTurfsReturnsEmptyArray(): void {
      $result = $this->buildTurfs()->createTurfNames(['turfs' => []]);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // getCountyTurfs
    // -------------------------------------------------------------------------

    public function testGetCountyTurfsSpecificCountyReturnsKeyedArray(): void {
      $turf = $this->baseTurf();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getCountyTurfs('Denver');
      $this->assertArrayHasKey(7, $result);
      $this->assertSame('Denver', $result[7]['county']);
    }

    public function testGetCountyTurfsAllReturnsKeyedArray(): void {
      $turf = $this->baseTurf();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getCountyTurfs('all');
      $this->assertArrayHasKey(7, $result);
    }

    public function testGetCountyTurfsEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getCountyTurfs('Denver'));
    }

    public function testGetCountyTurfsExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getCountyTurfs('Denver'));
    }

    // -------------------------------------------------------------------------
    // getTurf
    // -------------------------------------------------------------------------

    public function testGetTurfFoundReturnsTurfArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseTurf());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurf(7);
      $this->assertSame(7, $result['turfIndex']);
    }

    public function testGetTurfNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getTurf(7));
    }

    public function testGetTurfExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurf(7));
    }

    // -------------------------------------------------------------------------
    // getListId
    // -------------------------------------------------------------------------

    public function testGetListIdFoundReturnsListId(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['listId' => 999]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(999, $this->buildTurfs()->getListId(7));
    }

    public function testGetListIdNotFoundReturnsZero(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(0, $this->buildTurfs()->getListId(7));
    }

    public function testGetListIdExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->getListId(7));
    }

    // -------------------------------------------------------------------------
    // getTurfByListId
    // -------------------------------------------------------------------------

    public function testGetTurfByListIdFoundReturnsTurf(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseTurf());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfByListId(999);
      $this->assertSame(999, $result['listId']);
    }

    public function testGetTurfByListIdNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getTurfByListId(999));
    }

    public function testGetTurfByListIdExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfByListId(999));
    }

    // -------------------------------------------------------------------------
    // getTurfHdNames
    // -------------------------------------------------------------------------

    public function testGetTurfHdNamesReturnsSortedAssocArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfHd' => 8], ['turfHd' => 6], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfHdNames('Denver');
      $this->assertSame([6 => 6, 8 => 8], $result);
    }

    public function testGetTurfHdNamesEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getTurfHdNames('Denver'));
    }

    public function testGetTurfHdNamesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfHdNames('Denver'));
    }

    // -------------------------------------------------------------------------
    // getTurfPrecinctNames
    // -------------------------------------------------------------------------

    public function testGetTurfPrecinctNamesReturnsSortedArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfPrecinct' => '6.2'], ['turfPrecinct' => '6.1'], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfPrecinctNames('Denver');
      $this->assertSame(['6.1' => '6.1', '6.2' => '6.2'], $result);
    }

    public function testGetTurfPrecinctNamesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfPrecinctNames('Denver', 6));
    }

    // -------------------------------------------------------------------------
    // getCountyNames
    // -------------------------------------------------------------------------

    public function testGetCountyNamesReturnsIndexedArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['county' => 'Denver'], ['county' => 'Boulder'], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getCountyNames();
      $this->assertSame(['Denver', 'Boulder'], $result);
    }

    public function testGetCountyNamesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getCountyNames());
    }

    // -------------------------------------------------------------------------
    // getSyncedCountyNames
    // -------------------------------------------------------------------------

    public function testGetSyncedCountyNamesReturnsIndexedArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['county' => 'Denver'], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getSyncedCountyNames();
      $this->assertSame(['Denver'], $result);
    }

    public function testGetSyncedCountyNamesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getSyncedCountyNames());
    }

    // -------------------------------------------------------------------------
    // turfExists
    // -------------------------------------------------------------------------

    public function testTurfExistsFoundReturnsStructuredArray(): void {
      $turf = $this->baseTurf();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->turfExists(42, 'Denver');
      $this->assertArrayHasKey('turfs', $result);
      $this->assertSame(1, $result['turfCnt']);
      $this->assertSame(7, $result['turfIndex']);
    }

    public function testTurfExistsNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->turfExists(42, 'Denver'));
    }

    public function testTurfExistsExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildTurfs()->turfExists(42, 'Denver'));
    }

    // -------------------------------------------------------------------------
    // unreportedChanges
    // -------------------------------------------------------------------------

    public function testUnreportedChangesTrueWhenSet(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['unreportedChanges' => TRUE]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertTrue($this->buildTurfs()->unreportedChanges(7));
    }

    public function testUnreportedChangesFalseWhenEmpty(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['unreportedChanges' => NULL]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertFalse($this->buildTurfs()->unreportedChanges(7));
    }

    public function testUnreportedChangesExceptionReturnsFalse(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertFalse($this->buildTurfs()->unreportedChanges(7));
    }

    // -------------------------------------------------------------------------
    // getChangeHistory
    // -------------------------------------------------------------------------

    public function testGetChangeHistoryValidJsonReturnsArray(): void {
      $history = [['action' => 'add', 'vanid' => 101]];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['changeHistory' => json_encode($history)]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame($history, $this->buildTurfs()->getChangeHistory(7));
    }

    public function testGetChangeHistoryEmptyFieldReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['changeHistory' => NULL]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getChangeHistory(7));
    }

    public function testGetChangeHistoryInvalidJsonReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['changeHistory' => 'not-json{']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getChangeHistory(7));
    }

    // -------------------------------------------------------------------------
    // setTurfDelivered
    // -------------------------------------------------------------------------

    public function testSetTurfDeliveredCallsMerge(): void {
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildTurfs()->setTurfDelivered(7);
      $this->assertTrue(TRUE);
    }

    public function testSetTurfDeliveredExceptionHandledGracefully(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->buildTurfs()->setTurfDelivered(7);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // createTurf
    // -------------------------------------------------------------------------

    public function testCreateTurfAddsSyncDateAndReturnsTurfIndex(): void {
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('execute')->willReturn('42');
      $this->connectionMock->method('insert')->willReturn($insertMock);
      $result = $this->buildTurfs()->createTurf(['turfName' => 'Denver-001', 'mcid' => 55]);
      $this->assertSame(42, $result);
    }

    public function testCreateTurfExceptionReturnsZero(): void {
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->createTurf(['turfName' => 'Denver-001']));
    }

    // -------------------------------------------------------------------------
    // updateTurf
    // -------------------------------------------------------------------------

    public function testUpdateTurfCallsUpdate(): void {
      $updateMock = $this->buildUpdateMock();
      $updateMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('update')->willReturn($updateMock);
      $this->buildTurfs()->updateTurf(7, 'listId', 500);
      $this->assertTrue(TRUE);
    }

    public function testUpdateTurfExceptionHandledGracefully(): void {
      $this->connectionMock->method('update')->willThrowException(new \Exception('DB error'));
      $this->buildTurfs()->updateTurf(7, 'listId', 500);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getTurfIndexes
    // -------------------------------------------------------------------------

    public function testGetTurfIndexesReturnsIndexedArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfIndex' => 7], ['turfIndex' => 8], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([7, 8], $this->buildTurfs()->getTurfIndexes('Denver'));
    }

    public function testGetTurfIndexesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfIndexes('Denver'));
    }

    // -------------------------------------------------------------------------
    // getMinivanInfo
    // -------------------------------------------------------------------------

    public function testGetMinivanInfoFoundReturnsArray(): void {
      $info = ['minivanNumber' => 12, 'minivanCreateDate' => '2024-10-01'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($info);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getMinivanInfo(7);
      $this->assertSame(12, $result['minivanNumber']);
    }

    public function testGetMinivanInfoNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getMinivanInfo(7));
    }

    // -------------------------------------------------------------------------
    // Helpers for count and delete queries
    // -------------------------------------------------------------------------

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    /**
     * Returns a Select mock whose countQuery()->execute()->fetchField() chain
     * returns the supplied $count.  Also supports orConditionGroup() chaining.
     */
    private function buildCountSelectMock(int $count): Select {
      $fetchMock = $this->createMock(StatementInterface::class);
      $fetchMock->method('fetchField')->willReturn((string) $count);
      $countQueryMock = $this->createMock(Select::class);
      $countQueryMock->method('execute')->willReturn($fetchMock);
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('join')->willReturn('alias');
      $selectMock->method('leftJoin')->willReturn('alias');
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('isNotNull')->willReturnSelf();
      $selectMock->method('orConditionGroup')->willReturnSelf();
      $selectMock->method('countQuery')->willReturn($countQueryMock);
      return $selectMock;
    }

    // -------------------------------------------------------------------------
    // setAllTurfsDelivered
    // -------------------------------------------------------------------------

    public function testSetAllTurfsDeliveredCallsSetTurfDeliveredForEachTurf(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfIndex' => 7], ['turfIndex' => 8], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->exactly(2))->method('execute')->willReturn(1);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildTurfs()->setAllTurfsDelivered(42, 'Denver');
      $this->assertTrue(TRUE);
    }

    public function testSetAllTurfsDeliveredExceptionHandledGracefully(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->buildTurfs()->setAllTurfsDelivered(42, 'Denver');
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getCountyTurfIndexes
    // -------------------------------------------------------------------------

    public function testGetCountyTurfIndexesReturnsStaleTurfWhenSyncBeforeUpdate(): void {
      $turf = ['turfIndex' => 7, 'mapRegionId' => 100, 'folderId' => 50, 'syncDate' => '2024-01-01'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->configurationMock->method('getVoterBuilderUpdateDate')->willReturn('2024-06-01');
      $this->mapRegionsMock->method('getMapRegionInfo')->willReturn(['dateRefreshed' => '2024-01-01']);
      $result = $this->buildTurfs()->getCountyTurfIndexes('Denver');
      $this->assertContains(7, $result);
    }

    public function testGetCountyTurfIndexesReturnsEmptyWhenAllUpToDate(): void {
      $turf = ['turfIndex' => 7, 'mapRegionId' => 100, 'folderId' => 50, 'syncDate' => '2025-12-01'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->configurationMock->method('getVoterBuilderUpdateDate')->willReturn('2024-06-01');
      $this->mapRegionsMock->method('getMapRegionInfo')->willReturn(['dateRefreshed' => '2024-01-01']);
      $result = $this->buildTurfs()->getCountyTurfIndexes('Denver');
      $this->assertSame([], $result);
    }

    public function testGetCountyTurfIndexesExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getCountyTurfIndexes('Denver'));
    }

    // -------------------------------------------------------------------------
    // getNlsWithTurfs
    // -------------------------------------------------------------------------

    public function testGetNlsWithTurfsReturnsMcidArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['mcid' => 10], ['mcid' => 20], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getNlsWithTurfs('Denver');
      $this->assertSame([10, 20], $result);
    }

    public function testGetNlsWithTurfsExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getNlsWithTurfs('Denver'));
    }

    // -------------------------------------------------------------------------
    // getCountyTurfCount
    // -------------------------------------------------------------------------

    public function testGetCountyTurfCountReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(5));
      $this->assertSame(5, $this->buildTurfs()->getCountyTurfCount('Denver'));
    }

    public function testGetCountyTurfCountWithHdReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(3));
      $this->assertSame(3, $this->buildTurfs()->getCountyTurfCount('Denver', 6));
    }

    public function testGetCountyTurfCountExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->getCountyTurfCount('Denver'));
    }

    // -------------------------------------------------------------------------
    // getCdTurfCount
    // -------------------------------------------------------------------------

    public function testGetCdTurfCountReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(7));
      $this->assertSame(7, $this->buildTurfs()->getCdTurfCount(3));
    }

    public function testGetCdTurfCountExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->getCdTurfCount(3));
    }

    // -------------------------------------------------------------------------
    // getTurfsById
    // -------------------------------------------------------------------------

    public function testGetTurfsByIdReturnsTurfArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls($this->baseTurf(), false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfsById(42);
      $this->assertCount(1, $result);
      $this->assertSame(7, $result[0]['turfIndex']);
    }

    public function testGetTurfsByIdEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getTurfsById(42));
    }

    public function testGetTurfsByIdExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfsById(42));
    }

    // -------------------------------------------------------------------------
    // updateTurfFields
    // -------------------------------------------------------------------------

    public function testUpdateTurfFieldsCallsUpdate(): void {
      $updateMock = $this->buildUpdateMock();
      $updateMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('update')->willReturn($updateMock);
      $this->buildTurfs()->updateTurfFields(7, ['unreportedChanges' => 0, 'syncDate' => '2024-01-01']);
      $this->assertTrue(TRUE);
    }

    public function testUpdateTurfFieldsExceptionHandledGracefully(): void {
      $this->connectionMock->method('update')->willThrowException(new \Exception('DB error'));
      $this->buildTurfs()->updateTurfFields(7, ['unreportedChanges' => 0]);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // getTurfHD
    // -------------------------------------------------------------------------

    public function testGetTurfHDReturnsIndexedValues(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfHd' => 8], ['turfHd' => 6], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfHD('Denver');
      $this->assertSame([6, 8], $result);
    }

    // -------------------------------------------------------------------------
    // getTurfs
    // -------------------------------------------------------------------------

    public function testGetTurfsAllHdReturnsAllTurfs(): void {
      $turf = $this->baseTurf();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfs('Denver', 'All');
      $this->assertArrayHasKey(7, $result);
    }

    public function testGetTurfsSpecificHdReturnsFilteredTurfs(): void {
      $turf = $this->baseTurf();
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfs('Denver', 6);
      $this->assertArrayHasKey(7, $result);
    }

    public function testGetTurfsExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfs('Denver', 'All'));
    }

    // -------------------------------------------------------------------------
    // removeTurf
    // -------------------------------------------------------------------------

    public function testRemoveTurfReturnsTrueOnSuccess(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['turfIndex' => 7, 'turfPDF' => '']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $result = $this->buildTurfs()->removeTurf(['turfIndex' => 7, 'county' => 'Denver']);
      $this->assertTrue($result);
    }

    public function testRemoveTurfReturnsFalseOnSelectException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $result = $this->buildTurfs()->removeTurf(['turfIndex' => 7, 'county' => 'Denver']);
      $this->assertFalse($result);
    }

    public function testRemoveTurfReturnsFalseOnDeleteException(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['turfIndex' => 7, 'turfPDF' => '']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $result = $this->buildTurfs()->removeTurf(['turfIndex' => 7, 'county' => 'Denver']);
      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // deleteTurf
    // -------------------------------------------------------------------------

    public function testDeleteTurfCallsRemoveTurfAndDeletesVoters(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      // select for removeTurf, then empty for fetchVotersByTurf
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['turfIndex' => 7, 'turfPDF' => ''], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $this->votersMock->expects($this->once())->method('deleteVotersInTurf')->with(7);
      $this->votersMock->expects($this->once())->method('deleteAddressesInTurf')->with(7);
      $this->nlsMock->method('getNlsStatus')->willReturn(['mcid' => 42, 'county' => 'Denver',
        'turfCut' => 'X', 'turfDelivered' => 'X']);
      $result = $this->buildTurfs()->deleteTurf(['turfIndex' => 7, 'county' => 'Denver', 'mcid' => 42]);
      $this->assertTrue($result);
    }

    public function testDeleteTurfReturnsFalseWhenRemoveTurfFails(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $result = $this->buildTurfs()->deleteTurf(['turfIndex' => 7, 'county' => 'Denver', 'mcid' => 42]);
      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // getTurfByTurfName
    // -------------------------------------------------------------------------

    public function testGetTurfByTurfNameFoundReturnsTurf(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseTurf());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->getTurfByTurfName(50, 'Denver-001');
      $this->assertSame(7, $result['turfIndex']);
    }

    public function testGetTurfByTurfNameNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildTurfs()->getTurfByTurfName(50, 'Denver-001'));
    }

    public function testGetTurfByTurfNameExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildTurfs()->getTurfByTurfName(50, 'Denver-001'));
    }

    // -------------------------------------------------------------------------
    // getStaleTurfs
    // -------------------------------------------------------------------------

    public function testGetStaleTurfsReturnsBatchedTurfArray(): void {
      $turf = ['turfIndex' => 7, 'county' => 'Denver'];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($turf, false);
      $selectMock = $this->buildSelectMock($stmtMock);
      $selectMock->method('orConditionGroup')->willReturnSelf();
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->configurationMock->method('getVoterBuilderUpdateDate')->willReturn('2024-06-01');
      $result = $this->buildTurfs()->getStaleTurfs();
      $this->assertCount(1, $result);
      $this->assertSame($turf, $result[0][0]);
    }

    public function testGetStaleTurfsExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->configurationMock->method('getVoterBuilderUpdateDate')->willReturn('2024-06-01');
      $this->assertSame([], $this->buildTurfs()->getStaleTurfs());
    }

    // -------------------------------------------------------------------------
    // checkTurfIdChange
    // -------------------------------------------------------------------------

    public function testCheckTurfIdChangeUpdatesTurfWhenListIdDiffers(): void {
      $this->turfAssignmentsMock->method('getTurfAssignmentByTurfName')->willReturn([
        'turfId' => 99,
      ]);
      $this->turfAssignmentsMock->expects($this->once())->method('fixTurfAssignment')->with(99, 100);

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(
        array_merge($this->baseTurf(), ['listId' => 88])
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('update')->willReturn($this->buildUpdateMock());

      $this->buildTurfs()->checkTurfIdChange(50, ['turfName' => 'Denver-001', 'turfId' => 100]);
    }

    public function testCheckTurfIdChangeDoesNothingWhenListIdMatches(): void {
      $this->turfAssignmentsMock->method('getTurfAssignmentByTurfName')->willReturn([]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(
        array_merge($this->baseTurf(), ['listId' => 100])
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->expects($this->never())->method('update');
      $this->buildTurfs()->checkTurfIdChange(50, ['turfName' => 'Denver-001', 'turfId' => 100]);
    }

    // -------------------------------------------------------------------------
    // turfInUse
    // -------------------------------------------------------------------------

    public function testTurfInUseFoundByListIdReturnsNlInfo(): void {
      $turf = array_merge($this->baseTurf(), ['nlFirstName' => 'Jane', 'nlLastName' => 'Doe']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($turf);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildTurfs()->turfInUse(50, 999, 'Denver-001');
      $this->assertSame('X', $result['turfAssigned']);
      $this->assertSame('Jane Doe', $result['nl']);
    }

    public function testTurfInUseNotFoundReturnsEmptyValues(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->turfAssignmentsMock->method('getTurfAssignment')->willReturn([]);
      $this->turfAssignmentsMock->method('getTurfAssignmentByTurfName')->willReturn([]);
      $result = $this->buildTurfs()->turfInUse(50, 999, 'Denver-001');
      $this->assertSame('', $result['turfAssigned']);
      $this->assertSame('', $result['nl']);
    }

    public function testTurfInUseFoundByPreviousAssignmentReturnsNlInfo(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->turfAssignmentsMock->method('getTurfAssignment')->willReturn([
        'mcid' => 42, 'firstName' => 'Bob', 'lastName' => 'Smith',
      ]);
      $this->nlsMock->method('getNlById')->willReturn(['mcid' => 42]);
      $result = $this->buildTurfs()->turfInUse(50, 999, 'Denver-001');
      $this->assertSame('Bob Smith', $result['nl']);
      $this->assertSame('', $result['turfAssigned']);
    }

    // -------------------------------------------------------------------------
    // getVoterCounts
    // -------------------------------------------------------------------------

    public function testGetVoterCountsCountyTypeReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(42));
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $result = $this->buildTurfs()->getVoterCounts('County', 'Denver', 'All');
      $this->assertSame(42, $result);
    }

    public function testGetVoterCountsNlTypeReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(10));
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $result = $this->buildTurfs()->getVoterCounts('NL', 55, 'All');
      $this->assertSame(10, $result);
    }

    public function testGetVoterCountsExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->getVoterCounts('County', 'Denver', 'All'));
    }

    // -------------------------------------------------------------------------
    // getCountyTurfVoterStats
    // -------------------------------------------------------------------------

    public function testGetCountyTurfVoterStatsReturnsAllSixStats(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->connectionMock->method('select')->willReturnOnConsecutiveCalls(
        $this->buildCountSelectMock(100),  // total
        $this->buildCountSelectMock(30),   // totalVoted
        $this->buildCountSelectMock(60),   // contacted
        $this->buildCountSelectMock(20),   // contactedVoted
        $this->buildCountSelectMock(40),   // surveyed
        $this->buildCountSelectMock(15),   // surveyedVoted
      );
      $stats = $this->buildTurfs()->getCountyTurfVoterStats('Denver');
      $this->assertSame(100, $stats['total']);
      $this->assertSame(30,  $stats['totalVoted']);
      $this->assertSame(60,  $stats['contacted']);
      $this->assertSame(20,  $stats['contactedVoted']);
      $this->assertSame(40,  $stats['surveyed']);
      $this->assertSame(15,  $stats['surveyedVoted']);
    }

    public function testGetCountyTurfVoterStatsExceptionReturnsZeroStats(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $stats = $this->buildTurfs()->getCountyTurfVoterStats('Denver');
      $this->assertSame(0, $stats['total']);
      $this->assertSame(0, $stats['surveyed']);
    }

    // -------------------------------------------------------------------------
    // countOfNlsWithTurfsInCd
    // -------------------------------------------------------------------------

    public function testCountOfNlsWithTurfsInCdReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(8));
      $this->assertSame(8, $this->buildTurfs()->countOfNlsWithTurfsInCd(3));
    }

    public function testCountOfNlsWithTurfsInCdExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->countOfNlsWithTurfsInCd(3));
    }

    // -------------------------------------------------------------------------
    // countOfNlsInCdThatReportedResults
    // -------------------------------------------------------------------------

    public function testCountOfNlsInCdThatReportedResultsReturnsCount(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(5));
      $this->assertSame(5, $this->buildTurfs()->countOfNlsInCdThatReportedResults(3));
    }

    public function testCountOfNlsInCdThatReportedResultsExceptionReturnsZero(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildTurfs()->countOfNlsInCdThatReportedResults(3));
    }

  }

}
