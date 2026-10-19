<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class ApiMapRegions
{
  use ApiRetryTrait;

  /**
   * The data entry and turf pages wait on this request while they load, so a
   * stalled VAN request is retried only once instead of MAX_RETRIES times.
   */
  private const int PAGE_LOAD_RETRIES = 1;

  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;
  private array $mapRegions = [];
  public function __construct($client, $nlpConfigurationObj, $apiKeyManagementObj ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiMapRegions
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }

  /**
   * mapRegionOptions
   *
   * Get the map region options for a county and folder.
   * This function retrieves the map regions from the API and returns an array of
   * map region options where the key is the map region ID and the value is the
   * map region name.  The first element in the array is 'all' => 'All'.
   *
   * @param $committee
   * @param $folderId
   * @return array
   */
  public function mapRegionOptions($committee,$folderId): array
  {
    if(empty($committee)) {return [];}
    $mapRegionInfo = $this->getMapRegionInfo($committee,$folderId);
    $mapOptions = array_map(function ($mapRegion) {
      return $mapRegion['mapRegionName'];
    }, $mapRegionInfo);
    asort($mapOptions);
    $mapOptions = ['all'=>'All'] + $mapOptions;
    
    if(count($mapOptions) <= 1) {$mapOptions = [];}
    return $mapOptions;
  }
  
  /**
   * mapRegionTurfOptions
   *
   * Get the turf options for a specific map region.
   * This function retrieves the turf information for the specified map region ID
   * and returns an array of turf options where the key is the turf ID and the value
   * is an array containing turf details.
   *
   * The turf details include:
   * - turfId
   * - turfName
   * - mapRegionId
   * - mapRegionName
   * - turfDescription (currently empty) - VoteBuilder fails to preserve the turf description
   * - voterCount (routeSize)
   * - doorCount
   * - printedList (if available, otherwise empty) - This is an array of minivan number and date created.
   *     minivanNumber - The minivan number for the turf.
   *     minivanCreateDate - The date the minivan was created.
   *
   * @param $mapRegionId
   * @return array
   */
  public function mapRegionTurfOptions($mapRegionId): array
  {
    if(empty($mapRegionId)) {return [];}
    if($mapRegionId == 'all') {
      $mapRegionIds = array_keys($this->mapRegions);
    } else {
      $mapRegionIds = [0=>$mapRegionId,];
    }
    $turfOptions = [];
    foreach ($mapRegionIds as $mapRegionId) {
      // The region may have been deleted in VoteBuilder since the turf was created.
      if(empty($mapRegionId) || empty($this->mapRegions[$mapRegionId])) {continue;}
      $mapRegion = $this->mapRegions[$mapRegionId];
      foreach ($mapRegion['turfInfo'] as $turfId => $turfInfo) {
        if(!empty($turfInfo['printedList'])) {
          unset($turfInfo['printedList']['createdBy']);
        }
        $turfOptions[$turfId] = [
          'turfId' => $turfId,
          'turfName' => $turfInfo['turfName'],
          'mapRegionId' => $mapRegion['mapRegionId'],
          'mapRegionName' => $mapRegion['mapRegionName'],
          'turfDescription' => '',
          'voterCount' => $turfInfo['routeSize'],
          'doorCount' => $turfInfo['doorCount'],
          'printedList' => $turfInfo['printedList'],
        ];
      }
    }
    return $turfOptions;
  }
  
  /**
   * getMapRegionInfo
   *
   * Get the map region information for a county and folder.
   * If a mapRegionId is provided, it retrieves information for that specific map region.
   * If no mapRegionId is provided, it retrieves all map regions for the specified folder.
   *
   * @param string $committee
   * @param int $folderId
   * @param int $mapRegionId
   * @return array
   */
  public function getMapRegionInfo( string $committee, int $folderId, int $mapRegionId=0): array
  {
    // Clear any previous folder's regions so a failed request can't leave stale data.
    if(empty($mapRegionId)) {$this->mapRegions = [];}
    if(empty($folderId)) {return [];}
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];

    $foldersURL = "https://$user:$apiKey|0@$apiURL/folders/$folderId/mapRegions";
    if(!empty($mapRegionId)) {
      $foldersURL .= '/'.$mapRegionId;
    }
    
    for ($attempt = 0; ; $attempt++) {
      try {
        $request = $this->client->request('GET', $foldersURL, self::REQUEST_OPTIONS);
        break;
      } catch (GuzzleException $e) {
        if ($e->getCode() == 404) {return [];}
        if ($this->isRetryable($e) && $attempt < self::PAGE_LOAD_RETRIES) {
          $this->logRetry($e, $folderId, $attempt);
          $this->sleepMs($this->retryDelayMs($e, $attempt));
          continue;
        }
        nlp_debug_msg('getMapRegionInfo', $e);
        return [];
      } catch (Exception $e) {
        nlp_debug_msg('getMapRegionInfo', $e);
        return [];
      }
    }

    $regions = json_decode($request->getBody(), true);
    if(!empty($mapRegionId)) {
      return $this->extractRegion($regions);
    }
    
    $this->mapRegions = [];
    foreach ($regions['items'] as $region) {
      $this->mapRegions[$region['mapRegionId']] = $this->extractRegion($region);
    }
    return $this->mapRegions;
  }
  
  /**
   * extractRegion
   *
   * Extracts the relevant information from a map region array.
   * This function is used to format the map region data into a more usable structure.
   *
   * The map region array contains the following keys:
   * - `name`: The name of the map region.
   * - `mapRegionId`: The unique identifier for the map region.
   * - `dateRefreshed`: The date the map region was last refreshed.
   * - `mapRoutes`: An array of turf information for the map region.
   *
   * The turf information contains:
   * - `name`: The name of the turf.
   * - `mapRouteId`: The unique identifier for the turf.
   * - `savedListId`: The saved list ID for the turf.
   * - `routeSize`: The size of the route (number of voters).
   * - `doorCount`: The number of doors in the turf.
   * - `printedList`: An array containing the minivan number and date created for the turf.
   *
   * The printedList array contains:
   * - `number`: The minivan number for the turf.
   * - `dateCreated`: The date the minivan was created.
   *
   * @param array $region
   * @return array
   */
  private function extractRegion( array $region): array
  {
    $mapRegion = [
      'mapRegionName' => $region['name'],
      'mapRegionId' => $region['mapRegionId'],
      'dateRefreshed' => $region['dateRefreshed'],
      'turfInfo' => [],
    ];
    foreach ($region['mapRoutes'] as $regionTurf) {
      $turfInfo = [
        'turfName' => $regionTurf['name'],
        'mapRouteId' => $regionTurf['mapRouteId'],
        'savedListId' => $regionTurf['savedListId'],
        'routeSize' => $regionTurf['routeSize'],
        'doorCount' => $regionTurf['doorCount'],
        'printedList' => $regionTurf['printedList'],
      ];
      $mapRegion['turfInfo'][$regionTurf['savedListId']] = $turfInfo;
    }
    return $mapRegion;
  }
  
  /**
   * getTurfIds
   *
   * Get all turf IDs from the map regions.
   * This function iterates through the map regions and collects all turf IDs
   * into a single array. It returns an array of turf IDs.
   *
   * @return array
   */
  public function getTurfIds(): array
  {
    $turfIds = [];
    if(!empty($this->mapRegions)) {
      foreach ($this->mapRegions as $mapRegion) {
        $turfIdsInRegion = array_keys($mapRegion['turfInfo']);
        $turfIds = array_merge($turfIds, $turfIdsInRegion);
      }
    }
    return $turfIds;
  }
  
  /**
   * updateMinivanInfo
   *
   * For each turf in the turfArray, update the minivanNumber and minivanCreateDate.
   * Use the API call to get the latest minivan number and date created.  These are
   * stored in the provided turfArray and returned to the caller.
   *
   * @param string $committee
   * @param array $turfArray
   * @return void
   */
  public function updateMinivanInfo( string $committee, array &$turfArray): void
  {
    $mapRegionOptionsSaved = [];
    foreach ($turfArray as $turfIndex => $turf) {
      
      $mapRegionId = $turf['mapRegionId'];
      if(empty($mapRegionId)) {continue;}
      if (array_key_exists($mapRegionId, $mapRegionOptionsSaved)) {
        $mapRegionOptions = $mapRegionOptionsSaved[$mapRegionId];
      } else {
        $folderId = $turf['folderId'];
        $this->getMapRegionInfo($committee,$folderId);
        $mapRegionOptions = $this->mapRegionTurfOptions($mapRegionId);
        $mapRegionOptionsSaved[$mapRegionId] = $mapRegionOptions;
      }
      $listId = $turf['listId'];
      if(!empty($mapRegionOptions[$listId]['printedList'])) {
        $turfArray[$turfIndex]['minivanNumber'] = $mapRegionOptions[$listId]['printedList']['number'];
        $turfArray[$turfIndex]['minivanCreateDate'] = $mapRegionOptions[$listId]['printedList']['dateCreated'];
      }
    }
  }
  
  
}