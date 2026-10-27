<?php

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\nlpservices\NlpCronRunner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class NlpCronRunnerTest extends UnitTestCase {

  private QueueInterface&MockObject $queueMock;
  private QueueWorkerInterface&MockObject $workerMock;
  private TimeInterface&MockObject $timeMock;
  private int $now = 1000;

  protected function setUp(): void {
    parent::setUp();
    $this->queueMock = $this->createMock(QueueInterface::class);
    $this->workerMock = $this->createMock(QueueWorkerInterface::class);
    $this->timeMock = $this->createMock(TimeInterface::class);
    $this->timeMock->method('getCurrentTime')->willReturnCallback(fn() => $this->now);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  private function buildRunner(?QueueInterface $queue = NULL): NlpCronRunner {
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue ?? $this->queueMock);
    $workerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $workerManager->method('createInstance')->willReturn($this->workerMock);
    $runner = new NlpCronRunner($queueFactory, $workerManager, $this->timeMock);
    $runner->startBudget();
    return $runner;
  }

  private function item(array $data): object {
    return (object) ['data' => $data];
  }

  public function testRunQueueProcessesItemsUntilEmpty(): void {
    $first = $this->item(['n' => 1]);
    $second = $this->item(['n' => 2]);
    $this->queueMock->method('claimItem')->willReturnOnConsecutiveCalls($first, $second, FALSE);
    $this->workerMock->expects($this->exactly(2))->method('processItem');
    $this->queueMock->expects($this->exactly(2))->method('deleteItem');

    $this->assertTrue($this->buildRunner()->runQueue('nlpservices_matchback_queue'));
  }

  public function testRunQueueStopsWhenBudgetIsUsed(): void {
    $this->queueMock->method('claimItem')->willReturn($this->item([]));
    $this->queueMock->method('numberOfItems')->willReturn(3);
    $this->workerMock->expects($this->once())->method('processItem')
      ->willReturnCallback(function () { $this->now += NlpCronRunner::BUDGET_SECS; });

    $this->assertFalse($this->buildRunner()->runQueue('nlpservices_matchback_queue'));
  }

  public function testRunQueueDoesNothingWhenBudgetWasUsedBefore(): void {
    $runner = $this->buildRunner();
    $this->now += NlpCronRunner::BUDGET_SECS;
    $this->queueMock->expects($this->never())->method('claimItem');
    $this->queueMock->method('numberOfItems')->willReturn(0);

    $this->assertTrue($runner->runQueue('nlpservices_ac_verify_queue'));
  }

  public function testDelayedItemIsDelayed(): void {
    $queue = $this->createMock(DelayableQueueInterface::class);
    $item = $this->item([]);
    $queue->method('claimItem')->willReturnOnConsecutiveCalls($item, FALSE);
    $this->workerMock->method('processItem')->willThrowException(new DelayedRequeueException(60));
    $queue->expects($this->once())->method('delayItem')->with($item, 60);
    $queue->expects($this->never())->method('deleteItem');

    $this->assertTrue($this->buildRunner($queue)->runQueue('nlpservices_ac_verify_queue'));
  }

  public function testSuspendedQueueReleasesItemAndStops(): void {
    $item = $this->item([]);
    $this->queueMock->method('claimItem')->willReturn($item);
    $this->workerMock->expects($this->once())->method('processItem')->willThrowException(new SuspendQueueException());
    $this->queueMock->expects($this->once())->method('releaseItem')->with($item);

    $this->assertFalse($this->buildRunner()->runQueue('nlpservices_turf_ac_check_queue'));
  }

  public function testFailedItemIsLeftInQueue(): void {
    $this->queueMock->method('claimItem')->willReturnOnConsecutiveCalls($this->item([]), FALSE);
    $this->workerMock->method('processItem')->willThrowException(new RuntimeException('VAN down'));
    $this->queueMock->expects($this->never())->method('deleteItem');
    $this->queueMock->expects($this->never())->method('releaseItem');

    $this->buildRunner()->runQueue('nlpservices_turf_ac_check_queue');
  }

  public function testQueueIsEmpty(): void {
    $this->queueMock->method('numberOfItems')->willReturnOnConsecutiveCalls(0, 2);
    $runner = $this->buildRunner();

    $this->assertTrue($runner->queueIsEmpty('nlpservices_turf_ac_check_queue'));
    $this->assertFalse($runner->queueIsEmpty('nlpservices_turf_ac_check_queue'));
  }

}
