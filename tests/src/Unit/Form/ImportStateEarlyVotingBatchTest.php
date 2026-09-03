<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\nlpservices\NlpVoters;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class ImportStateEarlyVotingBatchTest extends UnitTestCase {

  private NlpVoters $votersMock;
  private NlpMatchbacks $matchbacksMock;
  private MessengerInterface $messengerMock;
  private array $tempFiles = [];

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once __DIR__ . '/../../../../src/Form/ImportStateEarlyVotingBatch.php';
  }

  protected function setUp(): void {
    parent::setUp();

    $this->votersMock     = $this->createMock(NlpVoters::class);
    $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation',    $this->getStringTranslationStub());
    $container->set('messenger',              $this->messengerMock);
    $container->set('nlpservices.voters',     $this->votersMock);
    $container->set('nlpservices.matchbacks', $this->matchbacksMock);
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
    $path = tempnam(sys_get_temp_dir(), 'nlp_sev_test_');
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, escape: '');
    foreach ($rows as $row) {
      fputcsv($fh, $row, escape: '');
    }
    fclose($fh);
    $this->tempFiles[] = $path;
    return $path;
  }

  private function baseArg(string $fileUri): array {
    return [
      'fileUri'  => $fileUri,
      'fieldPos' => ['stateFileId' => 0, 'date' => 1],
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

  private function stubEntityTypeManager(array $fileEntities = []): void {
    $storageMock = $this->createMock(EntityStorageInterface::class);
    $storageMock->method('loadByProperties')->willReturn($fileEntities);

    $etmMock = $this->createMock(EntityTypeManagerInterface::class);
    $etmMock->method('getStorage')->with('file')->willReturn($storageMock);

    Drupal::getContainer()->set('entity_type.manager', $etmMock);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — file cannot be opened
  // -------------------------------------------------------------------------

  public function testBatchFileOpenFailureSetsFinishedAndFileUri(): void {
    $this->matchbacksMock->expects($this->never())->method('matchbackTransaction');

    $context = $this->baseContext();
    @importStateEarlyVotingBatch($this->baseArg('/nonexistent/path/file.csv'), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame('/nonexistent/path/file.csv', $context['results']['fileUri']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — full file processed, done in one pass
  // -------------------------------------------------------------------------

  public function testBatchProcessesAllRowsAndFinishesWhenNoBatchLimitHit(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->method('getVanidByStateId')->willReturn(22222);
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate')->with('2024-11-01');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(1, $context['results']['matchedCount']);
    $this->assertSame($path, $context['results']['fileUri']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — skip lookup when stateFileId is empty
  // -------------------------------------------------------------------------

  public function testBatchSkipsLookupWhenStateFileIdEmpty(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['', '11/01/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->expects($this->never())->method('getVanidByStateId');
    $this->matchbacksMock->method('flushMatchbacks');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(0, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — skip insert when vanid is not found
  // -------------------------------------------------------------------------

  public function testBatchSkipsInsertWhenVanidNotFound(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->method('getVanidByStateId')->willReturn(0);
    $this->matchbacksMock->expects($this->never())->method('matchbackExists');
    $this->matchbacksMock->expects($this->never())->method('insertMatchbacks');
    $this->matchbacksMock->method('flushMatchbacks');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(0, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — skip insert when the matchback already exists
  // -------------------------------------------------------------------------

  public function testBatchSkipsInsertWhenMatchbackAlreadyExists(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->method('getVanidByStateId')->willReturn(22222);
    $this->matchbacksMock->method('matchbackExists')->willReturn(TRUE);
    $this->matchbacksMock->expects($this->never())->method('insertMatchbacks');
    $this->matchbacksMock->method('flushMatchbacks');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(0, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — skip duplicate vanids within the same batch
  // -------------------------------------------------------------------------

  public function testBatchSkipsDuplicateVanidInSameBatch(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    // Both state file IDs resolve to the same vanid.
    $this->votersMock->method('getVanidByStateId')->willReturn(99999);
    $this->matchbacksMock->expects($this->once())->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — skip records with an unparseable ballot date
  // -------------------------------------------------------------------------

  public function testBatchSkipsInsertWhenBallotDateIsUnparseable(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', 'not-a-date']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->method('getVanidByStateId')->willReturn(22222);
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->never())->method('insertMatchbacks');
    $this->matchbacksMock->method('flushMatchbacks');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(0, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — batch limit triggers mid-file pause
  // -------------------------------------------------------------------------

  public function testBatchPausesAndSavesSeekWhenBatchLimitReached(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
    $this->votersMock->method('getVanidByStateId')->willReturn(33333);
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    // Returning TRUE from insertMatchbacks signals the batch limit was reached.
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(TRUE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertArrayHasKey('seek', $context['sandbox']);
    $this->assertArrayHasKey('matchedCount', $context['sandbox']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — resume from a previously saved seek position
  // -------------------------------------------------------------------------

  public function testBatchResumesFromSavedSeekPosition(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024'], ['22222', '11/02/2024']],
    );

    // Calculate the byte offset after the header + first data row.
    $fh = fopen($path, 'r');
    fgetcsv($fh, escape: ''); // header
    fgetcsv($fh, escape: ''); // first data row — already processed in a prior batch.
    $seekPos = ftell($fh);
    fclose($fh);

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-01');
    $this->votersMock->method('getVanidByStateId')->willReturn(44444);
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->once())->method('flushMatchbacks');
    $this->matchbacksMock->expects($this->once())->method('setLatestMatchbackDate');

    $context = $this->baseContext();
    $context['sandbox']['seek']         = $seekPos;
    $context['sandbox']['matchedCount'] = 1; // one record already counted

    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame(1, $context['finished']);
    // The prior count (1) plus the resumed record (22222) = 2.
    $this->assertSame(2, $context['results']['matchedCount']);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatch — latest matchback date tracking
  // -------------------------------------------------------------------------

  public function testBatchUpdatesLatestMatchbackDateWhenNewerRecordFound(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/15/2024']],
    );

    $this->stubTransaction();
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-01');
    $this->votersMock->method('getVanidByStateId')->willReturn(55555);
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
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame('2024-11-15', $capturedDate);
  }

  public function testBatchPreservesLatestMatchbackDateWhenOlderRecordFound(): void {
    $path = $this->writeCsvFile(
      ['Assigned Voter ID', 'Ballot Received Date'],
      [['11111', '11/01/2024']],
    );

    $this->stubTransaction();
    // Existing latest is already newer than the file record.
    $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('2024-11-30');
    $this->votersMock->method('getVanidByStateId')->willReturn(66666);
    $this->matchbacksMock->method('matchbackExists')->willReturn(FALSE);
    $this->matchbacksMock->method('insertMatchbacks')->willReturn(FALSE);
    $this->matchbacksMock->method('flushMatchbacks');

    $capturedDate = NULL;
    $this->matchbacksMock->method('setLatestMatchbackDate')
      ->willReturnCallback(static function (string $date) use (&$capturedDate): void {
        $capturedDate = $date;
      });

    $context = $this->baseContext();
    importStateEarlyVotingBatch($this->baseArg($path), $context);

    $this->assertSame('2024-11-30', $capturedDate);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatchFinished — success
  // -------------------------------------------------------------------------

  public function testFinishedSuccessAddsStatusMessageAndDeletesFileEntities(): void {
    $fileEntityMock = $this->createMock(File::class);
    $fileEntityMock->expects($this->once())->method('delete');
    $this->stubEntityTypeManager([$fileEntityMock]);

    $this->messengerMock->expects($this->once())->method('addStatus');
    $this->messengerMock->expects($this->never())->method('addError');

    importStateEarlyVotingBatchFinished(TRUE, ['fileUri' => '/tmp/state_ev.csv', 'matchedCount' => 5], []);
  }

  // -------------------------------------------------------------------------
  // importStateEarlyVotingBatchFinished — failure
  // -------------------------------------------------------------------------

  public function testFinishedFailureAddsErrorAndDeletesFileEntities(): void {
    $fileEntityMock = $this->createMock(File::class);
    $fileEntityMock->expects($this->once())->method('delete');
    $this->stubEntityTypeManager([$fileEntityMock]);

    $this->messengerMock->expects($this->never())->method('addStatus');
    $this->messengerMock->expects($this->once())->method('addError');

    importStateEarlyVotingBatchFinished(FALSE, ['fileUri' => '/tmp/state_ev.csv'], []);
  }

}
