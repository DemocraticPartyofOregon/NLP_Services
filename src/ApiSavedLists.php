<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class ApiSavedLists {
  
  protected ClientInterface $client;
  protected NlpTurfs $turfsObj;
  protected NlpNls $nlsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  private array $stateCommitteeKey;
  
  public function __construct($client, $turfsObj, $nlsObj, $nlpConfigurationObj) {
    $this->client = $client;
    $this->turfsObj = $turfsObj;
    $this->nlsObj = $nlsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->stateCommitteeKey = $this->nlpConfigurationObj->getStateKey();
  }
  
  public static function create(ContainerInterface $container): ApiSavedLists
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * getTurfLists
   *
   * Get the information about saved lists from VoteBuilder.  In the VoterFile,
   * the response is both the saved lists and the turfs associated with map
   * regions.   The array of excluded IDs the map region associated turfs so
   * that only the saved lists remain.
   *
   * VoteBuilder has an internal limit to the number of saved lists that can be
   * returned.  If there are more than 1000 saved lists, VoteBuilder will chunk
   * the response.  The $skip parameter is used to skip the items already returned.
   *
   * @param string $county
   * @param int $folderId
   * @param array $excludedTurfIds
   * @return array
   */
  public function getTurfLists( string $county, int $folderId, array $excludedTurfIds=[]): array
  {
    $committeeKey = (empty($county))?$this->stateCommitteeKey:
      $this->nlpConfigurationObj->getCountyKey($county);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $listsURL = "https://$user:$apiKey|0@$apiURL/savedLists?folderId=$folderId";
    $count = 0;
    $totalList = [];
    // Get all the chunks of the saved lists.
    do {
      $url = $listsURL;
      if(!empty($count)) {
        $url .= '&$skip='.$count;
      }
      try {
        $request = $this->client->get($url);
        $results = json_decode($request->getBody(), true);
      } catch (Exception $e) {
        nlp_debug_msg('getTurfList',$e->getMessage());
        return [];
      }
      //nlp_debug_msg_safe('apiSavedLists $results',$results);
      if(empty($results['items'])) {return [];}
      $fragmentCount = count($results['items']);
      $count += $fragmentCount;
      $totalCount = $results['count'];
      foreach ($results['items'] as $item) {
        $savedListId = $item['savedListId'];
        if(in_array($savedListId, $excludedTurfIds)) {continue;}
        $totalList[$savedListId] = $item;
      }
      if($count >= $totalCount) {break;}
    } while (TRUE);
    
    return $totalList;
  }
  
  /**
   * getSavedLists
   *
   * Get the saved lists in either the VoterFile or MyCampaign. If the county
   * is empty, the state committee is used.  The information returned is
   * either a list of information for all the saved lists in the folder or
   * the information for a specific saved list.
   *
   * The return array has the following keys:
   *   'description' => Text from the saved list, likely empty,
   *   'listCount' => count of voters/NLs in the list,
   *   'doorCount' => count for doors, most for voters to be canvassed,
   *   'isSuppressed' => not used,
   *   'savedListId' => The id of the saved list,
   *   'name' => The saved list name.
   *
   * @param string $county
   * @param int $database
   * @param int $folderId
   * @param int $listId
   *
   * @return array
   */
  public function getSavedLists(string $county, int $database, int $folderId=0,
    int $listId=0): array
  {
    // Get a list of saved lists in a folder or the information about a specific
    // saved list.  The request is for a specific committee and the specific
    // database for that committee.
    $committeeKey = (empty($county))?$this->stateCommitteeKey:
      $this->nlpConfigurationObj->getCountyKey($county);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $listsURL = "https://$user:$apiKey|$database@$apiURL/savedLists";
    if(!empty($listId)) {
      $listsURL .= '/'.$listId;
    } elseif(!empty($folderId)) {
      $listsURL .= '?folderId='.$folderId;
    } else {
      return [];
    }
    $count = 0;
    $totalList = [];
    if(!empty($listId)) {
      // Get the information about a specific saved list.
      try {
        $request = $this->client->get($listsURL);
        $results = json_decode($request->getBody(), true);
      } catch (Exception $e) {
        $statusCode = $e->getCode();
        if ($statusCode == 404) {
          return [];
        }
        nlp_debug_msg('getSavedLists',$e->getMessage());
        return [];
      }
      $totalList[$results['savedListId']] = $results;
    } else {
      // Get the information about all the saved lists in a folder.  For large
      // numbers of saved lists in a folder, VoteBuilder can chunk up the
      // response.  The $skip parameter is used to skip the items already returned.
      // For NLP, this chunking probably does not happen.
      do {
        $url = $listsURL;
        if(!empty($count)) {
          $url .= '&$skip='.$count;
        }
        try {
          $request = $this->client->get($url);
          $results = json_decode($request->getBody(), true);
        } catch (Exception $e) {
          nlp_debug_msg('getSavedLists',$e->getMessage());
          return [];
        }
        if(empty($results['items'])) {return [];}
        $fragmentCount = count($results['items']);
        
        $count += $fragmentCount;
        $totalCount = $results['count'];
        foreach ($results['items'] as $item) {
          $totalList[$item['savedListId']] = $item;
        }
        if($count >= $totalCount) {break;}
    
      } while (TRUE);
    }
    return $totalList;
  }
  
  /**
   * savedListOptions
   *
   * Return an array of saved lists with information for the drop-down menu.
   * The array is sorted by turf name. And the keys are:
   * turfId, turfName, mapRegionName, description, voterCount, doorCount.
   *
   * @param $savedLists
   * @return array
   */
  public function savedListOptions($savedLists): array
  {
    $turfOptions = [];
    foreach ($savedLists as $list) {
      $listId = $list['savedListId'];
      $listInfo['turfName'] = $list['name'];
      $listInfo['mapRegionName'] = '';
      $listInfo['turfId'] = $listId;
      $listInfo['description'] =  (!empty($list['description']))?$list['description']:'';
      $listInfo['voterCount'] = $list['listCount'];
      $listInfo['doorCount'] = $list['doorCount'];
      
      $turfOptions[$listId] = $listInfo;
    }
    array_multisort($turfOptions);
    
    return $turfOptions;
  }
  
  /**
   * recoverTurfId
   *
   * The turf can't be found by its id which probably means it was changed.
   * So, search for the turf by name and return the new turf id. If the list
   * name was changed, then give up because turf names are not to be changed.
   *
   * @param string $county
   * @param int $folderId
   * @param string $turfName
   * @return array
   * Used in SyncTurfBatch
   * @noinspection PhpUnused
   */
  public function recoverTurfId(string $county, int $folderId, string $turfName): array
  {
    $newTurfId = 0;
    $savedLists = $this->getSavedLists($county, 0, $folderId);
    
    foreach ($savedLists as $savedList) {
      if (str_contains($savedList['name'], $turfName)) {
        $newTurfId = $savedList['savedListId'];
        break;
      }
    }
    
    if (empty($newTurfId)) {
      return [
        'result' => 'error',
        'message' => 'The original list of voters for your turf cannot be found by name.',
      ];
    } else {
      return [
        'result' => 'ok',
        'turfId' => $newTurfId,
      ];
    }
}
  
}
