<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Batch;

use Drupal;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\ApiVoter;
use Drupal\nlpservices\Batch\ExportVoterContactReportsBatch;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpVoters;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class ExportVoterContactReportsBatchTest extends UnitTestCase {

  private NlpReports $reportsMock;
  private NlpVoters $votersMock;
  private ApiVoter $apiVoterMock;
  private MessengerInterface $messengerMock;
  private PrivateTempStore $tempStoreMock;
  private PrivateTempStoreFactory $tempStoreFactoryMock;
  private array $tempFiles = [];

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock  = $this->createMock(NlpReports::class);
    $this->votersMock   = $this->createMock(NlpVoters::class);
    $this->apiVoterMock = $this->createMock(ApiVoter::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);

    // The "prepared" record is the raw record without the reportIndex the
    // helpers add, so tests can assert on the CSV output directly.
    $this->reportsMock->method('prepareExportRecord')->willReturnCallback(
      static fn(array $record) => array_diff_key($record, ['reportIndex' => TRUE])
    );

    $this->tempStoreMock = $this->createMock(PrivateTempStore::class);
    $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStoreFactoryMock->method('get')
      ->with('nlpservices.session_data')
      ->willReturn($this->tempStoreMock);

    $container = new ContainerBuilder();
    $container->set('string_translation',   $this->getStringTranslationStub());
    $container->set('messenger',             $this->messengerMock);
    $container->set('tempstore.private',     $this->tempStoreFactoryMock);
    $container->set('nlpservices.reports',   $this->reportsMock);
    $container->set('nlpservices.voters',    $this->votersMock);
    $container->set('nlpservices.api_voter', $this->apiVoterMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
    foreach ($this->tempFiles as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
    $this->tempFiles = [];
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function createReportsFile(string $header = 'vanid,other'): string {
    $path = tempnam(sys_get_temp_dir(), 'nlp_evcr_test_');
    file_put_contents($path, $header . "\n");
    $this->tempFiles[] = $path;
    return $path;
  }

  private function readDataLines(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($lines); // drop the header.
    return $lines;
  }

  private function baseArg(array $overrides = []): array {
    return array_merge([
      'uri'              => '',
      'startCycle'       => 1,
      'endCycle'         => 1,
      'currentCycleOnly' => FALSE,
      'cycleOptions'     => [1 => '2024-11-G', 2 => '2022-11-G'],
      'countOfRecords'   => 100,
      'removeInactive'   => FALSE,
    ], $overrides);
  }

  private function baseContext(): array {
    return ['sandbox' => [], 'finished' => 0, 'results' => []];
  }

  /**
   * A query result returning $rows, each given a reportIndex (1, 2, ...) if it
   * doesn't have one, as every nlp_results row does.
   */
  private function makeResultMock(array $rows): StatementInterface {
    $mock = $this->createMock(StatementInterface::class);
    $queue = [];
    foreach (array_values($rows) as $i => $row) {
      $queue[] = $row + ['reportIndex' => $i + 1];
    }
    $mock->method('fetchAssoc')->willReturnCallback(static function () use (&$queue) {
      return $queue ? array_shift($queue) : FALSE;
    });
    return $mock;
  }

  private function makeCountingResultMock(int $rowCount): StatementInterface {
    $mock = $this->createMock(StatementInterface::class);
    $counter = 0;
    $mock->method('fetchAssoc')->willReturnCallback(static function () use (&$counter, $rowCount) {
      $counter++;
      if ($counter > $rowCount) {
        return FALSE;
      }
      return ['vanid' => (string) $counter, 'other' => 'x', 'reportIndex' => $counter];
    });
    return $mock;
  }

  // -------------------------------------------------------------------------
  // process — file cannot be opened
  // -------------------------------------------------------------------------

  public function testBatchFileOpenFailureSetsFinishedAndAddsStatus(): void {
    $this->messengerMock->expects($this->once())->method('addStatus');
    $this->reportsMock->expects($this->never())->method('selectAllReports');

    $context = $this->baseContext();
    @ExportVoterContactReportsBatch::process($this->baseArg(['uri' => '/nonexistent_dir_xyz/file.csv']), $context);

    $this->assertTrue($context['finished']);
  }

  // -------------------------------------------------------------------------
  // process — writes active voters, skips API lookup
  // -------------------------------------------------------------------------

  public function testBatchWritesAllRecordsWhenNotRemovingInactive(): void {
    $path = $this->createReportsFile();
    $rows = [
      ['vanid' => '11111', 'other' => 'a'],
      ['vanid' => '22222', 'other' => 'b'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->apiVoterMock->expects($this->never())->method('votersExist');

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path]), $context);

    $this->assertSame(['11111,a', '22222,b'], $this->readDataLines($path));
    $this->assertSame(1, $context['finished']);
  }

  // -------------------------------------------------------------------------
  // process — removeInactive skips inactive voters
  // -------------------------------------------------------------------------

  public function testBatchSkipsInactiveVoterWhenRemoveInactiveTrue(): void {
    $path = $this->createReportsFile();
    $rows = [
      ['vanid' => '11111', 'other' => 'active'],
      ['vanid' => '22222', 'other' => 'inactive'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->apiVoterMock->expects($this->once())->method('votersExist')
      ->with([11111, 22222])
      ->willReturn([11111 => TRUE, 22222 => FALSE]);

    $capturedVotersExist = NULL;
    $this->tempStoreMock->expects($this->once())
      ->method('set')
      ->with('votersExist', $this->callback(function (array $v) use (&$capturedVotersExist): bool {
        $capturedVotersExist = $v;
        return TRUE;
      }));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'removeInactive' => TRUE]), $context);

    $this->assertSame(['11111,active'], $this->readDataLines($path));
    $this->assertSame([11111 => TRUE, 22222 => FALSE], $capturedVotersExist);
  }

  // -------------------------------------------------------------------------
  // process — cached votersExist skips API lookup
  // -------------------------------------------------------------------------

  public function testBatchReusesCachedVotersExistWithoutCallingApi(): void {
    $path = $this->createReportsFile();
    $rows = [['vanid' => '11111', 'other' => 'a']];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->tempStoreMock->method('get')->with('votersExist')->willReturn(['11111' => TRUE]);
    $this->apiVoterMock->expects($this->never())->method('votersExist');

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'removeInactive' => TRUE]), $context);

    $this->assertSame(['11111,a'], $this->readDataLines($path));
  }

  public function testBatchChecksOnlyUncachedVotersOnceEach(): void {
    $path = $this->createReportsFile();
    $rows = [
      ['vanid' => '11111', 'other' => 'a'],
      ['vanid' => '22222', 'other' => 'b'],
      ['vanid' => '22222', 'other' => 'c'],
      ['vanid' => '33333', 'other' => 'd'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->tempStoreMock->method('get')->with('votersExist')->willReturn([11111 => TRUE]);
    $this->apiVoterMock->expects($this->once())->method('votersExist')
      ->with([22222, 33333])
      ->willReturn([22222 => TRUE, 33333 => TRUE]);

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'removeInactive' => TRUE]), $context);

    $this->assertSame(['11111,a', '22222,b', '22222,c', '33333,d'], $this->readDataLines($path));
    $this->assertSame(2, $context['results']['votersNotFound']);
  }

  public function testBatchKeepsVoterVoteBuilderDidNotAnswerFor(): void {
    // An unanswered check isn't "not found": the report stays, and the voter
    // isn't cached, so a later export checks it again.
    $path = $this->createReportsFile();
    $rows = [
      ['vanid' => '11111', 'other' => 'a'],
      ['vanid' => '22222', 'other' => 'b'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->apiVoterMock->method('votersExist')->willReturn([11111 => TRUE]);
    $capturedVotersExist = NULL;
    $this->tempStoreMock->method('set')
      ->with('votersExist', $this->callback(function (array $v) use (&$capturedVotersExist): bool {
        $capturedVotersExist = $v;
        return TRUE;
      }));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'removeInactive' => TRUE]), $context);

    $this->assertSame(['11111,a', '22222,b'], $this->readDataLines($path));
    $this->assertSame([11111 => TRUE], $capturedVotersExist);
    $this->assertSame(1, $context['results']['votersUnchecked']);
  }

  public function testBatchReadsFewerRecordsPerStepWhenCheckingVoters(): void {
    $path = $this->createReportsFile();
    $limit = ExportVoterContactReportsBatch::CHECK_LIMIT;
    $this->reportsMock->expects($this->once())->method('selectAllReports')
      ->with(0, '2024-11-G', $limit)
      ->willReturn($this->makeCountingResultMock($limit));
    $this->apiVoterMock->method('votersExist')
      ->willReturnCallback(fn(array $vanids) => array_fill_keys($vanids, TRUE));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'removeInactive' => TRUE, 'countOfRecords' => 2000]), $context);

    $this->assertLessThan(1, $context['finished']);
    $this->assertSame($limit, $context['sandbox']['lastReportIndex']);
  }

  // -------------------------------------------------------------------------
  // process — currentCycleOnly fills missing cd
  // -------------------------------------------------------------------------

  public function testBatchFillsMissingCdWhenCurrentCycleOnly(): void {
    $path = $this->createReportsFile('vanid,cd,other');
    $rows = [['vanid' => '11111', 'cd' => '', 'other' => 'a']];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->votersMock->expects($this->once())
      ->method('getVoterCds')
      ->with([11111])
      ->willReturn([11111 => 7]);

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'currentCycleOnly' => TRUE]), $context);

    $this->assertSame(['11111,7,a'], $this->readDataLines($path));
  }

  public function testBatchDoesNotOverwriteExistingCdWhenCurrentCycleOnly(): void {
    $path = $this->createReportsFile('vanid,cd,other');
    $rows = [['vanid' => '11111', 'cd' => '3', 'other' => 'a']];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->votersMock->expects($this->never())->method('getVoterCds');

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'currentCycleOnly' => TRUE]), $context);

    $this->assertSame(['11111,3,a'], $this->readDataLines($path));
  }

  // -------------------------------------------------------------------------
  // process — cycle progression
  // -------------------------------------------------------------------------

  public function testBatchFinishesOnLastCycleBelowLimit(): void {
    $path = $this->createReportsFile();
    $rows = [['vanid' => '11111', 'other' => 'a']];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path]), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(1, $context['results']['records']);
    $this->assertSame($path, $context['results']['uri']);
    $this->assertArrayHasKey('votersExistCount', $context['results']);
    $this->assertArrayHasKey('votersNotFound', $context['results']);
  }

  public function testBatchAdvancesToNextCycleWhenNotLastCycleAndBelowLimit(): void {
    $path = $this->createReportsFile();
    $rows = [
      ['vanid' => '11111', 'other' => 'a'],
      ['vanid' => '22222', 'other' => 'b'],
      ['vanid' => '33333', 'other' => 'c'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'startCycle' => 1, 'endCycle' => 2, 'countOfRecords' => 100]), $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertSame(2, $context['sandbox']['workingCycle']);
    $this->assertSame(0, $context['sandbox']['lastReportIndex']);
    $this->assertSame(3, $context['sandbox']['recordCount']);
    $this->assertSame([], $context['results']);
  }

  public function testBatchMovesPastAnEmptyFirstCycle(): void {
    // An empty first cycle left recordCount at 0, and the next step started
    // over at the first cycle, so the batch never finished.
    $path = $this->createReportsFile();
    $this->reportsMock->method('selectAllReports')->willReturnCallback(
      fn(int $next, string $cycle) => $this->makeResultMock(
        $cycle === '2022-11-G' ? [['vanid' => '11111', 'other' => 'a']] : []
      )
    );
    $arg = $this->baseArg(['uri' => $path, 'startCycle' => 1, 'endCycle' => 2]);

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($arg, $context);
    $this->assertSame(2, $context['sandbox']['workingCycle']);

    ExportVoterContactReportsBatch::process($arg, $context);
    $this->assertSame(1, $context['finished']);
    $this->assertSame(['11111,a'], $this->readDataLines($path));
  }

  public function testBatchPausesMidCycleWhenBatchLimitReached(): void {
    $path = $this->createReportsFile();
    $this->reportsMock->method('selectAllReports')
      ->willReturn($this->makeCountingResultMock(NlpReports::BATCH_LIMIT));

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'countOfRecords' => 2000]), $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertSame(NlpReports::BATCH_LIMIT, $context['sandbox']['lastReportIndex']);
    $this->assertSame(NlpReports::BATCH_LIMIT, $context['sandbox']['recordCount']);
    $this->assertSame([], $context['results']);
  }

  public function testBatchStartsEachPageAfterTheLastReportRead(): void {
    // Paging by row offset made MySQL read every earlier row on each page.
    $path = $this->createReportsFile();
    $pageOne = [];
    for ($i = 1; $i <= NlpReports::BATCH_LIMIT; $i++) {
      $pageOne[] = ['vanid' => (string) $i, 'other' => 'x', 'reportIndex' => 1000 + $i];
    }
    $afterIndexes = [];
    $this->reportsMock->method('selectAllReports')->willReturnCallback(
      function (int $afterIndex) use (&$afterIndexes, $pageOne) {
        $afterIndexes[] = $afterIndex;
        return $this->makeResultMock($afterIndex === 0 ? $pageOne : []);
      }
    );
    $arg = $this->baseArg(['uri' => $path, 'countOfRecords' => 2000]);

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($arg, $context);
    ExportVoterContactReportsBatch::process($arg, $context);

    $this->assertSame([0, 1000 + NlpReports::BATCH_LIMIT], $afterIndexes);
    $this->assertSame(1, $context['finished']);
  }

  public function testBatchLooksUpMissingCdsTogether(): void {
    $path = $this->createReportsFile('vanid,cd,other');
    $rows = [
      ['vanid' => '11111', 'cd' => '', 'other' => 'a'],
      ['vanid' => '22222', 'cd' => '4', 'other' => 'b'],
      ['vanid' => '33333', 'cd' => '', 'other' => 'c'],
    ];
    $this->reportsMock->method('selectAllReports')->willReturn($this->makeResultMock($rows));
    $this->votersMock->expects($this->never())->method('getVoterCd');
    $this->votersMock->expects($this->once())->method('getVoterCds')
      ->with([11111, 33333])
      ->willReturn([11111 => 5]);

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'currentCycleOnly' => TRUE]), $context);

    $this->assertSame(['11111,5,a', '22222,4,b', '33333,0,c'], $this->readDataLines($path));
  }

  public function testBatchLeavesVoterCacheAloneWhenNotCheckingVoters(): void {
    // The cache grows with every export; reading and rewriting it on every
    // step of an export that doesn't use it was slow.
    $path = $this->createReportsFile();
    $this->reportsMock->method('selectAllReports')
      ->willReturn($this->makeResultMock([['vanid' => '11111', 'other' => 'a']]));
    $this->tempStoreMock->expects($this->never())->method('get');
    $this->tempStoreMock->expects($this->never())->method('set');

    $context = $this->baseContext();
    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path]), $context);

    $this->assertSame(['11111,a'], $this->readDataLines($path));
  }

  public function testBatchResumesFromSavedSandboxState(): void {
    $path = $this->createReportsFile();
    $rows = [['vanid' => '11111', 'other' => 'a']];

    $this->reportsMock->expects($this->once())
      ->method('selectAllReports')
      ->with(8500, '2022-11-G')
      ->willReturn($this->makeResultMock($rows));

    $context = $this->baseContext();
    $context['sandbox'] = [
      'recordCount'     => 750,
      'lastReportIndex' => 8500,
      'workingCycle'    => 2,
      'votersNotFound'  => 3,
    ];

    ExportVoterContactReportsBatch::process($this->baseArg(['uri' => $path, 'startCycle' => 1, 'endCycle' => 2]), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(751, $context['results']['records']);
  }

  // -------------------------------------------------------------------------
  // finished — success
  // -------------------------------------------------------------------------

  public function testFinishedSuccessAddsFourStatusMessages(): void {
    $this->messengerMock->expects($this->exactly(4))->method('addStatus');

    ExportVoterContactReportsBatch::finished(
      TRUE,
      ['records' => 10, 'votersExistCount' => 5, 'votersNotFound' => 2, 'uri' => '/tmp/x.csv'],
      [],
    );
  }

  public function testFinishedWarnsAboutVotersNotChecked(): void {
    $this->messengerMock->expects($this->exactly(4))->method('addStatus');
    $this->messengerMock->expects($this->once())->method('addWarning');

    ExportVoterContactReportsBatch::finished(
      TRUE,
      ['records' => 10, 'votersExistCount' => 5, 'votersNotFound' => 2, 'votersUnchecked' => 1, 'uri' => '/tmp/x.csv'],
      [],
    );
  }

  // -------------------------------------------------------------------------
  // finished — failure
  // -------------------------------------------------------------------------

  public function testFinishedFailureAddsSingleErrorStatus(): void {
    $this->messengerMock->expects($this->once())->method('addStatus');

    ExportVoterContactReportsBatch::finished(FALSE, [], []);
  }

}
