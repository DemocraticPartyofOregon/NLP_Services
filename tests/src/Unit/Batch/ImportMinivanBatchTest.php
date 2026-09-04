<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Batch;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Batch\ImportMinivanBatch;
use Drupal\nlpservices\NlpMinivan;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class ImportMinivanBatchTest extends UnitTestCase {

  private NlpMinivan $minivanMock;
  private MessengerInterface $messengerMock;
  private LoggerInterface $loggerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->minivanMock   = $this->createMock(NlpMinivan::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);
    $this->loggerMock    = $this->createMock(LoggerInterface::class);

    $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($this->loggerMock);

    $container = new ContainerBuilder();
    $container->set('string_translation',   $this->getStringTranslationStub());
    $container->set('messenger',            $this->messengerMock);
    $container->set('logger.factory',       $loggerFactoryMock);
    $container->set('nlpservices.minivan',  $this->minivanMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function baseArg(): array {
    return [
      'records'  => [['van_id' => 1], ['van_id' => 2]],
      'fileType' => 'csv',
      'subject'  => 'MiniVAN Report',
      'month'    => 6,
      'day'      => 15,
    ];
  }

  private function baseCounts(): array {
    return [
      'recordCnt'    => 10,
      'processedCnt' => 8,
      'duplicateCnt' => 1,
      'rejectedCnt'  => 1,
      'notNlp'       => 0,
    ];
  }

  private function baseContext(): array {
    return ['sandbox' => [], 'finished' => 0, 'results' => []];
  }

  // -------------------------------------------------------------------------
  // ImportMinivanBatch::process — processing
  // -------------------------------------------------------------------------

  public function testBatchCallsProcessMinivanQueueReports(): void {
    $arg = $this->baseArg();
    $this->minivanMock->expects($this->once())
      ->method('processMinivanQueueReports')
      ->with($arg['records'], $arg['fileType'])
      ->willReturn($this->baseCounts());

    $context = $this->baseContext();
    ImportMinivanBatch::process($arg, $context);
  }

  public function testBatchSetsFinishedToOne(): void {
    $this->minivanMock->method('processMinivanQueueReports')->willReturn($this->baseCounts());

    $context = $this->baseContext();
    ImportMinivanBatch::process($this->baseArg(), $context);

    $this->assertSame(1, $context['finished']);
  }

  public function testBatchStoresSubjectInResults(): void {
    $this->minivanMock->method('processMinivanQueueReports')->willReturn($this->baseCounts());

    $context = $this->baseContext();
    ImportMinivanBatch::process($this->baseArg(), $context);

    $this->assertSame('MiniVAN Report', $context['results']['subject']);
  }

  public function testBatchStoresMonthAndDayInResults(): void {
    $this->minivanMock->method('processMinivanQueueReports')->willReturn($this->baseCounts());

    $context = $this->baseContext();
    ImportMinivanBatch::process($this->baseArg(), $context);

    $this->assertSame(6,  $context['results']['month']);
    $this->assertSame(15, $context['results']['day']);
  }

  public function testBatchStoresCountsReturnedByMinivan(): void {
    $counts = $this->baseCounts();
    $this->minivanMock->method('processMinivanQueueReports')->willReturn($counts);

    $context = $this->baseContext();
    ImportMinivanBatch::process($this->baseArg(), $context);

    $this->assertSame($counts, $context['results']['counts']);
  }

  // -------------------------------------------------------------------------
  // ImportMinivanBatch::finished — success
  // -------------------------------------------------------------------------

  public function testFinishedSuccessAddsStatusMessage(): void {
    $this->messengerMock->expects($this->once())->method('addStatus');

    ImportMinivanBatch::finished(
      TRUE,
      ['counts' => $this->baseCounts(), 'subject' => 'Report', 'month' => 6, 'day' => 15],
      [],
    );
  }

  public function testFinishedSuccessLogsNotice(): void {
    $this->loggerMock->expects($this->once())->method('notice');

    ImportMinivanBatch::finished(
      TRUE,
      ['counts' => $this->baseCounts(), 'subject' => 'Report', 'month' => 6, 'day' => 15],
      [],
    );
  }

  public function testFinishedSuccessDoesNotAddError(): void {
    $this->messengerMock->expects($this->never())->method('addError');

    ImportMinivanBatch::finished(
      TRUE,
      ['counts' => $this->baseCounts(), 'subject' => 'Report', 'month' => 6, 'day' => 15],
      [],
    );
  }

  // -------------------------------------------------------------------------
  // ImportMinivanBatch::finished — failure
  // -------------------------------------------------------------------------

  public function testFinishedFailureAddsErrorMessage(): void {
    $this->messengerMock->expects($this->once())->method('addError');

    ImportMinivanBatch::finished(FALSE, [], []);
  }

  public function testFinishedFailureLogsNotice(): void {
    $this->loggerMock->expects($this->once())->method('notice');

    ImportMinivanBatch::finished(FALSE, [], []);
  }

  public function testFinishedFailureDoesNotAddStatus(): void {
    $this->messengerMock->expects($this->never())->method('addStatus');

    ImportMinivanBatch::finished(FALSE, [], []);
  }

}
