<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\nlpservices\ApiKeyManagement;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpTurfMap;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\PdfGenerator;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class NlpTurfMapTest extends UnitTestCase {

  private ClientInterface $httpMock;
  private NlpVoters $votersMock;
  private NlpConfiguration $configMock;
  private ApiKeyManagement $keysMock;
  private FileSystemInterface $fileSystemMock;
  private string $tempDir = '';

  protected function setUp(): void {
    parent::setUp();
    $this->httpMock = $this->createMock(ClientInterface::class);
    $this->votersMock = $this->createMock(NlpVoters::class);
    $this->configMock = $this->createMock(NlpConfiguration::class);
    $this->configMock->method('getStateName')->willReturn('Oregon');
    $this->keysMock = $this->createMock(ApiKeyManagement::class);
    $this->fileSystemMock = $this->createMock(FileSystemInterface::class);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $container = new ContainerBuilder();
    $container->set('logger.factory', $loggerFactory);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
    if ($this->tempDir !== '' && is_dir($this->tempDir)) {
      array_map('unlink', glob("$this->tempDir/*") ?: []);
      rmdir($this->tempDir);
    }
  }

  private function buildService(): NlpTurfMap {
    return new NlpTurfMap(
      $this->httpMock,
      $this->createMock(NlpTurfs::class),
      $this->votersMock,
      $this->configMock,
      $this->keysMock,
      $this->createMock(PdfGenerator::class),
      $this->fileSystemMock,
      $this->createMock(FileUrlGeneratorInterface::class),
    );
  }

  private function voter(int $vanid, string $streetNo, string $streetName, array $extra = []): array {
    return [
      'vanid' => $vanid,
      'address' => $extra + [
        'streetNo' => $streetNo, 'streetPrefix' => 'SE', 'streetName' => $streetName, 'streetType' => 'St',
        'streetSuffix' => '', 'aptType' => '', 'aptNo' => '', 'city' => 'Hillsboro', 'zip' => '97123-1234',
      ],
    ];
  }

  private function door(string $street, string $zip = '97123'): array {
    return ['street' => $street, 'city' => 'Hillsboro', 'zip' => $zip, 'voters' => 1];
  }

  /** A Geocodio batch result for one door. */
  private function geocodioResult(float $lat, float $lng, string $type = 'rooftop', float $accuracy = 1.0,
                                  string $zip = '97123'): array {
    return ['response' => ['results' => [[
      'formatted_address' => 'x', 'accuracy' => $accuracy, 'accuracy_type' => $type,
      'address_components' => ['zip' => $zip], 'location' => ['lat' => $lat, 'lng' => $lng],
    ]]]];
  }

  /** Twelve points in a block about 300 m across, plus any extras. */
  private function cluster(array $extra = []): array {
    $points = [];
    for ($i = 0; $i < 12; $i++) {
      $points[$i] = ['lat' => 45.4800 + ($i % 4) * 0.0008, 'lon' => -122.9000 + intdiv($i, 4) * 0.0012];
    }
    return $points + $extra;
  }

  // ---------------------------------------------------------------------------
  // turfDoors
  // ---------------------------------------------------------------------------

  public function testTurfDoorsCombinesVotersAtOneAddressAndDropsApartments(): void {
    $doors = $this->buildService()->turfDoors([
      $this->voter(1, '100', 'Gerhard'),
      $this->voter(2, '100', 'Gerhard', ['aptType' => 'Apt', 'aptNo' => '2']),
      $this->voter(3, '102', 'Gerhard'),
    ]);

    $this->assertSame([
      ['street' => '100 SE Gerhard St', 'city' => 'Hillsboro', 'zip' => '97123', 'voters' => 2],
      ['street' => '102 SE Gerhard St', 'city' => 'Hillsboro', 'zip' => '97123', 'voters' => 1],
    ], $doors);
  }

  // ---------------------------------------------------------------------------
  // Geocoder responses
  // ---------------------------------------------------------------------------

  public function testParseCensusResponseSplitsMatchesFromMisses(): void {
    $doors = [$this->door('100 SE Gerhard St'), $this->door('1 Nowhere Ln'), $this->door('5 Lost Rd')];
    $body = "\"0\",\"100 SE Gerhard St, Hillsboro, OR, 97123\",\"Match\",\"Exact\",\"100 SE GERHARD ST, HILLSBORO, OR, 97123\",\"-122.9,45.48\",\"1\",\"L\"\r\n"
      . "\"1\",\"1 Nowhere Ln, Hillsboro, OR, 97123\",\"No_Match\"\r\n";

    $result = $this->buildService()->parseCensusResponse($body, $doors);

    $this->assertSame(45.48, $result['matched'][0]['lat']);
    $this->assertSame(-122.9, $result['matched'][0]['lon']);
    $this->assertSame(NlpTurfMap::PLACED_STREET, $result['matched'][0]['placed']);
    $this->assertSame('No_Match', $result['unmatched'][1]['status']);
    $this->assertSame('Missing from response', $result['unmatched'][2]['status']);
  }

  public function testParseGeocodioResponseSortsByPlacement(): void {
    $doors = [$this->door('1 A St'), $this->door('2 B St'), $this->door('3 C St'), $this->door('4 D St'), $this->door('5 E St')];
    $decoded = ['results' => [
      'd0' => $this->geocodioResult(45.48, -122.90),
      'd1' => $this->geocodioResult(45.48, -122.90, 'nearest_rooftop_match', 0.9),
      'd2' => $this->geocodioResult(45.48, -122.90, 'range_interpolation', 0.9),
      'd3' => $this->geocodioResult(45.5, -122.9, 'place', 0.5),
      'd4' => ['response' => ['results' => []]],
    ]];

    $result = $this->buildService()->parseGeocodioResponse($decoded, $doors);

    $this->assertSame(NlpTurfMap::PLACED_BUILDING, $result['matched'][0]['placed']);
    $this->assertSame(NlpTurfMap::PLACED_NEAREST, $result['matched'][1]['placed']);
    $this->assertSame(NlpTurfMap::PLACED_STREET, $result['matched'][2]['placed']);
    $this->assertSame('Too vague (place)', $result['unmatched'][3]['status']);
    $this->assertSame('No match', $result['unmatched'][4]['status']);
  }

  public function testGeocodioDoubtsCatchesWrongZipStreetOnlyAndLowAccuracy(): void {
    $service = $this->buildService();
    $parse = fn(array $result) => $service->parseGeocodioResponse(['results' => ['d0' => $result]], [$this->door('1 A St', '32618')])['matched'][0];

    $this->assertSame([], $service->geocodioDoubts($parse($this->geocodioResult(29.5, -82.6, 'rooftop', 1.0, '32618'))));
    $this->assertSame(
      ['different zip (32621)', 'street only, no house number', 'low accuracy (0.7)'],
      $service->geocodioDoubts($parse($this->geocodioResult(29.4, -82.6, 'street_center', 0.7, '32621'))),
    );
  }

  // ---------------------------------------------------------------------------
  // findOutliers
  // ---------------------------------------------------------------------------

  public function testFindOutliersFlagsOnlyTheFarDoor(): void {
    // Door 12 is about 2.5 km south of the block; door 13 is just past its edge.
    $points = $this->cluster([12 => ['lat' => 45.4580, 'lon' => -122.9010], 13 => ['lat' => 45.4835, 'lon' => -122.9000]]);

    $outliers = $this->buildService()->findOutliers($points);

    $this->assertSame([12], array_keys($outliers));
    $this->assertGreaterThan(2400, $outliers[12]);
  }

  public function testFindOutliersNeedsEnoughDoorsToJudge(): void {
    $points = [['lat' => 45.48, 'lon' => -122.9], ['lat' => 45.48, 'lon' => -122.9], ['lat' => 45.0, 'lon' => -122.0]];

    $this->assertSame([], $this->buildService()->findOutliers($points));
  }

  // ---------------------------------------------------------------------------
  // mapboxUrl
  // ---------------------------------------------------------------------------

  public function testMapboxUrlColorsPinsByPlacement(): void {
    $service = $this->buildService();
    $points = [
      ['lat' => 45.48, 'lon' => -122.9, 'placed' => NlpTurfMap::PLACED_BUILDING],
      ['lat' => 45.481, 'lon' => -122.901, 'placed' => NlpTurfMap::PLACED_STREET],
    ];

    $url = $service->mapboxUrl($points, 'mapbox/streets-v12', $service->mapView($points));

    $this->assertStringStartsWith('https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/pin-s+d62728(-122.9,45.48),pin-s+ff8c00(-122.901,45.481)/', $url);
    $this->assertStringNotContainsString('access_token', $url);
  }

  // ---------------------------------------------------------------------------
  // Keys
  // ---------------------------------------------------------------------------

  public function testDeleteGeocodioKeyBlanksTheFileBeforeDeletingTheKey(): void {
    $calls = [];
    $this->keysMock->method('saveApiKey')->willReturnCallback(function ($id, $value) use (&$calls) {
      $calls[] = "blank $id '$value'";
      return TRUE;
    });
    $this->keysMock->method('deleteKey')->willReturnCallback(function ($id) use (&$calls) {
      $calls[] = "delete $id";
      return TRUE;
    });

    $this->assertTrue($this->buildService()->deleteGeocodioKey());
    $this->assertSame(["blank nlp_geocodio_key ''", 'delete nlp_geocodio_key'], $calls);
  }

  public function testVerifyMapboxTokenAcceptsAValidToken(): void {
    $this->keysMock->method('getKey')->willReturn('pk.token');
    $this->httpMock->expects($this->once())->method('request')
      ->with('GET', NlpTurfMap::MAPBOX_TOKEN_URL, $this->callback(fn($options) => $options['query']['access_token'] === 'pk.token'))
      ->willReturn(new Response(200, [], '{"code":"TokenValid"}'));

    $this->assertSame('', $this->buildService()->verifyMapboxToken());
  }

  public function testVerifyMapboxTokenReportsAnInvalidTokenWithoutShowingIt(): void {
    $this->keysMock->method('getKey')->willReturn('pk.secret-token');
    $this->httpMock->method('request')->willThrowException(new RequestException(
      'Client error: GET https://api.mapbox.com/tokens/v2?access_token=pk.secret-token',
      new Request('GET', 'https://api.mapbox.com/tokens/v2'),
      new Response(401, [], '{"code":"TokenInvalid"}'),
    ));

    $error = $this->buildService()->verifyMapboxToken();

    $this->assertSame('Mapbox says the token is not valid (TokenInvalid).', $error);
  }

  public function testVerifyGeocodioKeyReportsGeocodiosMessageWithoutShowingTheKey(): void {
    $this->keysMock->method('getKey')->willReturn('secretkey0123456789secretkey');
    $this->httpMock->method('request')->willThrowException(new RequestException(
      'Client error: GET https://api.geocod.io/v1.7/geocode?api_key=secretkey0123456789secretkey',
      new Request('GET', 'https://api.geocod.io/v1.7/geocode'),
      new Response(403, [], '{"error":"Invalid API key"}'),
    ));

    $error = $this->buildService()->verifyGeocodioKey();

    $this->assertSame('Geocodio refused the key (status 403): Invalid API key', $error);
    $this->assertStringNotContainsString('secretkey', $error);
  }

  // ---------------------------------------------------------------------------
  // walksheetMap
  // ---------------------------------------------------------------------------

  public function testWalksheetMapIsSkippedWithoutAMapboxToken(): void {
    $this->keysMock->method('getKey')->willReturn('');
    $this->httpMock->expects($this->never())->method('request');

    $this->assertSame([], $this->buildService()->walksheetMap(7));
  }

  public function testWalksheetMapUsesTheCachedMapWithoutCallingAnyService(): void {
    $voters = [$this->voter(1, '100', 'Gerhard'), $this->voter(2, '102', 'Gerhard')];
    $this->votersMock->method('fetchVotersByTurf')->with(7)->willReturn($voters);
    $this->keysMock->method('getKey')->willReturnMap([
      [NlpTurfMap::MAPBOX_KEY_ID, 'pk.token'],
      [NlpTurfMap::GEOCODIO_KEY_ID, ''],
    ]);
    $this->httpMock->expects($this->never())->method('request');

    // Write the cached files under the name walksheetMap() expects.
    $service = $this->buildService();
    $hash = md5(json_encode([NlpTurfMap::WALKSHEET_MAP_VERSION, 'census', NlpTurfMap::DEFAULT_STYLE, $service->turfDoors($voters)]));
    $this->tempDir = sys_get_temp_dir() . '/nlp-turf-map-test-' . getmypid();
    @mkdir($this->tempDir);
    file_put_contents("$this->tempDir/turf-7-$hash.png", 'png');
    file_put_contents("$this->tempDir/turf-7-$hash.json", json_encode(['notOnMap' => [['address' => '9 Far Rd', 'why' => '2.0 mi away']]]));
    $this->fileSystemMock->method('realpath')->willReturnCallback(
      fn(string $uri) => realpath(str_replace(NlpTurfMap::WALKSHEET_MAP_DIR, $this->tempDir, $uri))
    );

    $map = $service->walksheetMap(7);

    $this->assertSame(realpath("$this->tempDir/turf-7-$hash.png"), $map['path']);
    $this->assertSame([['address' => '9 Far Rd', 'why' => '2.0 mi away']], $map['notOnMap']);
  }

  public function testWalksheetMapFallsBackToCensusWhenGeocodioFailsAndDoesNotKeepTheMap(): void {
    $voters = [];
    for ($i = 0; $i < 5; $i++) {
      $voters[] = $this->voter($i + 1, (string) (100 + 2 * $i), 'Gerhard');
    }
    $this->votersMock->method('fetchVotersByTurf')->willReturn($voters);
    $this->keysMock->method('getKey')->willReturnMap([
      [NlpTurfMap::MAPBOX_KEY_ID, 'pk.token'],
      [NlpTurfMap::GEOCODIO_KEY_ID, 'abcdef0123456789abcdef0123456789'],
    ]);
    $this->fileSystemMock->method('realpath')->willReturnCallback(
      fn(string $uri) => str_ends_with($uri, '.png') ? '/private/turf-maps/map.png' : FALSE
    );
    $this->fileSystemMock->method('dirname')->willReturn(NlpTurfMap::WALKSHEET_MAP_DIR);
    // Only the PNG is saved; no JSON, so the map isn't reused.
    $this->fileSystemMock->expects($this->once())->method('saveData')
      ->with('png', $this->stringEndsWith('.png'));
    $censusRows = '';
    for ($i = 0; $i < 5; $i++) {
      $censusRows .= "\"$i\",\"x\",\"Match\",\"Exact\",\"x\",\"-122.90$i,45.48$i\",\"1\",\"L\"\n";
    }
    $calls = [];
    $this->httpMock->method('request')->willReturnCallback(
      function (string $method, string $url) use ($censusRows, &$calls) {
        $host = parse_url($url, PHP_URL_HOST);
        $calls[] = $host;
        return match ($host) {
          'api.geocod.io' => throw new RequestException('limit', new Request('POST', $url),
            new Response(403, [], '{"error":"You have reached your daily limit"}')),
          'geocoding.geo.census.gov' => new Response(200, [], $censusRows),
          default => new Response(200, [], 'png'),
        };
      }
    );

    $map = $this->buildService()->walksheetMap(7);

    $this->assertSame(['api.geocod.io', 'geocoding.geo.census.gov', 'api.mapbox.com'], $calls);
    $this->assertSame('/private/turf-maps/map.png', $map['path']);
  }

  public function testWalksheetMapGivesNoMapWhenMapboxFails(): void {
    $voters = [];
    for ($i = 0; $i < 5; $i++) {
      $voters[] = $this->voter($i + 1, (string) (100 + 2 * $i), 'Gerhard');
    }
    $this->votersMock->method('fetchVotersByTurf')->willReturn($voters);
    $this->keysMock->method('getKey')->willReturnMap([
      [NlpTurfMap::MAPBOX_KEY_ID, 'pk.token'],
      [NlpTurfMap::GEOCODIO_KEY_ID, ''],
    ]);
    $this->fileSystemMock->method('realpath')->willReturn(FALSE);
    $censusRows = '';
    for ($i = 0; $i < 5; $i++) {
      $censusRows .= "\"$i\",\"x\",\"Match\",\"Exact\",\"x\",\"-122.90$i,45.48$i\",\"1\",\"L\"\n";
    }
    $this->httpMock->method('request')->willReturnCallback(
      function (string $method, string $url) use ($censusRows) {
        if (str_contains($url, 'census.gov')) {
          return new Response(200, [], $censusRows);
        }
        throw new RequestException('denied', new Request('GET', $url), new Response(401, [], '{"message":"Not Authorized - Invalid Token"}'));
      }
    );

    $this->assertSame([], $this->buildService()->walksheetMap(7));
  }

}
