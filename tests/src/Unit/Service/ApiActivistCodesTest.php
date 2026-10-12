<?php

namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\VanRateLimiter;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
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
     * Same service, with setApiActivistCode()'s checks run without waiting.
     */
    private function buildServiceWithoutWaits(): ApiActivistCodes {
      $service = $this->buildService();
      (new \ReflectionProperty(ApiActivistCodes::class, 'verifyDelaysMs'))
        ->setValue($service, [0, 0, 0, 0]);
      return $service;
    }

    private function setLoggerMock(): LoggerInterface&MockObject {
      $loggerMock        = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
      return $loggerMock;
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

    public function testSetApiActivistCodeApplyConfirmedOnLaterCheckPostsOnce(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $posts = 0;
      $reads = 0;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method) use (&$posts, &$reads): ResponseInterface {
          if ($method === 'POST') {
            $posts++;
            return $this->makeResponse(NULL);
          }
          $reads++;
          $items = ($reads < 3) ? [] : [['activistCodeId' => 1001]];
          return $this->makeResponse(['items' => $items]);
        });

      $request = ['codeName' => 'NLPHostileAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertTrue($this->buildServiceWithoutWaits()->setApiActivistCode($request));
      $this->assertSame(1, $posts, 'The POST must not be repeated');
      $this->assertSame(3, $reads);
    }

    public function testSetApiActivistCodeApplyNeverConfirmedLogsDetailsAndPostsOnce(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $loggerMock = $this->setLoggerMock();
      $loggerMock->expects($this->once())->method('error')
        ->with($this->anything(), $this->callback(fn(array $context) =>
          $context['@vanid'] === 99999
          && $context['@rid'] === 1001
          && $context['@status'] === 204
          && $context['@body'] === '(empty)'
          && $context['@committee'] === 'State Committee'
          && $context['@codes'] === '2002, 3003'));
      $posts = 0;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method) use (&$posts): ResponseInterface {
          if ($method === 'POST') {
            $posts++;
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('__toString')->willReturn('');
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(204);
            $response->method('getBody')->willReturn($stream);
            return $response;
          }
          return $this->makeResponse(['items' => [['activistCodeId' => 2002], ['activistCodeId' => 3003]]]);
        });

      $request = ['codeName' => 'NLPHostileAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertFalse($this->buildServiceWithoutWaits()->setApiActivistCode($request));
      $this->assertSame(1, $posts, 'The POST must not be repeated');
    }

    public function testSetApiActivistCodeReturnsFalseOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException());

      $request = ['codeName' => 'NLPVoterAC', 'vanid' => 99999, 'action' => 'Apply'];
      $this->assertFalse($this->buildService()->setApiActivistCode($request));
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

    public function testHasApiActivistCodeBatchRejectedRequestTreatsVanidAsMissing(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise($this->makeGuzzleException()));

      $result = $this->buildService()->hasApiActivistCodeBatch([111], 'NLPVoterAC');

      $this->assertSame([111], $result);
    }

    public function testHasApiActivistCodeBatchInvalidJsonTreatsVanidAsMissing(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($response));

      $result = $this->buildService()->hasApiActivistCodeBatch([111], 'NLPVoterAC');

      $this->assertSame([111], $result);
    }

    // -------------------------------------------------------------------------
    // setApiActivistCodeBatch
    // -------------------------------------------------------------------------

    public function testSetApiActivistCodeBatchEmptyVanidsDoesNothing(): void {
      $this->clientMock->expects($this->never())->method('sendAsync');

      $this->buildService()->setApiActivistCodeBatch([], 'NLPVoterAC');
    }

    public function testSetApiActivistCodeBatchUnknownCodeNameDoesNothing(): void {
      $this->configurationMock->method('getActivistCode')->willReturn(0);
      $this->clientMock->expects($this->never())->method('sendAsync');

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPVoterAC');
    }

    public function testSetApiActivistCodeBatchSendsRequestsForAllVanids(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);

      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->createMock(ResponseInterface::class)),
          new FulfilledPromise($this->createMock(ResponseInterface::class)),
        );

      $this->buildService()->setApiActivistCodeBatch([111, 222], 'NLPVoterAC');
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

      $this->buildService()->setApiActivistCodeBatch([111], 'NLPVoterAC');
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

      $this->buildService()->setApiActivistCodeBatch(range(1, 16), 'NLPVoterAC');

      $this->assertCount(16, $sentAt);
      $this->assertLessThan(0.5, $sentAt[14] - $sentAt[0], 'The first 15 go out together.');
      $this->assertGreaterThanOrEqual(0.95, $sentAt[15] - $sentAt[0], 'The 16th waits for the next second.');
    }

  }

}
