<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\Plugin\QueueWorker\nlpservices_matchback_queue;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpMatchbackQueueTest extends UnitTestCase {

    private NlpMatchbacks $matchbacksMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
      $this->loggerMock     = $this->createMock(LoggerInterface::class);

      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($this->loggerMock);

      $container = new ContainerBuilder();
      $container->set('nlpservices.matchbacks', $this->matchbacksMock);
      $container->set('logger.factory', $loggerFactoryMock);
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
      return new nlpservices_matchback_queue([], 'nlpservices_matchback_queue', []);
    }

    // -------------------------------------------------------------------------
    // processItem
    // -------------------------------------------------------------------------

    public function testProcessItemPassesFilePathToMatchbacksService(): void {
      $this->matchbacksMock->expects($this->once())->method('processMatchbackFile')
        ->with('public://temp/matchback_2024.csv')
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
        '/^Matchback import elapsed: \d+:\d+:\d+ Records=100 New=60 Skipped=35 Rejected=5$/',
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
        '/^Matchback import elapsed: \d+:\d+:\d+ Records=0 New=0 Skipped=0 Rejected=0$/',
        $captured
      );
    }

  }

}
