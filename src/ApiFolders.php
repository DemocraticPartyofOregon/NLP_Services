<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Routing\RouteMatchInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Exception;

class ApiFolders {

  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;
  public function __construct(
    $client,
    $nlpConfigurationObj,
    $apiKeyManagementObj,
    private readonly RouteMatchInterface $routeMatch,
  ) {
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
      $request = $this->client->request('GET', $foldersURL);
    } catch (RequestException $e) {
      $code = $e->getCode();
      if ($code == 401) {
        nlp_debug_msg("getFolders","Invalid API Key for $committee");
        return [];
      }
      if ($code == 404) {
        // Handle was not found error
        $msg = "Folder not found, County=$committee, FolderId=$folderId";
        if (!str_contains($this->routeMatch->getRouteName(), 'system.cron')) {
          nlp_debug_msg('getApiFolders', $msg);
        }
        Drupal::logger('nlpservices')->notice($msg);
        return [];
      }
      nlp_debug_msg('getApiFolders', $e);
      // Request error
      return [];
    } catch (Exception $e) {
      // Other errors
      nlp_debug_msg('getApiFolders', $e);
      return [];
    } catch (GuzzleException $e) {
      nlp_debug_msg('getApiFolders', $e);
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

}
