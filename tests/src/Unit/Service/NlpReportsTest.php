<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Condition;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Query\Truncate;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Database\Transaction;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpReports;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpReportsTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpConfiguration $configMock;
    private DatabaseFunctions $dbFunctionsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->configMock = $this->createMock(NlpConfiguration::class);
      $this->dbFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->configMock->method('getCycle')->willReturn('2026');
      $this->configMock->method('getPledgeSurveyQuestion')
        ->willReturn(['surveyQuestionId' => 55]);
      $this->dbFunctionsMock->method('getColumnNames')->willReturn([
        'reportIndex',
        'vanid',
        'cycle',
        'contactId',
        'type',
        'value',
        'text',
        'mcid',
        'contactDate',
        'contactType',
        'qid',
        'rid',
      ]);
    }

    private function buildService(): NlpReports {
      return new NlpReports($this->configMock, $this->connectionMock, $this->dbFunctionsMock);
    }

    private function buildConditionMock(): Condition {
      $condMock = $this->createMock(Condition::class);
      $condMock->method('condition')->willReturnSelf();
      return $condMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('addExpression')->willReturn('alias');
      $selectMock->method('leftJoin')->willReturn('alias');
      $selectMock->method('groupBy')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('range')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('orConditionGroup')->willReturn($this->buildConditionMock());
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    // Returns a Select mock whose countQuery()->execute()->fetchField() chain yields $count.
    private function buildCountSelectMock(int $count): Select {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchField')->willReturn($count);
      $countSelectMock = $this->createMock(Select::class);
      $countSelectMock->method('execute')->willReturn($stmtMock);

      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orConditionGroup')->willReturn($this->buildConditionMock());
      $selectMock->method('countQuery')->willReturn($countSelectMock);
      return $selectMock;
    }

    private function buildInsertMock(): Insert {
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('values')->willReturnSelf();
      $insertMock->method('execute')->willReturn(77);
      return $insertMock;
    }

    private function buildUpdateMock(): Update {
      $updateMock = $this->createMock(Update::class);
      $updateMock->method('fields')->willReturnSelf();
      $updateMock->method('condition')->willReturnSelf();
      $updateMock->method('execute')->willReturn(1);
      return $updateMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.configuration', $this->configMock);
      $container->set('database', $this->connectionMock);
      $container->set('nlpservices.database_functions', $this->dbFunctionsMock);

      $service = NlpReports::create($container);

      $this->assertInstanceOf(NlpReports::class, $service);
    }

    // -------------------------------------------------------------------------
    // setNlReport()
    // -------------------------------------------------------------------------

    public function testSetNlReportAddsDefaultCycleAndContactId(): void {
      $capturedFields = [];
      $insertMock = $this->buildInsertMock();
      $insertMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $insertMock): Insert {
          $capturedFields = $fields;
          return $insertMock;
        });
      $this->connectionMock->method('insert')->willReturn($insertMock);

      $reportIndex = $this->buildService()->setNlReport([
        'vanid' => 999,
        'type' => 'Survey',
        'value' => 'Yes',
        'text' => 'Pledge',
      ]);

      $this->assertSame(77, $reportIndex);
      $this->assertSame('2026', $capturedFields['cycle']);
      $this->assertSame(0, $capturedFields['contactId']);
      $this->assertArrayNotHasKey('reportIndex', $capturedFields);
    }

    public function testSetNlReportPreservesExistingCycleWhenProvided(): void {
      $capturedFields = [];
      $insertMock = $this->buildInsertMock();
      $insertMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $insertMock): Insert {
          $capturedFields = $fields;
          return $insertMock;
        });
      $this->connectionMock->method('insert')->willReturn($insertMock);

      $this->buildService()->setNlReport(['vanid' => 999, 'type' => 'Survey', 'cycle' => '2024']);

      $this->assertSame('2024', $capturedFields['cycle']);
    }

    public function testSetNlReportPreservesExistingContactIdWhenProvided(): void {
      $capturedFields = [];
      $insertMock = $this->buildInsertMock();
      $insertMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $insertMock): Insert {
          $capturedFields = $fields;
          return $insertMock;
        });
      $this->connectionMock->method('insert')->willReturn($insertMock);

      $this->buildService()->setNlReport(['vanid' => 999, 'type' => 'Survey', 'contactId' => 42]);

      $this->assertSame(42, $capturedFields['contactId']);
    }

    public function testSetNlReportReturnsZeroOnInsertException(): void {
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildService()->setNlReport(['vanid' => 999, 'type' => 'Survey']));
    }

    // -------------------------------------------------------------------------
    // reportExists()
    // -------------------------------------------------------------------------

    public function testReportExistsReturnsTrueWhenRecordFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['reportIndex' => 1]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertTrue($this->buildService()->reportExists('Survey', 101));
    }

    public function testReportExistsReturnsFalseWhenRecordMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertFalse($this->buildService()->reportExists('Survey', 101));
    }

    public function testReportExistsReturnsFalseOnException(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertFalse($this->buildService()->reportExists('Survey', 101));
    }

    // -------------------------------------------------------------------------
    // displayNlReports()
    // -------------------------------------------------------------------------

    public function testDisplayNlReportsFormatsSupportedReportTypes(): void {
      $input = [
        [
          ['type' => 'contact', 'value' => 'Mailed postcard', 'text' => '', 'contactDate' => '2026-01-01', 'contactType' => 'Postcard'],
          ['type' => 'survey', 'value' => 'Yes', 'text' => 'Pledge', 'contactDate' => '2026-01-02', 'contactType' => 'Walk'],
          ['type' => 'activist', 'value' => 1, 'text' => 'NLPHostile', 'contactDate' => '2026-01-03', 'contactType' => 'Walk'],
          ['type' => 'comment', 'text' => 'Spoke with voter', 'contactDate' => '2026-01-04', 'contactType' => 'Walk'],
        ],
      ];

      $result = $this->buildService()->displayNlReports($input);

      $this->assertStringContainsString('Mailed postcard', $result['display']);
      $this->assertStringContainsString('Pledge: Yes', $result['display']);
      $this->assertStringContainsString('NLPHostile: Yes', $result['display']);
      $this->assertStringContainsString('Spoke with voter', $result['display']);
      $this->assertGreaterThan(0, $result['displayLines']);
    }

    public function testDisplayNlReportsReturnsEmptyForEmptyInput(): void {
      $result = $this->buildService()->displayNlReports([]);
      $this->assertSame('', $result['display']);
      $this->assertSame(0, $result['displayLines']);
    }

    public function testDisplayNlReportsSkipsReportsMissingType(): void {
      $input = [[['value' => 'something', 'text' => 'note', 'contactDate' => '2026-01-01', 'contactType' => 'Walk']]];
      $result = $this->buildService()->displayNlReports($input);
      $this->assertSame('', $result['display']);
      $this->assertSame(0, $result['displayLines']);
    }

    public function testDisplayNlReportsShowsNoForFalsyValuedActivist(): void {
      $input = [[
        ['type' => 'activist', 'value' => 0, 'text' => 'NLPHostile', 'contactDate' => '2026-01-01', 'contactType' => 'Walk'],
      ]];
      $result = $this->buildService()->displayNlReports($input);
      $this->assertStringContainsString('NLPHostile: No', $result['display']);
    }

    public function testDisplayNlReportsJoinsMultipleReportsWithBreakTag(): void {
      $input = [[
        ['type' => 'comment', 'text' => 'First note', 'contactDate' => '2026-01-01', 'contactType' => 'Walk'],
        ['type' => 'comment', 'text' => 'Second note', 'contactDate' => '2026-01-02', 'contactType' => 'Walk'],
      ]];
      $result = $this->buildService()->displayNlReports($input);
      $this->assertStringContainsString('<br/>', $result['display']);
      $this->assertStringContainsString('First note', $result['display']);
      $this->assertStringContainsString('Second note', $result['display']);
    }

    // -------------------------------------------------------------------------
    // prepareExportRecord()
    // -------------------------------------------------------------------------

    public function testPrepareExportRecordConvertsNewLinesInText(): void {
      $result = $this->buildService()->prepareExportRecord([
        'text' => "Line 1\r\nLine 2",
        'type' => 'Comment',
      ]);

      $this->assertSame('Line 1<br>Line 2', $result['text']);
      $this->assertSame('Comment', $result['type']);
    }

    public function testPrepareExportRecordReturnsEmptyStringForEmptyTextField(): void {
      $result = $this->buildService()->prepareExportRecord(['text' => '']);
      $this->assertSame('', $result['text']);
    }

    public function testPrepareExportRecordPassesNonTextFieldsUnchanged(): void {
      $result = $this->buildService()->prepareExportRecord([
        'vanid' => 999,
        'type' => 'Survey',
        'value' => 'Yes',
      ]);
      $this->assertSame(999, $result['vanid']);
      $this->assertSame('Survey', $result['type']);
      $this->assertSame('Yes', $result['value']);
    }

    // -------------------------------------------------------------------------
    // mergeReport()
    // -------------------------------------------------------------------------

    public function testMergeReportCallsMergeWithReportIndexKey(): void {
      $capturedKeys = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')
        ->willReturnCallback(function (array $keys) use (&$capturedKeys, $mergeMock): Merge {
          $capturedKeys = $keys;
          return $mergeMock;
        });
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->mergeReport([
        'reportIndex' => 55,
        'text' => 'Updated',
      ]);

      $this->assertSame(['reportIndex' => 55], $capturedKeys);
    }

    public function testMergeReportSwallowsException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->buildService()->mergeReport(['reportIndex' => 55, 'text' => 'Updated']);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // deleteReport()
    // -------------------------------------------------------------------------

    public function testDeleteReportCallsDelete(): void {
      $this->connectionMock->expects($this->once())
        ->method('delete')
        ->with(NlpReports::NLP_RESULTS_TBL)
        ->willReturn($this->buildDeleteMock());

      $this->buildService()->deleteReport(77);
      $this->addToAssertionCount(1);
    }

    public function testDeleteReportSwallowsException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));
      $this->buildService()->deleteReport(77);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getComment()
    // -------------------------------------------------------------------------

    public function testGetCommentReturnsNewestCommentByReportIndex(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 10, 'text' => 'Older'],
        ['reportIndex' => 11, 'text' => 'Newest'],
        false
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getComment(['mcid' => 42, 'vanid' => 999]);

      $this->assertSame(11, $result['reportIndex']);
      $this->assertSame('Newest', $result['text']);
    }

    public function testGetCommentReturnsNullIndexAndEmptyTextWhenNoRows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getComment(['mcid' => 42, 'vanid' => 999]);

      $this->assertNull($result['reportIndex']);
      $this->assertSame('', $result['text']);
    }

    public function testGetCommentReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getComment(['mcid' => 42, 'vanid' => 999]));
    }

    // -------------------------------------------------------------------------
    // getAcReportIndex()
    // -------------------------------------------------------------------------

    public function testGetAcReportIndexReturnsZeroWhenNoRecord(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(0, $this->buildService()->getAcReportIndex(999, 'NLPHostile'));
    }

    public function testGetAcReportIndexReturnsReportIndexWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['reportIndex' => 42]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(42, $this->buildService()->getAcReportIndex(999, 'NLPHostile'));
    }

    public function testGetAcReportIndexReturnsZeroOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildService()->getAcReportIndex(999, 'NLPHostile'));
    }

    // -------------------------------------------------------------------------
    // getNlpReport()
    // -------------------------------------------------------------------------

    public function testGetNlpReportReturnsFoundRecord(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['reportIndex' => 7, 'type' => 'Survey']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlpReport(7);

      $this->assertSame(7, $result['reportIndex']);
      $this->assertSame('Survey', $result['type']);
    }

    public function testGetNlpReportReturnsEmptyArrayWhenMissing(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getNlpReport(7));
    }

    public function testGetNlpReportReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlpReport(7));
    }

    // -------------------------------------------------------------------------
    // getNlpTypeReports()
    // -------------------------------------------------------------------------

    public function testGetNlpTypeReportsReturnsListOfReports(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 1, 'type' => 'Survey', 'vanid' => 999],
        ['reportIndex' => 2, 'type' => 'Survey', 'vanid' => 999],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlpTypeReports(999, 'Survey');

      $this->assertCount(2, $result);
      $this->assertSame('Survey', $result[0]['type']);
    }

    public function testGetNlpTypeReportsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlpTypeReports(999, 'Survey'));
    }

    // -------------------------------------------------------------------------
    // getNlpReports()
    // -------------------------------------------------------------------------

    public function testGetNlpReportsReturnsReportsKeyedByVanid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 1, 'vanid' => 999, 'type' => 'Survey'],
        ['reportIndex' => 2, 'vanid' => 888, 'type' => 'contact'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlpReports(999);

      $this->assertArrayHasKey(999, $result);
      $this->assertArrayHasKey(888, $result);
      $this->assertSame('Survey', $result[999][0]['type']);
    }

    public function testGetNlpReportsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlpReports(999));
    }

    // -------------------------------------------------------------------------
    // getNlsProgress()
    // -------------------------------------------------------------------------

    public function testGetNlsProgressReturnsCountsKeyedByMcid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['mcid' => 42, 'voterCount' => '10', 'attempts' => '4', 'contacts' => '2'],
        ['mcid' => 43, 'voterCount' => '3', 'attempts' => '0', 'contacts' => '0'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlsProgress([42, 43]);

      $this->assertSame(['voterCount' => 10, 'attempts' => 4, 'contacts' => 2], $result[42]);
      $this->assertSame(['voterCount' => 3, 'attempts' => 0, 'contacts' => 0], $result[43]);
    }

    public function testGetNlsProgressReturnsEmptyArrayForNoMcids(): void {
      $this->connectionMock->expects($this->never())->method('select');
      $this->assertSame([], $this->buildService()->getNlsProgress([]));
    }

    public function testGetNlsProgressReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlsProgress([42]));
    }

    // -------------------------------------------------------------------------
    // getCanvassResults()
    // -------------------------------------------------------------------------

    public function testGetCanvassResultsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getCanvassResults(999));
    }

    public function testGetCanvassResultsSetsAttemptForContactReport(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'contact', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 'Left Message/Lit', 'text' => '', 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('attempt', $result);
      $this->assertSame('2026-01-01', $result['attempt']['contactDate']);
    }

    public function testGetCanvassResultsSetsDeceasedKeyForDeceasedContact(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'contact', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 'deceased', 'text' => '', 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('deceased', $result);
    }

    public function testGetCanvassResultsSetsMovedKeyWithAddressForMovedContact(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'contact', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 'moved', 'text' => '{"street":"123 Main"}', 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('moved', $result);
      $this->assertSame('{"street":"123 Main"}', $result['moved']['address']);
    }

    public function testGetCanvassResultsSetsPostcardKeyForPostcardContactType(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'contact', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Postcard', 'value' => 'Mailed postcard', 'text' => '', 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('postcard', $result);
    }

    public function testGetCanvassResultsSetsPledgeToVoteForMatchingSurveyQid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'Survey', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 'Yes', 'text' => 'Pledge', 'qid' => 55],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('pledgeToVote', $result);
    }

    public function testGetCanvassResultsIgnoresSurveyWithNonMatchingQid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'Survey', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 'Yes', 'text' => 'Other', 'qid' => 99],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayNotHasKey('pledgeToVote', $result);
    }

    public function testGetCanvassResultsSetsHostileForNlpHostileActivist(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'Activist', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => 1, 'text' => 'NLPHostile', 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('hostile', $result);
    }

    public function testGetCanvassResultsSetsNoteKeyForComment(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'Comment', 'vanid' => 999, 'contactDate' => '2026-01-01',
          'contactType' => 'Walk', 'value' => '', 'text' => 'Voter was friendly.', 'reportIndex' => 5, 'qid' => null],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCanvassResults(999);

      $this->assertArrayHasKey('note', $result);
      $this->assertSame('Voter was friendly.', $result['note']['text']);
      $this->assertSame(5, $result['note']['reportIndex']);
    }

    // -------------------------------------------------------------------------
    // updateComment()
    // -------------------------------------------------------------------------

    public function testUpdateCommentExecutesUpdate(): void {
      $this->connectionMock->expects($this->once())->method('update')->willReturn($this->buildUpdateMock());
      $this->buildService()->updateComment(['reportIndex' => 5, 'text' => 'New note']);
    }

    public function testUpdateCommentSwallowsException(): void {
      $this->connectionMock->method('update')->willThrowException(new \Exception('DB error'));
      $this->buildService()->updateComment(['reportIndex' => 5, 'text' => 'New note']);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getNlpAcReport()
    // -------------------------------------------------------------------------

    public function testGetNlpAcReportReturnsReportKeyedByActivistCodeName(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(
        ['reportIndex' => 7, 'type' => 'Activist', 'text' => 'NLPHostile', 'value' => 1, 'vanid' => 999]
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getNlpAcReport(999);

      $this->assertArrayHasKey('NLPHostile', $result);
      $this->assertSame(7, $result['NLPHostile']['reportIndex']);
    }

    public function testGetNlpAcReportReturnsEmptyArrayWhenNoRecord(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getNlpAcReport(999));
    }

    public function testGetNlpAcReportReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getNlpAcReport(999));
    }

    // -------------------------------------------------------------------------
    // getReport()
    // -------------------------------------------------------------------------

    public function testGetReportReturnsNewestReportByReportIndex(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 5, 'vanid' => 999, 'type' => 'contact', 'value' => 'moved'],
        ['reportIndex' => 8, 'vanid' => 999, 'type' => 'contact', 'value' => 'moved'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getReport(['vanid' => 999, 'type' => 'contact', 'value' => 'moved']);

      $this->assertSame(8, $result['reportIndex']);
    }

    public function testGetReportReturnsAllReportsWhenNewestFalse(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 3, 'vanid' => 999],
        ['reportIndex' => 7, 'vanid' => 999],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getReport(['vanid' => 999, 'type' => 'contact', 'value' => 'moved'], false);

      $this->assertIsArray($result);
      $this->assertCount(2, $result);
    }

    public function testGetReportAppliesCycleConditionWhenPresent(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getReport([
        'vanid' => 999, 'type' => 'contact', 'value' => 'moved', 'cycle' => '2026',
      ]);

      $this->assertSame([], $result);
    }

    public function testGetReportReturnsEmptyArrayWhenNoRows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getReport(['vanid' => 999, 'type' => 'contact', 'value' => 'moved']));
    }

    public function testGetReportReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getReport(['vanid' => 999, 'type' => 'contact', 'value' => 'moved']));
    }

    // -------------------------------------------------------------------------
    // contactAttempt()
    // -------------------------------------------------------------------------

    public function testContactAttemptReturnsBothFalseWhenNoReports(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->contactAttempt(999);

      $this->assertFalse($result['attempt']);
      $this->assertFalse($result['survey']);
    }

    public function testContactAttemptSetsAttemptTrueForContactReport(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'contact', 'vanid' => 999],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->contactAttempt(999);

      $this->assertTrue($result['attempt']);
      $this->assertFalse($result['survey']);
    }

    public function testContactAttemptSetsSurveyTrueForSurveyReport(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['type' => 'Survey', 'vanid' => 999],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->contactAttempt(999);

      $this->assertTrue($result['attempt']);
      $this->assertTrue($result['survey']);
    }

    public function testContactAttemptReturnsDefaultFlagsOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));

      $result = $this->buildService()->contactAttempt(999);

      $this->assertFalse($result['attempt']);
      $this->assertFalse($result['survey']);
    }

    // -------------------------------------------------------------------------
    // voterSentPostcard()
    // -------------------------------------------------------------------------

    public function testVoterSentPostcardReturnsTrueWhenCountGreaterThanZero(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(2));
      $this->assertTrue($this->buildService()->voterSentPostcard(999));
    }

    public function testVoterSentPostcardReturnsFalseWhenCountIsZero(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(0));
      $this->assertFalse($this->buildService()->voterSentPostcard(999));
    }

    public function testVoterSentPostcardReturnsFalseOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildService()->voterSentPostcard(999));
    }

    // -------------------------------------------------------------------------
    // getCommitteeReportCounts()
    // -------------------------------------------------------------------------

    public function testGetCommitteeReportCountsReturnsCountsKeyedByMcid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['vanid' => 1, 'mcid' => 10, 'type' => 'contact'],
        ['vanid' => 2, 'mcid' => 10, 'type' => 'Survey'],
        ['vanid' => 3, 'mcid' => 20, 'type' => 'contact'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getCommitteeReportCounts('Denver');

      $this->assertSame(2, $result[10]['attempts']);
      $this->assertSame(1, $result[10]['contacts']);
      $this->assertSame(1, $result[20]['attempts']);
      $this->assertSame(0, $result[20]['contacts']);
    }

    public function testGetCommitteeReportCountsReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getCommitteeReportCounts('Denver'));
    }

    // -------------------------------------------------------------------------
    // getReportCount()
    // -------------------------------------------------------------------------

    public function testGetReportCountReturnsCountForSpecificCycle(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(42));
      $this->assertSame(42, $this->buildService()->getReportCount('2026'));
    }

    public function testGetReportCountReturnsTotalCountWithNoCycleFilter(): void {
      $this->connectionMock->method('select')->willReturn($this->buildCountSelectMock(100));
      $this->assertSame(100, $this->buildService()->getReportCount());
    }

    public function testGetReportCountReturnsZeroOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame(0, $this->buildService()->getReportCount('2026'));
    }

    // -------------------------------------------------------------------------
    // selectAllReports()
    // -------------------------------------------------------------------------

    public function testSelectAllReportsReturnsStatementResult(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->selectAllReports(0, '2026');

      $this->assertSame($stmtMock, $result);
    }

    public function testSelectAllReportsReturnsNullOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertNull($this->buildService()->selectAllReports(0, '2026'));
    }

    // -------------------------------------------------------------------------
    // emptyNlTable()
    // -------------------------------------------------------------------------

    public function testEmptyNlTableExecutesTruncate(): void {
      $truncateMock = $this->createMock(Truncate::class);
      $truncateMock->expects($this->once())->method('execute');
      $this->connectionMock->expects($this->once())
        ->method('truncate')
        ->with(NlpReports::NLP_RESULTS_TBL)
        ->willReturn($truncateMock);

      $this->buildService()->emptyNlTable();
    }

    public function testEmptyNlTableSwallowsException(): void {
      $this->connectionMock->method('truncate')->willThrowException(new \Exception('DB error'));
      $this->buildService()->emptyNlTable();
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getKnownCycles()
    // -------------------------------------------------------------------------

    public function testGetKnownCyclesReturnsDistinctCycles(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['cycle' => '2022'],
        ['cycle' => '2024'],
        ['cycle' => '2026'],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getKnownCycles();

      $this->assertSame(['2022', '2024', '2026'], $result);
    }

    public function testGetKnownCyclesReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getKnownCycles());
    }

    // -------------------------------------------------------------------------
    // getReportsById()
    // -------------------------------------------------------------------------

    public function testGetReportsByIdReturnsReportIndexesKeyedByReportIndex(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        ['reportIndex' => 10],
        ['reportIndex' => 20],
        false,
      );
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getReportsById(42);

      $this->assertSame([10 => 10, 20 => 20], $result);
    }

    public function testGetReportsByIdReturnsEmptyArrayOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildService()->getReportsById(42));
    }

    // -------------------------------------------------------------------------
    // insertNlReports() / flushNlReports()
    // -------------------------------------------------------------------------

    public function testInsertNlReportsReturnsFalseWhenBatchNotFull(): void {
      $result = $this->buildService()->insertNlReports(['vanid' => 999, 'type' => 'Survey']);
      $this->assertFalse($result);
    }

    public function testFlushNlReportsReturnsEarlyWithNoDbCallWhenNoRecordsPending(): void {
      $this->connectionMock->expects($this->never())->method('insert');
      $this->buildService()->flushNlReports();
    }

    public function testFlushNlReportsInsertsRemainingRecordsViaBatch(): void {
      $transactionMock = new class extends Transaction {
        public function __construct() {}
        public function __destruct() {}
      };
      $this->connectionMock->method('startTransaction')->willReturn($transactionMock);
      $this->connectionMock->expects($this->once())->method('insert')->willReturn($this->buildInsertMock());

      $service = $this->buildService();
      $service->insertNlReports(['vanid' => 999, 'type' => 'Survey']);
      $service->flushNlReports();
    }

  }

}
