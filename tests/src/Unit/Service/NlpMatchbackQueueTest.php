<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Queue\QueueFactory;
  use Drupal\Core\Queue\QueueInterface;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\Plugin\QueueWorker\nlpservices_matchback_queue;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpMatchbackQueueTest extends UnitTestCase {

    private NlpMatchbacks $matchbacksMock;
    private LoggerInterface $loggerMock;
    private QueueInterface $queueMock;

    protected function setUp(): void {
      parent::setUp();
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
      $this->loggerMock     = $this->createMock(LoggerInterface::class);
      $this->queueMock      = $this->createMock(QueueInterface::class);

      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($this->loggerMock);
      $queueFactoryMock = $this->createMock(QueueFactory::class);
      $queueFactoryMock->method('get')->willReturn($this->queueMock);

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('queue', $queueFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildWorker(): nlpservices_matchback_queue {
      return new nlpservices_matchback_queue([], 'nlpservices_matchback_queue', [], $this->matchbacksMock);
    }

    // -------------------------------------------------------------------------
    // processItem
    // -------------------------------------------------------------------------

    public function testProcessItemPassesFilePathToMatchbacksService(): void {
      $this->matchbacksMock->expects($this->once())->method('processMatchbackFile')
        ->with('public://temp/matchback_2024.csv', 0, nlpservices_matchback_queue::CHUNK_ROWS)
        ->willReturn(['recordCount' => 0, 'newRecordCount' => 0, 'skippedRecordCount' => 0, 'rejectedRecordCount' => 0]);

      $this->buildWorker()->processItem(['filePath' => 'public://temp/matchback_2024.csv']);
    }

    public function testProcessItemLogsNoticeWithRecordCounts(): void {
      $this->matchbacksMock->method('processMatchbackFile')->willReturn([
        'recordCount'        => 100,
        'newRecordCount'     => 60,
        'skippedRecordCount' => 35,
        'rejectedRecordCount' => 5,
      ]);
      $captured = NULL;
      $this->loggerMock->expects($this->once())->method('notice')
        ->with($this->callback(function (string $msg) use (&$captured) {
          $captured = $msg;
          return TRUE;
        }));

      $this->buildWorker()->processItem(['filePath' => 'public://temp/matchback_2024.csv']);

      $this->assertMatchesRegularExpression(
        '/^Matchback import elapsed: \d+:\d+:\d+ Records=100 New=60 Skipped=35 Rejected=5 Chunks=1$/',
        $captured
      );
    }

    public function testProcessItemLogsNoticeWithAllZeroCounts(): void {
      $this->matchbacksMock->method('processMatchbackFile')->willReturn([
        'recordCount'        => 0,
        'newRecordCount'     => 0,
        'skippedRecordCount' => 0,
        'rejectedRecordCount' => 0,
      ]);
      $captured = NULL;
      $this->loggerMock->expects($this->once())->method('notice')
        ->with($this->callback(function (string $msg) use (&$captured) {
          $captured = $msg;
          return TRUE;
        }));

      $this->buildWorker()->processItem(['filePath' => 'public://temp/empty.csv']);

      $this->assertMatchesRegularExpression(
        '/^Matchback import elapsed: \d+:\d+:\d+ Records=0 New=0 Skipped=0 Rejected=0 Chunks=1$/',
        $captured
      );
    }

    private function chunkCounts(int $records, int $nextOffset): array {
      return ['recordCount' => $records, 'newRecordCount' => $records, 'skippedRecordCount' => 0,
        'rejectedRecordCount' => 0, 'nextOffset' => $nextOffset];
    }

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

}
