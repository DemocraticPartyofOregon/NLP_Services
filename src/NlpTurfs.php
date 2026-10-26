<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfs
{
  const string TURF_TBL = 'nlp_turf';
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
  protected ApiMapRegions $apiMapRegionsObj;
  protected NlpTurfAssignments $turfAssignmentsObj;

  public function __construct( $connection, $fileSystem, $nlsObj, $pathsObj,
                               $votersObj, $apiActivistCodesObj, $nlpConfigurationObj, $nlpReportsObj,
                               $nlpMatchbacksObj, $apiFoldersObj, $apiMapRegionsObj, $turfAssignmentsObj )
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
    $this->apiMapRegionsObj = $apiMapRegionsObj;
    $this->turfAssignmentsObj = $turfAssignmentsObj;
  }

  public static function create(ContainerInterface $container): NlpTurfs
  {
    return new static(
      $container->get('database'),
      $container->get('file_system'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.api_folders'),
      $container->get('nlpservices.api_map_regions'),
      $container->get('nlpservices.turf_assignments'),
    );
  }

  /**
   * setAllTurfsDelivered
   *
   * Set the date when an email was sent to an NL for one of their turfs.  In
   * the case where the NL has more than one turf, all the turfs are set as
   * delivered since the NL can see all turfs on the data entry page.
   *
   * @param int $mcid
   * @param string $county
   * @return void
   */
  public function setAllTurfsDelivered(int $mcid, string $county): void
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('county', $county);
      $query->condition('mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setAllTurfsDelivered', $e);
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
   * @param int $turfIndex
   * @return void
   */
  public function setTurfDelivered(int $turfIndex): void
  {
    $isoDate = explode('T', date('c'));  // date/time in ISO format.
    try {
      $this->connection->merge(self::TURF_TBL)
        ->keys(['turfIndex' => $turfIndex])
        ->fields(['delivered' => $isoDate[0]])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setTurfDelivered', $e);
    }
  }

  /**
   * getCountyTurfs
   *
   * Returns an array of turfs for a specific county.  If the county is 'all', then
   * all NLP turfs are returned.
   *
   * @param string $county - Name of the specific county or 'all' for all counties.
   * @return array
   */
  public function getCountyTurfs(string $county): array
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
      nlp_debug_msg('getCountyTurfs', $e);
      return [];
    }
    $turfArray = [];
    do {
      $turfRecord = $result->fetchAssoc();
      if (empty($turfRecord)) { break; }
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
   * @param string $county - Name of the specific county.
   * @return array
   */
  public function getCountyTurfIndexes(string $county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->addField('i', 'turfIndex');
      $query->addField('i', 'mapRegionId');
      $query->addField('i', 'folderId');
      $query->addField('i', 'syncDate');
      $query->orderBy('mapRegionId');
      $query->condition('county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfIndexes', $e);
      return [];
    }

    $turfIndexArray = [];
    do {
      $turfRecord = $result->fetchAssoc();
      if (empty($turfRecord)) { break; }
      $turfIndex = $turfRecord['turfIndex'];
      $turfIndexArray[$turfIndex] = $turfRecord;
    } while (TRUE);

    $voterBuilderUpdateDate = $this->nlpConfigurationObj->getVoterBuilderUpdateDate();
    $voterBuilderUpdateTime = (!empty($voterBuilderUpdateDate)) ? strtotime($voterBuilderUpdateDate) : 0;

    // Identify the unique map regions in the county and get the IDs.
    $mapRegionInfo = [];
    foreach ($turfIndexArray as $turfRecord) {
      $mapRegionInfo[$turfRecord['mapRegionId']]['mapRegionId'] = $turfRecord['mapRegionId'];
      $mapRegionInfo[$turfRecord['mapRegionId']]['folderId'] = $turfRecord['folderId'];
    }
    // For each of the mapRegions, get the refresh date.
    $mapRegionRefreshTimes = [];
    foreach ($mapRegionInfo as $mapRegionItem) {
      $folderId = $mapRegionItem['folderId'];
      $mapRegionId = $mapRegionItem['mapRegionId'];
      $mapRegionDetail = $this->apiMapRegionsObj->getMapRegionInfo($county, $folderId, $mapRegionId);
      $mapRegionRefreshTime = strtotime($mapRegionDetail['dateRefreshed']);
      $mapRegionRefreshTimes[$mapRegionId] = $mapRegionRefreshTime;
    }
    // Now identify the turfs that may be stale and need to be checked for by resyncing.
    $resyncTurfIndexes = [];
    foreach ($turfIndexArray as $turfIndex => $turfRecord) {
      $mapRegionId = $turfRecord['mapRegionId'];
      $mapRegionRefreshTime = (!empty($mapRegionRefreshTimes[$mapRegionId])) ?
        $mapRegionRefreshTimes[$mapRegionId] : time();
      $syncTime = (!empty($turfRecord['syncDate'])) ? strtotime($turfRecord['syncDate']) : 0;

      if($syncTime < $voterBuilderUpdateTime || $syncTime < $mapRegionRefreshTime) {
        $resyncTurfIndexes[] = $turfIndex;
      }
    }
    return $resyncTurfIndexes;
  }

  /**
   * getNlsWithTurfs
   *
   * Returns an array of MCIDs for NLs that have turfs in a specific county.
   * If a house district or precinct is specified, then only those NLs with
   * turfs in that HD or precinct are returned.
   *
   * @param string $county
   * @param string|null $hd
   * @param string|null $precinct
   * @return array
   */
  public function getNlsWithTurfs(string $county, ?string $hd = NULL, ?string $precinct = NULL): array
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
      nlp_debug_msg('getNlsWithTurfs', $e);
      return [];
    }

    $mcids = [];
    do {
      $mcidRecord = $result->fetchAssoc();
      if (empty($mcidRecord)) { break; }
      $mcids[] = $mcidRecord['mcid'];
    } while (TRUE);
    return $mcids;
  }

  /**
   * getCountyTurfCount
   *
   * Returns the number of turfs in a specific county.
   *
   * @param string $county
   * @param int $hd - The house district.  If not specified, then all turfs in the county are counted.
   * @param string $pct - The precinct.  If not specified, then all turfs in the county or house district are counted.
   * @return int
   */
  public function getCountyTurfCount(string $county, int $hd = 0, string $pct = ''): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->condition('county', $county);
      if ($hd != 0) {
        $query->condition('turfHd', $hd);
        if(!empty($pct)) {
          $query->condition('turfPrecinct', $pct);
        }
      }
      $query->addField('i', 'turfIndex');
      $turfCount = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfCount', $e);
      return 0;
    }
    return (int) $turfCount;
  }

  /**
   * getCdTurfCount
   *
   * Returns the number of turfs in a specific congressional district.
   *
   * @param int $cd
   * @return int
   */
  public function getCdTurfCount(int $cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->condition('turfCd', $cd);
      $query->addField('i', 'turfIndex');
      $turfCount = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('getCdTurfCount', $e);
      return 0;
    }
    return (int) $turfCount;
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
      nlp_debug_msg('getTurf', $e);
      return [];
    }
    return (empty($dbTurf)) ? [] : $dbTurf;
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
      $query->addField('i', 'listId');
      $query->condition('turfIndex', $turfIndex);
      $result = $query->execute();
      $dbTurf = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getListId', $e);
      return 0;
    }
    return (empty($dbTurf)) ? 0 : $dbTurf['listId'];
  }

  /**
   * getTurfByListId
   *
   * Returns a turf record for a specific listId.  The listId is the ID of the turf in VoteBuilder.
   *
   * @param int $listId
   * @return array
   */
  public function getTurfByListId(int $listId): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->fields('i');
      $query->condition('listId', $listId);
      $result = $query->execute();
      $dbTurf = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfByListId', $e);
      return [];
    }
    if (empty($dbTurf)) { return []; }
    return $dbTurf;
  }

  /**
   * getTurfsById
   *
   * Returns an array of turfs for a specific MCID.
   *
   * @param int $mcid
   * @return array
   */
  public function getTurfsById(int $mcid): array
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
      nlp_debug_msg('getTurfById', $e);
      return [];
    }
    if (empty($result)) { return []; }

    $dbTurfs = [];
    do {
      $dbTurf = $result->fetchAssoc();
      if (empty($dbTurf)) { break; }
      $dbTurfs[] = $dbTurf;
    } while (TRUE);
    return $dbTurfs;
  }

  /**
   * getDoorCounts
   *
   * Returns the total door count of each NL's turfs with one query.
   *
   * @param array $mcids
   * @return array - door counts keyed by mcid.  An NL with no turfs is not
   *   in the array.
   */
  public function getDoorCounts(array $mcids): array
  {
    if (empty($mcids)) { return []; }
    try {
      $query = $this->connection->select(self::TURF_TBL, 'i');
      $query->addField('i', 'mcid');
      $query->addExpression('SUM(i.doorCount)', 'doorCount');
      $query->condition('i.mcid', $mcids, 'IN');
      $query->groupBy('i.mcid');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDoorCounts', $e);
      return [];
    }
    $doorCounts = [];
    while ($row = $result->fetchAssoc()) {
      $doorCounts[$row['mcid']] = (int) $row['doorCount'];
    }
    return $doorCounts;
  }

  public function getTurfAssignment(int $turfId): array
  {
    return $this->turfAssignmentsObj->getTurfAssignment($turfId);
  }

  /**
   * updateTurf
   *
   * Updates a specific field in a turf record.
   *
   * @param int $turfIndex
   * @param string $field
   * @param mixed $value
   * @return void
   */
  public function updateTurf(int $turfIndex, string $field, mixed $value): void
  {
    try {
      $this->connection->update(self::TURF_TBL)
        ->fields([$field => $value])
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateTurf', $e);
    }
  }

  /**
   * updateTurfFields
   *
   * Updates multiple fields in a turf record.
   *
   * @param int $turfIndex
   * @param array $fields
   * @return void
   */
  public function updateTurfFields(int $turfIndex, array $fields): void
  {
    try {
      $this->connection->update(self::TURF_TBL)
        ->fields($fields)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateTurfFields', $e);
    }
  }

  /**
   * reassignNlTurfs
   *
   * Moves every turf owned by an NL to a new MCID. Unlike getTurfsById, this
   * does not need the NL's nlp_nls record, which an NLs sync removes once
   * VoteBuilder has merged the old MCID away.
   *
   * @param int $previousMcid
   * @param int $newMcid
   * @return void
   */
  public function reassignNlTurfs(int $previousMcid, int $newMcid): void
  {
    try {
      $this->connection->update(self::TURF_TBL)
        ->fields(['mcid' => $newMcid])
        ->condition('mcid', $previousMcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('reassignNlTurfs', $e);
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
      nlp_debug_msg('createTurf', $e);
      return 0;
    }
    return $turfIndex;
  }

  /**
   * getTurfHD
   *
   * Returns an indexed array of unique house districts with assigned turf
   * for a specific county.
   *
   * @param string $county
   * @return array - An indexed array of unique house districts.
   */
  public function getTurfHD(string $county): array
  {
    $hdList = $this->getTurfHdNames($county);
    return array_values($hdList);
  }

  /**
   * getTurfHdNames
   *
   * Returns an associative array of unique house districts with assigned turf
   * for a specific county.
   *
   * @param string $county
   * @return array - An associative array of unique house districts.
   */
  public function getTurfHdNames(string $county): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'turfHd');
      $query->condition('t.county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfHdNames', $e);
      return [];
    }
    $hdNameList = [];
    do {
      $hdRecord = $result->fetchAssoc();
      if (empty($hdRecord)) { break; }
      $hd = $hdRecord['turfHd'];
      // HD can't select turfs synced without an HD (0 or NULL); if kept,
      // they sort first and become the default selection, which shows no turfs.
      if (empty($hd)) { continue; }
      $hdNameList[$hd] = $hd;
    } while (TRUE);
    if (empty($hdNameList)) { return []; }
    ksort($hdNameList);
    return $hdNameList;
  }

  /**
   * getTurfPrecinctNames
   *
   * Returns an associative array of unique precinct names for a specific county,
   * optionally filtered by house district.
   *
   * @param string $county
   * @param int $hd - The house district.
   * @return array - An associative array of unique precinct names.
   */
  public function getTurfPrecinctNames(string $county, int $hd = 0): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'turfPrecinct');
      $query->condition('t.county', $county);
      if(!empty($hd)) {
        $query->condition('t.turfHd', $hd);
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfPrecinctNames', $e);
      return [];
    }
    $precinctList = [];
    do {
      $precinctRecord = $result->fetchAssoc();
      if (empty($precinctRecord)) { break; }
      $precinctName = $precinctRecord['turfPrecinct'];
      $precinctList[$precinctName] = $precinctName;
    } while (TRUE);
    if (empty($precinctList)) { return []; }
    ksort($precinctList);
    return $precinctList;
  }

  /**
   * getTurfs
   *
   * Returns an array of turfs for a specific county and house district.
   * If the house district is 0 or 'All', then all turfs for the county are returned.
   *
   * By default, only turfs assigned to an NL in the NLs table are returned.  With
   * $includeRemovedNls, turfs whose NL has been removed from the list of active
   * NLs are returned too (the NL columns are NULL), so they can still be deleted.
   *
   * @param string $county - The name of the county.
   * @param int|string $hd - The house district, or 0 or 'All' for all HDs.
   * @param bool $includeRemovedNls - Include turfs whose NL is not in the NLs table.
   * @return array - An associative array of turf records.
   */
  public function getTurfs(string $county, int|string $hd, bool $includeRemovedNls = FALSE): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->fields('t');
      if ($includeRemovedNls) {
        $query->leftJoin($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
        // Name the NL columns rather than n.*: mcid and county are in both tables,
        // and the NULLs from a missing NL would overwrite the turf's own values.
        $query->fields('n', ['lastName', 'firstName', 'middleName', 'nickname', 'hd',
          'precinct', 'address', 'email', 'phone', 'homePhone', 'homePhoneId',
          'cellPhone', 'cellPhoneId', 'userName', 'notRegistered', 'vfid']);
      } else {
        $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
        $query->fields('n');
      }
      $query->condition('t.county', $county);
      if (!empty($hd) && $hd != 'All') {
        $query->condition('t.turfHd', $hd);
      }
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfs', $e);
      return [];
    }

    $turfArray = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) { break; }
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
      $query->addField('t', 'turfIndex');
      $query->condition('t.county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfIndexes', $e);
      return [];
    }
    $turfIndexes = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) { break; }
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
      nlp_debug_msg('getCountyNames', $e);
      return [];
    }

    $countyNames = [];
    do {
      $countyName = $result->fetchAssoc();
      if (empty($countyName)) { break; }
      $countyNames[] = $countyName['county'];
    } while (TRUE);
    return $countyNames;
  }

  /**
   * getSyncedCountyNames
   *
   * Returns an indexed array of unique county names that have at least one
   * synced turf (syncDate is not NULL).
   *
   * @return array - An indexed array of county names.
   */
  public function getSyncedCountyNames(): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->addField('t', 'county');
      $query->distinct();
      $query->isNotNull('t.syncDate');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getSyncedCountyNames', $e);
      return [];
    }

    $countyNames = [];
    do {
      $countyName = $result->fetchAssoc();
      if (empty($countyName)) { break; }
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
      nlp_debug_msg('removeTurf', $e);
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
      nlp_debug_msg('removeTurf', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * deleteTurf
   *
   * Deletes a turf and its associated voters and activist codes.
   *
   * The turf record is removed last.  If anything fails part way (the activist code
   * API is slow), the turf record is still there, so the turf stays visible, and the
   * delete method can simply be repeated.  Removing the turf record first would orphan the
   * voter records, which then block any later sync of those voters.
   *
   * @param array $turf - An associative array containing turf information.
   * @return bool - Returns TRUE on success, FALSE on failure.
   */
  public function deleteTurf(array $turf): bool
  {
    $county = $turf['county'];
    $turfIndex = $turf['turfIndex'];
    $mcid = $turf['mcid'];

    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    $vanids = array_keys($voters);

    // remove the list of voters.
    $this->votersObj->deleteVotersInTurf($turfIndex);
    $this->votersObj->deleteAddressesInTurf($turfIndex);

    // A voter who is also in another turf keeps NLPVoterAC.
    $inOtherTurfs = $this->votersObj->getVanidsInTurfs($vanids);
    $toRemove = array_values(array_diff($vanids, $inOtherTurfs));
    $this->apiActivistCodesObj->setApiActivistCodeBatch($toRemove, 'NLPVoterAC', 'Remove', "turf delete (turfIndex $turfIndex)");

    $status = $this->removeTurf($turf);
    if (!$status) { return FALSE; }
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
   * Returns an associative array containing turf information if it exists,
   * otherwise returns an empty array.  The keys to the associative array are:
   * - 'turfs': An array of turfs indexed by turfIndex assigned to this NL.
   * - 'turfCnt': The count of turfs found.
   * - 'turfIndex': The index of the first turf found.
   *
   * @param int $mcid
   * @param string $county
   * @return array - An associative array containing turf information if it exists, otherwise an empty array.
   */
  public function turfExists(int $mcid, string $county): array
  {
    $query = $this->connection->select(self::TURF_TBL, 'i');
    $query->fields('i');
    $query->condition('county', $county);
    $query->condition('mcid', $mcid);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('turfExists', $e);
      return [];
    }
    $turfs = [];
    do {
      $dbTurf = $result->fetchAssoc();
      if (empty($dbTurf)) { break; }
      $turfIndex = $dbTurf['turfIndex'];
      $turfs[$turfIndex] = $dbTurf;
    } while (TRUE);
    if (empty($turfs)) { return []; }
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
   * @param int $folderId
   * @param string $turfName
   * @return array - An associative array containing turf information if it exists, otherwise an empty array.
   */
  public function getTurfByTurfName(int $folderId, string $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->fields('t');
      $query->condition('t.folderId', $folderId);
      $query->condition('t.turfName', $turfName);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfByTurfName', $e);
      return [];
    }
    $assignment = $result->fetchAssoc();
    if (empty($assignment)) { return []; }
    return $assignment;
  }

  /**
   * getTurfsForSync
   *
   * Returns every turf record that corresponds to a VoteBuilder turf, matched either by
   * the VoteBuilder list ID or by folder and turf name (the list ID changes when a map
   * region is refreshed).  Used by the turf sync to find a prior assignment or the
   * residue of a failed sync of the same turf.
   *
   * @param int $folderId
   * @param int $listId - The VoteBuilder turf (saved list) ID.
   * @param string $turfName
   * @return array - Turf records keyed by turfIndex.
   */
  public function getTurfsForSync(int $folderId, int $listId, string $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->fields('t');
      $byName = $query->andConditionGroup()
        ->condition('t.folderId', $folderId)
        ->condition('t.turfName', $turfName);
      $match = $query->orConditionGroup()
        ->condition('t.listId', $listId)
        ->condition($byName);
      $query->condition($match);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfsForSync', $e);
      return [];
    }
    $turfs = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) { break; }
      $turfs[$turf['turfIndex']] = $turf;
    } while (TRUE);
    return $turfs;
  }

  /**
   * isIncompleteTurf
   *
   * A turf record left behind by a sync that failed part way.  Earlier versions of the
   * turf sync created the turf record first and filled in the NL name and districts only
   * after every voter was processed, so a failed sync left a record with neither.  Such a
   * record is not a real assignment and is replaced when the same turf is synced again.
   *
   * @param array $turf - A turf record.
   * @return bool
   */
  public function isIncompleteTurf(array $turf): bool
  {
    return empty($turf['nlFirstName']) && empty($turf['nlLastName']) && empty($turf['turfHd']);
  }

  /**
   * createTurfNames
   *
   * Creates an indexed array of turf names in the provided array of turfs.
   *
   * @param array $turfArray - An associative array containing turfs.
   * @return array - An indexed array of turf names.
   */
  public function createTurfNames(array $turfArray): array
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
      nlp_debug_msg('getStaleTurfs', $e);
      return [];
    }
    $turfIndexes = [];
    $batch = [];
    do {
      $turf = $result->fetchAssoc();
      if (empty($turf)) { break; }
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
   * Checks if the turfId has changed for a given turf and updates the assignment if necessary.
   *
   * @param int $folderId - The folder ID where the turf is assigned.
   * @param array $turf - An associative array containing turf information.
   * @return void
   */
  public function checkTurfIdChange(int $folderId, array $turf): void
  {
    $previousTurfAssignment = $this->turfAssignmentsObj->getTurfAssignmentByTurfName($folderId, $turf['turfName']);
    $turfId = $turf['turfId'];
    $turfName = $turf['turfName'];
    // Detect if the turf is in use or if the Map Region was altered.   Fix the turfId in NLP Services if
    // there is a new turfID assigned to the turf.
    if (!empty($previousTurfAssignment)) {
      if ($previousTurfAssignment['turfId'] != $turfId) {
        $this->turfAssignmentsObj->fixTurfAssignment($previousTurfAssignment['turfId'], $turfId);
      }
    }
    $alteredTurf = $this->getTurfByTurfName($folderId, $turfName);
    if (!empty($alteredTurf) && $alteredTurf['listId'] != $turfId) {
      $this->updateTurf($alteredTurf['turfIndex'], 'listId', $turfId);
    }
  }

  /**
   * Checks if a turf is currently assigned to an NL or has been previously assigned.
   *
   * @param int $folderId - The folder ID where the turf is assigned.
   * @param int $turfId - The ID of the turf in VoteBuilder.
   * @param string $turfName - The name of the turf.
   * @return array - An associative array containing information about the turf in use.
   */
  public function turfInUse(int $folderId, int $turfId, string $turfName): array
  {
    $turfInUse = [
      'nl' => '',
      'mcid' => '',
      'turfAssigned' => '',
    ];
    $turf = $this->getTurfByListId($turfId);
    if(empty($turf)) {
      $turf = $this->getTurfByTurfName($folderId, $turfName);
    }
    if(!empty($turf) && $this->isIncompleteTurf($turf)) {
      // Residue of a failed sync.  Leave the turf selectable; syncing it again
      // replaces the residue.
      $turfInUse = [
        'nl' => '<span class="nlp-footnote-warning">Incomplete sync, repeat the sync to repair</span>',
        'mcid' => $turf['mcid'],
        'turfAssigned' => '',
      ];
    } elseif(!empty($turf)) {
      $turfInUse = [
        'nl' => $turf['nlFirstName'] . ' ' . $turf['nlLastName'],
        'mcid' => $turf['mcid'],
        'turfAssigned' => 'X',
      ];
    } else {
      // The turf known to VoteBuilder has not yet been assigned to an NL.   So, check if the turfID known
      // to VoteBuilder matches a turf previously assigned to an NL.
      $previousTurfAssignment = $this->turfAssignmentsObj->getTurfAssignment($turfId);
      if(empty($previousTurfAssignment)) {
        $previousTurfAssignment = $this->turfAssignmentsObj->getTurfAssignmentByTurfName($folderId, $turfName);
        if(!empty($previousTurfAssignment)) {
          // Update the assignment record.
          $previousTurfAssignment['turfId'] = $turfId;
          $this->turfAssignmentsObj->deleteTurfAssignment($turfId);
          $this->turfAssignmentsObj->setTurfAssignment($previousTurfAssignment);
        }
      }

      if(!empty($previousTurfAssignment)) {
        $nls = $this->nlsObj->getNlById($previousTurfAssignment['mcid']);
        $footnote = (!empty($nls)) ? '*' : '**';
        $footnote = '<span class="nlp-footnote-warning">' . $footnote . '</span>';
        $turfInUse = [
          'nl' => $previousTurfAssignment['firstName'] . ' ' . $previousTurfAssignment['lastName'] . $footnote,
          'mcid' => $previousTurfAssignment['mcid'],
          'hintPrevious' => $previousTurfAssignment['mcid'],
          'turfAssigned' => '',
        ];
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
      nlp_debug_msg('unreportedChanges', $e);
      return FALSE;
    }

    $dbTurf = $result->fetchAssoc();
    if (empty($dbTurf)) { return FALSE; }
    return (!empty($dbTurf['unreportedChanges'])) ? $dbTurf['unreportedChanges'] : FALSE;
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
      nlp_debug_msg('getChangeHistory', $e);
      return [];
    }
    $dbTurf = $result->fetchAssoc();
    if(empty($dbTurf['changeHistory'])) { return []; }
    $changeHistory = json_decode($dbTurf['changeHistory'], TRUE);
    if(json_last_error() !== JSON_ERROR_NONE) { return []; }
    return $changeHistory;
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
      nlp_debug_msg('getMinivanInfo', $e);
      return [];
    }
    $dbTurf = $result->fetchAssoc();
    if(empty($dbTurf)) { return []; }
    return $dbTurf;
  }

  /**
   * getVoterCounts
   *
   * Get a count of voters with the filters for type, scope, and having voted.
   *
   * @param string $type - Possible keys: CD, County, NL, Turf.
   * @param mixed $value - the value of the type.
   * @param string $scope - Possible keys: All, Attempted, Contacted, Postcard.
   * @param bool $voted - If TRUE, only return the count of voters who have turned in a ballot.
   * @return int - A count of the voters meeting the criteria.
   */
  public function getVoterCounts(string $type, mixed $value, string $scope, bool $voted = FALSE): int
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
        $query->condition('r.cycle', $cycle);

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
      $query->addField('g', 'vanid');
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
    } catch (Exception $e) {
      nlp_debug_msg('getVotersInCounty', $e);
      return 0;
    }
    return (int) $voterCount;
  }

  /**
   * getCountyTurfVoterStats
   *
   * For a given county, counts voters assigned to NLP turfs and breaks them
   * down by voted status, contact attempts, and survey responses.
   *
   * Returns an array with keys:
   *   `total` - all voters in county turfs
   *   `totalVoted` - of those, how many have turned in a ballot
   *   `contacted` - voters who had at least one contact attempt (contact or survey)
   *   `contactedVoted` - of those, how many voted
   *   `surveyed` - voters who answered the survey question
   *   `surveyedVoted` - of those, how many voted
   *
   * @param string $county - The county name.
   * @return array<string,int>
   */
  public function getCountyTurfVoterStats(string $county): array
  {
    $cycle = $this->nlpConfigurationObj->getCycle();
    $stats = [
      'total'          => 0,
      'totalVoted'     => 0,
      'contacted'      => 0,
      'contactedVoted' => 0,
      'surveyed'       => 0,
      'surveyedVoted'  => 0,
    ];
    try {
      // Total voters assigned to turfs in the county.
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->distinct();
      $stats['total'] = (int) $query->countQuery()->execute()->fetchField();

      // Total who have turned in a ballot.
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->leftJoin($this->nlpMatchbacksObj::MATCHBACK_TBL, 'm', 'm.vanid = g.vanid');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->isNotNull('m.date');
      $query->distinct();
      $stats['totalVoted'] = (int) $query->countQuery()->execute()->fetchField();

      // Voters who had at least one contact attempt (contact or survey record).
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->join($this->nlpReportsObj::NLP_RESULTS_TBL, 'r', 'r.vanid = g.vanid');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->condition('r.cycle', $cycle);
      $contactGroup = $query->orConditionGroup()
        ->condition('r.type', $this->nlpReportsObj::SURVEY)
        ->condition('r.type', $this->nlpReportsObj::CONTACT);
      $query->condition($contactGroup);
      $query->distinct();
      $stats['contacted'] = (int) $query->countQuery()->execute()->fetchField();

      // Of those with a contact attempt, how many voted.
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->join($this->nlpReportsObj::NLP_RESULTS_TBL, 'r', 'r.vanid = g.vanid');
      $query->leftJoin($this->nlpMatchbacksObj::MATCHBACK_TBL, 'm', 'm.vanid = g.vanid');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->condition('r.cycle', $cycle);
      $contactGroup = $query->orConditionGroup()
        ->condition('r.type', $this->nlpReportsObj::SURVEY)
        ->condition('r.type', $this->nlpReportsObj::CONTACT);
      $query->condition($contactGroup);
      $query->isNotNull('m.date');
      $query->distinct();
      $stats['contactedVoted'] = (int) $query->countQuery()->execute()->fetchField();

      // Voters who responded to the survey question.
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->join($this->nlpReportsObj::NLP_RESULTS_TBL, 'r', 'r.vanid = g.vanid');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->condition('r.cycle', $cycle);
      $query->condition('r.type', $this->nlpReportsObj::SURVEY);
      $query->distinct();
      $stats['surveyed'] = (int) $query->countQuery()->execute()->fetchField();

      // Of those who answered the survey, how many voted.
      $query = $this->connection->select($this->votersObj::VOTER_TURF_TBL, 'g');
      $query->join($this->nlpReportsObj::NLP_RESULTS_TBL, 'r', 'r.vanid = g.vanid');
      $query->leftJoin($this->nlpMatchbacksObj::MATCHBACK_TBL, 'm', 'm.vanid = g.vanid');
      $query->addField('g', 'vanid');
      $query->condition('g.county', $county);
      $query->condition('r.cycle', $cycle);
      $query->condition('r.type', $this->nlpReportsObj::SURVEY);
      $query->isNotNull('m.date');
      $query->distinct();
      $stats['surveyedVoted'] = (int) $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyTurfVoterStats', $e);
    }
    return $stats;
  }

  /**
   * countOfNlsWithTurfsInCd
   *
   * Get the number of NLs in a CD with turfs.
   *
   * @param int $cd - The CD to check.
   * @return int - The number of NLS in the CD that have turfs.
   */
  public function countOfNlsWithTurfsInCd(int $cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_TBL, 'n', 'n.mcid = t.mcid');
      $query->addField('n', 'mcid');
      $query->condition('t.turfCd', $cd);
      $query->distinct();
      $count = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('countOfNlsWithTurfsInCd', $e);
      return 0;
    }
    return (int) $count;
  }

  /**
   * countOfNlsInCdThatReportedResults
   *
   * Get the number of NLs in a CD that have reported results.
   *
   * @param int $cd - The CD to check.
   * @return int - The number of NLS in the CD that have reported results.
   */
  public function countOfNlsInCdThatReportedResults(int $cd): int
  {
    try {
      $query = $this->connection->select(self::TURF_TBL, 't');
      $query->join($this->nlsObj::NLS_STATUS_TBL, 'n', 'n.mcid = t.mcid');
      $query->addField('n', 'mcid');
      $query->condition('t.turfCd', $cd);
      $query->condition('n.resultsReported', 'Y');
      $query->distinct();
      $count = $query->countQuery()->execute()->fetchField();
    } catch (Exception $e) {
      nlp_debug_msg('countOfNlsInCdThatReportedResults', $e);
      return 0;
    }
    return (int) $count;
  }

}
