<?php

namespace Drupal\nlpservices;

use Throwable;

/**
 * Shared retry/backoff helpers for Api* classes calling the VAN API.
 *
 * The VAN API enforces a per-key rate limit and responds with HTTP 429
 * (body like `{"errors":[{"code":"429","text":"Rate limit exceeded. Try
 * again in 9 ms."}]}`) once it's exceeded, which single-shot calls and the
 * concurrency-10 Guzzle pools used for batch lookups can trigger under
 * load. These helpers let callers retry only the rate-limited requests,
 * honoring the API's own backoff hint, and fall back to a slower
 * concurrency on retry rounds.
 */
trait ApiRetryTrait {

  private const int MAX_RETRIES = 4;
  private const int POOL_CONCURRENCY = 10;
  private const int REDUCED_CONCURRENCY = 3;

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
