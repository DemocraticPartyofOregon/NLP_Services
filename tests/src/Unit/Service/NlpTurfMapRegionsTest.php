<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\nlpservices\NlpTurfMapRegions;
  use Drupal\Tests\UnitTestCase;

  class NlpTurfMapRegionsTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpTurfMapRegions {
      return new NlpTurfMapRegions($this->connectionMock);
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function baseFields(): array {
      return [
        'mapRegionId'   => 5,
        'mapRegionName' => 'Region A',
        'dateRefreshed' => '2024-10-15',
        'folderId'      => 12,
        'folderName'    => 'Folder One',
      ];
    }

    // -------------------------------------------------------------------------
    // updateTurfMapRegion
    // -------------------------------------------------------------------------

    public function testUpdateTurfMapRegionMergesWithMapRegionIdAsKey(): void {
      $captured = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')->willReturnCallback(
        function (array $keys) use (&$captured, $mergeMock): Merge {
          $captured['keys'] = $keys;
          return $mergeMock;
        }
      );
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured['fields'] = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->updateTurfMapRegion($this->baseFields());

      $this->assertSame(['mapRegionId' => 5], $captured['keys']);
    }

    public function testUpdateTurfMapRegionFieldsContainMapRegionName(): void {
      $captured = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured['fields'] = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->updateTurfMapRegion($this->baseFields());

      $this->assertSame('Region A', $captured['fields']['mapRegionName']);
    }

    public function testUpdateTurfMapRegionFieldsContainFolderData(): void {
      $captured = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured['fields'] = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->updateTurfMapRegion($this->baseFields());

      $this->assertSame(12, $captured['fields']['folderId']);
      $this->assertSame('Folder One', $captured['fields']['folderName']);
    }

    public function testUpdateTurfMapRegionMergesCorrectTable(): void {
      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpTurfMapRegions::TURF_MAP_REGIONS_TBL)
        ->willReturn($this->buildMergeMock());

      $this->buildService()->updateTurfMapRegion($this->baseFields());
    }

    public function testUpdateTurfMapRegionDoesNotThrowOnException(): void {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->updateTurfMapRegion($this->baseFields());
      $this->assertTrue(TRUE);
    }

  }

}
