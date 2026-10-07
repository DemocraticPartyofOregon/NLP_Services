<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiDistrictFields;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;
  use Psr\Log\LoggerInterface;

  class ApiDistrictFieldsTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key'  => 'test-key',
        'Url'      => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);

      $loggerMock        = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiDistrictFields {
      return new ApiDistrictFields($this->clientMock, $this->apiKeyManagementMock);
    }

    private function makeResponse(mixed $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn(json_encode($body));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    private function makeRequestException(int $statusCode): RequestException {
      $responseMock = $this->createMock(ResponseInterface::class);
      $responseMock->method('getStatusCode')->willReturn($statusCode);
      return new RequestException(
        'HTTP ' . $statusCode,
        $this->createMock(RequestInterface::class),
        $responseMock,
      );
    }

    /**
     * The State House example from the VAN District Fields documentation.
     */
    private function stateHouseField(): array {
      return [
        'districtFieldId'     => 999,
        'name'                => 'State House',
        'parentFieldId'       => 888,
        'isCustomDistrict'    => false,
        'districtFieldValues' => [
          ['id' => '10', 'name' => 'Jackson County District 10', 'parentId' => '1234'],
          ['id' => '11', 'name' => 'Jackson County District 11', 'parentId' => '1234'],
          ['id' => '12', 'name' => 'Jackson County District 12', 'parentId' => '1234'],
          ['id' => '20', 'name' => 'Jefferson County District 20', 'parentId' => '2345'],
        ],
      ];
    }

    private function countyField(): array {
      return [
        'districtFieldId'  => 888,
        'name'             => 'County',
        'parentFieldId'    => null,
        'isCustomDistrict' => false,
      ];
    }

    private function captureUrl(?string &$capturedUrl, mixed $body): void {
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl, $body) {
          $capturedUrl = $url;
          return $this->makeResponse($body);
        });
    }

    // -------------------------------------------------------------------------
    // Request URL
    // -------------------------------------------------------------------------

    public function testRequestsAllFieldsWhenNoFieldIdGiven(): void {
      $capturedUrl = null;
      $this->captureUrl($capturedUrl, []);

      $this->buildService()->getDistrictFields('TEST');

      $this->assertSame('https://testapp:test-key|0@api.example.com/v4/districtFields', $capturedUrl);
    }

    public function testRequestsSingleFieldWhenFieldIdGiven(): void {
      $capturedUrl = null;
      $this->captureUrl($capturedUrl, $this->stateHouseField());

      $this->buildService()->getDistrictFields('TEST', 999);

      $this->assertSame('https://testapp:test-key|0@api.example.com/v4/districtFields/999', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    public function testSingleFieldIsKeyedByFieldIdWithValuesKeyedById(): void {
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->stateHouseField()));

      $result = $this->buildService()->getDistrictFields('TEST', 999);

      $this->assertSame([999], array_keys($result));
      $field = $result[999];
      $this->assertSame('State House', $field['name']);
      $this->assertSame(888, $field['parentFieldId']);
      $this->assertFalse($field['isCustomDistrict']);
      $this->assertSame(['10', '11', '12', '20'], array_map('strval', array_keys($field['districtFieldValues'])));
      $this->assertSame(
        ['id' => '20', 'name' => 'Jefferson County District 20', 'parentId' => '2345'],
        $field['districtFieldValues']['20'],
      );
    }

    public function testAllFieldsAcceptsBareList(): void {
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse([$this->stateHouseField(), $this->countyField()]));

      $result = $this->buildService()->getDistrictFields('TEST');

      $this->assertSame([999, 888], array_keys($result));
    }

    public function testAllFieldsAcceptsItemsWrapper(): void {
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse(['items' => [$this->stateHouseField(), $this->countyField()], 'count' => 2]));

      $result = $this->buildService()->getDistrictFields('TEST');

      $this->assertSame([999, 888], array_keys($result));
    }

    public function testMissingOptionalPropertiesGetDefaults(): void {
      $this->clientMock->method('request')->willReturn($this->makeResponse([$this->countyField()]));

      $field = $this->buildService()->getDistrictFields('TEST')[888];

      $this->assertNull($field['parentFieldId']);
      $this->assertSame([], $field['districtFieldValues']);
    }

    public function testEmptyResponseReturnsEmptyArray(): void {
      $this->clientMock->method('request')->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST'));
    }

    // -------------------------------------------------------------------------
    // Errors
    // -------------------------------------------------------------------------

    public function testReturnsEmptyOn401(): void {
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(401));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST'));
    }

    public function testReturnsEmptyOn404(): void {
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(404));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST', 12345));
    }

    public function testReturnsEmptyOnOtherRequestException(): void {
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(500));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST'));
    }

    public function testReturnsEmptyOnGenericException(): void {
      $this->clientMock->method('request')->willThrowException(new Exception('unexpected'));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST'));
    }

    public function testReturnsEmptyOnGuzzleException(): void {
      $this->clientMock->method('request')->willThrowException(new TransferException('transfer error'));

      $this->assertSame([], $this->buildService()->getDistrictFields('TEST'));
    }

  }

}
