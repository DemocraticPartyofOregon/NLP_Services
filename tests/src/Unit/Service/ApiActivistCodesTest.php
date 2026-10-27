<?php

namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Queue\QueueFactory;
  use Drupal\Core\Queue\QueueInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpAcHistory;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\nlpservices\VanRateLimiter;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\ConnectException;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Promise\FulfilledPromise;
  use GuzzleHttp\Promise\RejectedPromise;
  use PHPUnit\Framework\MockObject\MockObject;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;
  use Psr\Log\LoggerInterface;

  class ApiActivistCodesTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;
    /** Items given to the verify queue's createItem(), set up by setContainerMocks(). */
    private array $queuedItems = [];

    protected function setUp(): void {
      parent::setUp();
      VanRateLimiter::reset();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiActivistCodes {
      return new ApiActivistCodes(
        $this->clientMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    /**
     * Puts a logger mock and a verify queue mock in the container. Items the
     * service queues are collected in $this->queuedItems.
     */
    private function setContainerMocks(): LoggerInterface&MockObject {
      $loggerMock        = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $this->queuedItems = [];
      $queueMock = $this->createMock(QueueInterface::class);
      $queueMock->method('createItem')
        ->willReturnCallback(function ($item) {
          $this->queuedItems[] = $item;
          return count($this->queuedItems);
        });
      $queueFactoryMock = $this->createMock(QueueFactory::class);
      $queueFactoryMock->method('get')
        ->with(ApiActivistCodes::VERIFY_QUEUE)
        ->willReturn($queueMock);

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('queue', $queueFactoryMock);
      \Drupal::setContainer($container);
      return $loggerMock;
    }

    /**
     * Puts an NLPVoterAC history mock in the container. Each record() call is
     * collected in $recorded as [vanids, event, source].
     */
    private function setHistoryMock(array &$recorded): NlpAcHistory&MockObject {
      $historyMock = $this->createMock(NlpAcHistory::class);
      $historyMock->method('record')
        ->willReturnCallback(function (array $vanids, string $event, string $source) use (&$recorded) {
          if ($vanids) {
            $recorded[] = [$vanids, $event, $source];
          }
        });
      $historyMock->method('describe')->willReturn('222: Remove from turf delete (turfIndex 7) at 10:02:15');
      \Drupal::getContainer()->set('nlpservices.ac_history', $historyMock);
      return $historyMock;
    }

    private function makeErrorResponse(int $status, string $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($body);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn($status);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    private function makeHttpException(int $status, string $body): RequestException {
      return new RequestException(
        'error',
        $this->createMock(RequestInterface::class),
        $this->makeErrorResponse($status, $body),
      );
    }

    private function makeTimeoutException(): ConnectException {
      return new ConnectException(
        'timed out',
        $this->createMock(RequestInterface::class),
        NULL,
        ['error' => 'cURL error 28: Operation timed out'],
      );
    }

    private function verifyItem(array $overrides = []): array {
      return $overrides + [
        'vanids'    => [111],
        'codeName'  => 'NLPVoterAC',
        'rid'       => 1001,
        'action'    => 'Apply',
        'database'  => 0,
        'committee' => '',
        'source'    => 'turf sync of Oak Ave (turfIndex 7)',
        'queuedAt'  => 1700000000,
      ];
    }

    private function stubKeyValues(): void {
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key'  => 'test-key',
        'Url'      => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
    }

    private function makeResponse(mixed $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn(json_encode($body));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    private function makeGuzzleException(): RequestException {
      return new RequestException(
        'error',
        $this->createMock(RequestInterface::class),
      );
    }

    // -------------------------------------------------------------------------
    // getApiActivistCodes
    // -------------------------------------------------------------------------

    public function testGetApiActivistCodesReturnsItems(): void {
      $this->stubKeyValues();
      $items = [
        ['activistCodeId' => 1001, 'name' => 'NLPVoterAC',   'type' => 'Neighborhood Leader'],
        ['activistCodeId' => 1002, 'name' => 'NLPHostileAC', 'type' => 'Neighborhood Leader'],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => $items]));

      $result = $this->buildService()->getApiActivistCodes(0);

      $this->assertCount(2, $result);
      $this->assertSame(1001, $result[0]['activistCodeId']);
      $this->assertSame('NLPVoterAC', $result[0]['name']);
    }

    public function testGetApiActivistCodesMyCampaignDatabaseReturnsItems(): void {
      $this->stubKeyValues();
      $items = [['activistCodeId' => 2001, 'name' => 'NLPNotRegisteredAC', 'type' => 'Neighborhood Leader']];
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => $items]));

      $result = $this->buildService()->getApiActivistCodes(1);

      $this->assertCount(1, $result);
      $this->assertSame(2001, $result[0]['activistCodeId']);
    }

    public function testGetApiActivistCodesNoItemsKeyReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildService()->getApiActivistCodes(0));
    }

    public function testGetApiActivistCodesInvalidJsonReturnsEmpty(): void {
      $this->stubKeyValues();
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willReturn($response);

      $this->assertSame([], $this->buildService()->getApiActivistCodes(0));
    }

    public function testGetApiActivistCodesGuzzleExceptionReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException());

      $this->assertSame([], $this->buildService()->getApiActivistCodes(0));
    }

    // -------------------------------------------------------------------------
    // getApiVoterActivistCodes
    // -------------------------------------------------------------------------

    public function testGetApiVoterActivistCodesReturnsIntegerCodeIds(): void {
      $this->stubKeyValues();
      $items = [['activistCodeId' => 1001], ['activistCodeId' => 1002]];
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => $items]));

      $result = $this->buildService()->getApiVoterActivistCodes(99999);

      $this->assertSame([1001, 1002], $result);
    }

    public function testGetApiVoterActivistCodesReadsEveryPage(): void {
      $this->stubKeyValues();
      $urls = [];
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$urls): ResponseInterface {
          $urls[] = $url;
          return (count($urls) === 1)
            ? $this->makeResponse(['items' => [['activistCodeId' => 1001]], 'nextPageLink' => 'https://next'])
            : $this->makeResponse(['items' => [['activistCodeId' => 1002]], 'nextPageLink' => NULL]);
        });

      $result = $this->buildService()->getApiVoterActivistCodes(99999);

      $this->assertSame([1001, 1002], $result);
      $this->assertCount(2, $urls);
      $this->assertStringContainsString('$top=200&$skip=0', $urls[0]);
      $this->assertStringContainsString('$top=200&$skip=200', $urls[1]);
    }

    public function testGetApiVoterActivistCodesStopsAfterMaxPages(): void {
      $this->stubKeyValues();
      $this->clientMock->expects($this->exactly(ApiActivistCodes::VOTER_CODES_MAX_PAGES))
        ->method('request')
        ->willReturnCallback(fn() => $this->makeResponse(
          ['items' => [['activistCodeId' => 1001]], 'nextPageLink' => 'https://next']));

      $this->buildService()->getApiVoterActivistCodes(99999);
    }

    public function testGetApiVoterActivistCodesEmptyItemsReturnsEmptyArray(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => []]));

      $this->assertSame([], $this->buildService()->getApiVoterActivistCodes(99999));
    }

    public function testGetApiVoterActivistCodesInvalidJsonReturnsEmpty(): void {
      $this->stubKeyValues();
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willReturn($response);

      $this->assertSame([], $this->buildService()->getApiVoterActivistCodes(99999));
    }

    public function testGetApiVoterActivistCodesGuzzleExceptionReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException());

      $this->assertSame([], $this->buildService()->getApiVoterActivistCodes(99999));
    }

    // -------------------------------------------------------------------------
    // setApiActivistCode
    // -------------------------------------------------------------------------

    public function testSetApiActivistCodeReturnsFalseWhenCodeIdNotConfigured(): void {
      $this->configurationMock->method('getActivistCode')->willReturn(0);

      $request = ['codeName' => 'NLPVoterAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertFalse($this->buildService()->setApiActivistCode($request));
    }

    public function testSetApiActivistCodeApplyReturnsTrueOnSuccess(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse(['items' => [['activistCodeId' => 1001]]]));

      $request = ['codeName' => 'NLPVoterAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertTrue($this->buildService()->setApiActivistCode($request));
    }

    public function testSetApiActivistCodeRemoveReturnsTrueOnSuccess(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')
        ->willReturn($this->createMock(ResponseInterface::class));

      $request = ['codeName' => 'NLPVoterAC', 'vanid' => 99999, 'action' => 'Remove'];
      $this->assertTrue($this->buildService()->setApiActivistCode($request));
    }

    public function testSetApiActivistCodeReturnsFalseOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException());

      $request = ['codeName' => 'NLPVoterAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertFalse($this->buildService()->setApiActivistCode($request));
    }

    public function testSetApiActivistCodeQueuesCheckWithoutReadingBack(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();
      $this->clientMock->expects($this->once())->method('request')
        ->with('POST')
        ->willReturn($this->createMock(ResponseInterface::class));

      $request = ['codeName' => 'NLPHostileAC', 'vanid' => 99999, 'action' => 'Apply',
                  'source' => 'data entry by NL 42'];
      $this->assertTrue($this->buildService()->setApiActivistCode($request));

      $this->assertCount(1, $this->queuedItems);
      $item = $this->queuedItems[0];
      $this->assertSame([99999], $item['vanids']);
      $this->assertSame('NLPHostileAC', $item['codeName']);
      $this->assertSame(1001, $item['rid']);
      $this->assertSame('Apply', $item['action']);
      $this->assertSame(0, $item['database']);
      $this->assertSame('', $item['committee']);
      $this->assertSame('data entry by NL 42', $item['source']);
    }

    public function testSetApiActivistCodeQueuesMyCampaignCheckWithCommittee(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(3003);
      $this->setContainerMocks();
      $this->clientMock->method('request')->willReturn($this->createMock(ResponseInterface::class));

      $request = ['codeName' => 'NLPDropoutAC', 'vanid' => 555, 'action' => 'Remove',
                  'committee' => 'Lane', 'source' => 'NL display'];
      $this->buildService()->setApiActivistCode($request);

      $this->assertSame(1, $this->queuedItems[0]['database']);
      $this->assertSame('Lane', $this->queuedItems[0]['committee']);
    }

    public function testSetApiActivistCodeRefusedPostIsLoggedAndNotQueued(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('error', $this->anything(), $this->callback(fn(array $context) =>
          $context['@vanid'] === 99999
          && $context['@status'] === 400
          && $context['@detail'] === '{"errors":[{"text":"bad code"}]}'
          && $context['@source'] === 'data entry by NL 42'
          && $context['@outcome'] === 'VAN refused it.'));
      $this->clientMock->method('request')
        ->willThrowException($this->makeHttpException(400, '{"errors":[{"text":"bad code"}]}'));

      $request = ['codeName' => 'NLPHostileAC', 'vanid' => 99999, 'action' => 'Apply',
                  'source' => 'data entry by NL 42'];
      $this->assertFalse($this->buildService()->setApiActivistCode($request));
      $this->assertSame([], $this->queuedItems);
    }

    public function testSetApiActivistCodeTimedOutPostIsLoggedAndQueued(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('error', $this->anything(), $this->callback(fn(array $context) =>
          $context['@status'] === 'none'
          && $context['@detail'] === 'cURL error 28: Operation timed out'
          && str_starts_with($context['@outcome'], 'VAN may still have applied it')));
      $this->clientMock->method('request')->willThrowException($this->makeTimeoutException());

      $request = ['codeName' => 'NLPHostileAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertFalse($this->buildService()->setApiActivistCode($request));
      $this->assertCount(1, $this->queuedItems);
    }


    // -------------------------------------------------------------------------
    // hasApiActivistCodeBatch
    // -------------------------------------------------------------------------

    public function testHasApiActivistCodeBatchEmptyVanidsReturnsEmpty(): void {
      $this->assertSame([], $this->buildService()->hasApiActivistCodeBatch([], 'NLPVoterAC'));
    }

    public function testHasApiActivistCodeBatchUnknownCodeNameReturnsAllVanids(): void {
      $this->configurationMock->method('getActivistCode')->willReturn(0);

      $result = $this->buildService()->hasApiActivistCodeBatch([111, 222], 'NLPVoterAC');

      $this->assertSame([111, 222], $result);
    }

    public function testHasApiActivistCodeBatchReturnsMissingVanids(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      // Voter 111 has the code; voter 222 does not.
      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])),
          new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 9999]]])),
        );

      $result = $this->buildService()->hasApiActivistCodeBatch([111, 222], 'NLPVoterAC');

      $this->assertSame([222], $result);
    }

    public function testHasApiActivistCodeBatchAllHaveCodeReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])),
          new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])),
        );

      $result = $this->buildService()->hasApiActivistCodeBatch([111, 222], 'NLPVoterAC');

      $this->assertSame([], $result);
    }

    public function testHasApiActivistCodeBatchNoneHaveCodeReturnsAllVanids(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->makeResponse(['items' => []])),
          new FulfilledPromise($this->makeResponse(['items' => []])),
        );

      $result = $this->buildService()->hasApiActivistCodeBatch([111, 222], 'NLPVoterAC');

      $this->assertSame([111, 222], $result);
    }

    public function testHasApiActivistCodeBatchRejectedRequestIsUnreadNotMissing(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->stringContains('not counted as missing'), $this->callback(fn(array $context) =>
          $context['@count'] === 2 && $context['@details'] === 'status 404: 111; no response: 222'));

      $this->clientMock->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => new RejectedPromise(
          str_contains((string) $request->getUri(), '/people/111/')
            ? $this->makeHttpException(404, 'not found')
            : $this->makeGuzzleException()));

      $result = $this->buildService()->hasApiActivistCodeBatch([111, 222], 'NLPVoterAC', $unread);

      $this->assertSame([], $result);
      $this->assertSame([111, 222], $unread);
    }

    public function testHasApiActivistCodeBatchInvalidJsonIsUnreadNotMissing(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();

      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($response));

      $result = $this->buildService()->hasApiActivistCodeBatch([111], 'NLPVoterAC', $unread);

      $this->assertSame([], $result);
      $this->assertSame([111], $unread);
    }

    public function testHasApiActivistCodeBatchReadsLaterPagesBeforeCountingMissing(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(
          ['items' => [['activistCodeId' => 2002]], 'nextPageLink' => 'https://next'])));
      $this->clientMock->method('request')
        ->willReturnCallback(fn(string $method, string $url) => str_contains($url, '$skip=0')
          ? $this->makeResponse(['items' => [['activistCodeId' => 2002]], 'nextPageLink' => 'https://next'])
          : $this->makeResponse(['items' => [['activistCodeId' => 1001]]]));

      $result = $this->buildService()->hasApiActivistCodeBatch([111], 'NLPVoterAC', $unread);

      $this->assertSame([], $result);
      $this->assertSame([], $unread);
    }

    // -------------------------------------------------------------------------
    // setApiActivistCodeBatch
    // -------------------------------------------------------------------------

    public function testSetApiActivistCodeBatchEmptyVanidsDoesNothing(): void {
      $this->clientMock->expects($this->never())->method('sendAsync');

      $this->buildService()->setApiActivistCodeBatch([], 'NLPVoterAC', 'Apply');
    }

    public function testSetApiActivistCodeBatchUnknownCodeNameDoesNothing(): void {
      $this->configurationMock->method('getActivistCode')->willReturn(0);
      $this->clientMock->expects($this->never())->method('sendAsync');

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPVoterAC', 'Apply');
    }

    public function testSetApiActivistCodeBatchSendsRequestsForAllVanids(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->createMock(ResponseInterface::class)),
          new FulfilledPromise($this->createMock(ResponseInterface::class)),
        );

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC', 'Apply');
      $this->assertTrue(TRUE); // Reached without exception.
    }

    public function testSetApiActivistCodeBatchRemoveActionDoesNotThrow(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->createMock(ResponseInterface::class)));

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPVoterAC', 'Remove');
      $this->assertTrue(TRUE); // Reached without exception.
    }

    public function testSetApiActivistCodeBatchRejectedRequestDoesNotThrow(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise($this->makeGuzzleException()));

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPVoterAC', 'Apply');
      $this->assertTrue(TRUE); // Reached without exception.
    }

    public function testSetApiActivistCodeBatchSendsAtMostFifteenPerSecond(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $sentAt = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function() use (&$sentAt) {
          $sentAt[] = microtime(TRUE);
          return new FulfilledPromise($this->createMock(ResponseInterface::class));
        });

      $this->buildService()->setApiActivistCodeBatch(range(1, 16), 'NLPVoterAC', 'Apply');

      $this->assertCount(16, $sentAt);
      $this->assertLessThan(0.5, $sentAt[14] - $sentAt[0], 'The first 15 go out together.');
      $this->assertGreaterThanOrEqual(0.95, $sentAt[15] - $sentAt[0], 'The 16th waits for the next second.');
    }

    public function testSetApiActivistCodeBatchQueuesOneCheckWithSource(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->createMock(ResponseInterface::class)));

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC', 'Apply', 'turf sync of Oak Ave');

      $this->assertCount(1, $this->queuedItems);
      $this->assertSame([111, 222], $this->queuedItems[0]['vanids']);
      $this->assertSame('turf sync of Oak Ave', $this->queuedItems[0]['source']);
      $this->assertSame(1001, $this->queuedItems[0]['rid']);
    }

    public function testSetApiActivistCodeBatchLogsEachFailedVoter(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $loggerMock = $this->setContainerMocks();
      $loggedVanids = [];
      $loggerMock->method('log')
        ->willReturnCallback(function ($level, $message, array $context) use (&$loggedVanids) {
          $loggedVanids[] = $context['@vanid'];
        });
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) {
          return str_contains((string) $request->getUri(), '/people/222/')
            ? new RejectedPromise($this->makeHttpException(400, 'bad'))
            : new FulfilledPromise($this->createMock(ResponseInterface::class));
        });

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC', 'Remove', 'turf delete (turfIndex 7)');

      $this->assertSame([222], $loggedVanids);
      $this->assertSame([111], $this->queuedItems[0]['vanids'], 'A refused POST is not checked again.');
    }

    public function testSetApiActivistCodeBatchRecordsSentAndRefusedInHistory(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();
      $recorded = [];
      $historyMock = $this->setHistoryMock($recorded);
      $historyMock->method('vanidsWithEventSince')->willReturn([]);
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => str_contains((string) $request->getUri(), '/people/222/')
          ? new RejectedPromise($this->makeHttpException(400, 'bad'))
          : new FulfilledPromise($this->createMock(ResponseInterface::class)));

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC', 'Apply', 'turf sync of Oak Ave');

      $this->assertContains([[111], NlpAcHistory::APPLY, 'turf sync of Oak Ave'], $recorded);
      $this->assertContains([[222], NlpAcHistory::APPLY_REFUSED, 'turf sync of Oak Ave'], $recorded);
      $this->assertCount(1, $this->queuedItems, 'No late check without a conflicting Remove.');
    }

    public function testSetApiActivistCodeBatchQueuesLateCheckForVoterRemovedRecently(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();
      $recorded = [];
      $historyMock = $this->setHistoryMock($recorded);
      $historyMock->expects($this->once())->method('vanidsWithEventSince')
        ->with([111, 222], [NlpAcHistory::REMOVE], $this->greaterThan(time() - ApiActivistCodes::CONFLICT_WINDOW_SECS - 5))
        ->willReturn([222]);
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->createMock(ResponseInterface::class)));

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC', 'Apply', 'turf sync of Oak Ave');

      $this->assertCount(2, $this->queuedItems);
      $this->assertSame([222], $this->queuedItems[1]['vanids']);
      $this->assertSame('Apply', $this->queuedItems[1]['action']);
      $this->assertSame(ApiActivistCodes::LATE_CHECK_SECS, $this->queuedItems[1]['minAge']);
      $this->assertTrue($this->queuedItems[1]['late']);
    }

    public function testSetApiActivistCodeBatchKeepsNoHistoryForOtherCodes(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->setContainerMocks();
      $recorded = [];
      $historyMock = $this->setHistoryMock($recorded);
      $historyMock->expects($this->never())->method('vanidsWithEventSince');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->createMock(ResponseInterface::class)));

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPHostileAC', 'Apply', 'voter form');

      $this->assertSame([], $recorded);
    }

    // -------------------------------------------------------------------------
    // verifyActivistCodeChange
    // -------------------------------------------------------------------------

    public function testVerifyActivistCodeChangeAllShownLogsNothing(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->never())->method('log');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem(['vanids' => [111, 222]]));
    }

    public function testVerifyActivistCodeChangeResendsToVotersMissingAppliedCode(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->stringContains('Sending it again'), $this->callback(fn(array $context) =>
          $context['@vanids'] === '222'));
      $posts = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) use (&$posts) {
          if ($request->getMethod() === 'POST') {
            $posts[] = [(string) $request->getUri(), (string) $request->getBody()];
            return new FulfilledPromise($this->createMock(ResponseInterface::class));
          }
          return new FulfilledPromise(str_contains((string) $request->getUri(), '/people/222/')
            ? $this->makeResponse(['items' => [['activistCodeId' => 2002]]])
            : $this->makeResponse(['items' => [['activistCodeId' => 1001]]]));
        });

      $this->buildService()->verifyActivistCodeChange($this->verifyItem(['vanids' => [111, 222]]));

      $this->assertCount(1, $posts, 'Only the voter missing the code is sent it again.');
      $this->assertStringContainsString('/people/222/canvassResponses', $posts[0][0]);
      $body = json_decode($posts[0][1], TRUE);
      $this->assertSame(1001, $body['responses'][0]['activistCodeId']);
      $this->assertSame('Apply', $body['responses'][0]['action']);
      $this->assertCount(1, $this->queuedItems);
      $this->assertSame([222], $this->queuedItems[0]['vanids']);
      $this->assertSame(1700000000, $this->queuedItems[0]['firstQueuedAt']);
      $this->assertSame('turf sync of Oak Ave (turfIndex 7)', $this->queuedItems[0]['source']);
    }

    public function testVerifyActivistCodeChangeResendUsesCommitteeKeyAndDatabase(): void {
      $this->setContainerMocks();
      $this->apiKeyManagementMock->method('getKeyValues')
        ->with('Lane')
        ->willReturn(['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app']);
      $postUrls = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) use (&$postUrls) {
          if ($request->getMethod() === 'POST') {
            $postUrls[] = (string) $request->getUri();
            return new FulfilledPromise($this->createMock(ResponseInterface::class));
          }
          return new FulfilledPromise($this->makeResponse(['items' => []]));
        });

      $this->buildService()->verifyActivistCodeChange(
        $this->verifyItem(['rid' => 3003, 'database' => 1, 'committee' => 'Lane', 'vanids' => [555]]));

      $this->assertCount(1, $postUrls);
      $this->assertStringContainsString('|1@', urldecode($postUrls[0]));
      $this->assertSame('Lane', $this->queuedItems[0]['committee']);
      $this->assertSame(1, $this->queuedItems[0]['database']);
    }

    public function testVerifyActivistCodeChangeResendRefusedIsNotCheckedAgain(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $levels = [];
      $loggerMock->method('log')
        ->willReturnCallback(function ($level) use (&$levels) {
          $levels[] = $level;
        });
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => $request->getMethod() === 'POST'
          ? new RejectedPromise($this->makeHttpException(400, 'bad'))
          : new FulfilledPromise($this->makeResponse(['items' => []])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem());

      $this->assertSame(['warning', 'error'], $levels);
      $this->assertSame([], $this->queuedItems);
    }

    public function testVerifyActivistCodeChangeLogsVotersStillMissingAppliedCodeAfterResend(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('error', $this->stringContains('even after it was sent again'), $this->callback(fn(array $context) =>
          $context['@vanids'] === '222'
          && $context['@count'] === 1
          && $context['@rid'] === 1001
          && $context['@source'] === 'turf sync of Oak Ave (turfIndex 7)'
          && $context['@time'] === date('Y-m-d H:i:s', 1600000000)
          && $context['@database'] === 'VoterFile'
          && $context['@committee'] === 'State Committee'));
      $this->clientMock->expects($this->exactly(2))->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => new FulfilledPromise(
          str_contains((string) $request->getUri(), '/people/222/')
            ? $this->makeResponse(['items' => [['activistCodeId' => 2002]]])
            : $this->makeResponse(['items' => [['activistCodeId' => 1001]]])));

      $this->buildService()->verifyActivistCodeChange(
        $this->verifyItem(['vanids' => [111, 222], 'firstQueuedAt' => 1600000000]));

      $this->assertSame([], $this->queuedItems, 'A change is sent again only once.');
    }

    public function testVerifyActivistCodeChangeLogsVotersStillHavingRemovedCodeAfterResend(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('error', $this->anything(), $this->callback(fn(array $context) =>
          $context['@vanids'] === '111' && $context['@action'] === 'Remove'));
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])));

      $this->buildService()->verifyActivistCodeChange(
        $this->verifyItem(['action' => 'Remove', 'firstQueuedAt' => 1600000000]));
    }

    public function testVerifyActivistCodeChangeDoesNotResendRemoveToVoterNowInATurf(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $votersMock = $this->createMock(NlpVoters::class);
      $votersMock->method('getVanidsInTurfs')
        ->willReturnCallback(fn(array $vanids) => array_values(array_intersect($vanids, [111])));
      \Drupal::getContainer()->set('nlpservices.voters', $votersMock);
      // Both voters still show the code.  Voter 111 was synced into a new turf since the Remove.
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->stringContains('Sending it again'), $this->callback(fn(array $context) =>
          $context['@vanids'] === '222'));
      $posts = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) use (&$posts) {
          if ($request->getMethod() === 'POST') {
            $posts[] = (string) $request->getUri();
            return new FulfilledPromise($this->createMock(ResponseInterface::class));
          }
          return new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]]));
        });

      $this->buildService()->verifyActivistCodeChange($this->verifyItem([
        'vanids' => [111, 222], 'action' => 'Remove', 'source' => 'turf delete (turfIndex 7)']));

      $this->assertCount(1, $posts);
      $this->assertStringContainsString('/people/222/canvassResponses', $posts[0]);
    }

    public function testVerifyActivistCodeChangeRemoveForVotersAllNowInTurfsLogsNothing(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $votersMock = $this->createMock(NlpVoters::class);
      $votersMock->method('getVanidsInTurfs')->willReturn([111]);
      \Drupal::getContainer()->set('nlpservices.voters', $votersMock);
      $loggerMock->expects($this->never())->method('log');
      $this->clientMock->expects($this->once())->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 1001]]])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem(['action' => 'Remove']));

      $this->assertSame([], $this->queuedItems);
    }

    public function testVerifyActivistCodeChangeReadsLaterPagesBeforeLogging(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->never())->method('log');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(
          ['items' => [['activistCodeId' => 2002]], 'nextPageLink' => 'https://next'])));
      $this->clientMock->method('request')
        ->willReturnCallback(fn(string $method, string $url) => str_contains($url, '$skip=0')
          ? $this->makeResponse(['items' => [['activistCodeId' => 2002]], 'nextPageLink' => 'https://next'])
          : $this->makeResponse(['items' => [['activistCodeId' => 1001]]]));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem());
    }

    public function testVerifyActivistCodeChangeUnreadableVoterIsAWarningNotAnError(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->anything(), $this->callback(fn(array $context) =>
          $context['@vanids'] === '111'));
      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise($this->makeHttpException(404, 'not found')));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem());
    }

    public function testVerifyActivistCodeChangeUsesCommitteeKeyAndDatabase(): void {
      $this->setContainerMocks();
      $this->apiKeyManagementMock->expects($this->once())->method('getKeyValues')
        ->with('Lane')
        ->willReturn(['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app']);
      $urls = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) use (&$urls) {
          $urls[] = (string) $request->getUri();
          return new FulfilledPromise($this->makeResponse(['items' => [['activistCodeId' => 3003]]]));
        });

      $this->buildService()->verifyActivistCodeChange(
        $this->verifyItem(['rid' => 3003, 'database' => 1, 'committee' => 'Lane', 'vanids' => [555]]));

      $this->assertCount(1, $urls);
      $this->assertStringContainsString('|1@', urldecode($urls[0]));
      $this->assertStringContainsString('/people/555/activistCodes', $urls[0]);
    }

    public function testVerifyActivistCodeChangeAppliesCodeAgainToVoterInTurfThatRemoveTookItFrom(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $votersMock = $this->createMock(NlpVoters::class);
      $votersMock->method('getVanidsInTurfs')->willReturn([222]);
      \Drupal::getContainer()->set('nlpservices.voters', $votersMock);
      $recorded = [];
      $historyMock = $this->setHistoryMock($recorded);
      $historyMock->method('vanidsWithEventSince')->willReturn([]);
      // Neither voter shows the code.  Voter 222 is in a turf now and needs it.
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->stringContains('Applying it again'), $this->callback(fn(array $context) =>
          $context['@vanids'] === '222' && str_contains($context['@history'], 'turf delete (turfIndex 7)')));
      $posts = [];
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function (RequestInterface $request) use (&$posts) {
          if ($request->getMethod() === 'POST') {
            $posts[] = [(string) $request->getUri(), (string) $request->getBody()];
            return new FulfilledPromise($this->createMock(ResponseInterface::class));
          }
          return new FulfilledPromise($this->makeResponse(['items' => []]));
        });

      $this->buildService()->verifyActivistCodeChange($this->verifyItem([
        'vanids' => [111, 222], 'action' => 'Remove', 'source' => 'turf delete (turfIndex 7)']));

      $this->assertCount(1, $posts);
      $this->assertStringContainsString('/people/222/canvassResponses', $posts[0][0]);
      $this->assertSame('Apply', json_decode($posts[0][1], TRUE)['responses'][0]['action']);
      $this->assertContains([[222], NlpAcHistory::MISSING, 'first check of Remove from turf delete (turfIndex 7)'], $recorded);
      $this->assertCount(1, $this->queuedItems);
      $this->assertSame('Apply', $this->queuedItems[0]['action']);
      $this->assertSame([222], $this->queuedItems[0]['vanids']);
    }

    public function testVerifyActivistCodeChangeLeavesVoterWithApplySinceRemoveToItsLateCheck(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $votersMock = $this->createMock(NlpVoters::class);
      $votersMock->method('getVanidsInTurfs')->willReturn([222]);
      \Drupal::getContainer()->set('nlpservices.voters', $votersMock);
      $recorded = [];
      $historyMock = $this->setHistoryMock($recorded);
      $historyMock->expects($this->once())->method('vanidsWithEventSince')
        ->with([222], [NlpAcHistory::APPLY], 1700000000)
        ->willReturn([222]);
      $loggerMock->expects($this->never())->method('log');
      $this->clientMock->expects($this->once())->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse(['items' => []])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem(['action' => 'Remove', 'vanids' => [222]]));

      $this->assertSame([], $recorded);
      $this->assertSame([], $this->queuedItems);
    }

    public function testVerifyActivistCodeChangeLateCheckRecordsMissingVoterAndSendsAgain(): void {
      $this->stubKeyValues();
      $loggerMock = $this->setContainerMocks();
      $recorded = [];
      $this->setHistoryMock($recorded);
      $loggerMock->expects($this->once())->method('log')
        ->with('warning', $this->stringContains('Sending it again'), $this->callback(fn(array $context) =>
          $context['@check'] === 'late check' && $context['@history'] !== ''));
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => new FulfilledPromise($request->getMethod() === 'POST'
          ? $this->createMock(ResponseInterface::class)
          : $this->makeResponse(['items' => []])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem(
        ['minAge' => ApiActivistCodes::LATE_CHECK_SECS, 'late' => TRUE]));

      $this->assertContains([[111], NlpAcHistory::MISSING, 'late check of Apply from turf sync of Oak Ave (turfIndex 7)'],
        $recorded);
      $this->assertContains([[111], NlpAcHistory::APPLY, 'turf sync of Oak Ave (turfIndex 7) (sent again)'], $recorded);
      $this->assertSame(1700000000, $this->queuedItems[0]['firstQueuedAt']);
    }

    public function testVerifyActivistCodeChangeFirstCheckDoesNotRecordApplyNotYetShownAsMissing(): void {
      $this->stubKeyValues();
      $this->setContainerMocks();
      $recorded = [];
      $this->setHistoryMock($recorded);
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(fn(RequestInterface $request) => new FulfilledPromise($request->getMethod() === 'POST'
          ? $this->createMock(ResponseInterface::class)
          : $this->makeResponse(['items' => []])));

      $this->buildService()->verifyActivistCodeChange($this->verifyItem());

      $this->assertSame([NlpAcHistory::APPLY], array_column($recorded, 1), 'VAN may not have finished the Apply yet.');
    }

  }

}
