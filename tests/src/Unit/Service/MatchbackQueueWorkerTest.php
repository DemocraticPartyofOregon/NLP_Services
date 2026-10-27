<?php

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\nlpservices\Plugin\QueueWorker\nlpservices_matchback_queue;
use Drupal\Tests\UnitTestCase;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;

class MatchbackQueueWorkerTest extends UnitTestCase {

  private NlpMatchbacks&MockObject $matchbacksMock;
  private QueueInterface&MockObject $queueMock;
  private LoggerChannelInterface&MockObject $loggerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
    $this->queueMock = $this->createMock(QueueInterface::class);
    $this->loggerMock = $this->createMock(LoggerChannelInterface::class);

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($this->queueMock);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->loggerMock);

    $container = new ContainerBuilder();
    $container->set('nlpservices.matchbacks', $this->matchbacksMock);
    $container->set('queue', $queueFactory);
    $container->set('logger.factory', $loggerFactory);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  private function buildWorker(): nlpservices_matchback_queue {
    return new nlpservices_matchback_queue([], 'nlpservices_matchback_queue', []);
  }

  private function chunkCounts(int $records, int $nextOffset): array {
    return ['recordCount' => $records, 'newRecordCount' => $records, 'skippedRecordCount' => 0,
      'rejectedRecordCount' => 0, 'nextOffset' => $nextOffset];
  }

  /**
   * @throws Exception
   */
  public function testUnfinishedFileQueuesTheRestWithCountsSoFar(): void {
    $this->matchbacksMock->expects($this->once())->method('processMatchbackFile')
      ->with('/tmp/mb.csv', 0, nlpservices_matchback_queue::CHUNK_ROWS)
      ->willReturn($this->chunkCounts(25000, 1234));
    $this->queueMock->expects($this->once())->method('createItem')
      ->with($this->callback(function (array $data): bool {
        return $data['filePath'] === '/tmp/mb.csv'
          && $data['offset'] === 1234
          && $data['counts']['recordCount'] === 25000
          && $data['chunks'] === 1;
      }));
    $this->loggerMock->expects($this->never())->method('notice');

    $this->buildWorker()->processItem(['filePath' => '/tmp/mb.csv']);
  }

  /**
   * @throws Exception
   */
  public function testLastChunkLogsTotalsForWholeFile(): void {
    $this->matchbacksMock->method('processMatchbackFile')
      ->with('/tmp/mb.csv', 1234, nlpservices_matchback_queue::CHUNK_ROWS)
      ->willReturn($this->chunkCounts(100, 0));
    $this->queueMock->expects($this->never())->method('createItem');
    $this->loggerMock->expects($this->once())->method('notice')
      ->with($this->logicalAnd(
        $this->stringContains('Records=25100'),
        $this->stringContains('Chunks=2')
      ));

    $this->buildWorker()->processItem([
      'filePath' => '/tmp/mb.csv',
      'offset' => 1234,
      'counts' => ['recordCount' => 25000, 'newRecordCount' => 25000, 'skippedRecordCount' => 0,
        'rejectedRecordCount' => 0],
      'elapsed' => 3,
      'chunks' => 1,
    ]);
  }

}
