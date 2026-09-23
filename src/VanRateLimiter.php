<?php

namespace Drupal\nlpservices;

/**
 * Process-wide limit on how fast requests are sent to the VAN API.
 *
 * VAN throttles each API key at 15 requests per second. The limiter keeps
 * the start time of every request sent in the last second and, before a
 * group of requests is sent, waits until sending it would keep that count
 * at or under the limit. The state is static so that back-to-back calls
 * from different Api* classes within one PHP request share the same
 * budget. Separate PHP processes (e.g. a cron queue worker running while a
 * user submits a form) are not coordinated; the 429 retry in ApiRetryTrait
 * covers that case.
 */
final class VanRateLimiter {

  public const int MAX_REQUESTS = 15;
  public const int WINDOW_MS = 1000;

  /** @var float[] Start times (microtime seconds) of recently sent requests. */
  private static array $sentAt = [];

  /**
   * Blocks until $count more requests can be sent, then records them as sent.
   *
   * @param int $count - number of requests about to be sent, at most
   *   MAX_REQUESTS.
   */
  public static function acquire(int $count): void {
    $count = min(max($count, 0), self::MAX_REQUESTS);
    $window = self::WINDOW_MS / 1000;
    while (TRUE) {
      $now = microtime(TRUE);
      self::$sentAt = array_values(array_filter(self::$sentAt, fn($t) => $now - $t < $window));
      $excess = count(self::$sentAt) + $count - self::MAX_REQUESTS;
      if ($excess <= 0) {
        break;
      }
      // The oldest $excess entries must age out of the window first.
      $waitUntil = self::$sentAt[$excess - 1] + $window;
      usleep((int) ceil(($waitUntil - $now) * 1000000));
    }
    for ($i = 0; $i < $count; $i++) {
      self::$sentAt[] = $now;
    }
  }

  /**
   * Clears the recorded send times. Intended for tests.
   */
  public static function reset(): void {
    self::$sentAt = [];
  }

}
