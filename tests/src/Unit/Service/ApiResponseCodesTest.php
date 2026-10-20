<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiResponseCodes;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\VanRateLimiter;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Exception\TransferException;
  use GuzzleHttp\Promise\FulfilledPromise;
  use GuzzleHttp\Promise\RejectedPromise;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;
  use Psr\Log\LoggerInterface;

  class ApiResponseCodesTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      VanRateLimiter::reset();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->messengerMock        = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
      $container->set('logger.factory', $loggerFactory);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiResponseCodes {
      return new ApiResponseCodes(
        $this->clientMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    private function buildPartialService(): ApiResponseCodes {
      return $this->getMockBuilder(ApiResponseCodes::class)
        ->setConstructorArgs([
          $this->clientMock,
          $this->configurationMock,
          $this->apiKeyManagementMock,
        ])
        ->onlyMethods(['getApiContactTypes', 'getApiResultCodes'])
        ->getMock();
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

    private function makeHttpException(int $status, string $body): RequestException {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($body);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn($status);
      $response->method('getBody')->willReturn($stream);
      return new RequestException('error', $this->createMock(RequestInterface::class), $response);
    }

    // -------------------------------------------------------------------------
    // getApiContactTypes
    // -------------------------------------------------------------------------

    public function testGetApiContactTypesReturnsNameToIdMap(): void {
      $this->stubKeyValues();
      $apiData = [
        ['name' => 'Walk',  'contactTypeId' => 1],
        ['name' => 'Phone', 'contactTypeId' => 2],
      ];
      $this->clientMock->method('sendAsync')->willReturn(new FulfilledPromise($this->makeResponse($apiData)));

      $result = $this->buildService()->getApiContactTypes();

      $this->assertSame(['Walk' => 1, 'Phone' => 2], $result);
    }

    public function testGetApiContactTypesReturnsEmptyAndAddsStatusOnHttpError(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise($this->makeHttpException(400, 'bad request')));
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    public function testGetApiContactTypesReturnsEmptyAndAddsStatusOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise(new TransferException('connect error')));
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    public function testGetApiContactTypesReturnsEmptyArrayWhenApiReturnsNoItems(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')->willReturn(new FulfilledPromise($this->makeResponse([])));

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    // -------------------------------------------------------------------------
    // getApiResultCodes
    // -------------------------------------------------------------------------

    public function testGetApiResultCodesReturnsNameToIdMap(): void {
      $this->stubKeyValues();
      $apiData = [
        ['name' => 'Not Home', 'resultCodeId' => 101],
        ['name' => 'Moved',    'resultCodeId' => 102],
      ];
      $this->clientMock->method('sendAsync')->willReturn(new FulfilledPromise($this->makeResponse($apiData)));

      $this->assertSame(['Not Home' => 101, 'Moved' => 102], $this->buildService()->getApiResultCodes(37));
    }

    public function testGetApiResultCodesRetriesAfterRateLimit(): void {
      $this->stubKeyValues();
      $rateLimited = $this->makeHttpException(429, '{"errors":[{"code":"429","text":"Rate limit exceeded. Try again in 0 ms."}]}');
      $this->clientMock->expects($this->exactly(2))->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new RejectedPromise($rateLimited),
          new FulfilledPromise($this->makeResponse([['name' => 'Mailed', 'resultCodeId' => 55]])),
        );
      $this->messengerMock->expects($this->never())->method('addStatus');

      $this->assertSame(['Mailed' => 55], $this->buildService()->getApiResultCodes(37));
    }

    public function testGetApiResultCodesSendsCredentialsInHeaderNotUrl(): void {
      $this->stubKeyValues();
      $sent = NULL;
      $this->clientMock->method('sendAsync')
        ->willReturnCallback(function(RequestInterface $request) use (&$sent) {
          $sent = $request;
          return new FulfilledPromise($this->makeResponse([]));
        });

      $this->buildService()->getApiResultCodes(37);

      $this->assertSame('https://api.example.com/v4/canvassResponses/resultCodes?contactTypeId=37', (string) $sent->getUri());
      $this->assertSame('Basic '.base64_encode('testapp:test-key|0'), $sent->getHeaderLine('Authorization'));
    }

    // -------------------------------------------------------------------------
    // getApiKnownResultCodes
    // getApiResultCodes is stubbed via partial mock.
    // -------------------------------------------------------------------------

    public function testGetApiKnownResultCodesReturnsEmptyWhenNoContactTypes(): void {
      $svc = $this->buildPartialService();
      $svc->method('getApiContactTypes')->willReturn([]);

      $this->assertSame([], $svc->getApiKnownResultCodes());
    }

    public function testGetApiKnownResultCodesBuildsStructurePerContactType(): void {
      $svc = $this->buildPartialService();
      $svc->method('getApiContactTypes')->willReturn(['Walk' => 1, 'Phone' => 2]);
      $svc->method('getApiResultCodes')
        ->willReturnOnConsecutiveCalls(
          ['Not Home' => 101, 'Moved' => 102],
          ['Not Home' => 201],
        );

      $result = $svc->getApiKnownResultCodes();

      $this->assertArrayHasKey('Walk', $result);
      $this->assertSame(1, $result['Walk']['code']);
      $this->assertSame(101, $result['Walk']['responses']['Not Home']);
      $this->assertSame(102, $result['Walk']['responses']['Moved']);
      $this->assertSame(2, $result['Phone']['code']);
      $this->assertSame(201, $result['Phone']['responses']['Not Home']);
    }

    public function testGetApiKnownResultCodesHandlesEmptyResultCodesForType(): void {
      $svc = $this->buildPartialService();
      $svc->method('getApiContactTypes')->willReturn(['Walk' => 1]);
      $svc->method('getApiResultCodes')->willReturn([]);

      $result = $svc->getApiKnownResultCodes();

      $this->assertSame(1, $result['Walk']['code']);
      $this->assertSame([], $result['Walk']['responses'] ?? []);
    }

    // -------------------------------------------------------------------------
    // getApiExpectedResultCodes
    // -------------------------------------------------------------------------

    public function testGetApiExpectedResultCodesContainsAllContactTypeKeys(): void {
      $result = $this->buildService()->getApiExpectedResultCodes();

      foreach (['Walk', 'Phone', 'Postcard', 'Personal Email', 'SMS Text', 'Social Media'] as $type) {
        $this->assertArrayHasKey($type, $result);
      }
    }

    public function testGetApiExpectedResultCodesUsesTextAsKeyAndValue(): void {
      $result = $this->buildService()->getApiExpectedResultCodes();

      $walkResponses = $result['Walk']['responses'];
      $this->assertArrayHasKey('Not Home', $walkResponses);
      $this->assertSame('Not Home', $walkResponses['Not Home']);
    }

    public function testGetApiExpectedResultCodesWalkContainsExpectedEntries(): void {
      $result = $this->buildService()->getApiExpectedResultCodes();

      $walkResponses = $result['Walk']['responses'];
      foreach (['Not Home', 'Refused', 'Moved', 'Deceased', 'Hostile'] as $text) {
        $this->assertArrayHasKey($text, $walkResponses, "Walk responses missing: $text");
      }
    }

    public function testGetApiExpectedResultCodesPhoneContainsExpectedEntries(): void {
      $result = $this->buildService()->getApiExpectedResultCodes();

      $phoneResponses = $result['Phone']['responses'];
      foreach (['Left Message', 'Deceased', 'Hostile', 'Moved', 'Wrong Number', 'Not Home'] as $text) {
        $this->assertArrayHasKey($text, $phoneResponses, "Phone responses missing: $text");
      }
    }

    // -------------------------------------------------------------------------
    // getApiExpectedAbbreviations
    // -------------------------------------------------------------------------

    public function testGetApiExpectedAbbreviationsDefaultsToWalkType(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations();

      $this->assertArrayHasKey('Not Home', $result);
      $this->assertSame('NH', $result['Not Home']);
    }

    public function testGetApiExpectedAbbreviationsWalkIsSortedByWeight(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations('Walk');
      $keys = array_keys($result);

      // weight 1 = Left Message/Lit, weight 2 = Not Home — so LM/L must appear before NH
      $posLml = array_search('Left Message/Lit', $keys);
      $posNh  = array_search('Not Home', $keys);
      $this->assertLessThan($posNh, $posLml);
    }

    public function testGetApiExpectedAbbreviationsWalkContainsKnownAbbreviations(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations('Walk');

      $this->assertSame('LM/L', $result['Left Message/Lit']);
      $this->assertSame('NH',   $result['Not Home']);
      $this->assertSame('RF',   $result['Refused']);
      $this->assertSame('IN',   $result['Inaccessible']);
      $this->assertSame('MV',   $result['Moved']);
      $this->assertSame('WA',   $result['Wrong Address']);
    }

    public function testGetApiExpectedAbbreviationsPhoneReturnsEmptyStringsForMissingAbbreviations(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations('Phone');

      foreach ($result as $text => $abbreviation) {
        $this->assertSame('', $abbreviation, "Phone entry '$text' should have empty abbreviation");
      }
    }

    public function testGetApiExpectedAbbreviationsPhoneIsSortedByWeight(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations('Phone');
      $keys = array_keys($result);

      // weight 5 = Moved, weight 6 = Hostile, weight 7 = Deceased, weight 8 = Left Message
      $posMoved   = array_search('Moved', $keys);
      $posHostile = array_search('Hostile', $keys);
      $posDeceased= array_search('Deceased', $keys);
      $this->assertLessThan($posHostile, $posMoved);
      $this->assertLessThan($posDeceased, $posHostile);
    }

    public function testGetApiExpectedAbbreviationsReturnsAllPhoneEntries(): void {
      $result = $this->buildService()->getApiExpectedAbbreviations('Phone');

      foreach (['Left Message', 'Deceased', 'Hostile', 'Moved', 'Wrong Number', 'Not Home'] as $text) {
        $this->assertArrayHasKey($text, $result, "Phone abbreviations missing: $text");
      }
    }

  }

}
