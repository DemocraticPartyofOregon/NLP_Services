<?php

namespace Drupal\nlpservices;

use Drupal;
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
  public array $contactList = array(self::CANVASS,self::MINIVAN,self::PHONE,self::MAIL,);
  const string DASH = '-';
  const string ASKED = 'Asked';
  const string YES = 'Yes';
  const string NO = 'Not this election';
  const string QUIT = 'Dropout';
  const string DNR = 'Did not respond';
  public array $askOptions = array(
    'dash' => self::DASH,
    'asked' => self::ASKED,
    'yes' => self::YES,
    'no' => self::NO,
    'quit' => self::QUIT,
    'dnr' => self::DNR);
  public array $askList = [];
  
  protected Connection $connection;
  public function __construct( $connection) {
    $this->connection = $connection;
    $nlpConfig = Drupal::service('config.factory')->get('nlpservices.configuration');
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
      //$container->get('nlpservices.awards'),
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
      nlp_debug_msg('deleteNls', $e->getMessage() );
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
      nlp_debug_msg('deleteNl', $e->getMessage() );
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
      nlp_debug_msg('createNl', $e->getMessage() );
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * getColumnNames
   *
   * Get the column names for the NLS status table.
   * Used to initialize a new NL status record.
   *
   * @return array
   */
  private function getColumnNames(): array
  {
    try {
      $select = "SHOW COLUMNS FROM  {".self::NLS_STATUS_TBL.'}';
      $result = $this->connection->query($select);
    }
    catch (Exception $e) {
      nlp_debug_msg('getColumnNames', $e->getMessage() );
      return [];
    }
    $colNames = [];
    do {
      $name = $result->fetchAssoc();
      if(empty($name)) {break;}
      $colNames[] = $name['Field'];
    } while (TRUE);
    return $colNames;
  }
  
  /**
   * getNlsStatus
   *
   * Get the status record  of a NL by mcid and county.
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
      nlp_debug_msg('getNlsStatus', $e->getMessage() );
      return [];
    }
    $nlStatus = $result->fetchAssoc();
    if(empty($nlStatus)) {
      $statusColumnNames = $this->getColumnNames();
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
      nlp_debug_msg('setNlsStatus', $e->getMessage() );
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
      nlp_debug_msg('deleteNlsStatus', $e->getMessage() );
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
  public function getNls(string $county, int|string $hd, string $precinct=''): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('n.county',$county);
      if ($hd != 'All') {
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
      nlp_debug_msg('getNls', $e->getMessage() );
      return [];
    }
    // Fetch each NL record and build the array of information about each NL
    // needed to build the display table.
    $nlRecords = [];
    do {
      $nlRecord = $result->fetchAssoc();
      if(empty($nlRecord)) {break;}
      $mcid = $nlRecord['mcid'];
      $nlRecord['status'] = $this->getNlsStatus($mcid,$county);
      $nlRecords[$mcid] = $nlRecord;
    } while (TRUE);
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
      nlp_debug_msg('getHdList', $e->getMessage() );
      return [];
    }
    
    $hdOptions  = [];
    do {
      $hdRecord = $result->fetchAssoc();
      if(empty($hdRecord)) {break;}
      $hdOptions[] = $hdRecord['hd'];
    } while (TRUE);
    return $hdOptions;
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
      nlp_debug_msg('getPctList', $e->getMessage()  );
      return [];
    }
    $pctOptions = [];
    do {
      $pct = $result->fetchAssoc();
      if(empty($pct)) {break;}
      $pctOptions[] = $pct['precinct'];
    } while (TRUE);
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
      nlp_debug_msg('getNlById', $e->getMessage() );
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
      if(!empty($hd) AND $hd!='All' ) {
        $query->condition('n.'.'hd',$hd);
      }
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getCountyNls', $e->getMessage() );
      return [];
    }
    $nlRecords = [];
    do {
      $nlRecord = $result->fetchAssoc();
      if(empty($nlRecord)) {break;}
      $nlRecords[$nlRecord['mcid']] = $nlRecord;
    } while (TRUE);
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
      nlp_debug_msg('resultsReported', $e->getMessage() );
      return FALSE;
    }
    $resultsReported = $result->fetchAssoc();
    if(!empty($resultsReported['resultsReported'])) {
      return TRUE;
    }
    try {
      $this->connection->update(self::NLS_STATUS_TBL)
        ->fields(array('resultsReported' => 'Y',))
        ->condition('mcid',$mcid)
        ->condition('county',$county)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('resultsReported', $e->getMessage() );
      return FALSE;
    }
    /*
    $config = Drupal::config('nlpservices.configuration');
    $electionDates = $config->get('nlpservices-election-configuration');
    $cycle = $electionDates['nlp_election_cycle'];
    $this->awardsObj->awardsLevelUp($mcid,$cycle);
    */
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
      nlp_debug_msg('updateNlStatus', $e->getMessage() );
    }
  }
  
  /**
   * searchNls
   *
   * Search for NLs in a given county by a search needle.
   * The needle is searched in lastName, firstName, nickname, email, and mcid.
   * The needle is any fragment in any of the fields.
   * Returns an associative array of NL records keyed by mcid with one of the
   * fields containing the needle.
   *
   * @param string $county
   * @param string $needle
   * @return array
   */
  public function searchNls(string $county, string $needle): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('county',$county);
      $orGroup = $query->orConditionGroup()
        ->condition('lastName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('firstName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('nickname', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('email', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('mcid', "%" . $query->escapeLike($needle) . "%", 'LIKE');
      $query->condition($orGroup);
      $query->orderBy('lastName');
      $query->orderBy('nickname');
      $result =  $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('searchNls', $e->getMessage() );
      return [];
    }
    // Fetch each NL record and build the array of information about each NL
    // needed to build the display table.
    $nlRecords = [];
    do {
      $nlRecord = $result->fetchAssoc();
      if(empty($nlRecord)) {break;}
      $mcid = $nlRecord['mcid'];
      $nlRecord['status'] = $this->getNlsStatus($mcid,$county);
      $nlRecords[$mcid] = $nlRecord;
    } while (TRUE);
    return $nlRecords;
  }
  
  /**
   * searchNlsByIds
   *
   * Search for NLs in the given array of mcids for values that contain the needle.
   * The needle is searched in lastName, firstName, nickname, email, and mcid.
   * Returns an associative array of NL records keyed by mcid with one of the
   * fields containing the needle.
   *
   * @param array $mcids
   * @param string $needle
   * @return array
   */
  public function searchNlsByIds(array $mcids, string $needle): array
  {
    try {
      $query = $this->connection->select(self::NLS_TBL, 'n');
      $query->fields('n');
      $query->condition('mcid', $mcids, 'IN');
      $orGroup = $query->orConditionGroup()
        ->condition('lastName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('firstName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('nickname', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('email', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('mcid', "%" . $query->escapeLike($needle) . "%", 'LIKE');
      $query->condition($orGroup);
      $query->orderBy('lastName');
      $query->orderBy('nickname');
      $result =  $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('searchNlsByIds', $e->getMessage() );
      return [];
    }
    // Fetch each NL record and build the array of information about each NL
    // needed to build the display table.
    $nlRecords = [];
    do {
      $nlRecord = $result->fetchAssoc();
      if(empty($nlRecord)) {break;}
      $mcid = $nlRecord['mcid'];
      $nlRecords[$mcid] = $nlRecord;
    } while (TRUE);
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
      } elseif (!empty($hd) OR $hd !== 0) {
        $query->condition('hd', $hd);
      }
      $query->orderBy('hd');
      $query->orderBy('lastName');
      $query->orderBy('firstName');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getNlsByIds', $e->getMessage());
      return [];
    }
    $nlRecords = [];
    do {
      $nlRecord = $result->fetchAssoc();
      if (empty($nlRecord)) { break; }
      $nlRecords[$nlRecord['mcid']] = $nlRecord;
    } while (TRUE);
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
      nlp_debug_msg('getDistinctHdByMcids', $e->getMessage());
      return [];
    }
    $hdList = [];
    do {
      $row = $result->fetchAssoc();
      if (empty($row)) { break; }
      $hdList[] = $row['hd'];
    } while (TRUE);
    return $hdList;
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
      nlp_debug_msg('getDistinctPrecinctByMcids', $e->getMessage());
      return [];
    }
    $precinctList = [];
    do {
      $row = $result->fetchAssoc();
      if (empty($row)) { break; }
      $precinctList[] = $row['precinct'];
    } while (TRUE);
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
      nlp_debug_msg('getDistinctHdPrecinctArrayByMcids', $e->getMessage());
      return [];
    }
    $precinctArray = [];
    do {
      $row = $result->fetchAssoc();
      if (empty($row)) { break; }
      $precinctArray[$row['hd']][$row['precinct']] = $row['precinct'];
    } while (TRUE);
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
      nlp_debug_msg('getCountyNlsParticipation', $e->getMessage());
      return [];
    }
    return $result->fetchAssoc();
  }
  
}
