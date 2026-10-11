<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiSavedLists;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiSavedListsTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpTurfs $turfsMock;
    private NlpNls $nlsMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->turfsMock            = $this->createMock(NlpTurfs::class);
      $this->nlsMock              = $this->createMock(NlpNls::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiSavedLists {
      return new ApiSavedLists(
        $this->clientMock,
        $this->turfsMock,
        $this->nlsMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    private function buildPartialService(): ApiSavedLists {
      return $this->getMockBuilder(ApiSavedLists::class)
        ->setConstructorArgs([
          $this->clientMock,
          $this->turfsMock,
          $this->nlsMock,
          $this->configurationMock,
          $this->apiKeyManagementMock,
        ])
        ->onlyMethods(['getSavedLists'])
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

    private function listItem(int $id, string $name = ''): array {
      return [
        'savedListId' => $id,
        'name'        => $name ?: "List $id",
        'description' => "Desc $id",
        'listCount'   => 100 + $id,
        'doorCount'   => 50 + $id,
      ];
    }

    private function pagedResponse(array $items, int $total): array {
      return ['count' => $total, 'items' => $items];
    }

    // -------------------------------------------------------------------------
    // getTurfLists
    // -------------------------------------------------------------------------

    public function testGetTurfListsReturnsEmptyOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('connect error'));

      $this->assertSame([], $this->buildService()->getTurfLists('TEST', 10));
    }

    public function testGetTurfListsReturnsEmptyOnGenericException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('unexpected'));

      $this->assertSame([], $this->buildService()->getTurfLists('TEST', 10));
    }

    public function testGetTurfListsReturnsEmptyWhenItemsIsAbsent(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse(['count' => 0]));

      $this->assertSame([], $this->buildService()->getTurfLists('TEST', 10));
    }

    public function testGetTurfListsReturnsAllItemsKeyedBySavedListId(): void {
      $this->stubKeyValues();
      $items = [$this->listItem(1), $this->listItem(2)];
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->pagedResponse($items, 2)));

      $result = $this->buildService()->getTurfLists('TEST', 10);

      $this->assertArrayHasKey(1, $result);
      $this->assertArrayHasKey(2, $result);
      $this->assertCount(2, $result);
    }

    public function testGetTurfListsExcludesIdsInExcludedList(): void {
      $this->stubKeyValues();
      $items = [$this->listItem(1), $this->listItem(2), $this->listItem(3)];
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->pagedResponse($items, 3)));

      $result = $this->buildService()->getTurfLists('TEST', 10, [2]);

      $this->assertArrayHasKey(1, $result);
      $this->assertArrayNotHasKey(2, $result);
      $this->assertArrayHasKey(3, $result);
    }

    public function testGetTurfListsHandlesPaginatedResponses(): void {
      $this->stubKeyValues();
      $page1 = $this->pagedResponse([$this->listItem(1)], 2);
      $page2 = $this->pagedResponse([$this->listItem(2)], 2);
      $this->clientMock->method('request')
        ->willReturnOnConsecutiveCalls(
          $this->makeResponse($page1),
          $this->makeResponse($page2),
        );

      $result = $this->buildService()->getTurfLists('TEST', 10);

      $this->assertArrayHasKey(1, $result);
      $this->assertArrayHasKey(2, $result);
      $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // getSavedLists — early returns
    // -------------------------------------------------------------------------

    public function testGetSavedListsReturnsEmptyWhenBothFolderIdAndListIdAreZero(): void {
      $this->stubKeyValues();

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0));
    }

    // -------------------------------------------------------------------------
    // getSavedLists — specific list (listId given)
    // -------------------------------------------------------------------------

    public function testGetSavedListsReturnsEmptyOnGuzzleExceptionForSpecificList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('error'));

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0, 0, 99));
    }

    public function testGetSavedListsReturnsEmptyOnGenericExceptionForSpecificList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('error'));

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0, 0, 99));
    }

    public function testGetSavedListsReturnsEmptyWhenSavedListIdMissingFromResponse(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse(['name' => 'No Id Here']));

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0, 0, 99));
    }

    public function testGetSavedListsReturnsSingleItemKeyedBySavedListId(): void {
      $this->stubKeyValues();
      $item = $this->listItem(99);
      $this->clientMock->method('request')->willReturn($this->makeResponse($item));

      $result = $this->buildService()->getSavedLists('TEST', 0, 0, 99);

      $this->assertArrayHasKey(99, $result);
      $this->assertSame('List 99', $result[99]['name']);
    }

    // -------------------------------------------------------------------------
    // getSavedLists — folder list (folderId given)
    // -------------------------------------------------------------------------

    public function testGetSavedListsReturnsEmptyOnGuzzleExceptionForFolderList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('error'));

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0, 10));
    }

    public function testGetSavedListsReturnsEmptyWhenItemsAbsentForFolderList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse(['count' => 0]));

      $this->assertSame([], $this->buildService()->getSavedLists('TEST', 0, 10));
    }

    public function testGetSavedListsReturnsAllItemsForFolder(): void {
      $this->stubKeyValues();
      $items = [$this->listItem(1), $this->listItem(2)];
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->pagedResponse($items, 2)));

      $result = $this->buildService()->getSavedLists('TEST', 0, 10);

      $this->assertArrayHasKey(1, $result);
      $this->assertArrayHasKey(2, $result);
    }

    public function testGetSavedListsHandlesPaginationForFolderList(): void {
      $this->stubKeyValues();
      $page1 = $this->pagedResponse([$this->listItem(1)], 2);
      $page2 = $this->pagedResponse([$this->listItem(2)], 2);
      $this->clientMock->method('request')
        ->willReturnOnConsecutiveCalls(
          $this->makeResponse($page1),
          $this->makeResponse($page2),
        );

      $result = $this->buildService()->getSavedLists('TEST', 0, 10);

      $this->assertArrayHasKey(1, $result);
      $this->assertArrayHasKey(2, $result);
    }

    public function testGetSavedListsUsesListIdInUrlWhenGiven(): void {
      $this->stubKeyValues();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->makeResponse($this->listItem(99));
        });

      $this->buildService()->getSavedLists('TEST', 1, 0, 99);

      $this->assertStringContainsString('/savedLists/99', $capturedUrl);
      $this->assertStringContainsString('|1@', $capturedUrl);
    }

    public function testGetSavedListsUsesFolderIdInUrlWhenGiven(): void {
      $this->stubKeyValues();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->makeResponse($this->pagedResponse([$this->listItem(1)], 1));
        });

      $this->buildService()->getSavedLists('TEST', 0, 10);

      $this->assertStringContainsString('folderId=10', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // savedListOptions
    // -------------------------------------------------------------------------

    public function testSavedListOptionsReturnsEmptyForEmptyInput(): void {
      $this->assertSame([], $this->buildService()->savedListOptions([]));
    }

    public function testSavedListOptionsMapsFieldsCorrectly(): void {
      $lists = [
        1 => ['savedListId' => 1, 'name' => 'Turf Alpha', 'description' => 'Desc', 'listCount' => 200, 'doorCount' => 100],
        2 => ['savedListId' => 2, 'name' => 'Turf Beta',  'description' => '',     'listCount' => 50,  'doorCount' => 25],
      ];

      $result = $this->buildService()->savedListOptions($lists);

      $this->assertSame('Turf Alpha', $result[1]['turfName']);
      $this->assertSame('', $result[1]['mapRegionName']);
      $this->assertSame(1, $result[1]['turfId']);
      $this->assertSame('Desc', $result[1]['description']);
      $this->assertSame(200, $result[1]['voterCount']);
      $this->assertSame(100, $result[1]['doorCount']);
    }

    public function testSavedListOptionsDefaultsDescriptionToEmptyString(): void {
      $lists = [5 => ['savedListId' => 5, 'name' => 'T', 'listCount' => 1, 'doorCount' => 1]];

      $result = $this->buildService()->savedListOptions($lists);

      $this->assertSame('', $result[5]['description']);
    }

    public function testSavedListOptionsAlwaysSetsEmptyMapRegionName(): void {
      $lists = [5 => ['savedListId' => 5, 'name' => 'T', 'description' => '', 'listCount' => 1, 'doorCount' => 1]];

      $result = $this->buildService()->savedListOptions($lists);

      $this->assertSame('', $result[5]['mapRegionName']);
    }

    public function testSavedListOptionsSetsMapRegionIdAndMinivanKeys(): void {
      $lists = [5 => ['savedListId' => 5, 'name' => 'T', 'description' => '', 'listCount' => 1, 'doorCount' => 1]];

      $result = $this->buildService()->savedListOptions($lists);

      $this->assertArrayHasKey('mapRegionId', $result[5]);
      $this->assertNull($result[5]['mapRegionId']);
      $this->assertSame('', $result[5]['minivanNumber']);
      $this->assertSame('', $result[5]['minivanCreateDate']);
    }

    // -------------------------------------------------------------------------
    // recoverTurfId
    // -------------------------------------------------------------------------

    public function testRecoverTurfIdReturnsErrorWhenTurfNotFound(): void {
      $svc = $this->buildPartialService();
      $svc->method('getSavedLists')->willReturn([
        1 => ['savedListId' => 1, 'name' => 'Other Turf'],
      ]);

      $result = $svc->recoverTurfId('TEST', 10, 'Missing Turf');

      $this->assertSame('error', $result['result']);
      $this->assertArrayHasKey('message', $result);
    }

    public function testRecoverTurfIdReturnsOkWithTurfIdWhenFound(): void {
      $svc = $this->buildPartialService();
      $svc->method('getSavedLists')->willReturn([
        42 => ['savedListId' => 42, 'name' => 'Denver-001 East'],
      ]);

      $result = $svc->recoverTurfId('TEST', 10, 'Denver-001');

      $this->assertSame('ok', $result['result']);
      $this->assertSame(42, $result['turfId']);
    }

    public function testRecoverTurfIdUsesStrContainsForNameMatch(): void {
      $svc = $this->buildPartialService();
      $svc->method('getSavedLists')->willReturn([
        7  => ['savedListId' => 7,  'name' => 'Unrelated List'],
        42 => ['savedListId' => 42, 'name' => 'Target Turf Name Extra'],
      ]);

      $result = $svc->recoverTurfId('TEST', 10, 'Target Turf');

      $this->assertSame('ok', $result['result']);
      $this->assertSame(42, $result['turfId']);
    }

    public function testRecoverTurfIdReturnsFirstMatchWhenMultipleContainName(): void {
      $svc = $this->buildPartialService();
      $svc->method('getSavedLists')->willReturn([
        10 => ['savedListId' => 10, 'name' => 'Target A'],
        20 => ['savedListId' => 20, 'name' => 'Target B'],
      ]);

      $result = $svc->recoverTurfId('TEST', 10, 'Target');

      $this->assertSame('ok', $result['result']);
      $this->assertSame(10, $result['turfId']);
    }

  }

}
