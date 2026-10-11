<?php

namespace Drupal\nlpservices;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpNls {
  
  const string NLS_TBL = 'nlp_nls';
  const string NLS_STATUS_TBL = 'nlp_nls_status';
  const string CANVASS = 'canvass';
  const string MINIVAN = 'minivan';
  const string PHONE = 'phone';
  const string MAIL = 'mail';
  const int NOTES_MAX = 250;   // Notes max length of the note.
  const int NLS_ALL_MAX = 200;
  public array $contactList = [self::CANVASS, self::MINIVAN, self::PHONE, self::MAIL,];
  const string DASH = '-';
  const string ASKED = 'Asked';
  const string YES = 'Yes';
  const string NO = 'Not this election';
  const string QUIT = 'Dropout';
  const string DNR = 'Did not respond';
  public array $askOptions = [
    'dash' => self::DASH,
    'asked' => self::ASKED,
    'yes' => self::YES,
    'no' => self::NO,
    'quit' => self::QUIT,
    'dnr' => self::DNR,
  ];
  public array $askList = [];
  
  protected Connection $connection;
  protected DatabaseFunctions $dbFunctionsObj;
  public function __construct( $connection, $dbFunctionsObj, ConfigFactoryInterface $configFactory) {
    $this->connection = $connection;
    $this->dbFunctionsObj = $dbFunctionsObj;
    $nlpConfig = $configFactory->get('nlpservices.configuration');
    $surveyQuestions = $nlpConfig->get('nlpservices_survey_questions');
    if(empty($surveyQuestions['askList'])) {
      $this->askList = $this->askOptions;
      return;
    }
    $this->askList = $surveyQuestions['askList'];
  }

  /**
   * @param ContainerInterface $container
   * @return NlpNls
   */
  public static function create(ContainerInterface $container): NlpNls
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.database_functions'),
      $container->get('config.factory'),
    );
  }
  
  /**
   * deleteNls
   *
   * Delete all NL records for a given county.
   *
   * @param string $county
   * @return void
   */
  public function deleteNls(string $county): void
  {
    try {
      $this->connection->delete(self::NLS_TBL)
        ->condition('county', $county)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('deleteNls', $e);
      return;
    }
  }
  
  /**
   * deleteNl
   *
   * Delete a single NL record by mcid.
   *
   * @param int $mcid
   * @return void
   */
  public function deleteNl(int $mcid): void
  {
    try {
      $this->connection->delete(self::NLS_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteNl', $e);
    }
  }
  
  /**
   * createNl
   *
   * Create a new NL record.
   *
   * @param array $nlRecord
   * @return bool
   */
  public function createNl(array $nlRecord): bool
  {
    unset($nlRecord['zip']);   // The ZIP is not saved for now.
    $this->deleteNl($nlRecord['mcid']);
    try {
      $this->connection->insert(self::NLS_TBL)
        ->fields($nlRecord)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('createNl', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * getNlsStatus
   *
   * Get the status record of a NL by mcid and county.
   * If no status record exists, return an empty record with default values.
   *
   * @param int $mcid
   * @param string $county
   * @return array
   */
  public function getNlsStatus(int $mcid,string $county=''): array
  {
    try {
      $query = $this->connection->select(self::NLS_STATUS_TBL, 's');
      $query->fields('s');
      if(!empty($county)) {
        $query->condition('county',$county);
      }
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlsStatus', $e);
      return [];
    }
    $nlStatus = $result->fetchAssoc();
    if(empty($nlStatus)) {
      $statusColumnNames = $this->dbFunctionsObj->getColumnNames(self::NLS_STATUS_TBL);
      $nlStatus = [];
      foreach ($statusColumnNames as $nlpKey) {
        $nlStatus[$nlpKey] = NULL;
      }
      $nlStatus['mcid'] = $mcid;
      $nlStatus['county'] = $county;
      $nlStatus['contact'] = self::CANVASS;
      $nlStatus['asked'] = self::DASH;
    }
    return $nlStatus;
  }
  
  /**
   * setNlsStatus
   *
   * Set the status record of a NL by mcid.
   * If no status record exists, create a new one.
   *
   * @param array $status
   * @return bool
   */
  public function setNlsStatus(array $status): bool
  {
    try {
      $this->connection->merge(self::NLS_STATUS_TBL)
        ->fields($status)
        ->keys([
          'mcid' => $status['mcid'],
          'county' => $status['county']])
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('setNlsStatus', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * deleteNlsStatus
   *
   * Delete the status record of a NL by mcid.
   *
   * @param int $mcid
   * @return void
   */
  public function deleteNlsStatus(int $mcid): void
  {
    try {
      $this->connection->delete(self::NLS_STATUS_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteNlsStatus', $e);
    }
  }
  
  /**
   * getNls
   *
   * Get all NL records for a given county and HD.
   * If HD is 'All', get all NLs for the county.
   * If precinct is provided, filter by precinct as well.
   *
   * The resultant array is keyed by mcid, with each value being an
   * associative array of NL information.
   *
   * The array is sorted by HD, precinct, last name, and nickname.
   *
   * @param string $county
   * @param mixed $hd
   * @param string $precinct
   * @return array
   */
  public function getNls(string $county, mixed $hd, string $precinct=''): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('n.county',$county);
      if ($hd != 'All' && !empty($hd)) {
        $query->condition('n.hd',$hd);
      }
      if (!empty($precinct)) {
        $query->condition('n.precinct',$precinct);
      }
      $query->orderBy('hd');
      $query->orderBy('precinct');
      $query->orderBy('lastName');
      $query->orderBy('nickname');
      $result =  $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNls', $e);
      return [];
    }
    // Fetch each NL record and build the array of information about each NL
    // needed to build the display table.
    $nlRecords = [];
    while ($nlRecord = $result->fetchAssoc()) {
      $mcid = $nlRecord['mcid'];
      $nlRecord['status'] = $this->getNlsStatus($mcid,$county);
      $nlRecords[$mcid] = $nlRecord;
    }
    return $nlRecords;
  }

  /**
   * getHdList
   *
   * Get a list of distinct HD numbers for a given county.
   * The list is ordered numerically.
   *
   * @param string $county
   * @return array
   */
  public function getHdList(string $county): array
  {
    // Get the list of distinct HD numbers for this group, order numerically.
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->addField('n', 'hd');
      $query->distinct();
      $query->condition('n.county',$county);
      $query->orderBy('hd');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getHdList', $e);
      return [];
    }
    
    $hdOptions = [];
    while ($hdRecord = $result->fetchAssoc()) {
      $hdOptions[] = $hdRecord['hd'];
    }
    return $hdOptions;
  }
  
  /**
   * getPrecinctsByMcid
   *
   * Get the precinct of every NL in a county in one query, keyed by mcid.
   * Used to narrow lists of Drupal users (whose accounts have no precinct) by precinct.
   *
   * @param string $county
   * @return array - mcid => precinct.
   */
  public function getPrecinctsByMcid(string $county): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n', ['mcid', 'precinct']);
      $query->condition('n.county',$county);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getPrecinctsByMcid', $e);
      return [];
    }
    $precincts = [];
    while ($nlRecord = $result->fetchAssoc()) {
      $precincts[$nlRecord['mcid']] = $nlRecord['precinct'];
    }
    return $precincts;
  }

  /**
   * getPctList
   *
   * Get a list of distinct precinct numbers for a given county and HD.
   * If HD is 0, get all precincts for the county.
   * The list is ordered numerically by precinct number.
   *
   * @param string $county
   * @param int $hd
   * @return array
   */
  public function getPctList(string $county, int $hd=0): array
  {
    // Get the list of precinct numbers with at least one prospective NL in
    // this HD and order numerically by precinct number.
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->addField('n', 'precinct');
      $query->distinct();
      $query->condition('n.county',$county);
      if(!empty($hd)) {
        $query->condition('hd',$hd);
      }
      $query->orderBy('precinct');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getPctList', $e);
      return [];
    }
    $pctOptions = [];
    while ($pct = $result->fetchAssoc()) {
      $pctOptions[] = $pct['precinct'];
    }
    return $pctOptions;
  }
  
  /**
   * getNlById
   *
   * Get a single NL record by mcid.
   * Returns an associative array of NL information.
   *
   * @param int|null $mcid
   * @return array
   */
  public function getNlById(int|null $mcid): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlById', $e);
      return [];
    }
    $nlRecord = $result->fetchAssoc();
    if(empty($nlRecord)) return [];
    return $nlRecord;
  }
  
  /**
   * getCountyNls
   *
   * Get all NL records for a given county and HD.
   * If HD is 'All', get all NLs for the county.
   * Returns an associative array of NL information keyed by mcid.
   *
   * @param string $county
   * @param int|string $hd
   * @return array
   */
  public function getCountyNls(string $county, int|string $hd='All'): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->join(self::NLS_STATUS_TBL, 's', 'n.mcid = s.mcid');
      $query->fields('n');
      $query->fields('s');
      $query->condition('n.'.'county',$county);
      if(!empty($hd) && $hd!='All' ) {
        $query->condition('n.'.'hd',$hd);
      }
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getCountyNls', $e);
      return [];
    }
    $nlRecords = [];
    while ($nlRecord = $result->fetchAssoc()) {
      $nlRecords[$nlRecord['mcid']] = $nlRecord;
    }
    return $nlRecords;
  }

  /**
   * resultsReported
   *
   * Check if results have been reported for a given mcid and county.
   * If not, update the status to indicate results have been reported.
   *
   * @param int $mcid
   * @param string $county
   * @return bool
   */
  public function resultsReported(int $mcid,string $county): bool
  {
    try {
      $query = $this->connection->select(self::NLS_STATUS_TBL, 's');
      $query->addField('s', 'resultsReported');
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('resultsReported', $e);
      return FALSE;
    }
    $resultsReported = $result->fetchAssoc();
    if(!empty($resultsReported['resultsReported'])) {
      return TRUE;
    }
    try {
      $this->connection->update(self::NLS_STATUS_TBL)
        ->fields(['resultsReported' => 'Y',])
        ->condition('mcid',$mcid)
        ->condition('county',$county)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('resultsReported', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * updateNlStatus
   *
   * Update the status record of a NL by mcid.
   * The $status array should contain the fields to be updated.
   *
   * @param int $mcid
   * @param array $status
   * @return void
   */
  public function updateNlStatus(int $mcid,array $status): void
  {
    try {
      $this->connection->update(self::NLS_STATUS_TBL)
        ->fields($status)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateNlStatus', $e);
    }
  }
  
  /**
   * searchNls
   *
   * Search for NLs by a needle fragment across lastName, firstName, nickname, email, and mcid.
   * Filter by county (with status attached) or by an explicit mcids list.
   * Returns an associative array of NL records keyed by mcid.
   *
   * @param string $needle
   * @param string $county - filter by county and attach status to each record.
   * @param array $mcids - filter by this set of mcids instead.
   * @return array
   */
  public function searchNls(string $needle, string $county = '', array $mcids = []): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      if(!empty($county)) {
        $query->condition('county', $county);
      } elseif (!empty($mcids)) {
        $query->condition('mcid', $mcids, 'IN');
      }
      $orGroup = $query->orConditionGroup()
        ->condition('lastName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('firstName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('nickname', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('email', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('mcid', "%" . $query->escapeLike($needle) . "%", 'LIKE');
      $query->condition($orGroup);
      $query->orderBy('lastName');
      $query->orderBy('nickname');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('searchNls', $e);
      return [];
    }
    $nlRecords = [];
    while ($nlRecord = $result->fetchAssoc()) {
      $mcid = $nlRecord['mcid'];
      if(!empty($county)) {
        $nlRecord['status'] = $this->getNlsStatus($mcid, $county);
      }
      $nlRecords[$mcid] = $nlRecord;
    }
    return $nlRecords;
  }

  /**
   * getNlsByIds
   *
   * Get NL records by an array of mcids.
   * If hd or precinct is provided, filter by those as well.
   * Returns an associative array of NL records keyed by mcid.
   *
   * @param array $mcids
   * @param int $hd
   * @param string $precinct
   * @return array
   */
  public function getNlsByIds(array $mcids, int $hd=0, string $precinct=''): array
  {
    if (empty($mcids)) {return [];}
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('mcid', $mcids, 'IN');
      if(!empty($precinct)) {
        $query->condition('precinct', $precinct);
      } elseif (!empty($hd)) {
        $query->condition('hd', $hd);
      }
      $query->orderBy('hd');
      $query->orderBy('lastName');
      $query->orderBy('firstName');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getNlsByIds', $e);
      return [];
    }
    $nlRecords = [];
    while ($nlRecord = $result->fetchAssoc()) {
      $nlRecords[$nlRecord['mcid']] = $nlRecord;
    }
    return $nlRecords;
  }
  
  /**
   * getDistinctHdByMcids
   *
   * Get a list of distinct HD values for the given mcid array.
   * The list is ordered numerically.
   *
   * @param array $mcids
   * @return array
   */
  public function getDistinctHdByMcids(array $mcids): array
  {
    if (empty($mcids)) {
      return [];
    }
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->addField('n', 'hd');
      $query->distinct();
      $query->condition('mcid', $mcids, 'IN');
      $query->orderBy('hd');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDistinctHdByMcids', $e);
      return [];
    }
    $hdList = [];
    while ($row = $result->fetchAssoc()) {
      $hdList[] = $row['hd'];
    }
    return $hdList;
  }
  
  /**
   * getDistinctPrecinctByMcids
   *
   * Get a list of distinct precinct values for the given mcid array.
   * The list is ordered numerically.
   *
   * @param array $mcids
   * @return array
   */
  public function getDistinctPrecinctByMcids(array $mcids): array
  {
    if (empty($mcids)) {
      return [];
    }
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->addField('n', 'precinct');
      $query->distinct();
      $query->condition('mcid', $mcids, 'IN');
      $query->orderBy('precinct');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDistinctPrecinctByMcids', $e);
      return [];
    }
    $precinctList = [];
    while ($row = $result->fetchAssoc()) {
      $precinctList[] = $row['precinct'];
    }
    return $precinctList;
  }
  
  /**
   * getDistinctHdPrecinctArrayByMcids
   *
   * Get a list of distinct hd and precinct values for the given mcid array.
   * Returns an array with hd as keys and precincts as values.
   *
   * @param array $mcids
   * @return array
   */
  public function getDistinctHdPrecinctArrayByMcids(array $mcids): array
  {
    if (empty($mcids)) {return [];}
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->addField('n', 'hd');
      $query->addField('n', 'precinct');
      $query->distinct();
      $query->condition('mcid', $mcids, 'IN');
      $query->orderBy('precinct');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDistinctHdPrecinctArrayByMcids', $e);
      return [];
    }
    $precinctArray = [];
    while ($row = $result->fetchAssoc()) {
      $precinctArray[$row['hd']][$row['precinct']] = $row['precinct'];
    }
    return $precinctArray;
  }
  
  /**
   * getCountyNlsParticipation
   *
   * Get the number of NLs signed up and results reported for a county.
   * Returns an array with keys 'participatingNls' and 'resultsReported'.
   *
   * @param string $county
   * @return array
   */
  public function getCountyNlsParticipation(string $county): array
  {
    try {
      $query = $this->connection->select(self::NLS_STATUS_TBL, 's');
      $query->addExpression('SUM(CASE WHEN s.signedUp = \'Y\' THEN 1 ELSE 0 END)', 'participatingNls');
      $query->addExpression('SUM(CASE WHEN s.resultsReported = \'Y\' THEN 1 ELSE 0 END)', 'resultsReported');
      $query->condition('s.county', $county);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountyNlsParticipation', $e);
      return [];
    }
    return $result->fetchAssoc();
  }
  
}
