<?php

namespace Drupal\nlpservices;

use GuzzleHttp\Pool;
use Throwable;

/**
 * Shared retry/backoff helpers for Api* classes calling the VAN API.
 *
 * The VAN API enforces a per-key rate limit and responds with HTTP 429
 * (body like `{"errors":[{"code":"429","text":"Rate limit exceeded. Try
 * again in 9 ms."}]}`) once it's exceeded (vendor-stated limit: 15 requests
 * per second). sendInRateLimitedGroups() keeps batch lookups under that limit by
 * sending requests in groups paced by VanRateLimiter, and retries only the
 * requests that were still rate-limited, honoring the API's own backoff
 * hint and using smaller groups on retry rounds.
 */
trait ApiRetryTrait {

  private const int MAX_RETRIES = 4;
  private const int RETRY_GROUP_SIZE = 3;

  /**
   * Sends one request per key and waits for every reply.
   *
   * Requests go out in groups of at most VanRateLimiter::MAX_REQUESTS. All
   * requests in a group must be answered before the next group is sent,
   * and VanRateLimiter holds each group back until sending it keeps the
   * rate at or under 15 requests per second. Requests rejected with a 429
   * are retried in smaller groups after the API's suggested delay; any
   * other failure, or a 429 on the final attempt, is passed to $onFailed.
   *
   * @param array $keys - identifiers (e.g. vanids), one request each.
   * @param callable $buildRequest - fn($key): RequestInterface
   * @param callable $onFulfilled - fn(ResponseInterface $response, $key)
   * @param callable $onFailed - fn(Throwable $reason, $key)
   */
  private function sendInRateLimitedGroups(array $keys, callable $buildRequest, callable $onFulfilled, callable $onFailed): void {
    $pending = $keys;
    $groupSize = VanRateLimiter::MAX_REQUESTS;

    for ($attempt = 0; $pending && $attempt <= self::MAX_RETRIES; $attempt++) {
      if ($attempt > 0) {
        $this->sleepMs($retryDelayMs);
        $groupSize = self::RETRY_GROUP_SIZE;
      }
      $retry = [];
      $retryDelayMs = 0;

      foreach (array_chunk($pending, $groupSize) as $group) {
        VanRateLimiter::acquire(count($group));

        $requests = function() use ($group, $buildRequest) {
          foreach ($group as $key) {
            yield $key => $buildRequest($key);
          }
        };

        $pool = new Pool($this->client, $requests(), [
          'concurrency' => count($group),
          'fulfilled' => fn($response, $key) => $onFulfilled($response, $key),
          'rejected' => function($reason, $key) use (&$retry, &$retryDelayMs, $attempt, $onFailed) {
            if ($this->isRateLimited($reason) && $attempt < self::MAX_RETRIES) {
              $retry[] = $key;
              $retryDelayMs = max($retryDelayMs, $this->retryDelayMs($reason, $attempt));
              return;
            }
            $onFailed($reason, $key);
          },
        ]);
        $pool->promise()->wait();
      }
      $pending = $retry;
    }
  }

  private function isRateLimited(Throwable $reason): bool {
    return $reason->getCode() == 429;
  }

  /**
   * How long to wait before the next retry, in whole milliseconds.
   *
   * Prefers a `Retry-After` header, then the "Try again in X ms" hint VAN
   * puts in the response body, falling back to exponential backoff. A
   * small random jitter is added in all cases so that requests which were
   * rate-limited together don't all retry at exactly the same instant.
   */
  private function retryDelayMs(Throwable $reason, int $attempt): int {
    $response = method_exists($reason, 'getResponse') ? $reason->getResponse() : NULL;
    if ($response) {
      if ($response->hasHeader('Retry-After')) {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if (is_numeric($retryAfter)) {
          return max((int) $retryAfter * 1000, 250) + random_int(0, 250);
        }
      }
      try {
        $body = (string) $response->getBody();
        if (preg_match('/Try again in\s+(\d+)\s*ms/i', $body, $matches)) {
          return max((int) $matches[1], 250) + random_int(0, 250);
        }
      }
      catch (Throwable $e) {
        // Body unavailable/unreadable; fall through to backoff below.
      }
    }
    return (int) (250 * (2 ** $attempt)) + random_int(0, 250);
  }

  private function sleepMs(int $ms): void {
    usleep($ms * 1000);
  }

}
