<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiRetryTrait;
  use Drupal\nlpservices\VanRateLimiter;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\ClientException;
  use GuzzleHttp\Exception\ConnectException;
  use GuzzleHttp\Exception\ServerException;
  use GuzzleHttp\Promise\FulfilledPromise;
  use GuzzleHttp\Promise\RejectedPromise;
  use GuzzleHttp\Psr7\Request;
  use GuzzleHttp\Psr7\Response;
  use Psr\Http\Message\RequestInterface;
  use Psr\Log\LoggerInterface;
  use Throwable;

  /**
   * Exposes ApiRetryTrait's private helpers and records sleeps instead of
   * actually sleeping, so retry tests run instantly.
   */
  class ApiRetryTraitTestDouble {
    use ApiRetryTrait;

    public array $sleeps = [];

    public function __construct(protected ClientInterface $client) {}

    public function send(array $keys, callable $buildRequest, callable $onFulfilled, callable $onFailed): void {
      $this->sendInRateLimitedGroups($keys, $buildRequest, $onFulfilled, $onFailed);
    }

    public function retryable(Throwable $reason): bool {
      return $this->isRetryable($reason);
    }

    public function log(Throwable $reason, int $key, int $attempt): void {
      $this->logRetriesExhausted($reason, $key, $attempt);
    }

    private function sleepMs(int $ms): void {
      $this->sleeps[] = $ms;
    }

  }

  class ApiRetryTraitTest extends UnitTestCase {

    private const string URL = 'https://testapp:secret-key|0@api.example.com/v4/people/';

    protected function setUp(): void {
      parent::setUp();
      VanRateLimiter::reset();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function request(string $method, int $vanid = 3034261): Request {
      return new Request($method, self::URL . $vanid);
    }

    /** What Guzzle's curl handler throws for cURL error 28. */
    private function timeout(RequestInterface $request): ConnectException {
      $error = 'Operation timed out after 30002 milliseconds with 0 bytes received';
      return new ConnectException(
        "cURL error 28: $error (see https://curl.se/libcurl/c/libcurl-errors.html) for " . $request->getUri(),
        $request,
        NULL,
        ['errno' => 28, 'error' => $error],
      );
    }

    private function statusException(RequestInterface $request, int $status, string $body = ''): Throwable {
      $response = new Response($status, [], $body);
      return $status >= 500
        ? new ServerException("status $status", $request, $response)
        : new ClientException("status $status", $request, $response);
    }

    /**
     * Sends $keys through the trait with a client that answers each
     * sendAsync() call with the next entry of $outcomes: a Response for
     * success, or a callable fn(RequestInterface): Throwable for failure.
     *
     * @return array{0: ApiRetryTraitTestDouble, 1: array, 2: array, 3: array}
     *   The double, fulfilled keys, failed keys, and each call's options.
     */
    private function sendThroughTrait(array $keys, string $method, array $outcomes): array {
      $client = $this->createMock(ClientInterface::class);
      $options = [];
      $client->method('sendAsync')->willReturnCallback(
        function (RequestInterface $request, array $opts) use (&$outcomes, &$options) {
          $options[] = $opts;
          $outcome = array_shift($outcomes);
          if ($outcome instanceof Response) {
            return new FulfilledPromise($outcome);
          }
          return new RejectedPromise($outcome($request));
        }
      );

      $double = new ApiRetryTraitTestDouble($client);
      $fulfilled = $failed = [];
      $double->send(
        $keys,
        fn($key) => $this->request($method, $key),
        function ($response, $key) use (&$fulfilled) { $fulfilled[] = $key; },
        function ($reason, $key) use (&$failed) { $failed[] = $key; },
      );
      return [$double, $fulfilled, $failed, $options];
    }

    // -------------------------------------------------------------------------
    // sendInRateLimitedGroups — transient failures
    // -------------------------------------------------------------------------

    public function testGetTimeoutIsRetriedAndSucceeds(): void {
      [$double, $fulfilled, $failed] = $this->sendThroughTrait([3034261], 'GET', [
        fn($request) => $this->timeout($request),
        new Response(200, [], '{}'),
      ]);

      $this->assertSame([3034261], $fulfilled);
      $this->assertSame([], $failed);
      $this->assertCount(1, $double->sleeps);
    }

    public function testOnlyTheTimedOutRequestIsRetried(): void {
      [, $fulfilled, $failed, $options] = $this->sendThroughTrait([1, 2, 3], 'GET', [
        new Response(200, [], '{}'),
        fn($request) => $this->timeout($request),
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
      ]);

      $this->assertEqualsCanonicalizing([1, 2, 3], $fulfilled);
      $this->assertSame([], $failed);
      $this->assertCount(4, $options);
    }

    /**
     * @dataProvider gatewayStatusProvider
     */
    public function testGetGatewayErrorIsRetried(int $status): void {
      [, $fulfilled, $failed] = $this->sendThroughTrait([3034261], 'GET', [
        fn($request) => $this->statusException($request, $status),
        new Response(200, [], '{}'),
      ]);

      $this->assertSame([3034261], $fulfilled);
      $this->assertSame([], $failed);
    }

    public static function gatewayStatusProvider(): array {
      return [[502], [503], [504]];
    }

    public function testGetTimeoutGivesUpAfterMaxRetries(): void {
      $alwaysTimeout = array_fill(0, 10, fn($request) => $this->timeout($request));
      [$double, $fulfilled, $failed, $options] = $this->sendThroughTrait([3034261], 'GET', $alwaysTimeout);

      $this->assertSame([], $fulfilled);
      $this->assertSame([3034261], $failed);
      // One initial attempt plus four retries.
      $this->assertCount(5, $options);
      $this->assertCount(4, $double->sleeps);
    }

    public function testPostTimeoutIsNotRetried(): void {
      // VAN may already have recorded a POST it didn't answer; resending it
      // could record the same canvass response twice.
      [$double, $fulfilled, $failed, $options] = $this->sendThroughTrait([3034261], 'POST', [
        fn($request) => $this->timeout($request),
        new Response(204),
      ]);

      $this->assertSame([], $fulfilled);
      $this->assertSame([3034261], $failed);
      $this->assertCount(1, $options);
      $this->assertSame([], $double->sleeps);
    }

    public function testPostServiceUnavailableIsNotRetried(): void {
      [, $fulfilled, $failed, $options] = $this->sendThroughTrait([3034261], 'POST', [
        fn($request) => $this->statusException($request, 503),
        new Response(204),
      ]);

      $this->assertSame([], $fulfilled);
      $this->assertSame([3034261], $failed);
      $this->assertCount(1, $options);
    }

    public function testPostRateLimitIsStillRetried(): void {
      [$double, $fulfilled, $failed] = $this->sendThroughTrait([3034261], 'POST', [
        fn($request) => $this->statusException($request, 429, '{"errors":[{"code":"429","text":"Rate limit exceeded. Try again in 9 ms."}]}'),
        new Response(204),
      ]);

      $this->assertSame([3034261], $fulfilled);
      $this->assertSame([], $failed);
      $this->assertCount(1, $double->sleeps);
    }

    public function testRateLimitBacksOffPastAShortHintAndGetsMoreRetries(): void {
      // While another process keeps the key busy, VAN keeps answering 429
      // with a hint of a few ms; waiting only that long used up every retry
      // in about a second.
      $rateLimited = array_fill(0, 6, fn($request) => $this->statusException($request, 429, '{"errors":[{"code":"429","text":"Rate limit exceeded. Try again in 38 ms."}]}'));
      [$double, $fulfilled, $failed, $options] = $this->sendThroughTrait([1754250], 'GET', [...$rateLimited, new Response(200, [], '{}')]);

      $this->assertSame([1754250], $fulfilled);
      $this->assertSame([], $failed);
      $this->assertCount(7, $options);
      $this->assertCount(6, $double->sleeps);
      foreach ([250, 500, 1000, 2000, 4000, 8000] as $i => $minMs) {
        $this->assertGreaterThanOrEqual($minMs, $double->sleeps[$i]);
        $this->assertLessThanOrEqual($minMs + 250, $double->sleeps[$i]);
      }
    }

    public function testRateLimitGivesUpAfterMaxRateLimitRetries(): void {
      $rateLimited = array_fill(0, 10, fn($request) => $this->statusException($request, 429, '{"errors":[{"code":"429","text":"Rate limit exceeded. Try again in 38 ms."}]}'));
      [, $fulfilled, $failed, $options] = $this->sendThroughTrait([1754250], 'GET', $rateLimited);

      $this->assertSame([], $fulfilled);
      $this->assertSame([1754250], $failed);
      $this->assertCount(7, $options);
    }

    public function testLongerRateLimitHintIsHonored(): void {
      [$double] = $this->sendThroughTrait([1754250], 'GET', [
        fn($request) => $this->statusException($request, 429, '{"errors":[{"code":"429","text":"Rate limit exceeded. Try again in 900 ms."}]}'),
        new Response(200, [], '{}'),
      ]);

      $this->assertGreaterThanOrEqual(900, $double->sleeps[0]);
    }

    public function testGetNotFoundIsNotRetried(): void {
      [, $fulfilled, $failed, $options] = $this->sendThroughTrait([3034261], 'GET', [
        fn($request) => $this->statusException($request, 404),
        new Response(200, [], '{}'),
      ]);

      $this->assertSame([], $fulfilled);
      $this->assertSame([3034261], $failed);
      $this->assertCount(1, $options);
    }

    public function testGetInternalServerErrorIsNotRetried(): void {
      [, , $failed, $options] = $this->sendThroughTrait([3034261], 'GET', [
        fn($request) => $this->statusException($request, 500),
        new Response(200, [], '{}'),
      ]);

      $this->assertSame([3034261], $failed);
      $this->assertCount(1, $options);
    }

    public function testEveryRequestUsesExplicitTimeouts(): void {
      [, , , $options] = $this->sendThroughTrait([1, 2], 'GET', [
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
      ]);

      foreach ($options as $opts) {
        $this->assertSame(10, $opts['connect_timeout']);
        $this->assertSame(20, $opts['timeout']);
      }
    }

    // -------------------------------------------------------------------------
    // isRetryable
    // -------------------------------------------------------------------------

    public function testExceptionWithoutRequestIsNotRetryable(): void {
      $double = new ApiRetryTraitTestDouble($this->createMock(ClientInterface::class));
      $this->assertFalse($double->retryable(new \RuntimeException('boom', 503)));
    }

    // -------------------------------------------------------------------------
    // logRetriesExhausted
    // -------------------------------------------------------------------------

    /**
     * Installs a logger that counts the errors logged to nlpservices.
     */
    private function countLoggedErrors(): \ArrayObject {
      $logged = new \ArrayObject();
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->never())->method('warning');
      $logger->method('error')->willReturnCallback(function (string $message, array $context) use ($logged) {
        $logged[] = $context;
      });
      $factory = $this->createMock(LoggerChannelFactoryInterface::class);
      $factory->method('get')->with('nlpservices')->willReturn($logger);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $factory);
      \Drupal::setContainer($container);
      return $logged;
    }

    public function testRetryThatSucceedsIsNotLogged(): void {
      $logged = $this->countLoggedErrors();
      $this->sendThroughTrait([32933], 'GET', [
        fn($request) => $this->timeout($request),
        new Response(200, [], '{}'),
      ]);

      $this->assertCount(0, $logged);
    }

    public function testRetriesRunningOutIsLoggedOnce(): void {
      $logged = $this->countLoggedErrors();
      $alwaysTimeout = array_fill(0, 10, fn($request) => $this->timeout($request));
      $this->sendThroughTrait([32933], 'GET', $alwaysTimeout);

      $this->assertCount(1, $logged);
      $this->assertSame(32933, $logged[0]['@key']);
      $this->assertSame(4, $logged[0]['@retries']);
    }

    public function testFailureThatIsNotRetriedIsNotLoggedByTheTrait(): void {
      // The caller reports it through $onFailed.
      $logged = $this->countLoggedErrors();
      $this->sendThroughTrait([32933], 'GET', [fn($request) => $this->statusException($request, 404)]);

      $this->assertCount(0, $logged);
    }

    public function testRetriesExhaustedIsLoggedWithoutCredentials(): void {
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->once())->method('error')
        ->willReturnCallback(function (string $message, array $context) {
          $this->assertSame(3034261, $context['@key']);
          $this->assertSame('ConnectException', $context['@class']);
          $this->assertStringContainsString('0 bytes received', $context['@detail']);
          $this->assertSame(4, $context['@retries']);
          $flat = $message . ' ' . implode(' ', array_map('strval', $context));
          $this->assertStringNotContainsString('secret-key', $flat);
          $this->assertStringNotContainsString('api.example.com', $flat);
        });
      $factory = $this->createMock(LoggerChannelFactoryInterface::class);
      $factory->method('get')->with('nlpservices')->willReturn($logger);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $factory);
      \Drupal::setContainer($container);

      $double = new ApiRetryTraitTestDouble($this->createMock(ClientInterface::class));
      $double->log($this->timeout($this->request('GET')), 3034261, 4);
    }

    public function testRetryLogIsSkippedWithoutContainer(): void {
      \Drupal::unsetContainer();
      $double = new ApiRetryTraitTestDouble($this->createMock(ClientInterface::class));
      $double->log($this->timeout($this->request('GET')), 3034261, 0);
      $this->addToAssertionCount(1);
    }

  }

}
