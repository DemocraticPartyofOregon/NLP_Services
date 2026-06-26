<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiResponseCodes;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiResponseCodesTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->messengerMock        = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
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

    // -------------------------------------------------------------------------
    // getApiContactTypes
    // -------------------------------------------------------------------------

    public function testGetApiContactTypesReturnsNameToIdMap(): void {
      $this->stubKeyValues();
      $apiData = [
        ['name' => 'Walk',  'contactTypeId' => 1],
        ['name' => 'Phone', 'contactTypeId' => 2],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($apiData));

      $result = $this->buildService()->getApiContactTypes();

      $this->assertSame(['Walk' => 1, 'Phone' => 2], $result);
    }

    public function testGetApiContactTypesReturnsEmptyAndAddsStatusOnException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('timeout'));
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    public function testGetApiContactTypesReturnsEmptyAndAddsStatusOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('connect error'));
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    public function testGetApiContactTypesReturnsEmptyArrayWhenApiReturnsNoItems(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildService()->getApiContactTypes());
    }

    // -------------------------------------------------------------------------
    // getApiKnownResultCodes
    // Note: getApiResultCodes uses curl directly and cannot be unit-tested here;
    // it is stubbed via partial mock.
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
      foreach (['Not Home', 'Refused', 'Moved', 'Deceased', 'Hostile', 'Do Not Contact', 'Do Not Knock'] as $text) {
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
