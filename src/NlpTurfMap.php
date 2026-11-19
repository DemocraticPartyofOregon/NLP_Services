<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turf maps: geocode a turf's doors (Geocodio, double-checked with the US
 * Census geocoder, or Census alone) and fetch a Mapbox static map with an
 * unnumbered pin at each door.
 *
 * walksheetMap() supplies the map page of the walk sheet PDF and caches it
 * per turf.  The test form at /nlp-awake-form uses buildTurfMap() to compare
 * geocoders, styles and zoom levels; it geocodes every time.
 */
class NlpTurfMap {

  const string MAPBOX_KEY_ID = 'nlp_mapbox_token';
  const string CENSUS_BATCH_URL = 'https://geocoding.geo.census.gov/geocoder/locations/addressbatch';
  const string CENSUS_BENCHMARK = 'Public_AR_Current';
  const string MAPBOX_STYLES_URL = 'https://api.mapbox.com/styles/v1';
  const string DEFAULT_STYLE = 'mapbox/streets-v12';
  // Built-in styles to compare; a Mapbox Studio style ("username/styleId")
  // can be entered on the form too.
  const array STYLES = [
    'mapbox/streets-v12' => 'Streets v12',
    'mapbox/outdoors-v12' => 'Outdoors v12',
    'mapbox/light-v11' => 'Light v11',
    'mapbox/satellite-streets-v12' => 'Satellite streets v12',
  ];
  const int MAP_WIDTH = 1280;
  const int MAP_HEIGHT = 900;
  const int MAP_PADDING = 50;
  // Mapbox static maps use 512-pixel tiles; the most it zooms in is 22.
  const int TILE_SIZE = 512;
  const float MAX_ZOOM = 18.0;
  // Where the geocoder put each door, and the pin for each.
  const string PLACED_BUILDING = 'building';
  const string PLACED_NEAREST = 'nearest';
  const string PLACED_STREET = 'street';
  const array MARKERS = [
    // Red: on the building.
    self::PLACED_BUILDING => 'pin-s+d62728',
    // Purple: on the nearest building the geocoder knows, which is usually,
    // but not always, the right one.
    self::PLACED_NEAREST => 'pin-s+8e44ad',
    // Orange: estimated along the street.
    self::PLACED_STREET => 'pin-s+ff8c00',
  ];

  // A door is far away, and left off the map, when it's both this many times
  // the typical door's distance from the middle of the turf and at least
  // this many meters from it.
  const float OUTLIER_FACTOR = 3.0;
  const int OUTLIER_MIN_METERS = 300;
  // Too few doors to say what's typical.
  const int OUTLIER_MIN_DOORS = 4;

  const array GEOCODERS = [
    'census' => 'US Census (along the street)',
    'geocodio' => 'Geocodio (on the building where it can)',
  ];
  const string GEOCODIO_KEY_ID = 'nlp_geocodio_key';
  const string GEOCODIO_URL = 'https://api.geocod.io/v1.7/geocode';
  // Geocoded to test a Geocodio key: the Oregon State Capitol.
  const string GEOCODIO_TEST_ADDRESS = '900 Court St NE, Salem, OR 97301';
  const string MAPBOX_TOKEN_URL = 'https://api.mapbox.com/tokens/v2';
  // Geocodio accuracy types that put the point on the building.
  const array GEOCODIO_BUILDING_TYPES = ['rooftop', 'point'];
  const string GEOCODIO_NEAREST_TYPE = 'nearest_rooftop_match';
  // Geocodio results to double-check with Census: below this accuracy, or
  // only the street (no house number), or in a different zip than the voter's.
  const float GEOCODIO_MIN_ACCURACY = 0.8;
  const string GEOCODIO_STREET_ONLY_TYPE = 'street_center';
  // Accuracy types too vague to put on a walk map.
  const array GEOCODIO_TOO_VAGUE_TYPES = ['place', 'county', 'state'];
  // Mapbox rejects request URLs longer than 8,192 characters.
  const int MAPBOX_URL_MAX = 8192;
  // The test form's maps and PDFs.
  const string MAP_DIR = 'public://temp/nlp-map-test';
  // Cached walk sheet maps.  Private: they show where voters live.
  const string WALKSHEET_MAP_DIR = 'private://turf-maps';
  // Change to redraw every cached walk sheet map, e.g. after changing the
  // style or the pins.
  const int WALKSHEET_MAP_VERSION = 1;

  const array STATE_ABBREVIATIONS = [
    'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA',
    'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE', 'District of Columbia' => 'DC',
    'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID', 'Illinois' => 'IL',
    'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA',
    'Maine' => 'ME', 'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN',
    'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
    'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
    'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK', 'Oregon' => 'OR',
    'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC', 'South Dakota' => 'SD',
    'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA',
    'Washington' => 'WA', 'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY',
  ];

  public function __construct(
    protected ClientInterface $httpClient,
    protected NlpTurfs $turfsObj,
    protected NlpVoters $votersObj,
    protected NlpConfiguration $nlpConfigurationObj,
    protected ApiKeyManagement $apiKeyManagementObj,
    protected PdfGenerator $pdfGeneratorObj,
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
      $container->get('nlpservices.pdf_generator'),
      $container->get('file_system'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * hasMapboxToken
   *
   * @return bool - TRUE if a Mapbox token is stored in the Key module.
   */
  public function hasMapboxToken(): bool
  {
    return $this->apiKeyManagementObj->getKey(self::MAPBOX_KEY_ID) !== '';
  }

  /**
   * mapboxTokenFingerprint
   *
   * Enough of the stored token to tell which one it is, without showing it.
   *
   * @return string - e.g. "pk.eyJ1…x9Qw (92 characters)", or '' if none is stored.
   */
  public function mapboxTokenFingerprint(): string
  {
    $token = $this->apiKeyManagementObj->getKey(self::MAPBOX_KEY_ID);
    if ($token === '') {
      return '';
    }
    return substr($token, 0, 7) . '…' . substr($token, -4) . ' (' . strlen($token) . ' characters)';
  }

  /**
   * isMapboxTokenFormat
   *
   * Mapbox tokens are "pk.", "sk." or "tk." followed by two dot-separated
   * base64url parts.
   *
   * @param string $token
   * @return bool
   */
  public static function isMapboxTokenFormat(string $token): bool
  {
    return preg_match('/^(pk|sk|tk)\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) === 1;
  }

  /**
   * saveMapboxToken
   *
   * Stores the Mapbox access token in the Key module, creating the key the
   * first time.
   *
   * @param string $token
   * @return bool - TRUE if the token was saved.
   */
  public function saveMapboxToken(string $token): bool
  {
    if ($this->apiKeyManagementObj->saveApiKey(self::MAPBOX_KEY_ID, $token)) {
      return TRUE;
    }
    return $this->apiKeyManagementObj->createKey(self::MAPBOX_KEY_ID, 'Mapbox access token', $token);
  }

  /**
   * hasGeocodioKey
   *
   * @return bool - TRUE if a Geocodio API key is stored in the Key module.
   */
  public function hasGeocodioKey(): bool
  {
    return $this->apiKeyManagementObj->getKey(self::GEOCODIO_KEY_ID) !== '';
  }

  /**
   * geocodioKeyFingerprint
   *
   * @return string - the first and last 4 characters and the length, or ''.
   */
  public function geocodioKeyFingerprint(): string
  {
    $key = $this->apiKeyManagementObj->getKey(self::GEOCODIO_KEY_ID);
    if ($key === '') {
      return '';
    }
    return substr($key, 0, 4) . '…' . substr($key, -4) . ' (' . strlen($key) . ' characters)';
  }

  /**
   * isGeocodioKeyFormat
   *
   * @param string $key
   * @return bool - letters and digits only.
   */
  public static function isGeocodioKeyFormat(string $key): bool
  {
    return preg_match('/^[A-Za-z0-9]{20,}$/', $key) === 1;
  }

  /**
   * saveGeocodioKey
   *
   * @param string $key
   * @return bool - TRUE if the key was saved.
   */
  public function saveGeocodioKey(string $key): bool
  {
    if ($this->apiKeyManagementObj->saveApiKey(self::GEOCODIO_KEY_ID, $key)) {
      return TRUE;
    }
    return $this->apiKeyManagementObj->createKey(self::GEOCODIO_KEY_ID, 'Geocodio API key', $key);
  }

  /**
   * deleteMapboxToken
   *
   * Without a token, walk sheets have no map.
   *
   * @return bool - TRUE if a stored token was removed.
   */
  public function deleteMapboxToken(): bool
  {
    return $this->deleteStoredKey(self::MAPBOX_KEY_ID);
  }

  /**
   * deleteGeocodioKey
   *
   * Without a key, maps are geocoded by Census alone.
   *
   * @return bool - TRUE if a stored key was removed.
   */
  public function deleteGeocodioKey(): bool
  {
    return $this->deleteStoredKey(self::GEOCODIO_KEY_ID);
  }

  /**
   * deleteStoredKey
   *
   * ApiKeyManagement::deleteKey() removes the Key entity but not its file,
   * so the file is blanked first.
   *
   * @param string $id
   * @return bool
   */
  protected function deleteStoredKey(string $id): bool
  {
    $this->apiKeyManagementObj->saveApiKey($id, '');
    return $this->apiKeyManagementObj->deleteKey($id);
  }

  /**
   * verifyMapboxToken
   *
   * Asks Mapbox's token service whether the stored token is valid.
   *
   * @return string - '' if it's valid, otherwise the problem.
   */
  public function verifyMapboxToken(): string
  {
    $token = $this->apiKeyManagementObj->getKey(self::MAPBOX_KEY_ID);
    if ($token === '') {
      return 'No Mapbox token is stored.';
    }
    try {
      $response = $this->httpClient->request('GET', self::MAPBOX_TOKEN_URL,
        ['query' => ['access_token' => $token], 'timeout' => 30, 'connect_timeout' => 15]);
      $decoded = json_decode((string) $response->getBody(), TRUE);
    } catch (RequestException $e) {
      // The exception message contains the URL, and with it the token.
      $errorResponse = $e->getResponse();
      $decoded = $errorResponse ? json_decode((string) $errorResponse->getBody(), TRUE) : NULL;
      if (!is_array($decoded)) {
        return 'Mapbox refused the request (status ' . ($errorResponse?->getStatusCode() ?? 'no response') . ').';
      }
    } catch (GuzzleException) {
      return 'Mapbox did not respond.';
    }
    $code = is_array($decoded) ? (string) ($decoded['code'] ?? '') : '';
    return $code === 'TokenValid' ? '' : 'Mapbox says the token is not valid (' . ($code ?: 'no reason given') . ').';
  }

  /**
   * verifyGeocodioKey
   *
   * Geocodes one address with the stored key.  This uses one lookup.
   *
   * @return string - '' if the key works, otherwise the problem.
   */
  public function verifyGeocodioKey(): string
  {
    $apiKey = $this->apiKeyManagementObj->getKey(self::GEOCODIO_KEY_ID);
    if ($apiKey === '') {
      return 'No Geocodio API key is stored.';
    }
    try {
      $this->httpClient->request('GET', self::GEOCODIO_URL, [
        'query' => ['q' => self::GEOCODIO_TEST_ADDRESS, 'api_key' => $apiKey],
        'timeout' => 30,
        'connect_timeout' => 15,
      ]);
    } catch (RequestException $e) {
      // The exception message contains the URL, and with it the key.
      $errorResponse = $e->getResponse();
      $decoded = $errorResponse ? json_decode((string) $errorResponse->getBody(), TRUE) : NULL;
      $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
      return 'Geocodio refused the key (status ' . ($errorResponse?->getStatusCode() ?? 'no response') . ')'
        . ($message !== '' ? ": $message" : '.');
    } catch (GuzzleException) {
      return 'Geocodio did not respond.';
    }
    return '';
  }

  /**
   * firstTurfVanid
   *
   * @param int $turfIndex
   * @return int - the first voter in the turf's walk order, or 0.
   */
  public function firstTurfVanid(int $turfIndex): int
  {
    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    return empty($voters) ? 0 : (int) array_key_first($voters);
  }

  /**
   * fetchVanAddresses
   *
   * Asks VAN for one voter's addresses, exactly as the API returns them, to
   * see whether they include coordinates (expected as 'geoLocation' with
   * 'lat' and 'lon').  Same committee key and request as
   * ApiVoter::getApiVoter(), with only the addresses expanded.
   *
   * @param int $vanid
   * @return array - 'addresses' => the raw address list, or 'error'.
   */
  public function fetchVanAddresses(int $vanid): array
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues('');
    if (empty($committeeKey['API Key']) || empty($committeeKey['Url'])) {
      return ['error' => 'The VAN committee key is not configured.'];
    }
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'] ?? '';
    $url = "https://$user:$apiKey|0@$apiURL/people/$vanid" . '?$expand=addresses';
    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 30, 'connect_timeout' => 15]);
    } catch (RequestException $e) {
      // The exception message contains the URL, and with it the API key, so
      // report only the status code.
      $status = $e->getResponse()?->getStatusCode() ?? 'no response';
      return ['error' => "The VAN request failed (status $status)."];
    } catch (GuzzleException) {
      return ['error' => 'The VAN request failed (no response).'];
    }
    $result = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($result)) {
      return ['error' => 'VAN returned something that is not JSON.'];
    }
    return ['vanid' => $vanid, 'addresses' => $result['addresses'] ?? []];
  }

  /**
   * buildTurfMap
   *
   * Runs the whole experiment for one turf: geocode, map image, PDF.
   *
   * @param int $turfIndex
   * @param string $style - a Mapbox style, "username/styleId".
   * @param float|null $minZoom - zoom in at least this far, even if some doors
   *   end up off the edge; NULL fits the map to the doors.
   * @param string $geocoder - a key of GEOCODERS.
   * @return array - turf, counts, matched/unmatched doors, timings, the map
   *   and PDF URLs, and 'error' if a step failed.
   */
  public function buildTurfMap(int $turfIndex, string $style = self::DEFAULT_STYLE, ?float $minZoom = NULL,
                               string $geocoder = 'census'): array
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    if (empty($turf)) {
      return ['error' => "Turf $turfIndex was not found."];
    }
    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    $doors = $this->turfDoors($voters);
    $report = [
      'turf' => $turf,
      'voterCount' => count($voters),
      'doors' => $doors,
      'matched' => [],
      'unmatched' => [],
      'timings' => [],
    ];
    if (empty($doors)) {
      $report['error'] = 'The turf has no voter addresses.';
      return $report;
    }

    $mapUri = self::MAP_DIR . "/turf-$turfIndex.png";
    $report = $this->mapDoors($doors, $mapUri, $geocoder, $style, $minZoom) + $report;
    if (!empty($report['error'])) {
      return $report;
    }
    $report['mapUrl'] = $this->fileUrlGenerator->generateAbsoluteString($mapUri)
      . '?t=' . time();

    $start = microtime(TRUE);
    $pdfUri = self::MAP_DIR . "/turf-$turfIndex-" . date('Y-m-d-H-i-s') . '.pdf';
    $report['pdfUrl'] = $this->pdfGeneratorObj->generatePdf($this->pdfHtml($report, $mapUri), $pdfUri);
    $report['timings']['pdf'] = microtime(TRUE) - $start;
    if ($report['pdfUrl'] === '') {
      $report['error'] = 'The PDF could not be created.';
    }
    return $report;
  }

  /**
   * mapDoors
   *
   * Geocodes the doors, leaves far-away ones off, and saves the map image.
   *
   * @param array $doors - from turfDoors().
   * @param string $mapUri - where to save the PNG.
   * @param string $geocoder - a key of GEOCODERS.  With Geocodio, doubtful
   *   results are double-checked with Census.
   * @param string $style - a Mapbox style, "username/styleId".
   * @param float|null $minZoom - see buildTurfMap().
   * @param bool $colorByPlacement - TRUE colors each pin by where the
   *   geocoder placed it (see MARKERS); FALSE makes them all red.
   * @return array - geocoder, timings, matched, unmatched, placedCounts,
   *   checks, outliers, style, zoom; 'error' if a step failed.
   */
  public function mapDoors(array $doors, string $mapUri, string $geocoder = 'census',
                           string $style = self::DEFAULT_STYLE, ?float $minZoom = NULL,
                           bool $colorByPlacement = TRUE): array
  {
    $result = ['geocoder' => $geocoder, 'matched' => [], 'unmatched' => [], 'outliers' => [], 'timings' => []];
    $start = microtime(TRUE);
    $geocoded = ($geocoder === 'geocodio') ? $this->geocodeDoorsGeocodio($doors) : $this->geocodeDoors($doors);
    if ($geocoder === 'geocodio' && !empty($geocoded['error'])) {
      // E.g., the daily lookup limit was reached: use Census alone.
      Drupal::logger('nlpservices')->warning('Geocodio failed, using Census instead: @error', ['@error' => $geocoded['error']]);
      $result['geocodioError'] = $geocoded['error'];
      $geocoder = $result['geocoder'] = 'census';
      $geocoded = $this->geocodeDoors($doors);
    }
    $result['timings']['geocoder'] = microtime(TRUE) - $start;
    if (!empty($geocoded['error'])) {
      $result['error'] = $geocoded['error'];
      return $result;
    }
    if ($geocoder === 'geocodio') {
      $start = microtime(TRUE);
      $checked = $this->checkWithCensus($doors, $geocoded);
      $result['timings']['censusCheck'] = microtime(TRUE) - $start;
      if (!empty($checked['error'])) {
        // Carry on with Geocodio's results alone.
        $result['censusCheckError'] = $checked['error'];
      }
      else {
        $geocoded = $checked;
        $result['checks'] = $checked['checks'];
      }
    }
    $result['matched'] = $geocoded['matched'];
    $result['unmatched'] = $geocoded['unmatched'];
    $result['placedCounts'] = array_count_values(array_column($result['matched'], 'placed'));
    if (empty($result['matched'])) {
      $result['error'] = 'The geocoder matched none of the addresses.';
      return $result;
    }

    // Leave far-away doors off the map, so it can zoom in on the rest.
    foreach ($this->findOutliers($result['matched']) as $index => $meters) {
      $result['outliers'][$index] = $result['matched'][$index] + ['meters' => $meters];
    }
    $mapPoints = array_diff_key($result['matched'], $result['outliers']);
    if (!$colorByPlacement) {
      $mapPoints = array_map(fn($point) => ['placed' => self::PLACED_BUILDING] + $point, $mapPoints);
    }

    $start = microtime(TRUE);
    $view = $this->mapView($mapPoints, $minZoom);
    $result['style'] = $style;
    $result['zoom'] = $view['zoom'];
    $mapError = $this->fetchMapImage($mapPoints, $mapUri, $style, $view);
    $result['timings']['mapbox'] = microtime(TRUE) - $start;
    if ($mapError !== '') {
      $result['error'] = $mapError;
    }
    return $result;
  }

  /**
   * walksheetMap
   *
   * The map for a turf's walk sheet: all pins red, the default style, and
   * Geocodio (with the Census double-check) when a Geocodio key is stored,
   * otherwise Census alone.
   *
   * The map is cached under WALKSHEET_MAP_DIR, named for the turf and a hash
   * of its doors, so printing the walk sheet again doesn't repeat the
   * geocoding or the Mapbox request; when the turf's addresses change, the
   * name changes and the old map is deleted.
   *
   * Never throws: any problem (no Mapbox token, a service down) is logged
   * and the walk sheet goes without a map.
   *
   * @param int $turfIndex
   * @return array - 'path' => the PNG's file path, 'notOnMap' => list of
   *   ['address', 'why'] for doors left off; or [] if there's no map.
   */
  public function walksheetMap(int $turfIndex): array
  {
    try {
      if (!$this->hasMapboxToken()) {
        return [];
      }
      $doors = $this->turfDoors($this->votersObj->fetchVotersByTurf($turfIndex));
      if (empty($doors)) {
        return [];
      }
      $geocoder = $this->hasGeocodioKey() ? 'geocodio' : 'census';
      $hash = md5(json_encode([self::WALKSHEET_MAP_VERSION, $geocoder, self::DEFAULT_STYLE, $doors]));
      $baseUri = self::WALKSHEET_MAP_DIR . "/turf-$turfIndex-$hash";

      // The JSON is written last, so it marks a complete cached map.
      $cached = $this->readCachedMap($baseUri);
      if (!empty($cached)) {
        return $cached;
      }
      $this->deleteWalksheetMaps($turfIndex);

      $result = $this->mapDoors($doors, "$baseUri.png", $geocoder, self::DEFAULT_STYLE, NULL, FALSE);
      if (!empty($result['error'])) {
        Drupal::logger('nlpservices')->warning('Walk sheet map for turf @turf: @error',
          ['@turf' => $turfIndex, '@error' => $result['error']]);
        return [];
      }
      $notOnMap = [];
      foreach ($result['outliers'] as $door) {
        $notOnMap[] = ['address' => "{$door['street']}, {$door['city']}", 'why' => self::distanceText($door['meters'])];
      }
      foreach ($result['unmatched'] as $door) {
        $notOnMap[] = ['address' => "{$door['street']}, {$door['city']}", 'why' => 'address not found'];
      }
      // A map made with Census because Geocodio failed isn't kept, so the
      // next print tries Geocodio again.
      if (empty($result['geocodioError'])) {
        $this->fileSystem->saveData(json_encode(['notOnMap' => $notOnMap]), "$baseUri.json", FileExists::Replace);
      }
      return ['path' => $this->fileSystem->realpath("$baseUri.png"), 'notOnMap' => $notOnMap];
    } catch (Exception $e) {
      Drupal::logger('nlpservices')->warning('Walk sheet map for turf @turf failed: @message',
        ['@turf' => $turfIndex, '@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * readCachedMap
   *
   * @param string $baseUri - the cached map's URI without the extension.
   * @return array - as walksheetMap() returns, or [] if it isn't cached.
   */
  protected function readCachedMap(string $baseUri): array
  {
    $pngPath = $this->fileSystem->realpath("$baseUri.png");
    $jsonPath = $this->fileSystem->realpath("$baseUri.json");
    if (!$pngPath || !$jsonPath || !is_file($pngPath) || !is_file($jsonPath)) {
      return [];
    }
    $cached = json_decode((string) file_get_contents($jsonPath), TRUE);
    if (!is_array($cached)) {
      return [];
    }
    return ['path' => $pngPath, 'notOnMap' => $cached['notOnMap'] ?? []];
  }

  /**
   * deleteWalksheetMaps
   *
   * Deletes a turf's cached walk sheet maps.
   *
   * @param int $turfIndex
   */
  public function deleteWalksheetMaps(int $turfIndex): void
  {
    $directory = $this->fileSystem->realpath(self::WALKSHEET_MAP_DIR);
    if (!$directory || !is_dir($directory)) {
      return;
    }
    // "turf-12-*" doesn't match turf 123's "turf-123-…".
    foreach (glob("$directory/turf-$turfIndex-*") ?: [] as $file) {
      @unlink($file);
    }
  }

  /**
   * turfDoors
   *
   * Reduces a turf's voters to one entry per door.  Apartment numbers are
   * dropped, since a building is one point on the map, and voters who share
   * an address share a door.
   *
   * @param array $voters - from NlpVoters::fetchVotersByTurf().
   * @return array - list of ['street', 'city', 'zip', 'voters'].
   */
  public function turfDoors(array $voters): array
  {
    $doors = [];
    foreach ($voters as $voter) {
      $address = $voter['address'];
      $parts = [];
      foreach (['streetNo', 'streetPrefix', 'streetName', 'streetType', 'streetSuffix'] as $field) {
        $value = trim((string) ($address[$field] ?? ''));
        if ($value !== '') {
          $parts[] = $value;
        }
      }
      $street = implode(' ', $parts);
      if ($street === '') {
        continue;
      }
      $city = trim((string) ($address['city'] ?? ''));
      $zip = substr(trim((string) ($address['zip'] ?? '')), 0, 5);
      $key = strtolower("$street|$city|$zip");
      if (!isset($doors[$key])) {
        $doors[$key] = ['street' => $street, 'city' => $city, 'zip' => $zip, 'voters' => 0];
      }
      $doors[$key]['voters']++;
    }
    return array_values($doors);
  }

  /**
   * geocodeDoors
   *
   * Sends all the doors to the Census batch geocoder in one request.
   *
   * @param array $doors - from turfDoors().
   * @return array - 'matched' => [index => door + lon, lat, matchType],
   *   'unmatched' => [index => door + status], or 'error'.
   */
  public function geocodeDoors(array $doors): array
  {
    $state = $this->stateAbbreviation();
    $csv = fopen('php://temp', 'r+');
    foreach ($doors as $index => $door) {
      fputcsv($csv, [$index, $door['street'], $door['city'], $state, $door['zip']], escape: '');
    }
    rewind($csv);
    $addressFile = stream_get_contents($csv);
    fclose($csv);

    try {
      $response = $this->httpClient->request('POST', self::CENSUS_BATCH_URL, [
        'multipart' => [
          ['name' => 'benchmark', 'contents' => self::CENSUS_BENCHMARK],
          ['name' => 'addressFile', 'contents' => $addressFile, 'filename' => 'addresses.csv'],
        ],
        'timeout' => 120,
        'connect_timeout' => 30,
      ]);
    } catch (GuzzleException $e) {
      Drupal::logger('nlpservices')->error('NlpTurfMap: Census geocoder request failed: @msg', ['@msg' => $e->getMessage()]);
      return ['error' => 'The Census geocoder request failed: ' . $e->getMessage()];
    }
    return $this->parseCensusResponse((string) $response->getBody(), $doors);
  }

  /**
   * parseCensusResponse
   *
   * Census returns one CSV row per address, in no particular order:
   *   id, input address, Match|No_Match|Tie, Exact|Non_Exact, matched address,
   *   "lon,lat", TIGER line id, side
   * Rows that didn't match stop after the third column.  The coordinates are
   * longitude first.
   *
   * @param string $body
   * @param array $doors
   * @return array - see geocodeDoors().
   */
  public function parseCensusResponse(string $body, array $doors): array
  {
    $matched = [];
    $unmatched = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($body)) as $line) {
      if (trim($line) === '') {
        continue;
      }
      $row = str_getcsv($line, escape: '');
      $index = (int) $row[0];
      if (!isset($doors[$index])) {
        continue;
      }
      $status = $row[2] ?? '';
      $coordinates = explode(',', $row[5] ?? '');
      if ($status === 'Match' && count($coordinates) === 2) {
        $matched[$index] = $doors[$index] + [
          'lon' => (float) $coordinates[0],
          'lat' => (float) $coordinates[1],
          'matchType' => $row[3] ?? '',
          'matchedAddress' => $row[4] ?? '',
          // Census always estimates along the street centerline.
          'placed' => self::PLACED_STREET,
        ];
      }
      else {
        $unmatched[$index] = $doors[$index] + ['status' => $status ?: 'No response'];
      }
    }
    // Any door Census left out of the response is unmatched too.
    foreach ($doors as $index => $door) {
      if (!isset($matched[$index]) && !isset($unmatched[$index])) {
        $unmatched[$index] = $door + ['status' => 'Missing from response'];
      }
    }
    ksort($matched);
    ksort($unmatched);
    return ['matched' => $matched, 'unmatched' => $unmatched];
  }

  /**
   * geocodeDoorsGeocodio
   *
   * Sends all the doors to Geocodio's batch geocoder in one request.  Unlike
   * Census, Geocodio places most US addresses on the building ("rooftop").
   *
   * @param array $doors - from turfDoors().
   * @return array - see geocodeDoors().
   */
  public function geocodeDoorsGeocodio(array $doors): array
  {
    $apiKey = $this->apiKeyManagementObj->getKey(self::GEOCODIO_KEY_ID);
    if ($apiKey === '') {
      return ['error' => 'No Geocodio API key is stored.'];
    }
    $state = $this->stateAbbreviation();
    // Keyed so the results come back keyed the same way (a plain list would
    // also work, but keys make the matching explicit).
    $addresses = [];
    foreach ($doors as $index => $door) {
      $addresses["d$index"] = trim("{$door['street']}, {$door['city']}, $state {$door['zip']}");
    }
    try {
      $response = $this->httpClient->request('POST', self::GEOCODIO_URL, [
        'query' => ['api_key' => $apiKey],
        'json' => $addresses,
        'timeout' => 120,
        'connect_timeout' => 30,
      ]);
    } catch (RequestException $e) {
      // The exception message contains the URL, and with it the key, so
      // report only the status code and Geocodio's own error message.
      $errorResponse = $e->getResponse();
      $status = $errorResponse?->getStatusCode() ?? 'no response';
      $decoded = $errorResponse ? json_decode((string) $errorResponse->getBody(), TRUE) : NULL;
      $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
      Drupal::logger('nlpservices')->error('NlpTurfMap: Geocodio request failed, status @status: @message',
        ['@status' => $status, '@message' => $message]);
      return ['error' => "The Geocodio request failed (status $status)" . ($message !== '' ? ": $message" : '.')];
    } catch (GuzzleException) {
      return ['error' => 'The Geocodio request failed (no response).'];
    }
    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded)) {
      return ['error' => 'Geocodio returned something that is not JSON.'];
    }
    return $this->parseGeocodioResponse($decoded, $doors);
  }

  /**
   * parseGeocodioResponse
   *
   * The batch response is {"results": {"d0": {"query": …, "response":
   * {"results": [best match first, each with location {lat, lng},
   * accuracy (0-1), accuracy_type, formatted_address]}}}}.
   *
   * @param array $decoded
   * @param array $doors
   * @return array - see geocodeDoors().
   */
  public function parseGeocodioResponse(array $decoded, array $doors): array
  {
    $matched = [];
    $unmatched = [];
    $results = $decoded['results'] ?? [];
    foreach ($doors as $index => $door) {
      $best = $results["d$index"]['response']['results'][0] ?? NULL;
      $type = $best['accuracy_type'] ?? '';
      $lat = $best['location']['lat'] ?? NULL;
      $lon = $best['location']['lng'] ?? NULL;
      if (!is_numeric($lat) || !is_numeric($lon)) {
        $unmatched[$index] = $door + ['status' => 'No match'];
        continue;
      }
      if (in_array($type, self::GEOCODIO_TOO_VAGUE_TYPES)) {
        $unmatched[$index] = $door + ['status' => "Too vague ($type)"];
        continue;
      }
      $matched[$index] = $door + [
        'lon' => (float) $lon,
        'lat' => (float) $lat,
        'matchType' => $type . ' (' . ($best['accuracy'] ?? '?') . ')',
        'matchedAddress' => $best['formatted_address'] ?? '',
        'accuracyType' => $type,
        'accuracy' => is_numeric($best['accuracy'] ?? NULL) ? (float) $best['accuracy'] : NULL,
        'matchedZip' => substr((string) ($best['address_components']['zip'] ?? ''), 0, 5),
        'source' => 'Geocodio',
        'placed' => match (TRUE) {
          in_array($type, self::GEOCODIO_BUILDING_TYPES) => self::PLACED_BUILDING,
          $type === self::GEOCODIO_NEAREST_TYPE => self::PLACED_NEAREST,
          default => self::PLACED_STREET,
        },
      ];
    }
    return ['matched' => $matched, 'unmatched' => $unmatched];
  }

  /**
   * findOutliers
   *
   * Finds doors far from the rest of the turf, usually a geocoding mistake
   * or a voter whose address is outside the turf's area.  The middle of the
   * turf is the median latitude and longitude, which one far-away door can't
   * pull toward itself the way an average would.  A door is far away when
   * its distance from the middle is both OUTLIER_FACTOR times the median
   * distance and at least OUTLIER_MIN_METERS.
   *
   * @param array $points - each with 'lat' and 'lon', keyed by door index.
   * @return array - meters from the middle of the turf, keyed by door index,
   *   farthest first.
   */
  public function findOutliers(array $points): array
  {
    $spread = $this->turfSpread($points);
    if ($spread === NULL) {
      return [];
    }
    $outliers = [];
    foreach ($points as $index => $point) {
      $meters = self::meters($spread['lat'], $spread['lon'], $point['lat'], $point['lon']);
      if ($meters > $spread['limit']) {
        $outliers[$index] = (int) round($meters);
      }
    }
    arsort($outliers);
    return $outliers;
  }

  /**
   * turfSpread
   *
   * The middle of the turf (median latitude and longitude) and how far from
   * it a door can be before it counts as far away.
   *
   * @param array $points - each with 'lat' and 'lon'.
   * @return array|null - 'lat', 'lon', 'limit' (meters), or NULL when there
   *   are too few doors to tell.
   */
  public function turfSpread(array $points): ?array
  {
    if (count($points) < self::OUTLIER_MIN_DOORS) {
      return NULL;
    }
    $centerLat = self::median(array_column($points, 'lat'));
    $centerLon = self::median(array_column($points, 'lon'));
    $distances = [];
    foreach ($points as $point) {
      $distances[] = self::meters($centerLat, $centerLon, $point['lat'], $point['lon']);
    }
    return [
      'lat' => $centerLat,
      'lon' => $centerLon,
      'limit' => max(self::OUTLIER_FACTOR * self::median($distances), self::OUTLIER_MIN_METERS),
    ];
  }

  /**
   * geocodioDoubts
   *
   * Reasons not to trust a Geocodio match.
   *
   * @param array $match - a matched door from parseGeocodioResponse().
   * @return array - the reasons; empty if it looks right.
   */
  public function geocodioDoubts(array $match): array
  {
    $doubts = [];
    if ($match['zip'] !== '' && $match['matchedZip'] !== '' && $match['zip'] !== $match['matchedZip']) {
      $doubts[] = "different zip ({$match['matchedZip']})";
    }
    if ($match['accuracyType'] === self::GEOCODIO_STREET_ONLY_TYPE) {
      $doubts[] = 'street only, no house number';
    }
    if ($match['accuracy'] !== NULL && $match['accuracy'] < self::GEOCODIO_MIN_ACCURACY) {
      $doubts[] = "low accuracy ({$match['accuracy']})";
    }
    return $doubts;
  }

  /**
   * checkWithCensus
   *
   * Gets a second opinion from Census for the Geocodio results that can't be
   * trusted: no match, a doubtful match (see geocodioDoubts()), or far from
   * the rest of the turf.  The trusted doors define where the turf is.  For
   * each door checked, in order of preference:
   *   - Census's point, if it's near the turf;
   *   - otherwise Geocodio's point, if it's near the turf (a weak match in
   *     the right place is still useful);
   *   - otherwise Geocodio's far-away point, which the map then leaves off
   *     and lists as far away;
   *   - otherwise the door is unmatched.
   *
   * @param array $doors - from turfDoors().
   * @param array $geocoded - from geocodeDoorsGeocodio().
   * @return array - 'matched', 'unmatched', and 'checks' => [index => door +
   *   'reasons', 'outcome'], or 'error' if the Census request failed.
   */
  public function checkWithCensus(array $doors, array $geocoded): array
  {
    $matched = $geocoded['matched'];
    $unmatched = $geocoded['unmatched'];

    $reasons = [];
    foreach ($matched as $index => $match) {
      $doubts = $this->geocodioDoubts($match);
      if (!empty($doubts)) {
        $reasons[$index] = $doubts;
      }
    }
    foreach ($unmatched as $index => $door) {
      $reasons[$index] = ['Geocodio: ' . strtolower($door['status'])];
    }
    $trusted = array_diff_key($matched, $reasons);
    foreach ($this->findOutliers($trusted) as $index => $meters) {
      $reasons[$index] = ['far from the rest of the turf (' . self::distanceText($meters) . ')'];
    }
    if (empty($reasons)) {
      return ['matched' => $matched, 'unmatched' => $unmatched, 'checks' => []];
    }
    $trusted = array_diff_key($trusted, $reasons);
    $spread = $this->turfSpread($trusted);
    $isNear = function (array $point) use ($spread): bool {
      return $spread === NULL
        || self::meters($spread['lat'], $spread['lon'], $point['lat'], $point['lon']) <= $spread['limit'];
    };

    $census = $this->geocodeDoors(array_intersect_key($doors, $reasons));
    if (!empty($census['error'])) {
      return ['error' => $census['error']];
    }

    $checks = [];
    foreach ($reasons as $index => $why) {
      $censusMatch = $census['matched'][$index] ?? NULL;
      $geocodioMatch = $matched[$index] ?? NULL;
      unset($matched[$index], $unmatched[$index]);
      if ($censusMatch !== NULL && $isNear($censusMatch)) {
        $matched[$index] = $censusMatch + ['source' => 'Census'];
        $outcome = 'Used Census: ' . $censusMatch['matchedAddress'];
      }
      elseif ($geocodioMatch !== NULL && $isNear($geocodioMatch)) {
        $matched[$index] = $geocodioMatch;
        $outcome = ($censusMatch === NULL ? 'Census found no match' : 'Census was also far away') . '; kept Geocodio';
      }
      elseif ($geocodioMatch !== NULL) {
        $matched[$index] = $geocodioMatch;
        $outcome = ($censusMatch === NULL ? 'Census found no match' : 'Census was also far away') . '; left off the map';
      }
      else {
        $unmatched[$index] = $doors[$index] + ['status' => 'Neither Geocodio nor Census found it'];
        $outcome = 'Not matched';
      }
      $checks[$index] = $doors[$index] + ['reasons' => implode('; ', $why), 'outcome' => $outcome];
    }
    ksort($matched);
    ksort($unmatched);
    ksort($checks);
    return ['matched' => $matched, 'unmatched' => $unmatched, 'checks' => $checks];
  }

  /**
   * The median of a list of numbers.
   */
  protected static function median(array $values): float
  {
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);
    return ($count % 2) ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
  }

  /**
   * Distance in meters between two points.  A flat-earth approximation,
   * accurate to well under 1% over the few miles a turf covers.
   */
  protected static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
  {
    $dx = ($lon2 - $lon1) * cos(deg2rad(($lat1 + $lat2) / 2)) * 111320;
    $dy = ($lat2 - $lat1) * 110540;
    return sqrt($dx * $dx + $dy * $dy);
  }

  /**
   * isStyleFormat
   *
   * @param string $style
   * @return bool - TRUE for "username/styleId", e.g. "mapbox/streets-v12".
   */
  public static function isStyleFormat(string $style): bool
  {
    return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $style) === 1;
  }

  /**
   * mapView
   *
   * The center and zoom that fit all the points inside the padded map, the
   * same view Mapbox's "auto" would pick.  It's computed here so a minimum
   * zoom can be applied: house outlines only appear when zoomed in closely.
   *
   * @param array $points - each with 'lon' and 'lat'.
   * @param float|null $minZoom
   * @return array - 'lon', 'lat', 'zoom'.
   */
  public function mapView(array $points, ?float $minZoom = NULL): array
  {
    $xs = [];
    $ys = [];
    foreach ($points as $point) {
      $xs[] = self::mercatorX($point['lon']);
      $ys[] = self::mercatorY($point['lat']);
    }
    $dx = max($xs) - min($xs);
    $dy = max($ys) - min($ys);
    $zoom = self::MAX_ZOOM;
    if ($dx > 0) {
      $zoom = min($zoom, log((self::MAP_WIDTH - 2 * self::MAP_PADDING) / (self::TILE_SIZE * $dx), 2));
    }
    if ($dy > 0) {
      $zoom = min($zoom, log((self::MAP_HEIGHT - 2 * self::MAP_PADDING) / (self::TILE_SIZE * $dy), 2));
    }
    if ($minZoom !== NULL) {
      $zoom = max($zoom, $minZoom);
    }
    $centerX = (max($xs) + min($xs)) / 2;
    $centerY = (max($ys) + min($ys)) / 2;
    return [
      'lon' => $centerX * 360 - 180,
      'lat' => rad2deg(atan(sinh(M_PI * (1 - 2 * $centerY)))),
      'zoom' => round($zoom, 2),
    ];
  }

  /**
   * Web Mercator x, 0 to 1 west to east.
   */
  protected static function mercatorX(float $lon): float
  {
    return ($lon + 180) / 360;
  }

  /**
   * Web Mercator y, 0 to 1 north to south.
   */
  protected static function mercatorY(float $lat): float
  {
    $radians = deg2rad($lat);
    return (1 - log(tan($radians) + 1 / cos($radians)) / M_PI) / 2;
  }

  /**
   * mapboxUrl
   *
   * Builds the static-image URL: one unnumbered pin per point, colored by
   * where the geocoder placed it (see MARKERS), centered and
   * zoomed per $view.  The token is left out so the URL can be shown or
   * logged; fetchMapImage() adds it.
   *
   * @param array $points - each with 'lon' and 'lat'.
   * @param string $style - "username/styleId".
   * @param array $view - from mapView().
   * @return string
   */
  public function mapboxUrl(array $points, string $style, array $view): string
  {
    $markers = [];
    foreach ($points as $point) {
      // Five decimals is about a meter, plenty for a door.
      $marker = self::MARKERS[$point['placed'] ?? self::PLACED_STREET];
      $markers[] = $marker . '(' . round($point['lon'], 5) . ',' . round($point['lat'], 5) . ')';
    }
    $center = round($view['lon'], 6) . ',' . round($view['lat'], 6) . ',' . $view['zoom'];
    return self::MAPBOX_STYLES_URL . "/$style/static/" . implode(',', $markers)
      . "/$center/" . self::MAP_WIDTH . 'x' . self::MAP_HEIGHT . '@2x?';
  }

  /**
   * fetchMapImage
   *
   * Fetches the map PNG from Mapbox and saves it at $uri.
   *
   * @param array $points - each with 'lon' and 'lat'.
   * @param string $uri - where to save the PNG.
   * @param string $style - "username/styleId".
   * @param array $view - from mapView().
   * @return string - '' on success, otherwise the error.
   */
  public function fetchMapImage(array $points, string $uri, string $style, array $view): string
  {
    $token = $this->apiKeyManagementObj->getKey(self::MAPBOX_KEY_ID);
    if ($token === '') {
      return 'No Mapbox token is stored.';
    }
    $url = $this->mapboxUrl($points, $style, $view) . 'access_token=' . rawurlencode($token);
    if (strlen($url) > self::MAPBOX_URL_MAX) {
      return 'Too many markers for one Mapbox request (' . count($points) . ').';
    }
    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 30, 'connect_timeout' => 15]);
    } catch (RequestException $e) {
      // The exception message contains the URL, and with it the token, so
      // report only the status code and Mapbox's own message (e.g.
      // {"message":"Not Authorized - Invalid Token"}), which doesn't echo it.
      $errorResponse = $e->getResponse();
      $status = $errorResponse?->getStatusCode() ?? 'no response';
      $message = '';
      if ($errorResponse) {
        $decoded = json_decode((string) $errorResponse->getBody(), TRUE);
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
      }
      Drupal::logger('nlpservices')->error('NlpTurfMap: Mapbox request failed, status @status: @message',
        ['@status' => $status, '@message' => $message]);
      return "The Mapbox request failed (status $status)" . ($message !== '' ? ": $message" : '.');
    } catch (GuzzleException) {
      Drupal::logger('nlpservices')->error('NlpTurfMap: Mapbox request failed, no response');
      return 'The Mapbox request failed (no response).';
    }

    try {
      $directory = $this->fileSystem->dirname($uri);
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->saveData((string) $response->getBody(), $uri, FileExists::Replace);
    } catch (Exception $e) {
      nlp_debug_msg('NlpTurfMap fetchMapImage', $e->getMessage());
      return 'The map image could not be saved.';
    }
    return '';
  }

  /**
   * pdfHtml
   *
   * @param array $report - from buildTurfMap().
   * @param string $mapUri
   * @return string
   */
  protected function pdfHtml(array $report, string $mapUri): string
  {
    $turf = $report['turf'];
    $title = htmlspecialchars($turf['turfName'] ?? ('Turf ' . $turf['turfIndex']));
    $nl = htmlspecialchars(trim(($turf['nlFirstName'] ?? '') . ' ' . ($turf['nlLastName'] ?? '')));
    $doorCount = count($report['doors']);
    $onMapCount = count($report['matched']) - count($report['outliers'] ?? []);
    $mapPath = $this->fileSystem->realpath($mapUri);
    $html = "<h3 style='margin:0'>$title</h3>"
      . "<p style='margin:2px 0 6px 0'>NL: $nl &nbsp;&middot;&nbsp; {$report['voterCount']} voters"
      . " &nbsp;&middot;&nbsp; $doorCount doors ($onMapCount on the map)</p>"
      . "<img src='$mapPath' style='width:100%'>";
    if (!empty($report['outliers'])) {
      $items = [];
      foreach ($report['outliers'] as $door) {
        $items[] = htmlspecialchars("{$door['street']}, {$door['city']}") . ' (' . self::distanceText($door['meters']) . ')';
      }
      $html .= "<p style='margin:4px 0 0 0'><b>Not on the map, far from the rest of the turf:</b> "
        . implode('; ', $items) . '</p>';
    }
    return $html;
  }

  /**
   * distanceText
   *
   * @param int $meters
   * @return string - feet under a quarter mile, otherwise miles.
   */
  public static function distanceText(int $meters): string
  {
    $feet = $meters * 3.28084;
    return ($feet < 1320) ? round($feet, -1) . ' ft away' : number_format($feet / 5280, 1) . ' mi away';
  }

  /**
   * stateAbbreviation
   *
   * @return string - the USPS abbreviation for the configured state, or the
   *   configured name if it isn't a known state.
   */
  protected function stateAbbreviation(): string
  {
    $name = trim($this->nlpConfigurationObj->getStateName());
    return self::STATE_ABBREVIATIONS[$name] ?? $name;
  }

}
