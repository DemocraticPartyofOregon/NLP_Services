<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use Throwable;

/**
 * Shared retry/backoff helpers for Api* classes calling the VAN API.
 *
 * The VAN API enforces a per-key rate limit and responds with HTTP 429
 * (body like `{"errors":[{"code":"429","text":"Rate limit exceeded. Try
 * again in 9 ms."}]}`) once it's exceeded (vendor-stated limit: 15 requests
 * per second). sendInRateLimitedGroups() keeps batch lookups under that limit by
 * sending requests in groups paced by VanRateLimiter and retries only the
 * requests that were still rate-limited, honoring the API's own backoff
 * hint and using smaller groups on retry rounds.
 *
 * VAN also occasionally accepts a request and then never answers it (cURL
 * error 28, "Operation timed out ... with 0 bytes received"), or answers
 * with a 502/503/504. Those are retried too, but only for GET/HEAD
 * requests: a POST that timed out may already have been applied by VAN,
 * and sending it again could record the same canvass response twice.
 */
trait ApiRetryTrait {

  private const int MAX_RETRIES = 4;

  /**
   * A 429 gets more retries than other failures. VAN's per-key limit is
   * shared with every other process using the key (cron queues, other
   * users), and while one of them keeps the key busy, the "Try again in X ms"
   * hint is too short to wait out.
   */
  private const int MAX_RATE_LIMIT_RETRIES = 6;
  private const int RETRY_GROUP_SIZE = 3;

  /**
   * Guzzle options for every VAN request. Without them, Drupal's http_client
   * waits 30 seconds on a stalled request; failing sooner lets the retry
   * start sooner.
   */
  private const array REQUEST_OPTIONS = [
    'connect_timeout' => 10,
    'timeout' => 20,
  ];

  private const array TRANSIENT_STATUS_CODES = [502, 503, 504];
  private const array RETRYABLE_TRANSIENT_METHODS = ['GET', 'HEAD'];

  /**
   * Sends one request per key and waits for every reply.
   *
   * Requests go out in groups of at most VanRateLimiter::MAX_REQUESTS. All
   * requests in a group must be answered before the next group is sent,
   * and VanRateLimiter holds each group back until sending it keeps the
   * rate at or under 15 requests per second. Requests rejected with a 429,
   * or GET requests that timed out or got a 502/503/504, are retried in
   * smaller groups after a delay; any other failure, or a retryable failure
   * on the final attempt, is passed to $onFailed.
   *
   * @param array $keys - identifiers (e.g., vanids), one request each.
   * @param callable $buildRequest - fn($key): RequestInterface
   * @param callable $onFulfilled - fn(ResponseInterface $response, $key)
   * @param callable $onFailed - fn(Throwable $reason, $key)
   */
  private function sendInRateLimitedGroups(array $keys, callable $buildRequest, callable $onFulfilled, callable $onFailed): void {
    $pending = $keys;
    $groupSize = VanRateLimiter::MAX_REQUESTS;
    $retryDelayMs = 0;

    for ($attempt = 0; $pending && $attempt <= self::MAX_RATE_LIMIT_RETRIES; $attempt++) {
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
          'options' => self::REQUEST_OPTIONS,
          'fulfilled' => fn($response, $key) => $onFulfilled($response, $key),
          'rejected' => function($reason, $key) use (&$retry, &$retryDelayMs, $attempt, $onFailed) {
            if ($this->isRetryable($reason) && $attempt < $this->maxRetries($reason)) {
              $retry[] = $key;
              $retryDelayMs = max($retryDelayMs, $this->retryDelayMs($reason, $attempt));
              return;
            }
            if ($this->isRetryable($reason)) {
              $this->logRetriesExhausted($reason, $key, $attempt);
            }
            $onFailed($reason, $key);
          },
        ]);
        $pool->promise()->wait();
      }
      $pending = $retry;
    }
  }

  /**
   * Sends a single request with the VAN timeouts, retrying it on the same
   * failures sendInRateLimitedGroups() retries.  Callers keep their own
   * handling of the final failure (e.g., a 404 meaning "no such person").
   *
   * @param string $method
   * @param string $url
   * @param int|string $logKey - identifier logged with each retry (e.g., a vanid), never the URL.
   * @return ResponseInterface
   * @throws GuzzleException - the last failure, when it can't be retried or the retries ran out.
   */
  private function requestWithRetry(string $method, string $url, int|string $logKey): ResponseInterface {
    for ($attempt = 0; ; $attempt++) {
      try {
        return $this->client->request($method, $url, self::REQUEST_OPTIONS);
      }
      catch (GuzzleException $e) {
        if ($this->isRetryable($e) && $attempt < $this->maxRetries($e)) {
          $this->sleepMs($this->retryDelayMs($e, $attempt));
          continue;
        }
        if ($this->isRetryable($e)) {
          $this->logRetriesExhausted($e, $logKey, $attempt);
        }
        throw $e;
      }
    }
  }

  /**
   * Whether a failed request is worth sending again.
   *
   * A 429 means VAN rejected the request without acting on it, so it is
   * always safe to resend. Timeouts, dropped connections, and 502/503/504
   * are only resent for GET/HEAD, since VAN may have already acted on them.
   */
  private function isRetryable(Throwable $reason): bool {
    if ($this->isRateLimited($reason)) {
      return TRUE;
    }
    return $this->isTransient($reason) && $this->isSafeToResend($reason);
  }

  private function isRateLimited(Throwable $reason): bool {
    return $reason->getCode() == 429;
  }

  private function maxRetries(Throwable $reason): int {
    return $this->isRateLimited($reason) ? self::MAX_RATE_LIMIT_RETRIES : self::MAX_RETRIES;
  }

  /**
   * A network-level failure (timeout, refused/reset connection) or a
   * gateway/unavailable response from VAN.
   */
  private function isTransient(Throwable $reason): bool {
    if ($reason instanceof ConnectException) {
      return TRUE;
    }
    if ($reason instanceof RequestException && !$reason->hasResponse()) {
      return TRUE;
    }
    return in_array($reason->getCode(), self::TRANSIENT_STATUS_CODES, TRUE);
  }

  private function isSafeToResend(Throwable $reason): bool {
    if (!method_exists($reason, 'getRequest')) {
      return FALSE;
    }
    $method = strtoupper($reason->getRequest()->getMethod());
    return in_array($method, self::RETRYABLE_TRANSIENT_METHODS, TRUE);
  }

  /**
   * Records in the Drupal log a request that was still failing when its
   * retries ran out, so a VAN support ticket can cite the time and the
   * person ID of a request VAN didn't answer. Retries that later succeed
   * are not logged; they are routine and flooded the log. Only the cURL
   * error text and status code are logged, never the exception message:
   * Guzzle's message embeds the request URL, which carries the API
   * credentials.
   */
  private function logRetriesExhausted(Throwable $reason, int|string $key, int $retries): void {
    if (!Drupal::hasContainer()) {
      return;
    }
    $context = method_exists($reason, 'getHandlerContext') ? $reason->getHandlerContext() : [];
    $detail = $context['error'] ?? '';
    Drupal::logger('nlpservices')->error(
      'VAN request for ID @key failed (@class, status @code: @detail) after @retries retries.',
      [
        '@key' => $key,
        '@class' => (new ReflectionClass($reason))->getShortName(),
        '@code' => $reason->getCode(),
        '@detail' => $detail,
        '@retries' => $retries,
      ],
    );
  }

  /**
   * How long to wait before the next retry, in whole milliseconds.
   *
   * Exponential backoff (250 ms, 500 ms, 1 s, ...), stretched to a
   * `Retry-After` header or the "Try again in X ms" hint VAN puts in the
   * response body when either asks for longer. The hint alone is not
   * enough: it is often only a few ms, so a key kept busy by another
   * process would use up every retry in about a second. A small random
   * jitter is added so that requests which were rate-limited together
   * don't all retry at exactly the same instant.
   */
  private function retryDelayMs(Throwable $reason, int $attempt): int {
    $backoffMs = (int) (250 * (2 ** $attempt));
    $response = method_exists($reason, 'getResponse') ? $reason->getResponse() : NULL;
    if ($response) {
      if ($response->hasHeader('Retry-After')) {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if (is_numeric($retryAfter)) {
          return max((int) $retryAfter * 1000, $backoffMs) + mt_rand(0, 250);
        }
      }
      try {
        $body = (string) $response->getBody();
        if (preg_match('/Try again in\s+(\d+)\s*ms/i', $body, $matches)) {
          return max((int) $matches[1], $backoffMs) + mt_rand(0, 250);
        }
      }
      catch (Throwable) {
        // Body unavailable/unreadable; fall through to backoff below.
      }
    }
    return $backoffMs + mt_rand(0, 250);
  }

  private function sleepMs(int $ms): void {
    usleep($ms * 1000);
  }

}
