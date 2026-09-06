<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\Routing\RouteMatchInterface;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;
  use Psr\Log\LoggerInterface;

  class ApiFoldersTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);

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

    private function buildService(): ApiFolders {
      return new ApiFolders(
        $this->clientMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    private function buildPartialService(): ApiFolders {
      return $this->getMockBuilder(ApiFolders::class)
        ->setConstructorArgs([
          $this->clientMock,
          $this->configurationMock,
          $this->apiKeyManagementMock,
        ])
        ->onlyMethods(['getApiFolders'])
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

    private function makeRequestException(int $statusCode): RequestException {
      $responseMock = $this->createMock(ResponseInterface::class);
      $responseMock->method('getStatusCode')->willReturn($statusCode);
      return new RequestException(
        'HTTP ' . $statusCode,
        $this->createMock(RequestInterface::class),
        $responseMock,
      );
    }

    private function addRouteMatchToContainer(string $routeName = 'some.route'): void {
      $container = \Drupal::getContainer();
      $routeMatchMock = $this->createMock(RouteMatchInterface::class);
      $routeMatchMock->method('getRouteName')->willReturn($routeName);
      $container->set('current_route_match', $routeMatchMock);
    }

    private function addMessengerToContainer(): MessengerInterface {
      $container = \Drupal::getContainer();
      $messengerMock = $this->createMock(MessengerInterface::class);
      $container->set('messenger', $messengerMock);
      return $messengerMock;
    }

    // -------------------------------------------------------------------------
    // getApiFolders
    // -------------------------------------------------------------------------

    public function testGetApiFoldersReturnsEmptyOn401(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(401));

      $this->assertSame([], $this->buildService()->getApiFolders('TEST', 0));
    }

    public function testGetApiFoldersReturnsEmptyOn404(): void {
      $this->stubKeyValues();
      $this->addRouteMatchToContainer('some.route');
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(404));

      $this->assertSame([], $this->buildService()->getApiFolders('TEST', 0, 55));
    }

    public function testGetApiFoldersReturnsEmptyOnOtherRequestException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeRequestException(500));

      $this->assertSame([], $this->buildService()->getApiFolders('TEST', 0));
    }

    public function testGetApiFoldersReturnsEmptyOnGenericException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('unexpected'));

      $this->assertSame([], $this->buildService()->getApiFolders('TEST', 0));
    }

    public function testGetApiFoldersReturnsEmptyOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('transfer error'));

      $this->assertSame([], $this->buildService()->getApiFolders('TEST', 0));
    }

    public function testGetApiFoldersReturnsEmptyFolderMessageWhenItemsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => []]));

      $result = $this->buildService()->getApiFolders('TEST', 0);

      $this->assertSame(['0' => 'folder is empty'], $result);
    }

    public function testGetApiFoldersReturnsAllFoldersWhenNoFolderIdGiven(): void {
      $this->stubKeyValues();
      $items = [
        ['folderId' => 10, 'name' => 'TEST County Turfs'],
        ['folderId' => 11, 'name' => 'OTHER County Turfs'],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse(['items' => $items]));

      $result = $this->buildService()->getApiFolders('TEST', 0);

      $this->assertSame([10 => 'TEST County Turfs', 11 => 'OTHER County Turfs'], $result);
    }

    public function testGetApiFoldersReturnsSingleFolderWhenFolderIdGiven(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse(['folderId' => 10, 'name' => 'TEST County Turfs']));

      $result = $this->buildService()->getApiFolders('TEST', 0, 10);

      $this->assertSame([10 => 'TEST County Turfs'], $result);
    }

    public function testGetApiFoldersMyCampaignDatabaseUsesDatabase1(): void {
      $this->stubKeyValues();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->makeResponse(['items' => []]);
        });

      $this->buildService()->getApiFolders('TEST', 1);

      $this->assertStringContainsString('|1@', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // getFolderOptions
    // -------------------------------------------------------------------------

    public function testGetFolderOptionsReturnsEmptyAndWarnsWhenNoFolders(): void {
      $messengerMock = $this->addMessengerToContainer();
      $messengerMock->expects($this->once())->method('addWarning');

      $svc = $this->buildPartialService();
      $svc->method('getApiFolders')->willReturn([]);

      $this->assertSame([], $svc->getFolderOptions(0, 'TEST'));
    }

    public function testGetFolderOptionsFiltersToMatchingCountyFolders(): void {
      $this->addMessengerToContainer();
      $allFolders = [
        10 => 'TEST County Turfs',
        11 => 'OTHER County Turfs',
        12 => 'TEST Primary List',
      ];

      $svc = $this->buildPartialService();
      $svc->method('getApiFolders')->willReturn($allFolders);

      $result = $svc->getFolderOptions(0, 'TEST');

      $this->assertSame([10 => 'TEST County Turfs', 12 => 'TEST Primary List'], $result);
    }

    public function testGetFolderOptionsMatchIsCaseInsensitive(): void {
      $this->addMessengerToContainer();
      $svc = $this->buildPartialService();
      $svc->method('getApiFolders')->willReturn([10 => 'test county turfs']);

      $result = $svc->getFolderOptions(0, 'TEST');

      $this->assertArrayHasKey(10, $result);
    }

    public function testGetFolderOptionsReturnsEmptyWhenNoFoldersMatchCommittee(): void {
      $this->addMessengerToContainer();
      $svc = $this->buildPartialService();
      $svc->method('getApiFolders')->willReturn([11 => 'OTHER County Turfs']);

      $result = $svc->getFolderOptions(0, 'TEST');

      $this->assertSame([], $result);
    }

  }

}
