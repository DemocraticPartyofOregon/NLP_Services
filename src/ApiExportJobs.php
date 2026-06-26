<?php
namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApiExportJobs {
  
  const string EXPORT_JOBS_TBL = 'nlp_export_jobs';

  protected ClientInterface $client;
  protected Connection $connection;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;

  public function __construct($client,  $connection, $nlpConfigurationObj, $apiKeyManagementObj) {
    $this->client = $client;
    $this->connection = $connection;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiExportJobs
  {
    return new static(
      $container->get('http_client'),
      $container->get('database'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }
  
  /**
   * getExportJobs
   *
   * Creates an export job for a saved list or turf.
   * The export job signals it is finished by a GET to the uri: nlp-webhook-callback.
   *
   * Returns an array with the following keys:
   *   `result` - 'ok' or 'error'
   *   `savedListId` - ID of the saved list or turf.
   *   `downloadUrl` - URL to download the file.
   *   `eventId` - code to identify the export job.
   *   `msg` - error message if the result is 'error'.
   *
   * @param string $committee
   * @param int $database - 0=VoterFile, 1=MyCampaign
   * @param int $listId - either the ID for a saved list or a turf associated with a Map Region.
   * @param string|array $activistCode - a single code or an array of codes, eg 'NLPVoterAC'.
   * @return array
   */
  public function getExportJobs(string $committee,int $database,int $listId,string|array $activistCode='' ): array
  {
    global $base_url;
    $req['committee'] = $committee;
    $req['listId'] = $listId;
    $req['startTime'] = time();
    $req['endTime'] = 0;
    $eventId = $this->insertExportJob($req);
    
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $post_url = "https://$user:$apiKey|$database@$apiURL/exportJobs";

    $jobObj = new stdClass();
    $jobObj->savedListId = $listId;
    $jobObj->type = 4;
    $jobObj->webhookUrl = "$base_url/nlp-webhook-callback?committee=$committee&listId=$listId&eventId=$eventId";
    
    if(!empty($activistCode)) {
      $activistCodeNames = (is_array($activistCode))?$activistCode:[$activistCode,];
      $activistCodes = [];
      foreach ($activistCodeNames as $activistCodeName) {
        $nlpVoterAC = $this->nlpConfigurationObj->getActivistCode($activistCodeName);
        if(!empty($nlpVoterAC)) {
          $activistCodeObj = new stdClass();
          $activistCodeObj->activistCodeId = $nlpVoterAC;
          $activistCodes[] = $activistCodeObj;
        }
      }
      $jobObj->activistCodes = $activistCodes;
    }
    $dataJson = json_encode($jobObj);
    $headers = ['Content-Type' => 'application/json'];
    try {
      try {
        $response = $this->client->request('POST', $post_url, ['headers' => $headers, 'body' => $dataJson]);
      } catch (GuzzleException $e) {
        nlp_debug_msg('getExportJobs', $e->getMessage());
        return [];
      }
    } catch (Exception $e) {
      $errorMessage = $e->getMessage();
      $lostList = str_contains($errorMessage, "must be a valid saved list ID");
      if($lostList) {
        return [
          'result' => 'error',
          'msg' => "The original file with your turf has been deleted and your turf cannot be updated."
          ];
      }
      nlp_debug_msg('getExportJobs', $e->getMessage());
      nlp_debug_msg('$dataJson', $dataJson);
      nlp_debug_msg('$post_url', $post_url);
      return [];
    }
    $body = (string) $response->getBody();
    $dataObj = json_decode($body);
    $exportJob = [
      'result' => 'ok',
      'savedListId' => $dataObj->savedListId,
      'downloadUrl' => $dataObj->downloadUrl,
      'eventId' => $eventId,
    ];
    
    // Pause for VoteBuilder to finish creating the file.   It's almost always done immediately.
    for ($index = 0; $index < 50; $index++) {
      $exportJobStatus = $this->getExportJobStatus($exportJob['eventId']);
      if($exportJobStatus != -1) {break;}
      usleep(50000);
    }
    return $exportJob;
  }
  
  /**
   * insertExportJob
   *
   * Creates an entry in the table EXPORT_JOBS_TBL.
   * The entry is used to track the status of the export job.
   * The entry is deleted when the export job is finished.
   *
   * $req is an array with the following keys:
   *   'committee'
   *   'listId' - ID of the turf or saved list.
   *   'startTime'
   *   'endTime'
   *
   * @param array $req
   * @return int - ID for this export job.  Used to identify the end of the job.
   * Or 0 if the insert fails.
   */
  public function insertExportJob(array $req): int
  {
    try {
      $eventId = $this->connection->insert(self::EXPORT_JOBS_TBL)
        ->fields($req)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('insertExportJob',$e->getMessage());
      return 0;
    }
    return $eventId;
  }

  /**
   * endExportJob
   *
   * Updates the table EXPORT_JOBS_TBL with the endTime.
   * The entry is used to track the status of the export job.
   * The entry is deleted when the export job is finished.
   *
   * @param int $eventId
   * @return bool
   */
  public function endExportJob(int $eventId): bool
  {
    $fields = ['endTime' => time(),];
    try {
      $this->connection->merge(self::EXPORT_JOBS_TBL)
        ->keys(['eventId' => $eventId])
        ->fields($fields)
        ->execute();
    }
    catch (Exception $e) {
      $nice = highlight_string("<?php\n\endExportJob 89 =\n" . var_export($e->getMessage(), true) . ";\n?>", TRUE);
      Drupal::logger('nlpservices')->notice($nice);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * getExportJobStatus
   *
   * Waits for the export job to finish.
   *
   * Checks the table EXPORT_JOBS_TBL for an entry for the eventId.  The entry is created
   * when the export job is started.  This looks for the existence of the entry in the table for the
   * eventId.   The entry is created by the arrival of a GET to the uri: nlp-webhook-callback.
   *
   * Returns the following values:
   *   -1 - still waiting for the export job to finish.
   *   0 - the export job has failed.
   *   >0 - the export job has finished.  The value is the number of seconds the export job took to complete.
   *
   * @param int $eventId - code to identity the export job.
   * @return int - Elapsed time in seconds for completion of the export job.
   */
  public function getExportJobStatus(int $eventId): int
  {
    try {
      $query = $this->connection->select(self::EXPORT_JOBS_TBL, 'n');
      $query->condition('eventId',$eventId);
      $query->fields('n');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getExportJobStatus', $e->getMessage() );
      return 0;
    }
    $exportJob = $result->fetchAssoc();
    if (empty($exportJob)) {return 0;}
    if(empty($exportJob['endTime'])) {
      return -1; // Still waiting.
    }
    // If more than 4 seconds have passed, the export job has taken too long.
    $elapsedTime = $exportJob['endTime']-$exportJob['startTime'];
    $nice = 'getExportJobStatus: elapsedTime=' . $elapsedTime;
    Drupal::logger('nlpservices')->notice($nice);
    if($elapsedTime < 4) {
      try {
        $this->connection->delete(self::EXPORT_JOBS_TBL)
          ->condition('eventId', $eventId)
          ->execute();
      }
      catch (Exception $e) {
        nlp_debug_msg('getExportJobStatus', $e->getMessage() );
        return 0;
      }
    }
    return $elapsedTime;
  }
  
}
