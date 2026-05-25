<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Exception;

class ApiFolders {
  
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;
  private array $mapRegions = [];
  public function __construct($client, $nlpConfigurationObj, $apiKeyManagementObj ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getApiFolders
   *
   * Get the folders from the API.
   *
   * Returns an array of folders that are visible to the county committee API key.  If the
   * county is not specified, the API key for the state committee is used.  The array ke is
   * the folderID and the value is the folder name.
   * If a folderId is provided, only that folder is returned.
   *
   * @param string $committee
   * @param int $database 0=VoterFile, 1=MyCampaign
   * @param int $folderId - if provided, get a specific folder by ID
   * @return array
   */
  public function getApiFolders(string $committee, int $database, int $folderId=0): array
  {

    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $foldersURL = 'https://'.$user.':'.$apiKey.'|'.$database.'@'.$apiURL.'/folders';
    if(!empty($folderId)) {
      $foldersURL .= '/'.$folderId;
    }
    
    try {
      $request = $this->client->get($foldersURL);
    } catch (RequestException $e) {
      $code = $e->getCode();
      if ($code == 401) {
        nlp_debug_msg("getFolders","Invalid API Key for $committee");
        return [];
      }
      if ($code == 404) {
        // Handle was not found error
        $msg = "Folder not found, County=$committee, FolderId=$folderId";
        if (!str_contains(Drupal::routeMatch()->getRouteName(), 'system.cron')) {
          nlp_debug_msg('getApiFolders', $msg);
        }
        Drupal::logger('nlpservices')->notice($msg);
        return [];
      }
      nlp_debug_msg('getApiFolders', $e->getMessage());
      // Request error
      return [];
    } catch (Exception $e) {
      // Other errors
      nlp_debug_msg('getApiFolders', $e->getMessage());
      return [];
    }
    
    $results = json_decode($request->getBody(), true);
    $folderArray = [];
    if(empty($folderId)) {
      if(empty($results['items'])) {
        return ['0'=>'folder is empty'];
      }
      foreach ($results['items'] as $folderInfo) {
        $folderArray[$folderInfo['folderId']] = $folderInfo['name'];
      }
    } else {
      $folderArray[$results['folderId']] = $results['name'];
    }
    return $folderArray;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getFolderOptions
   *
   * Get the folder options for a county.
   * This function retrieves all folders from the API and filters them
   * to include only those that contain the county name in the folder name.
   * It returns an array of folder options where the key is the folder ID
   * and the value is the folder name.
   * This function is used because the API returns all folders regardless of the
   * committee credentials.   It seems odd that the API does not filter for the
   * committee credentials.
   *
   * @param int $database 0=VoterFile, 1=MyCampaign
   * @param string $committee
   * @return array
   */
  public function getFolderOptions(int $database, string $committee): array
  {
    $messenger = Drupal::messenger();
    $allFolderInfo = $this->getApiFolders($committee,$database);
    if(empty($allFolderInfo)) {
      $messenger->addWarning(t('There are no shared folders'));
      return [];
    }
    // Check for the county name in the folder name.
    $folderOptions = [];
    foreach ($allFolderInfo as $aFolderId => $aFolderName) {
      $pos = stripos($aFolderName, $committee);
      if ($pos !== false) {
        $folderOptions[$aFolderId] = $aFolderName;
      }
    }
    return $folderOptions;
  }
  
  /**
   * mapRegionOptions
   *
   * Get the map region options for a county and folder.
   * This function retrieves the map regions from the API and returns an array of
   * map region options where the key is the map region ID and the value is the
   * map region name.  The first element in the array is 'all' => 'All'.
   *
   * @param string $committee
   * @param int $folderId
   * @return array
   */
  public function mapRegionOptions(string $committee, int $folderId): array
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
   * @param int $mapRegionId
   * @return array
   */
  public function mapRegionTurfOptions(int $mapRegionId): array
  {
    if(empty($mapRegionId)) {return [];}
    if($mapRegionId == 'all') {
      $mapRegionIds = array_keys($this->mapRegions);
    } else {
      $mapRegionIds = [0=>$mapRegionId,];
    }
    $turfOptions = [];
    foreach ($mapRegionIds as $mapRegionId) {
      if(empty($mapRegionId)) {continue;}
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
  public function getMapRegionInfo(string $committee, int $folderId, int $mapRegionId=0): array
  {
    if(empty($folderId)) {return [];}
    $committeeKey = empty($committee)
      ? $this->nlpConfigurationObj->getStateKey()
      : $this->nlpConfigurationObj->getCommitteeKey($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];

    $foldersURL = "https://$user:$apiKey|0@$apiURL/folders/$folderId/mapRegions";
    if($mapRegionId !== null) {
      $foldersURL .= '/'.$mapRegionId;
    }
   
    try {
      $request = $this->client->get($foldersURL);
    } catch (RequestException $e) {
      // Request error
      $code = $e->getCode();
      if ($code == 404) {
        // Not found error
        $msg = "Map Region not found, Committee=$committee, FolderId=$folderId, MapRegionId=$mapRegionId";
        if (!str_contains(Drupal::routeMatch()->getRouteName(), 'system.cron')) {
          nlp_debug_msg('getMapRegionInfo', $msg);
        }
        Drupal::logger('nlpservices')->notice($msg);
        return [];
      }
      nlp_debug_msg('getMapRegionInfo', $e->getMessage());
      return [];
    } catch (Exception $e) {
      // Handle other errors
      nlp_debug_msg('getMapRegionInfo', $e->getMessage());
      return [];
    }
    
    $regions = json_decode($request->getBody(), true);
    if($mapRegionId !== null) {
      return $this->extractRegion($regions);
    }

    $this->mapRegions = [];
    foreach ($regions['items'] ?? [] as $region) {
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
  private function extractRegion(array $region): array
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
  public function updateMinivanInfo(string $committee, array &$turfArray): void
  {
    $mapRegionOptionsSaved = [];
    foreach ($turfArray as $turfIndex => $turf) {
      
      $mapRegionId = $turf['mapRegionId'];
      if(empty($mapRegionId)) {
        $turfArray[$turfIndex]['minivanNumber'] = 0;
        $turfArray[$turfIndex]['minivanCreateDate'] = '';
        continue;
      }
      if (!empty($mapRegionOptionsSaved[$mapRegionId])) {
        $mapRegionOptions = $mapRegionOptionsSaved[$mapRegionId];
      } else {
        $folderId = $turf['folderId'];
        $this->getMapRegionInfo($committee,$folderId);
        $mapRegionOptions = $this->mapRegionTurfOptions($mapRegionId);
        $mapRegionOptionsSaved[$mapRegionId] = $mapRegionOptions;
      }
      $listId = $turf['listId'];
      if( empty($listId) || empty($mapRegionOptions[$listId]['printedList'])) {
        $turfArray[$turfIndex]['minivanNumber'] = 0;
        $turfArray[$turfIndex]['minivanCreateDate'] = '';
        continue;
      }
      if(!empty($mapRegionOptions[$listId]['printedList'])) {
        $turfArray[$turfIndex]['minivanNumber'] = $mapRegionOptions[$listId]['printedList']['number'];
        $turfArray[$turfIndex]['minivanCreateDate'] = $mapRegionOptions[$listId]['printedList']['dateCreated'];
      }
    }
  }
  
}
