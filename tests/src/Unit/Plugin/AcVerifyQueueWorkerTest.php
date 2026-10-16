<?php

namespace Drupal\Tests\nlpservices\Unit\Plugin;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\nlpservices\ApiActivistCodes;
use Drupal\nlpservices\Plugin\QueueWorker\nlpservices_ac_verify_queue;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class AcVerifyQueueWorkerTest extends UnitTestCase {

  private ApiActivistCodes&MockObject $activistCodesMock;

  protected function setUp(): void {
    parent::setUp();
    $this->activistCodesMock = $this->createMock(ApiActivistCodes::class);
    $container = new ContainerBuilder();
    $container->set('nlpservices.activist_codes', $this->activistCodesMock);
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::setContainer(new ContainerBuilder());
  }

  private function buildWorker(): nlpservices_ac_verify_queue {
    return new nlpservices_ac_verify_queue([], 'nlpservices_ac_verify_queue', []);
  }

  public function testRecentChangeIsPutBackUntilOldEnough(): void {
    $this->activistCodesMock->expects($this->never())->method('verifyActivistCodeChange');

    try {
      $this->buildWorker()->processItem(['vanids' => [111], 'queuedAt' => time() - 20]);
      $this->fail('Expected DelayedRequeueException');
    }
    catch (DelayedRequeueException $e) {
      $this->assertGreaterThan(0, $e->getDelay());
      $this->assertLessThanOrEqual(nlpservices_ac_verify_queue::MIN_AGE_SECS - 20, $e->getDelay());
    }
  }

  public function testOldEnoughChangeIsChecked(): void {
    $item = ['vanids' => [111], 'queuedAt' => time() - nlpservices_ac_verify_queue::MIN_AGE_SECS];
    $this->activistCodesMock->expects($this->once())->method('verifyActivistCodeChange')->with($item);

    $this->buildWorker()->processItem($item);
  }

}
