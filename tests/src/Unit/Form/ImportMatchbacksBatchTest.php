<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\Tests\UnitTestCase;

/**
 * @group nlpservices
 */
class ImportMatchbacksBatchTest extends UnitTestCase {

  private NlpMatchbacks $matchbacksMock;
  private MessengerInterface $messengerMock;
  private FileSystemInterface $fileObjMock;
  private array $tempFiles = [];

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once __DIR__ . '/../../../../src/Form/ImportMatchbacksBatch.php';
  }

  protected function setUp(): void {
    parent::setUp();

    $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);
    $this->fileObjMock    = $this->createMock(FileSystemInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation',     $this->getStringTranslationStub());
    $container->set('messenger',              $this->messengerMock);
    $container->set('nlpservices.matchbacks', $this->matchbacksMock);
    $container->set('file_system',            $this->fileObjMock);
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

  private function writeCsvFile(array $headers, array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'nlp_mb_test_');
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, escape: '');
    foreach ($rows as $row) {
      fputcsv($fh, $row, escape: '');
    }
    fclose($fh);
    $this->tempFiles[] = $path;
    return $path;
  }

  private function writeTsvFile(array $headers, array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'nlp_mb_test_');
    $fh = fopen($path, 'w');
    fwrite($fh, implode("\t", $headers) . "\n");
    foreach ($rows as $row) {
      fwrite($fh, implode("\t", $row) . "\n");
    }
    fclose($fh);
    $this->tempFiles[] = $path;
    return $path;
  }

  private function baseArg(string $fileUri, string $fileType = 'csv'): array {
    return [
      'fileUri'  => $fileUri,
      'fileType' => $fileType,
      'fieldPos' => ['vanid' => 0, 'ballotReceived' => 1],
    ];
  }

  private function baseContext(): array {
    return ['sandbox' => [], 'finished' => 0, 'results' => []];
  }

  private function stubTransaction(): void {
    $this->matchbacksMock->method('matchbackTransaction')
      ->willReturn(new class extends Transaction {
        public function __construct() {}
        public function __destruct() {}
      });
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — file cannot be opened
  // -------------------------------------------------------------------------

  public function testBatchSetsFinishedAndFileUriWhenFileCannotBeOpened(): void {
    $this->matchbacksMock->expects($this->never())->method('matchbackTransaction');

    $context = $this->baseContext();
    @importMatchbacksBatch($this->baseArg('/nonexistent/path/file.csv'), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame('/nonexistent/path/file.csv', $context['results']['fileUri']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — CSV: full file processed, done in one pass
  // -------------------------------------------------------------------------

  public function testBatchProcessesCsvFileAndSetsFinishedToOne(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(2, $context['results']['matchbackCount']);
    $this->assertSame($path, $context['results']['fileUri']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — TSV: full file processed, done in one pass
  // -------------------------------------------------------------------------

  public function testBatchProcessesTsvFileAndSetsFinishedToOne(): void {
    $path = $this->writeTsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['33333', '11/03/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path, 'txt'), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(1, $context['results']['matchbackCount']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — skip records with empty ballotReceived
  // -------------------------------------------------------------------------

  public function testBatchSkipsRecordsWithEmptyBallotReceived(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', ''], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');
    $this->matchbacksMock->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['results']['matchbackCount']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — skip insert when vanid is empty
  // -------------------------------------------------------------------------

  public function testBatchSkipsInsertForRecordWithEmptyVanid(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['', '11/01/2024'], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    // matchbackExists must only be called once — for the valid vanid, not the empty one.
    $this->matchbacksMock->expects($this->once())->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');
    $this->matchbacksMock->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — skip duplicate vanids within the same batch
  // -------------------------------------------------------------------------

  public function testBatchSkipsDuplicateVanidInSameBatch(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024'], ['11111', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    // Only the first occurrence of vanid 11111 should be inserted.
    $this->matchbacksMock->expects($this->once())->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');
    $this->matchbacksMock->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — skip records that already exist in the DB
  // -------------------------------------------------------------------------

  public function testBatchSkipsInsertWhenMatchbackAlreadyExists(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(TRUE);
    $this->matchbacksMock->expects($this->never())->method('insertMatchbacks');
    $this->matchbacksMock->method('flushMatchbacks');
    $this->matchbacksMock->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — batch limit triggers mid-file pause
  // -------------------------------------------------------------------------

  public function testBatchBreaksAndSavesSeekWhenBatchLimitReached(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    // Returning TRUE from insertMatchbacks signals the batch limit was reached.
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(TRUE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertArrayHasKey('seek', $context['sandbox']);
    $this->assertArrayHasKey('matchbackCount', $context['sandbox']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — resume from a previously saved seek position
  // -------------------------------------------------------------------------

  public function testBatchResumesFromSavedSeekPosition(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    // Calculate the byte offset of the second data row.
    $fh = fopen($path, 'r');
    fgetcsv($fh, escape: ''); // header
    fgetcsv($fh, escape: ''); // first data row — already processed in a prior batch
    $seekPos = ftell($fh);
    fclose($fh);

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-01');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    $context['sandbox']['seek']          = $seekPos;
    $context['sandbox']['matchbackCount'] = 1;  // one record already counted

    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
    // The prior count (1) plus the resumed record (22222) = 2.
    $this->assertSame(2, $context['results']['matchbackCount']);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatch — latest matchback date tracking
  // -------------------------------------------------------------------------

  public function testBatchUpdatesLatestMatchbackDateWhenNewerRecordFound(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/15/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-01');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');

    $capturedDate = NULL;
    $this->matchbacksMock->expects($this->once())
      ->method('setLatestMatchbackDate')
      ->willReturnCallback(static function (string $date) use (&$capturedDate): void {
        $capturedDate = $date;
      });

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame('2024-11-15', $capturedDate);
  }

  public function testBatchPreservesLatestMatchbackDateWhenOlderRecordFound(): void {
    $path = $this->writeCsvFile(
      ['VoterFileVANID', 'BallotReceived'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    // Existing latest is already newer than the file record.
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-30');
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');

    $capturedDate = NULL;
    $this->matchbacksMock->method('setLatestMatchbackDate')
      ->willReturnCallback(static function (string $date) use (&$capturedDate): void {
        $capturedDate = $date;
      });

    $context = $this->baseContext();
    importMatchbacksBatch($this->baseArg($path), $context);

    $this->assertSame('2024-11-30', $capturedDate);
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatchFinished — success
  // -------------------------------------------------------------------------

  public function testBatchFinishedSuccessAddsTwoStatusMessagesAndUnlinksFile(): void {
    $this->messengerMock->expects($this->exactly(2))->method('addStatus');
    $this->fileObjMock->expects($this->once())->method('unlink')->with('/tmp/matchback.csv');

    importMatchbacksBatchFinished(
      TRUE,
      ['fileUri' => '/tmp/matchback.csv', 'matchbackCount' => 5],
      [],
    );
  }

  // -------------------------------------------------------------------------
  // importMatchbacksBatchFinished — failure
  // -------------------------------------------------------------------------

  public function testBatchFinishedFailureAddsErrorAndUnlinksFile(): void {
    $this->messengerMock->expects($this->once())->method('addError');
    $this->fileObjMock->expects($this->once())->method('unlink')->with('/tmp/matchback.csv');

    importMatchbacksBatchFinished(
      FALSE,
      ['fileUri' => '/tmp/matchback.csv'],
      [],
    );
  }

}
