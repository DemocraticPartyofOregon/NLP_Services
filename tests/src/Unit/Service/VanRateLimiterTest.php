<?php

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\nlpservices\VanRateLimiter;
use Drupal\Tests\UnitTestCase;

class VanRateLimiterTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    VanRateLimiter::reset();
  }

  public function testFullGroupIsNotDelayed(): void {
    $start = microtime(TRUE);
    VanRateLimiter::acquire(VanRateLimiter::MAX_REQUESTS);
    $this->assertLessThan(0.1, microtime(TRUE) - $start);
  }

  public function testRequestBeyondLimitWaitsForWindowToPass(): void {
    VanRateLimiter::acquire(VanRateLimiter::MAX_REQUESTS);
    $start = microtime(TRUE);
    VanRateLimiter::acquire(1);
    $this->assertGreaterThanOrEqual(0.95, microtime(TRUE) - $start);
  }

  public function testSmallGroupsShareOneWindow(): void {
    $start = microtime(TRUE);
    VanRateLimiter::acquire(5);
    VanRateLimiter::acquire(5);
    VanRateLimiter::acquire(5);
    $this->assertLessThan(0.1, microtime(TRUE) - $start, 'Up to 15 requests fit in one window.');
    VanRateLimiter::acquire(5);
    $this->assertGreaterThanOrEqual(0.95, microtime(TRUE) - $start, 'The 16th request waits for the window.');
  }

  public function testOversizedCountIsCappedAtLimit(): void {
    $start = microtime(TRUE);
    VanRateLimiter::acquire(VanRateLimiter::MAX_REQUESTS + 10);
    $this->assertLessThan(0.1, microtime(TRUE) - $start);
  }

}
