<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiMapRegions;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiMapRegionsTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiMapRegions {
      return new ApiMapRegions(
        $this->clientMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
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

    /**
     * Two-region API response used by most tests.
     * Region A (100) has one turf with a printedList.
     * Region B (200) has one turf with no printedList.
     */
    private function twoRegionResponse(): array {
      return [
        'items' => [
          [
            'name'          => 'Region B',
            'mapRegionId'   => 200,
            'dateRefreshed' => '2024-01-01',
            'mapRoutes'     => [
              [
                'name'        => 'Turf 2',
                'mapRouteId'  => 2001,
                'savedListId' => 5002,
                'routeSize'   => 100,
                'doorCount'   => 50,
                'printedList' => NULL,
              ],
            ],
          ],
          [
            'name'          => 'Region A',
            'mapRegionId'   => 100,
            'dateRefreshed' => '2024-01-01',
            'mapRoutes'     => [
              [
                'name'        => 'Turf 1',
                'mapRouteId'  => 1001,
                'savedListId' => 5001,
                'routeSize'   => 150,
                'doorCount'   => 75,
                'printedList' => [
                  'number'      => 'MV-001',
                  'dateCreated' => '2024-01-15',
                  'createdBy'   => 'user1',
                ],
              ],
            ],
          ],
        ],
      ];
    }

    private function singleRegionResponse(): array {
      return [
        'name'          => 'Region A',
        'mapRegionId'   => 100,
        'dateRefreshed' => '2024-01-01',
        'mapRoutes'     => [
          [
            'name'        => 'Turf 1',
            'mapRouteId'  => 1001,
            'savedListId' => 5001,
            'routeSize'   => 150,
            'doorCount'   => 75,
            'printedList' => NULL,
          ],
        ],
      ];
    }

    // -------------------------------------------------------------------------
    // getMapRegionInfo
    // -------------------------------------------------------------------------

    public function testGetMapRegionInfoReturnsEmptyWhenFolderIdIsZero(): void {
      $this->assertSame([], $this->buildService()->getMapRegionInfo('TEST', 0));
    }

    public function testGetMapRegionInfoReturnsEmptyOn404(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException($this->makeRequestException(404));

      $this->assertSame([], $this->buildService()->getMapRegionInfo('TEST', 10));
    }

    public function testGetMapRegionInfoReturnsEmptyOnOtherRequestException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException($this->makeRequestException(500));

      $this->assertSame([], $this->buildService()->getMapRegionInfo('TEST', 10));
    }

    public function testGetMapRegionInfoReturnsEmptyOnGenericException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException(new Exception('unexpected'));

      $this->assertSame([], $this->buildService()->getMapRegionInfo('TEST', 10));
    }

    public function testGetMapRegionInfoReturnsEmptyOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException(new TransferException('transfer error'));

      $this->assertSame([], $this->buildService()->getMapRegionInfo('TEST', 10));
    }

    public function testGetMapRegionInfoReturnsAllRegionsWhenNoMapRegionId(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));

      $result = $this->buildService()->getMapRegionInfo('TEST', 10);

      $this->assertArrayHasKey(100, $result);
      $this->assertArrayHasKey(200, $result);
      $this->assertSame('Region A', $result[100]['mapRegionName']);
      $this->assertSame('Region B', $result[200]['mapRegionName']);
    }

    public function testGetMapRegionInfoReturnsSingleRegionWhenMapRegionIdGiven(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->singleRegionResponse()));

      $result = $this->buildService()->getMapRegionInfo('TEST', 10, 100);

      $this->assertSame('Region A', $result['mapRegionName']);
      $this->assertSame(100, $result['mapRegionId']);
    }

    public function testGetMapRegionInfoExtractsTurfInfo(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));

      $result = $this->buildService()->getMapRegionInfo('TEST', 10);

      $turfInfo = $result[100]['turfInfo'][5001];
      $this->assertSame('Turf 1', $turfInfo['turfName']);
      $this->assertSame(5001, $turfInfo['savedListId']);
      $this->assertSame(150, $turfInfo['routeSize']);
      $this->assertSame(75, $turfInfo['doorCount']);
    }

    public function testGetMapRegionInfoKeysTurfInfoBySavedListId(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));

      $result = $this->buildService()->getMapRegionInfo('TEST', 10);

      $this->assertArrayHasKey(5001, $result[100]['turfInfo']);
      $this->assertArrayHasKey(5002, $result[200]['turfInfo']);
    }

    // -------------------------------------------------------------------------
    // mapRegionOptions
    // -------------------------------------------------------------------------

    public function testMapRegionOptionsReturnsEmptyWhenCommitteeIsEmpty(): void {
      $this->assertSame([], $this->buildService()->mapRegionOptions('', 10));
    }

    public function testMapRegionOptionsReturnsEmptyWhenNoRegionsFound(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse(['items' => []]));

      // Empty items → getMapRegionInfo returns [], so mapOptions has only 'all' → returns []
      $result = $this->buildService()->mapRegionOptions('TEST', 10);

      $this->assertSame([], $result);
    }

    public function testMapRegionOptionsPrependsAllAndSortsAlphabetically(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));

      $result = $this->buildService()->mapRegionOptions('TEST', 10);

      $keys = array_keys($result);
      $this->assertSame('all', $keys[0]);
      $this->assertSame('All', $result['all']);
      // Region A should sort before Region B
      $this->assertSame(100, $keys[1]);
      $this->assertSame(200, $keys[2]);
    }

    public function testMapRegionOptionsContainsRegionNames(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));

      $result = $this->buildService()->mapRegionOptions('TEST', 10);

      $this->assertSame('Region A', $result[100]);
      $this->assertSame('Region B', $result[200]);
    }

    // -------------------------------------------------------------------------
    // mapRegionTurfOptions
    // -------------------------------------------------------------------------

    public function testMapRegionTurfOptionsReturnsEmptyWhenMapRegionIdIsEmpty(): void {
      $this->assertSame([], $this->buildService()->mapRegionTurfOptions(''));
    }

    public function testMapRegionTurfOptionsReturnsSpecificRegionTurfs(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();
      $svc->getMapRegionInfo('TEST', 10);

      $result = $svc->mapRegionTurfOptions(100);

      $this->assertArrayHasKey(5001, $result);
      $this->assertArrayNotHasKey(5002, $result);
      $this->assertSame('Turf 1', $result[5001]['turfName']);
      $this->assertSame(100, $result[5001]['mapRegionId']);
      $this->assertSame('Region A', $result[5001]['mapRegionName']);
    }

    public function testMapRegionTurfOptionsReturnsAllTurfsWhenMapRegionIdIsAll(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();
      $svc->getMapRegionInfo('TEST', 10);

      $result = $svc->mapRegionTurfOptions('all');

      $this->assertArrayHasKey(5001, $result);
      $this->assertArrayHasKey(5002, $result);
    }

    public function testMapRegionTurfOptionsRemovesCreatedByFromPrintedList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();
      $svc->getMapRegionInfo('TEST', 10);

      $result = $svc->mapRegionTurfOptions(100);

      $this->assertArrayNotHasKey('createdBy', $result[5001]['printedList']);
      $this->assertSame('MV-001', $result[5001]['printedList']['number']);
      $this->assertSame('2024-01-15', $result[5001]['printedList']['dateCreated']);
    }

    public function testMapRegionTurfOptionsIncludesTurfDetails(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();
      $svc->getMapRegionInfo('TEST', 10);

      $result = $svc->mapRegionTurfOptions(100);

      $turf = $result[5001];
      $this->assertSame(5001, $turf['turfId']);
      $this->assertSame(150, $turf['voterCount']);
      $this->assertSame(75, $turf['doorCount']);
      $this->assertSame('', $turf['turfDescription']);
    }

    // -------------------------------------------------------------------------
    // getTurfIds
    // -------------------------------------------------------------------------

    public function testGetTurfIdsReturnsEmptyBeforeGetMapRegionInfoCalled(): void {
      $this->assertSame([], $this->buildService()->getTurfIds());
    }

    public function testGetTurfIdsReturnsAllSavedListIdsAfterLoad(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();
      $svc->getMapRegionInfo('TEST', 10);

      $result = $svc->getTurfIds();

      $this->assertContains(5001, $result);
      $this->assertContains(5002, $result);
      $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // updateMinivanInfo
    // -------------------------------------------------------------------------

    public function testUpdateMinivanInfoSkipsTurfsWithNoMapRegionId(): void {
      $svc = $this->buildService();
      $turfArray = [
        7 => ['mapRegionId' => '', 'folderId' => 10, 'listId' => 5001],
      ];
      $this->clientMock->expects($this->never())->method('request');

      $svc->updateMinivanInfo('TEST', $turfArray);
    }

    public function testUpdateMinivanInfoSetsMinivanFieldsWhenPrintedListAvailable(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();

      $turfArray = [
        7 => [
          'mapRegionId'      => 100,
          'folderId'         => 10,
          'listId'           => 5001,
          'minivanNumber'    => '',
          'minivanCreateDate'=> '',
        ],
      ];
      $svc->updateMinivanInfo('TEST', $turfArray);

      $this->assertSame('MV-001', $turfArray[7]['minivanNumber']);
      $this->assertSame('2024-01-15', $turfArray[7]['minivanCreateDate']);
    }

    public function testUpdateMinivanInfoDoesNotSetMinivanFieldsWhenNoPrintedList(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse($this->twoRegionResponse()));
      $svc = $this->buildService();

      $turfArray = [
        8 => [
          'mapRegionId'      => 200,
          'folderId'         => 10,
          'listId'           => 5002,
          'minivanNumber'    => '',
          'minivanCreateDate'=> '',
        ],
      ];
      $svc->updateMinivanInfo('TEST', $turfArray);

      $this->assertSame('', $turfArray[8]['minivanNumber']);
      $this->assertSame('', $turfArray[8]['minivanCreateDate']);
    }

  }

}
