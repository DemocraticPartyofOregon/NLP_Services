<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Batch;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Batch\ImportReportsBatch;
use Drupal\nlpservices\NlpReports;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class ImportReportsBatchTest extends UnitTestCase {

  private NlpReports $reportsMock;
  private MessengerInterface $messengerMock;
  private LoggerInterface $loggerMock;
  private array $tempFiles = [];

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock   = $this->createMock(NlpReports::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);
    $this->loggerMock    = $this->createMock(LoggerInterface::class);

    $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($this->loggerMock);

    $container = new ContainerBuilder();
    $container->set('string_translation',   $this->getStringTranslationStub());
    $container->set('messenger',             $this->messengerMock);
    $container->set('logger.factory',        $loggerFactoryMock);
    $container->set('nlpservices.reports',   $this->reportsMock);
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

  private function writeCsv(array $headers, array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'nlp_reports_test_');
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, escape: '');
    foreach ($rows as $row) {
      fputcsv($fh, $row, escape: '');
    }
    fclose($fh);
    $this->tempFiles[] = $path;
    return $path;
  }

  private function baseHeaders(): array {
    return ['reportIndex', 'vanid', 'contactDate', 'value', 'text'];
  }

  private function baseRow(array $overrides = []): array {
    $defaults = [
      'reportIndex' => '1',
      'vanid'       => '12345',
      'contactDate' => '01/15/2024',
      'value'       => 'Yes',
      'text'        => 'Some notes',
    ];
    $row = array_merge($defaults, $overrides);
    $ordered = [];
    foreach ($this->baseHeaders() as $col) {
      $ordered[] = $row[$col];
    }
    return $ordered;
  }

  private function baseContext(): array {
    return ['sandbox' => [], 'finished' => 0, 'results' => []];
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — file cannot be opened
  // -------------------------------------------------------------------------

  public function testBatchFileOpenFailureSetsFinishedAndLogsNotice(): void {
    $this->loggerMock->expects($this->once())->method('notice');
    $this->reportsMock->expects($this->never())->method('insertNlReports');

    $context = $this->baseContext();
    @ImportReportsBatch::process(['uri' => '/nonexistent_dir_xyz/reports.csv'], $context);

    $this->assertTrue($context['finished']);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — processes all rows and finishes in one pass
  // -------------------------------------------------------------------------

  public function testBatchProcessesAllRowsAndFinishesWhenNoBatchLimitHit(): void {
    $path = $this->writeCsv($this->baseHeaders(), [
      $this->baseRow(['vanid' => '11111']),
      $this->baseRow(['vanid' => '22222']),
    ]);

    $this->reportsMock->method('insertNlReports')->willReturn(FALSE);
    $this->reportsMock->expects($this->once())->method('flushNlReports');

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(2, $context['results']['recordCount']);
    $this->assertSame($path, $context['results']['uri']);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — reads and stores the CSV header on first call
  // -------------------------------------------------------------------------

  public function testBatchStoresCsvColumnNamesInSandboxOnFirstCall(): void {
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow()]);
    $this->reportsMock->method('insertNlReports')->willReturn(TRUE);

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertSame($this->baseHeaders(), $context['sandbox']['csvColumnNames']);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — pauses and saves a seek position at the batch limit
  // -------------------------------------------------------------------------

  public function testBatchPausesAndSavesSeekWhenBatchLimitReached(): void {
    $path = $this->writeCsv($this->baseHeaders(), [
      $this->baseRow(['vanid' => '11111']),
      $this->baseRow(['vanid' => '22222']),
    ]);

    $this->reportsMock->method('insertNlReports')->willReturn(TRUE);
    $this->reportsMock->expects($this->never())->method('flushNlReports');

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertArrayHasKey('seek', $context['sandbox']);
    $this->assertSame(1, $context['sandbox']['recordCount']);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — resumes from a previously saved seek position
  // -------------------------------------------------------------------------

  public function testBatchResumesFromSavedSeekPosition(): void {
    $path = $this->writeCsv($this->baseHeaders(), [
      $this->baseRow(['vanid' => '11111']),
      $this->baseRow(['vanid' => '22222']),
    ]);

    // Calculate the byte offset after the header + first data row.
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh, escape: '');
    fgetcsv($fh, escape: ''); // first data row — already processed in a prior batch.
    $seekPos = ftell($fh);
    fclose($fh);

    $this->reportsMock->method('insertNlReports')->willReturn(FALSE);
    $this->reportsMock->expects($this->once())->method('flushNlReports');

    $context = $this->baseContext();
    $context['sandbox']['seek']           = $seekPos;
    $context['sandbox']['recordCount']    = 1;
    $context['sandbox']['csvColumnNames'] = $header;

    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertSame(1, $context['finished']);
    // The prior count (1) plus the resumed record (22222) = 2.
    $this->assertSame(2, $context['results']['recordCount']);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::process — field transformations
  // -------------------------------------------------------------------------

  public function testBatchUnsetsReportIndexBeforeInserting(): void {
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow()]);

    $captured = NULL;
    $this->reportsMock->method('insertNlReports')
      ->willReturnCallback(function (array $fields) use (&$captured): bool {
        $captured = $fields;
        return FALSE;
      });

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertArrayNotHasKey('reportIndex', $captured);
  }

  public function testBatchNormalizesContactDateToYmd(): void {
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow(['contactDate' => '01/15/2024'])]);

    $captured = NULL;
    $this->reportsMock->method('insertNlReports')
      ->willReturnCallback(function (array $fields) use (&$captured): bool {
        $captured = $fields;
        return FALSE;
      });

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $expected = date('Y-m-d', strtotime('01/15/2024'));
    $this->assertSame($expected, $captured['contactDate']);
  }

  public function testBatchEscapesSingleQuotesInValueField(): void {
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow(['value' => "O'Brien"])]);

    $captured = NULL;
    $this->reportsMock->method('insertNlReports')
      ->willReturnCallback(function (array $fields) use (&$captured): bool {
        $captured = $fields;
        return FALSE;
      });

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertSame(str_replace("'", "\'", "O'Brien"), $captured['value']);
  }

  public function testBatchStripsTagsAndEntitiesFromTextField(): void {
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow(['text' => '<b>Bold</b> & "quoted"'])]);

    $captured = NULL;
    $this->reportsMock->method('insertNlReports')
      ->willReturnCallback(function (array $fields) use (&$captured): bool {
        $captured = $fields;
        return FALSE;
      });

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $expected = trim(strip_tags(htmlentities(stripslashes('<b>Bold</b> & "quoted"'), ENT_QUOTES)));
    $this->assertSame($expected, $captured['text']);
  }

  public function testBatchTruncatesLongTextFieldTo1022Characters(): void {
    $longText = str_repeat('a', 1025);
    $path = $this->writeCsv($this->baseHeaders(), [$this->baseRow(['text' => $longText])]);

    $captured = NULL;
    $this->reportsMock->method('insertNlReports')
      ->willReturnCallback(function (array $fields) use (&$captured): bool {
        $captured = $fields;
        return FALSE;
      });

    $context = $this->baseContext();
    ImportReportsBatch::process(['uri' => $path], $context);

    $this->assertSame(1022, strlen($captured['text']));
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::finished — success
  // -------------------------------------------------------------------------

  public function testFinishedSuccessAddsThreeStatusMessages(): void {
    $this->messengerMock->expects($this->exactly(3))->method('addStatus');
    $this->messengerMock->expects($this->never())->method('addError');

    ImportReportsBatch::finished(TRUE, ['recordCount' => 5, 'uri' => '/tmp/reports.csv'], []);
  }

  // -------------------------------------------------------------------------
  // ImportReportsBatch::finished — failure
  // -------------------------------------------------------------------------

  public function testFinishedFailureAddsStatusAndError(): void {
    $this->messengerMock->expects($this->once())->method('addStatus');
    $this->messengerMock->expects($this->once())->method('addError');

    ImportReportsBatch::finished(FALSE, [], []);
  }

}
