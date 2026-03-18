<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfs
{
  
  const string TURF_TBL = 'nlp_turf';
  const string TURF_ASSIGNMENT_TBL = 'nlp_turf_assignment';
  const int TURF_ALL_MAX = 200;  // Maximum turfs to display before restricting display to house districts.
  const int TURF_RESYNC_MAX = 100;
  
  protected Connection $connection;
  protected FileSystemInterface $fileSystem;
  protected NlpNls $nlsObj;
  protected NlpPaths $pathsObj;
  protected NlpVoters $votersObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpReports $nlpReportsObj;
  protected NlpMatchbacks $nlpMatchbacksObj;
  protected ApiFolders $apiFoldersObj;
  
  public function __construct( $connection, $fileSystem, $nlsObj, $pathsObj,
                               $votersObj, $apiActivistCodesObj, $nlpConfigurationObj, $nlpReportsObj,
                               $nlpMatchbacksObj, $apiFoldersObj )
  {
    $this->connection = $connection;
    $this->fileSystem = $fileSystem;
    $this->nlsObj = $nlsObj;
    $this->pathsObj = $pathsObj;
    $this->votersObj = $votersObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpReportsObj = $nlpReportsObj;
    $this->nlpMatchbacksObj = $nlpMatchbacksObj;
    $this->apiFoldersObj = $apiFoldersObj;
  }
  
  public static function create(ContainerInterface $container): NlpTurfs
  {
    return new static(
      $container->get('database'),
      $container->get('file.system'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.folders')
    );
  }
  
  /**
   * setAllTurfsDelivered
   *
   * Set the date when an email was sent to an NL for one of their turfs.  In
   * the case where the NL has more than one turf, all the turfs are set as
   * delivered since the NL can see all turfs on the data entry page.
   *
   * @param $mcid
   * @param $county
   * @return void
   */
  public function setAllTurfsDelivered($mcid, $county): void
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('county', $county);
      $query->condition('mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setAllTurfsDelivered', $e->getMessage());
      return;
    }
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) {
        break;
      }
      $this->setTurfDelivered($turf['turfIndex']);
    } while (TRUE);
  }
  
  /**
   * setTurfDelivered
   *
   * Sets the turf delivered date to the current date.  The date is set
   * either when an email is sent to the NL or the coordinator checks the box
   * in the list of active NLs.
   *
   * @param $turfIndex
   * @return void
   */
  public function setTurfDelivered($turfIndex): void
  {
    $isoDate = explode('T', date('c'));  // date/time in ISO format.
    try {
      $this->connection->merge(self::TURF_TBL)
        ->keys(['turfIndex' => $turfIndex,])
        ->fields(['delivered' => $isoDate[0],])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setTurfDelivered', $e->getMessage());
      return;
    }
  }
  
  /**
   * getCountyTurfs
   *
   * Returns an array of turfs for a specific county.  If the county is 'all', then
   * all NLP turfs are returned.
   *
   * @param $county - Name of the specific county or 'all' for all counties.
   * @return array
   */
  public function getCountyTurfs($county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      if ($county == 'all') {
        $query->orderBy('county');
      } else {
        $query->condition('county', $county);
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfs', $e->getMessage());
      return [];
    }
    $turfArray = [];
    do {
      $turfRecord = $result->fetchAssoc();
      if (empty($turfRecord)) {
        break;
      }
      $turfIndex = $turfRecord['turfIndex'];
      $turfArray[$turfIndex] = $turfRecord;
    } while (TRUE);
    return $turfArray;
  }
  
  /**
   * getCountyTurfIndexes
   *
   * Returns an array of turf indexes for a specific county that were synced before an update
   * of the voter registrations or the map region was refreshed.  The latter happens if the region
   * was viewed or the refresh link was clicked.
   *
   * @param $county - Name of the specific county.
   * @return array
   */
  public function getCountyTurfIndexes($county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->addField('i','turfIndex');
      $query->addField('i','mapRegionId');
      $query->addField('i','folderId');
      $query->addField('i','syncDate');
      $query->orderBy('mapRegionId');
      $query->condition('county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfIndexes', $e->getMessage());
      return [];
    }
    
    $turfIndexArray = [];
    do {
      $turfRecord = $result->fetchAssoc();
      if (empty($turfRecord)) {
        break;
      }
      $turfIndex = $turfRecord['turfIndex'];
      $turfIndexArray[$turfIndex] = $turfRecord;
    } while (TRUE);
    
    
    $voterBuilderUpdateDate = $this->nlpConfigurationObj->getVoterBuilderUpdateDate();
    //nlp_debug_msg('voterBuilderUpdateDate', $voterBuilderUpdateDate);
    $voterBuilderUpdateTime = (!empty($voterBuilderUpdateDate))?strtotime($voterBuilderUpdateDate):0;
    
    // Identify the unique map regions in the county and get the IDs.
    $mapRegionInfo = [];
    foreach ($turfIndexArray as $turfRecord) {
      $mapRegionInfo[$turfRecord['mapRegionId']]['mapRegionId'] = $turfRecord['mapRegionId'];
      $mapRegionInfo[$turfRecord['mapRegionId']]['folderId'] = $turfRecord['folderId'];
    }
    //nlp_debug_msg('mapRegionInfo', $mapRegionInfo);
    // For each of the mapRegions, get the refresh date.
    $mapRegionRefreshTimes = [];
    foreach ($mapRegionInfo as $mapRegionItem) {
      $folderId = $mapRegionItem['folderId'];
      $mapRegionId = $mapRegionItem['mapRegionId'];
      $mapRegionInfo = $this->apiFoldersObj->getMapRegionInfo($county, $folderId, $mapRegionId );
      
      $mapRegionRefreshDate = $mapRegionInfo['dateRefreshed'];
      $mapRegionRefreshTime = strtotime($mapRegionRefreshDate);
      $mapRegionRefreshTimes[$mapRegionId] = $mapRegionRefreshTime;
    }
    //nlp_debug_msg('$mapRegionRefreshTimes', $mapRegionRefreshTimes);
    // Now identify the turfs that may be stale and need to be checked for resync.
    $resyncTurfIndexes = [];
    foreach ($turfIndexArray as $turfIndex => $turfRecord) {
      $mapRegionId = $turfRecord['mapRegionId'];
      $mapRegionRefreshTime = (!empty($mapRegionRefreshTimes[$mapRegionId]))?
        $mapRegionRefreshTimes[$mapRegionId]:time();
      $syncTime = (!empty($turfRecord['syncDate']))?strtotime($turfRecord['syncDate']):0;
      
      if($syncTime < $voterBuilderUpdateTime OR $syncTime < $mapRegionRefreshTime) {
        $resyncTurfIndexes[] = $turfIndex;
      }
    }
    //nlp_debug_msg('resyncTurfIndexes', $resyncTurfIndexes);;
    return $resyncTurfIndexes;
  }
  
  /**
   * getNlsWithTurfs
   *
   * Returns an array of MCIDs for NLs that have turfs in a specific county.
   * If a house district or precinct is specified, then only those NLs with
   * turfs in that HD or precinct are returned.
   *
   * @param $county
   * @param null $hd
   * @param null $precinct
   * @return array
   */
  public function getNlsWithTurfs($county,$hd=NULL,$precinct=NULL): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
      $query->addField('t', 'mcid');
      $query->addField('n', 'precinct');
      $query->addField('n', 'hd');
      
      $query->distinct();
      $query->condition('t.county', $county);
      if(!empty($precinct)) {
        $query->condition('precinct', $precinct);
      } elseif (!empty($hd)) {
        $query->condition('hd', $hd);
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getNlsWithTurfs', $e->getMessage());
      return [];
    }
    
    $mcids = [];
    do {
      $mcidRecord = $result->fetchAssoc();
      if (empty($mcidRecord)) {break;}
      $mcids[] = $mcidRecord['mcid'];
    } while (TRUE);
    return $mcids;
  }
  
  /**
   * getCountyTurfCount
   *
   * Returns the number of turfs in a specific county.
   *
   * @param $county
   * @return int
   */
  public function getCountyTurfCount($county): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->condition('county', $county);
      $query->addField('i', 'turfIndex');
      $turfCount = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfCount', $e->getMessage());
      return 0;
    }
    return $turfCount;
  }
  
  /**
   * getCdTurfCount
   *
   * Returns the number of turfs in a specific congressional district.
   *
   * @param $cd
   * @return int
   */
  public function getCdTurfCount($cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->condition('turfCd', $cd);
      $query->addField('i', 'turfIndex');
      $turfCount = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfCount', $e->getMessage());
      return 0;
    }
    return $turfCount;
  }
  
  /**
   * getTurf
   *
   * Returns a turf record for a specific turf index.
   *
   * @param int $turfIndex
   * @return array
   */
  public function getTurf(int $turfIndex): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('turfIndex', $turfIndex);
      $result = $query->execute();
      $dbTurf = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getTurf', $e->getMessage());
      return [];
    }
    return (empty($dbTurf))?[]:$dbTurf;
  }
  
  /**
   * getListId
   *
   * Returns the listId for a specific turf index.  The listId is
   * the ID of the turf in VoteBuilder.  Generally, this is the ID of
   * the turf associated with a map region.  It is rarely the ID of a
   * saved list.
   *
   * @param int $turfIndex
   * @return int
   */
  public function getListId(int $turfIndex): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->addField('i','listId');
      $query->condition('turfIndex', $turfIndex);
      $result = $query->execute();
      $dbTurf = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getListId', $e->getMessage());
      return 0;
    }
    return (empty($dbTurf))?0:$dbTurf['listId'];
  }
  
  /**
   * getTurfByListId
   *
   * Returns a turf record for a specific listId.  The listId is the ID of the turf in VoteBuilder.
   *
   * @param $listId
   * @return array
   */
  public function getTurfByListId($listId): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('listId', $listId);
      $result = $query->execute();
      $dbTurf = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfByListId', $e->getMessage());
      return [];
    }
    if (empty($dbTurf)) {return [];}
    return $dbTurf;
  }
  
  /**
   * getTurfsById
   *
   * Returns an array of turfs for a specific MCID.
   *
   * @param $mcid
   * @return array
   */
  public function getTurfsById($mcid): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = i.mcid');
      $query->fields('i');
      $query->addField('n', 'hd');
      $query->addField('n', 'precinct');
      $query->condition('i.mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfById', $e->getMessage());
      return [];
    }
    if (empty($result)) {return [];}
    
    $dbTurfs = [];
    do {
      $dbTurf = $result->fetchAssoc();
      if (empty($dbTurf)) {break;}
      $dbTurfs[] = $dbTurf;
    } while (TRUE);
    return $dbTurfs;
  }
  
  /**
   * updateTurf
   *
   * Updates a specific field in a turf record.
   *
   * @param $turfIndex
   * @param $field
   * @param $value
   * @return void
   */
  public function updateTurf($turfIndex, $field, $value): void
  {
    try {
      $this->connection->update(self::TURF_TBL)
        ->fields([
          $field => $value,
        ])
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateTurf', $e->getMessage());
    }
  }
  
  /**
   * updateTurfFields
   *
   * Updates multiple fields in a turf record.
   *
   * @param $turfIndex
   * @param $fields
   * @return void
   */
  public function updateTurfFields($turfIndex, $fields): void
  {
    try {
      $this->connection->update(self::TURF_TBL)
        ->fields($fields)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateTurfFields', $e->getMessage());
    }
  }
  
  /**
   * createTurf
   *
   * Creates a new turf record in the database.
   *
   * @param array $turf
   * @return int - The index of the newly created turf record.
   * @noinspection PhpUnused
   * used in SyncTurfBatch
   */
  public function createTurf(array $turf): int
  {
    $turf['syncDate'] = date('Y-m-d', time());
    try {
      $turfIndex = $this->connection->insert(self::TURF_TBL)
        ->fields($turf)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createTurf', $e->getMessage());
      return 0;
    }
    return $turfIndex;
  }
  
  /**
   * getTurfHD
   *
   * Returns an array of unique house districts with assigned turf
   * for a specific county.
   *
   * @param string $county
   * @return array - An indexed array of unique house districts.
   */
  public function getTurfHD(string $county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'turfHd');
      $query->condition('t.county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfHD', $e->getMessage());
      return [];
    }
    $uhd = [];
    do {
      $hdRec = $result->fetchAssoc();
      if (empty($hdRec)) {break;}
      $hd = $hdRec['turfHd'];
      $uhd[$hd] = $hd;
    } while (TRUE);
    if (empty($uhd)) {return [];}
    ksort($uhd);
    return array_values($uhd);
  }
  
  /**
   * getTurfs
   *
   * Returns an array of turfs for a specific county and house district.
   * If the house district is 'All', then all turfs for the county are returned.
   *
   * The list can optionally be sorted by ordered by a specific field and direction.
   * The orderBy parameter is an associative array with 'field' and 'sort' keys.
   * The sort key can be 'asc' or 'desc', and the field key is the name of the field to sort by.
   *
   * @param string $county - The name of the county.
   * @param mixed $hd - The house district or 'All'.
   * @param array|null $orderBy - Optional sorting parameters.
   * @return array - An associative array of turf records.
   */
  public function getTurfs(string $county, mixed $hd, array $orderBy=NULL): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
      $query->fields('t');
      $query->fields('n');
      $query->condition('t.county', $county);
      if ($hd != 'All') {
        $query->condition('t.turfHd', $hd);
      }
      if(!empty($orderBy) && !empty($orderBy['field'])) {
        $sortOrder = ($orderBy['sort']=='desc')?'DESC':'ASC';
        $query->orderBy($orderBy['field'],$sortOrder);
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfs', $e->getMessage());
      return [];
    }
    
    $turfArray = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) {break;}
      $turfIndex = $turf['turfIndex'];
      $turfArray[$turfIndex] = $turf;
    } while (TRUE);
    return $turfArray;
  }
  
  /**
   * getTurfIndexes
   *
   * Returns an indexed array of turf indexes for a specific county.
   *
   * @param string $county - The name of the county.
   * @return array - An indexed array of turf indexes.
   * @noinspection PhpUnused
   * used in VerifyTurfBatch
   */
  public function getTurfIndexes(string $county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t','turfIndex');
      $query->condition('t.county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfIndexes', $e->getMessage());
      return [];
    }
    $turfIndexes = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) {break;}
      $turfIndexes[] = $turf['turfIndex'];
    } while (TRUE);
    return $turfIndexes;
  }
  
  /**
   * getCountyNames
   *
   * Returns an indexed array of unique county names with turfs assigned.
   *
   * @return array - An indexed array of county names.
   */
  public function getCountyNames(): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'county');
      $query->distinct();
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfs', $e->getMessage());
      return [];
    }
    
    $countyNames = [];
    do {
      $countyName = $result->fetchAssoc();
      if (empty($countyName)) {break;}
      $countyNames[] = $countyName['county'];
    } while (TRUE);
    return $countyNames;
  }
  
  /**
   * unlinkFile
   *
   * Delete a file from the file system if it exists.
   *
   * @param string $fileName - The name of the file to delete.
   * @param string $path - The path where the file is located.
   * @return void
   */
  private function unlinkFile(string $fileName, string $path): void
  {
    if ($fileName != '') {
      $fullName = $path . $fileName;
      if (file_exists($fullName)) {
        $this->fileSystem->unlink($fullName);
      }
    }
  }
  
  /**
   * removeTurf
   *
   * Delete a turf from the database and delete its associated PDF file.
   *
   * @param array $turf - An associative array containing turf information.
   * @return bool - Returns TRUE on success, FALSE on failure.
   */
  public function removeTurf(array $turf): bool
  {
    $turfIndex = $turf['turfIndex'];
    $county = $turf['county'];
    // Get the filenames the PDF walksheet, mail list, and call list.
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('turfIndex', $turfIndex);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('removeTurf', $e->getMessage());
      return FALSE;
    }
    $turfRecord = $result->fetchAssoc();
    // Delete the PDF file.
    if (!empty($turfRecord['turfPDF'])) {
      $fileName = $turfRecord['turfPDF'];
      $path = $this->pathsObj->getPath('PDF', $county);
      $this->unlinkFile($fileName, $path);
    }
    
    // Delete the turf info in the turf table.
    try {
      $this->connection->delete(self::TURF_TBL)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('removeTurf', $e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * deleteTurf
   *
   * Deletes a turf and its associated voters and activist codes.
   *
   * @param array $turf - An associative array containing turf information.
   * @return bool - Returns TRUE on success, FALSE on failure.
   */
  public function deleteTurf(array $turf): bool
  {
    $county = $turf['county'];
    $turfIndex = $turf['turfIndex'];
    $mcid = $turf['mcid'];
    
    $status = $this->removeTurf($turf);
    if (!$status) {return FALSE;}
    
    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    $vanids = array_keys($voters);
    // Delete the NLP Voter AC.
    $request = [
      'codeName' => 'NLPVoterAC',
      'action' => 'Remove',
    ];
    foreach ($vanids as $vanid) {
      $request['vanid'] = $vanid;
      $this->apiActivistCodesObj->setApiActivistCode($request);
    }
    
    // remove the list of voters.
    $this->votersObj->deleteVotersInTurf($turfIndex);
    $this->votersObj->deleteAddressesInTurf($turfIndex);
    // Clear the turf-cut and turf-delivered status.
    $nlsStatus = $this->nlsObj->getNlsStatus($mcid, $county);
    $nlsStatus['turfCut'] = $nlsStatus['turfDelivered'] = '';
    $this->nlsObj->setNlsStatus($nlsStatus);
    return TRUE;
  }
  
  /**
   * turfExists
   *
   * Checks if a turf exists for a specific MCID.
   *
   * Returns and associative array containing turf information if it exists,
   * otherwise returns an empty array.  The keys to the associative array are:
   * - 'turfs': An array of turfs indexed by turfIndex assigned to this NL.
   * - 'turfCnt': The count of turfs found.
   * - 'turfIndex': The index of the first turf found.
   *
   * @param $mcid
   * @param $county
   * @return array - An associative array containing turf information if it exists, otherwise an empty array.
   */
  public function turfExists($mcid, $county): array
  {
    $query = $this->connection->select(self::TURF_TBL, 'i');
    $query->fields('i');
    $query->condition('county', $county);
    $query->condition('mcid', $mcid);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('turfExists', $e->getMessage());
      return [];
    }
    $turfs = [];
    do {
      $dbTurf = $result->fetchAssoc();
      if (empty($dbTurf)) {break;}
      $turfIndex = $dbTurf['turfIndex'];
      $turfs[$turfIndex] = $dbTurf;
    } while (TRUE);
    if (empty($turfs)) {return [];}
    $turfArray['turfs'] = $turfs;
    $turfArray['turfCnt'] = count($turfs);
    $turfArray['turfIndex'] = key($turfs);
    return $turfArray;
  }
  
  /**
   * getTurfByTurfName
   *
   * Returns a turf record for a specific folderId and turfName.
   *
   * @param $folderId
   * @param $turfName
   * @return array - An associative array containing turf information if it exists, otherwise an empty array.
   */
  public function getTurfByTurfName($folderId, $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->fields('t');
      $query->condition('t.folderId', $folderId);
      $query->condition('t.turfName', $turfName);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfByTurfName', $e->getMessage());
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) {
      return [];
    }
    return $assignment;
  }
  
  /**
   * createTurfNames
   *
   * Creates an indexed array of turf names in the provided array of turfs.
   *
   * @param $turfArray - An associative array containing turfs.
   * @return array - An indexed array of turf names.
   */
  public function createTurfNames($turfArray): array
  {
    return array_map(function ($turf) {
      return $turf['turfName'];
    }, $turfArray['turfs']);
  }
  
  /**
   * getStaleTurfs
   *
   * Returns an array of turf indexes that are stale, meaning they have not been updated
   * since the last VoterBuilder update date.
   *
   * @return array - An indexed array of arrays containing turf indexes and counties.
   */
  public function getStaleTurfs(): array
  {
    $votebuilderUpdateDate = $this->nlpConfigurationObj->getVoterBuilderUpdateDate();
    nlp_debug_msg('votebuilderUpdateDate', $votebuilderUpdateDate);
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'turfIndex');
      $query->addField('t', 'county');
      
      $Group1 = $query->orConditionGroup()
        ->condition('t.syncDate', $votebuilderUpdateDate, '<')
        ->condition('t.syncDate', NULL, 'IS NULL');
      $query->condition($Group1);
      
      $query->isNotNull('t.listId');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getStaleTurfs', $e->getMessage());
      return [];
    }
    $turfIndexes = [];
    $batch = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) {break;}
      $batch[] = $turf;
      if(count($batch) >= self::TURF_RESYNC_MAX) {
        $turfIndexes[] = $batch;
        $batch = [];
      }
    } while (TRUE);
    
    if(!empty($batch)) {
      $turfIndexes[] = $batch;
    }
    return $turfIndexes;
  }
  
  /**
   * setTurfAssignment
   *
   * Sets or updates a turf assignment in the database.
   *
   * @param $assignment - An associative array containing turf assignment information.
   * @return void
   */
  public function setTurfAssignment($assignment): void
  {
    try {
      $this->connection->merge(self::TURF_ASSIGNMENT_TBL)
        ->fields($assignment)
        ->condition('turfId', $assignment['turfId'])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setTurfAssignment', $e->getMessage());
    }
  }
  
  /**
   * getTurfAssignment
   *
   * Returns a turf assignment record for a specific turfId.
   *
   * @param $turfId
   * @return array - An associative array containing turf assignment information if it exists, otherwise an empty array.
   */
  public function getTurfAssignment($turfId): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.turfId', $turfId);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignment', $e->getMessage());
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) {
      return [];
    }
    return $assignment;
  }
  
  /**
   * getTurfAssignmentsById
   *
   * Returns an array of turf assignments for a specific MCID.
   *
   * @param $mcid
   * @return array - An indexed array of turf assignments.
   */
  public function getTurfAssignmentsById($mcid): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignment', $e->getMessage());
      return [];
    }
    if (empty($assignment)) {
      return [];
    }
    
    $assignments = [];
    do {
      $assignment = $result->fetchAssoc();
      $assignments[] = $assignment;
      if (empty($assignments)) {
        break;
      }
    } while (TRUE);
    
    return $assignments;
  }
  
  /**
   * getTurfAssignmentByTurfName
   *
   * Returns a turf assignment record for a specific folderId and turfName.
   *
   * @param int $folderId
   * @param string $turfName
   * @return array - An associative array containing turf assignment information if it exists, otherwise an empty array.
   */
  public function getTurfAssignmentByTurfName(int $folderId, string $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_ASSIGNMENT_TBL, 't');
      $query->fields('t');
      $query->condition('t.folderId', $folderId);
      $query->condition('t.turfName', $turfName);
      
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfAssignmentByTurfName', $e->getMessage());
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) {
      return [];
    }
    return $assignment;
  }
  
  /**
   * deleteTurfAssignment
   *
   * Deletes a turf assignment record for a specific turfId.
   *
   * @param $turfId
   * @return void
   */
  public function deleteTurfAssignment($turfId): void
  {
    try {
      $this->connection->delete(self::TURF_ASSIGNMENT_TBL)
        ->condition('turfId', $turfId)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteTurfAssignment', $e->getMessage());
    }
  }
  
  /**
   * decodeTurfAssignmentHeader
   *
   * Decodes the header of a turf assignment file and checks for required columns.
   *
   * @param $fileHdr - An array of column names from the file header.
   * @return array - An associative array containing header positions, errors, and status.
   */
  public function decodeTurfAssignmentHeader($fileHdr): array
  {
    $hdrErr = $hdrPos = [];
    $awardHeader = $this->getAssignmentColumnNames();
    foreach ($awardHeader as $columnName) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol => $fileColName) {
        if (trim($fileColName) == $columnName) {
          $hdrPos[$columnName] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $hdrErr[] = 'The awards column "' . $columnName . '" is missing.';
      }
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }
  
  /**
   * getAssignmentColumnNames
   *
   * Returns an array of column names from the turf assignment table.
   *
   * @return array - An indexed array of column names.
   */
  public function getAssignmentColumnNames(): array
  {
    try {
      $select = "SHOW COLUMNS FROM  {" . self::TURF_ASSIGNMENT_TBL . '}';
      $result = $this->connection->query($select);
    } catch (Exception $e) {
      nlp_debug_msg('getAssignmentColumnNames', $e->getMessage());
      return array();
    }
    $colNames = [];
    do {
      $name = $result->fetchAssoc();
      if (empty($name)) {
        break;
      }
      $colNames[] = $name['Field'];
    } while (TRUE);
    return $colNames;
  }
  
  /**
   * fixTurfAssignment
   *
   * Fixes a turf assignment by changing the turfId to a new turfId.
   *
   * @param int $turfId - The current turfId to be fixed.
   * @param int $newTurfId - The new turfId to assign.
   * @return void
   */
  public function fixTurfAssignment(int $turfId, int $newTurfId): void
  {
    $turfAssignment = $this->getTurfAssignment($turfId);
    $this->deleteTurfAssignment($turfId);
    $turfAssignment['turfId'] = $newTurfId;
    $this->setTurfAssignment($turfAssignment);
  }
  
  /**
   * checkTurfIdChange
   *
   * Checks if the turfId has changed for a given turf and updates the assignment if necessary.
   *
   * @param int $folderId - The folder ID where the turf is assigned.
   * @param array $turf - An associative array containing turf information.
   * @return void
   */
  public function checkTurfIdChange(int $folderId, array $turf): void
  {
    $previousTurfAssignment = $this->getTurfAssignmentByTurfName($folderId, $turf['turfName']);
    $turfId = $turf['turfId'];
    $turfName = $turf['turfName'];
    // Detect if the turf is in use or if the Map Region was altered.   Fix the turfId in NLP Services if
    // there is a new turfID assigned to the turf.
    if (!empty($previousTurfAssignment)) {
      if ($previousTurfAssignment['turfId'] != $turfId) {
        $this->fixTurfAssignment($previousTurfAssignment['turfId'], $turfId);
      }
    }
    $alteredTurf = $this->getTurfByTurfName($folderId, $turfName);
    if (!empty($alteredTurf) and $alteredTurf['listId'] != $turfId) {
      $this->updateTurf($alteredTurf['turfIndex'], 'listId', $turfId);
    }
  }
  
  /**
   * turfInUse
   *
   * Checks if a turf is currently assigned to an NL or has been previously assigned.
   *
   * @param int $folderId - The folder ID where the turf is assigned.
   * @param int $turfId - The ID of the turf in VoteBuilder.
   * @param string $turfName - The name of the turf.
   * @return array - An associative array containing information about the turf in use.
   */
  public function turfInUse(int $folderId,int $turfId,string $turfName): array
  {
    $turfInUse = [
      'nl' => '',
      'mcid' => '',
      'turfAssigned'=> '',
      ];
    $turf = $this->getTurfByListId($turfId);
    if(!empty($turf)) {
      $turfInUse = [
        'nl' => $turf['nlFirstName'].' '.$turf['nlLastName'],
        'mcid' => $turf['mcid'],
        'turfAssigned'=> 'X',
      ];
     
    } else {
      $turf = $this->getTurfByTurfName($folderId, $turfName);
      if(!empty($turf)) {
        $turfInUse = [
          'nl' => $turf['nlFirstName'].' '.$turf['nlLastName'],
          'mcid' => $turf['mcid'],
          'turfAssigned'=> 'X',
        ];
       
      } else {
        // The turf known to VoteBuilder has not yet been assigned to and NL.   So, check if the turfID known
        // to VoteBuilder matches a turf previously assigned to an NL.
        $previousTurfAssignment = $this->getTurfAssignment($turfId);
        if(empty($previousTurfAssignment)) {
          $previousTurfAssignment = $this->getTurfAssignmentByTurfName($folderId, $turfName);
          if(!empty($previousTurfAssignment)) {
            // Update the assignment record.
            $previousTurfAssignment['turfId'] = $turfId;
            $this->deleteTurfAssignment($turfId);
            $this->setTurfAssignment($previousTurfAssignment);
          }
        }
        
        if(!empty($previousTurfAssignment)) {
          $nls = $this->nlsObj->getNlById($previousTurfAssignment['mcid']);
          $footnote = (!empty($nls))?'*':'**';
          $footnote = '<span class="nlp-footnote-warning">'.$footnote.'</span>';
          $turfInUse = [
            'nl' => $previousTurfAssignment['firstName'].' '.$previousTurfAssignment['lastName'],
            'mcid' => $previousTurfAssignment['mcid'].$footnote,
            'hintPrevious'=> $previousTurfAssignment['mcid'],
            'turfAssigned'=> '',
          ];
        }
      }
    }
    return $turfInUse;
  }
  
  /**
   * unreportedChanges
   *
   * Check if there are unreported changes for a turf.  The NL must acknowledge
   * that changes have been reported to VoteBuilder before the turf is
   * displayed for data entry.
   *
   * @param int $turfIndex - The turf index to check.
   * @return bool - TRUE if there are unreported changes, FALSE otherwise.
   */
  public function unreportedChanges(int $turfIndex): bool
  {
    $query = $this->connection->select(self::TURF_TBL, 'i');
    $query->addField('i', 'unreportedChanges');
    $query->condition('turfIndex', $turfIndex);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('unreportedChanges', $e->getMessage());
      return FALSE;
    }
    
    $dbTurf = $result->fetchAssoc();
    if (empty($dbTurf)) {return FALSE;}
   
    return (!empty($dbTurf['unreportedChanges']))?$dbTurf['unreportedChanges']:FALSE;
  }
  
  /**
   * getChangeHistory
   *
   * Get the change history for a turf.  The change history is the
   * difference between the original turf and the turf as it was
   * last synced with VoteBuilder.  The history identifies the voters
   * added and/or deleted.
   *
   * @param int $turfIndex - The turf index to look up.
   * @return array - An array of change history records, or an empty array if not found.
   */
  public function getChangeHistory(int $turfIndex): array
  {
    $query = $this->connection->select(self::TURF_TBL, 'i');
    $query->addField('i', 'changeHistory');
    $query->condition('turfIndex', $turfIndex);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getUnreportedChanges', $e->getMessage());
      return [];
    }
    $dbTurf = $result->fetchAssoc();
    return (!empty($dbTurf['changeHistory']))?json_decode($dbTurf['changeHistory'],TRUE):[];
  }
  
  /**
   * getMinivanInfo
   *
   * Get the minivan number and create a date for a turf.
   *
   * @param int $turfIndex - The turf index to look up.
   * @return array - An array with the minivan number and create date, or an empty array if not found.
   */
  public function getMinivanInfo(int $turfIndex): array
  {
    $query = $this->connection->select(self::TURF_TBL, 'i');
    $query->addField('i', 'minivanNumber');
    $query->addField('i', 'minivanCreateDate');
    $query->condition('turfIndex', $turfIndex);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getMinivanInfo', $e->getMessage());
      return [];
    }
    $dbTurf = $result->fetchAssoc();
    if(empty($dbTurf)) {return [];}
    return $dbTurf;
  }
  
  /**
   * getVoterCounts
   *
   * Get a count of voters with the filters for type, scope and having voted.
   *
   * @param string $type - Possible keys: CD, County, NL, Turf.
   * @param mixed $value - the value of the type.
   * @param string $scope - Possible keys: All, Attempted, Contacted, Postcard.
   * @param bool $voted - If TRUE, only return the count of voters who have turned in a ballot.
   * @return int - A count of the voters meeting the criteria.
   */
  public function getVoterCounts(string $type, mixed $value, string $scope, bool $voted=FALSE): int
  {
    try {
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      if($type == 'CD') {
        $query->leftJoin($this->votersObj::VOTER_ADDRESS_TBL, 'a', 'a.vanid = g.vanid');
      }
      if($voted) {
        $query->leftJoin($this->nlpMatchbacksObj::MATCHBACK_TBL, 'm', 'm.vanid = g.vanid');
      }
      if($scope != 'All') {
        $query->leftJoin($this->nlpReportsObj::NLP_RESULTS_TBL, 'r', 'r.vanid = g.vanid');
        $cycle = $this->nlpConfigurationObj->getCycle();
        $query->condition('r.cycle',$cycle);
        
        switch ($scope) {
          case 'Attempted':
            $group = $query->orConditionGroup()
              ->condition('r.type', $this->nlpReportsObj::SURVEY)
              ->condition('r.type', $this->nlpReportsObj::CONTACT);
            $query->condition($group);
            $query->distinct();
            break;
          case 'Contacted':
            $query->condition('r.type', $this->nlpReportsObj::SURVEY);
            $query->distinct();
            break;
          case 'Postcard':
            $query->condition('r.type', $this->nlpReportsObj::CONTACT);
            $group = $query->orConditionGroup()
              ->condition('r.value', 'Mailed')
              ->condition('r.value', $this->nlpReportsObj::POSTCARD);
            $query->condition($group);
            $query->distinct();
            break;
        }
      }
      $query->addField('g','vanid');
      switch ($type) {
        case 'CD':
          $query->condition('a.cd', (int) $value);
          $query->distinct();
          break;
        case 'County':
          $query->condition('g.county', (string) $value);
          break;
        case 'NL':
          $query->condition('g.mcid', (int) $value);
          break;
        case 'Turf':
          $query->condition('g.turfIndex', (int) $value);
          break;
      }
      if($voted) {
        $query->isNotNull('m.date');
      }
      $voterCount = $query->countQuery()->execute()->fetchField();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVotersInCounty', $e->getMessage() );
      return 0;
    }
    
    return $voterCount;
  }
  
  /**
   * countOfNlsWithTurfsInCd
   *
   * Get the number of NLs in a CD with turfs.
   *
   * @param $cd - The CD to check.
   * @return int - The number of NLS in the CD that have turfs.
   */
  public function countOfNlsWithTurfsInCd($cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
      $query->addField('n', 'mcid');
      $query->condition('t.turfCd', (int) $cd);
      $query->distinct();
      $count = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('countOfNlsWithTurfsInCd', $e->getMessage());
      return 0;
    }
    return (int) $count;
  }
  
  /**
   * countOfNlsInCdThatReportedResults
   *
   * Get the number of NLs in a CD that have reported results.
   *
   * @param $cd - The CD to check.
   * @return int - The number of NLS in the CD that have reported results.
   */
  public function countOfNlsInCdThatReportedResults($cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_STATUS_TBL, 'n', 'n.mcid = t.mcid');
      $query->addField('n', 'mcid');
      $query->condition('t.turfCd', (int) $cd);
      $query->condition('n.resultsReported', 'Y');
      $query->distinct();
      $count = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('countOfNlsInCdThatReportedResults', $e->getMessage());
      return 0;
    }
    return (int)$count;
  }
  
}


