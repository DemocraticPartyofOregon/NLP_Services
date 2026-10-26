<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpVoters {

  const string VOTER_TBL = 'nlp_voter';
  const string VOTER_TURF_TBL = 'nlp_voter_turf';
  const string VOTER_STATUS_TBL = 'nlp_voter_status';
  const string VOTER_ADDRESS_TBL = 'nlp_voter_address';

  private array $statusList = ['vanid','cycle','deceased','hostile','voter','voted',];

  public array $addressFields = ['streetNo','streetPrefix','streetName','streetType','streetSuffix',
    'aptType','aptNo', 'city'];

  protected Connection $connection;
  protected NlpMatchbacks $matchbacksObj;
  protected NlpReports $reportsObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct( $connection, $matchbacksObj, $reportsObj, $nlpConfigurationObj) {
    $this->connection = $connection;
    $this->matchbacksObj = $matchbacksObj;
    $this->reportsObj = $reportsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpVoters
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * reassignNlVoters
   *
   * Move the voters in an NL's turfs from one MCID to another.
   *
   * @param int $previousMcid
   * @param int $newMcid
   * @return void
   */
  public function reassignNlVoters(int $previousMcid, int $newMcid): void
  {
    try {
      $this->connection->update(self::VOTER_TURF_TBL)
        ->fields(['mcid' => $newMcid])
        ->condition('mcid', $previousMcid)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('reassignNlVoters', $e);
    }
  }

  /**
   * getVoterCountByNl
   *
   * Get the number of voters in all turfs assigned to an NL.
   *
   * @param int $mcid
   * @return int - the number of voters assigned to the NL.
   */
  public function getVoterCountByNl(int $mcid): int
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->addField('g','vanid');
      $query->condition('mcid',$mcid);
      $voterCount = $query->countQuery()->execute()->fetchField();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVoterCountByNl', $e);
      return 0;
    }
    return (int) $voterCount;
  }


  /**
   * duplicateVoters
   *
   * Get the voter turf records for all voters in the given array of vanIds that are
   * already in another turf.   Voters assigned to an NL cannot be assigned to another turf.
   *
   * @param array $vanIds - array of vanids to fetch.
   * @return array - Array of voter records keyed by vanid.  The normal case is to return an
   * empty array.
   * @noinspection PhpUnused
   * Used in SyncTurfsBatch.php
   */
  public function duplicateVoters(array $vanIds): array
  {
    $dupVoters = [];
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->fields('g');
      $query->condition('vanid',$vanIds,'IN');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('duplicateVoters', $e);
      return [];
    }
    do {
      $dupVoter = $result->fetchAssoc();
      if(empty($dupVoter)) {break;}
      $dupVoters[$dupVoter['vanid']] = $dupVoter;
    } while (TRUE);
    return $dupVoters;
  }

  /**
   * getVotersInList
   *
   * Get the voter records for all voters in the given array of vanIds.
   *
   * @param $vanIds - array of vanids to fetch.
   * @return array - array of voter records keyed by vanid.
   */
  public function getVotersInList(array $vanIds): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanIds,'IN');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVotersInList', $e);
      return [];
    }
    $voters = [];
    do {
      $voter = $result->fetchAssoc();
      if(empty($voter)) {break;}

      $voter['address'] = $this->fetchVoterAddress($voter['vanid']);

      $voters[$voter['vanid']] = $voter;
    } while (TRUE);
    return $voters;
  }

  /**
   * nullVoterStatus
   *
   * Create a null voter status record.
   *
   * @return array - an array of null values for the voter status fields.
   */
  public function nullVoterStatus(): array
  {
    $null = [];
    foreach ($this->statusList as $key) {
      $null[$key] = NULL;
    }
    $null['cycle'] = $this->nlpConfigurationObj->getCycle();
    return $null;
  }

  /**
   * getVoterStatus
   *
   * Get the voter status record for a given vanid.  Also, the matchback
   * database is checked to see if this voter has voted in the current cycle.
   * If so, the status is updated.
   *
   * @param int $vanid - the vanid to fetch the voter status for.
   * @return array - an array of voter status fields.
   */
  public function getVoterStatus(int $vanid): array
  {
    try {
      $query = $this->connection->select(self::VOTER_STATUS_TBL, 's');
      $query->fields('s');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVoterStatus', $e);
      return $this->nullVoterStatus();
    }
    $status = $result->fetchAssoc();
    if(empty($status)) {
      $status = $this->nullVoterStatus();
    }
    if(empty($status['voted'])) {
      $mbDate = $this->matchbacksObj->getMatchbackDate($vanid);
      if (!empty($mbDate)) {  // Voted!
        $status['voted'] = $mbDate;
        $this->setVoterStatus($vanid,$status);
      }
    }
    return $status;
  }

  /**
   * setVoterStatus
   *
   * Set the voter status record for a given vanid.  If the cycle is not provided,
   * the current cycle is used.
   *
   * @param int $vanid - the vanid to set the voter status for.
   * @param array $fields - an array of voter status fields to set.
   * @return bool - TRUE if the voter status was set, FALSE otherwise.
   */
  public function setVoterStatus(int $vanid, array $fields): bool
  {
    if(empty($fields['cycle'])) {
      $fields['cycle'] = $this->nlpConfigurationObj->getCycle();
    }
    try {
      $fields['vanid'] = $vanid;
      $this->connection->merge(self::VOTER_STATUS_TBL)
        ->keys(['vanid' => $vanid])
        ->fields($fields)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('setVoterStatus', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * createVoter
   *
   * Create a new voter record in the database.  The voter record is created in the
   * nlp_voter table, and the address is created in the nlp_voter_address table.
   *
   * @param $voter - an array of voter fields to create.
   * @return bool - TRUE if the voter was created, FALSE otherwise.
   */
  public function createVoter(array $voter): bool
  {
    $vanid = $voter['vanid'];
    $dbAddress = $voter['address'];
    $dbAddress['vanid'] = $voter['vanid'];
    unset($voter['address']);
    try {
      $this->connection->merge(self::VOTER_TBL)
        ->keys(['vanid' => $vanid,])
        ->fields($voter)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('createVoter', $e);
      return FALSE;
    }
    try {
      $this->connection->insert(self::VOTER_ADDRESS_TBL)
        ->fields($dbAddress)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('createVoter', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * createTurfVoter
   *
   * @param array $turfVoter
   * @return bool
   *
   * Create a new turf voter record in the database.  The turf voter record is created in the
   * nlp_voter_turf table.  The voter turf table is used to associate a voter with a turf.
   */
  public function createTurfVoter(array $turfVoter): bool
  {
    $turfVoter['cycle'] = $this->nlpConfigurationObj->getCycle();
    try {
      $this->connection->insert(self::VOTER_TURF_TBL)
        ->fields($turfVoter)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('createTurfVoter', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * updateTurfVoter
   *
   * @param array $turfVoter
   * @return bool
   *
   * Update an existing turf voter record in the database.  The turf voter record is updated in the
   * nlp_voter_turf table.  The voter turf table is used to associate a voter with a turf.
   */
  public function updateTurfVoter(array $turfVoter): bool
  {
    try {
      $this->connection->merge(self::VOTER_TURF_TBL)
        ->keys([
          'turfIndex' => $turfVoter['turfIndex'],
          'vanid' => $turfVoter['vanid'],
        ])
        ->fields($turfVoter)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateTurfVoter', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * getVotersInTurf
   *
   * @param int $turfIndex
   * @return array
   *
   * Get the voters in a turf by turfIndex.  The turf index is the unique identifier for a turf.
   * The result is an array of vanids for the voters in the turf.
   */
  public function getVotersInTurf(int $turfIndex): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 't');
      $query->addField('t','vanid');
      $query->condition('turfIndex', $turfIndex);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVotersInTurf', $e);
      return [];
    }
    $voters = [];
    do {
      $voter = $result->fetchAssoc();
      if(empty($voter)) {break;}
      $voters[$voter['vanid']] = $voter['vanid'];
    } while (TRUE);
    return $voters;
  }

  /**
   * fetchVotersByTurf
   *
   * Get the voters in a turf by turfIndex.  The turf index is the unique identifier for a turf.
   * The result is an array of voter records keyed by vanid.  The order of the voters is to mimic
   * the order created by VoteBuilder for a printed turf.
   *
   * @param int $turfIndex
   * @return array
   */
  public function fetchVotersByTurf(int $turfIndex): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'v');
      $query->join(self::VOTER_TURF_TBL, 'g', 'g.vanid = v.vanid');
      $query->join(self::VOTER_ADDRESS_TBL, 'a', 'a.TurfIndex = g.TurfIndex AND g.vanid = a.vanid' );
      $query->fields('v');
      $query->condition('g.TurfIndex',$turfIndex);
      $query->orderBy('city');
      $query->orderBy('streetName');
      $query->orderBy('streetType');
      $query->orderBy('streetNo');
      $query->orderBy('aptType');
      $query->orderBy('aptNo');
      $query->orderBy('lastName');
      $query->orderBy('firstName');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('fetchVotersByTurf', $e);
      return [];
    }
    $voters = [];
    do {
      $voter = $result->fetchAssoc();
      if(empty($voter)) {break;}
      $voter['turfVoter'] = $this->getTurfVoter($voter['vanid']);
      $voter['address'] = $this->fetchVoterAddress($voter['vanid']);
      if(empty($voter['address']) || empty($voter['turfVoter'])) {
        Drupal::logger('nlpservices')->warning('fetchVotersByTurf: skipping vanid @vanid due to missing address or turf-voter record', ['@vanid' => $voter['vanid']]);
        continue;
      }
      $voters[$voter['vanid']] = $voter;
    } while (TRUE);
    return $voters;
  }

  /**
   * getTurfVoter
   *
   * Get the turf voter record for a given vanid.  The turf voter record is used to associate a voter with a turf.
   *
   * @param int $vanid - the vanid to fetch the turf voter for.
   * @return array - an array of turf voter fields.
   */
  public function getTurfVoter(int $vanid): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->fields('g');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getTurfVoter', $e);
      return [];
    }

    return $result->fetchAssoc();
  }

  /**
   * deleteVotersInTurf
   *
   * Delete all voters in a turf table.  This generally means the turf has been deleted.
   *
   * @param int $turfIndex
   * @return void
   */
  public function deleteVotersInTurf(int $turfIndex): void
  {
    try {
      $this->connection->delete(self::VOTER_TURF_TBL)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteVotersInTurf', $e);
    }
  }

  /**
   * deleteAddressesInTurf
   *
   * Delete all voter addresses in a turf table.  This is used when a turf is deleted.
   * The addresses are removed from the nlp_voter_address table.
   *
   * @param int $turfIndex
   * @return void
   */
  public function deleteAddressesInTurf(int $turfIndex): void
  {
    try {
      $this->connection->delete(self::VOTER_ADDRESS_TBL)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteAddressesInTurf', $e);
    }
  }

  /**
   * deleteVoterInTurf
   *
   * Delete a voter in a turf table.  This is used when a voter is removed from a turf.  Voters
   * are removed individually by the resynced process.
   *
   * @param int $vanid
   * @return void
   */
  public function deleteVoterInTurf(int $vanid): void
  {
    try {
      $this->connection->delete(self::VOTER_TURF_TBL)
        ->condition('vanid', $vanid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteVoterInTurf', $e);
    }
  }

  /**
   * deleteVoterFromTurf
   *
   * Delete a voter from one turf, leaving the voter in any other turf.  Used by the resync
   * when VoteBuilder no longer lists the voter in this turf.
   *
   * @param int $vanid
   * @param int $turfIndex
   * @return void
   */
  public function deleteVoterFromTurf(int $vanid, int $turfIndex): void
  {
    try {
      $this->connection->delete(self::VOTER_TURF_TBL)
        ->condition('vanid', $vanid)
        ->condition('turfIndex', $turfIndex)
        ->execute();
      $this->connection->delete(self::VOTER_ADDRESS_TBL)
        ->condition('vanid', $vanid)
        ->condition('turfIndex', $turfIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteVoterFromTurf', $e);
    }
  }

  /**
   * getVanidsInTurfs
   *
   * Returns the vanids, of those given, that are in a turf that still exists.  A voter in a
   * turf needs the NLPVoterAC activist code, so it must not be removed from them.
   *
   * @param array $vanids
   * @return array - the vanids in a turf, as ints.
   */
  public function getVanidsInTurfs(array $vanids): array
  {
    if (empty($vanids)) {return [];}
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->join(NlpTurfs::TURF_TBL, 't', 't.turfIndex = g.turfIndex');
      $query->addField('g', 'vanid');
      $query->condition('g.vanid', $vanids, 'IN');
      $query->distinct();
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVanidsInTurfs', $e);
      return [];
    }
    return array_map('intval', $result->fetchCol());
  }

  /**
   * deleteAddressInTurf
   *
   * Delete a voter address in a turf table.  This is used when a voter address is removed from a turf.
   * Voter addresses are removed individually by the resynced process.
   *
   * @param int $vanid
   * @return void
   */
  public function deleteAddressInTurf(int $vanid): void
  {
    try {
      $this->connection->delete(self::VOTER_ADDRESS_TBL)
        ->condition('vanid', $vanid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteAddressInTurf', $e);
    }
  }

  /**
   * updateTurfNote
   *
   * Update a turf note for a given turfIndex and vanid.  The turf note is used to store notes about a voter in a turf.
   *
   * @param int $turfIndex - the turf index to update the note for.
   * @param int $vanid - the vanid to update the note for.
   * @param string $note - the note to update.
   * @param int $reportIndex - the report index to update the note for.
   * @param int $noteId - the note id to update.
   * @return bool - TRUE if the note was updated, FALSE otherwise.
   */
  public function updateTurfNote(int $turfIndex,int $vanid,string $note,int $reportIndex,int $noteId): bool
  {
    try {
      $this->connection->merge(self::VOTER_TURF_TBL)
        ->keys([
          'turfIndex' => $turfIndex,
          'vanid' => $vanid,
        ])
        ->fields([
          'reportIndex' => $reportIndex,
          'NoteId' => $noteId,
          'note' => $note,])
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateTurfNote', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * fetchVoterAddress
   *
   * Fetch the voter address for a given vanid.  The voter address is used to store the address of a voter.
   *
   * @param int $vanid - the vanid to fetch the voter address for.
   * @return array - an array of voter address fields.
   */
  public function fetchVoterAddress(int $vanid): array
  {
    try {
      $query = $this->connection->select(self::VOTER_ADDRESS_TBL, 'a');
      $query->fields('a');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('fetchVoterAddress', $e);
      return [];
    }
    if(empty($address = $result->fetchAssoc())) {return [];}
    return $address;
  }

  /**
   * addressCompare
   *
   * Compare two addresses to see if they are the same.  The addresses are compared by the fields in $this->addressFields.
   * If any of the fields are different, the addresses are considered different.
   *
   * @param array $address1 - the first address to compare.
   * @param array $address2 - the second address to compare.
   * @return bool - TRUE if the addresses are the same, FALSE otherwise.
   */
  public function addressCompare(array $address1,array $address2): bool
  {
    if(empty($address2) || empty($address1)) {return FALSE;}
    foreach ($this->addressFields as $addressKey) {
      if($address1[$addressKey] != $address2[$addressKey]) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * addressExtract
   *
   * Extract the address fields from a voter address.  The result is an array of the fields in $this->addressFields.
   * If the address is empty, an empty array is returned.
   *
   * @param array $address - the address to extract the fields from.
   * @return array - an array of the address fields.
   */
  public function addressExtract(array $address): array
  {
    if(empty($address)) {return [];}
    $simpleAddress = [];
    foreach ($this->addressFields as $addressKey) {
      $simpleAddress[$addressKey] = $address[$addressKey];
    }
    return $simpleAddress;
  }

  /**
   * setMovedStatus
   *
   * Set the moved status for a given turfIndex and vanid.  The moved status is used to indicate if a voter has moved.
   *
   * @param int $turfIndex - the turf index to set the moved status for.
   * @param int $vanid - the vanid to set the moved status for.
   * @param bool $value - the value to set the moved status to.
   * @return void
   */
  public function setMovedStatus(int $turfIndex,int $vanid,bool $value): void
  {
    try {
      $this->connection->update(self::VOTER_ADDRESS_TBL)
        ->fields(['moved' => $value])
        ->condition('turfIndex',$turfIndex)
        ->condition('vanid',$vanid)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('setMovedStatus', $e);
      return;
    }
  }

  /**
   * updateVoterPhone
   *
   * Update the phone number for a given vanid.  The phone number is stored in the nlp_voter table.
   *
   * @param int $vanid - the vanid to update the phone number for.
   * @param array $phone - the phone number to update.
   * - Keys are:
   *    cellPhone - the cell phone number.
   *    cellPhoneId - the cell phone id (null for new number).
   * Or
   *    homePhone - the home phone number.
   *    homePhoneId - the home phone id (null for new number).
   * Phone numbers are stored as 10-digit numbers without any formatting.
   * @return void
   */
  public function updateVoterPhone(int $vanid,array $phone): void
  {
    try {
      $this->connection->merge(self::VOTER_TBL)
        ->keys(['vanid' => $vanid])
        ->fields($phone)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateVoterPhone', $e);
      return;
    }
  }

  /**
   * getVoterById
   *
   * Get the voter record for a given vanid.  The voter record is used to store the information about a voter.
   *
   * @param int $vanid - the vanid to fetch the voter for.
   * @return array - an array of voter fields, including address.
   */
  public function getVoterById(int $vanid): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVoterById', $e);
      return [];
    }
    $voter = $result->fetchAssoc();
    if(empty($voter)) {return [];}  // Voter is not known.
    $voter['address'] = $this->fetchVoterAddress($vanid);
    return $voter;
  }

  /**
   * getVoterCd
   *
   * Get the congressional district number for a given vanid.  The congressional district
   * is stored in the voter address.
   *
   * @param int $vanid - the vanid to fetch the congressional district for.
   * @return int - the congressional district for the voter, or 0 if not found.
   */
  public function getVoterCd(int $vanid): int
  {
    $address = $this->fetchVoterAddress($vanid);
    return (!empty($address['cd']))?$address['cd']:0;
  }

  /**
   * getVotersInCounty
   *
   * Get the voters in a county.  The county is stored in the voter turf table.
   *
   * @param string $county - the county to fetch the voters for.
   * @return array - an array of vanids for the voters in the county.
   */
  public function getVotersInCounty(string $county): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->addField('g','vanid');
      $query->condition('county',$county);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVotersInCounty', $e);
      return [];
    }
    $records = $result->fetchAll();
    $keys = [];
    foreach ($records as $record) {
      $keys[$record->vanid] = $record->vanid;
    }
    return $keys;
  }

  /**
   * getVotersInCd
   *
   * Get the voters in a congressional district.  The congressional district is stored in the voter address.
   *
   * @param int $cd - the congressional district to fetch the voters for.
   * @return array - an array of vanids for the voters in the congressional district.
   */
  public function getVotersInCd(int $cd): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->leftJoin(self::VOTER_ADDRESS_TBL, 'a', 'g.vanid = a.vanid' );
      $query->addField('g','vanid');
      $query->condition('a.cd',$cd);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVotersInCd', $e);
      return [];
    }
    $records = $result->fetchAll();
    $keys = [];
    foreach ($records as $record) {
      $keys[$record->vanid] = $record->vanid;
    }
    return $keys;
  }

  /**
   * getParticipatingCounties
   *
   * Get the list of counties that have turfs assigned to NLs.
   *
   * @return array - an array of county names.
   */
  public function getParticipatingCounties(): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 'g');
      $query->addField('g', 'county');
      $query->distinct();
      $query->orderBy('county');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getParticipatingCounties', $e);
      return [];
    }

    $countyNames = [];
    do {
      $record = $result->fetchAssoc();
      if(empty($record)) {break;}
      if(!empty($record['county'])) {
        $countyNames[] = $record['county'];
      }
    } while (TRUE);
    return $countyNames;
  }

  /**
   * getVoterAddresses
   *
   * Get the voter addresses for a given vanid.  The voter addresses are stored in the nlp_voter_address table.
   *
   * @param int $vanid - the vanid to fetch the voter addresses for.
   * @return array - an array of voter address records.
   */
  public function getVoterAddresses(int $vanid): array
  {
    try {
      $query = $this->connection->select(self::VOTER_ADDRESS_TBL, 'a');
      $query->fields('a');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVoterAddresses', $e);
      return [];
    }
    $addresses = [];
    do {
      $address = $result->fetchAssoc();
      if(empty($address)) {break;}
      $addresses[] = $address;
    } while (TRUE);
    return $addresses;
  }

  /**
   * getTurfIndexByVanid
   *
   * Get the turfIndex for a given vanid.  The turfIndex is used to associate a voter with a turf.
   *
   * @param int $vanid - the vanid to fetch the turfIndex for.
   * @return int - the turfIndex for the voter, or 0 if not found.
   * @noinspection PhpUnused
   */
  public function getTurfIndexByVanid(int $vanid): int
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 't');
      $query->fields('t');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getTurfIndexByVanid', $e);
      return 0;
    }
    $nl = $result->fetchAssoc();
    if(empty($nl)) {return 0;}
    return (!empty($nl['turfIndex']))?$nl['turfIndex']:0;
  }

  /**
   * getMcidByVanid
   *
   * Get the mcid for a given vanid from the voter turf table.
   *
   * @param int $vanid - the vanid to fetch the mcid for.
   * @return int - the mcid for the voter, or 0 if not found.
   */
  public function getMcidByVanid(int $vanid): int
  {
    try {
      $query = $this->connection->select(self::VOTER_TURF_TBL, 't');
      $query->fields('t');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getMcidByVanid', $e);
      return 0;
    }
    $nl = $result->fetchAssoc();
    if(empty($nl)) {return 0;}
    return (!empty($nl['mcid']))?$nl['mcid']:0;
  }

  /**
   * searchVoters
   *
   * Search for voters in a given county by a needle.  The needle is used to search the lastName, firstName,
   * homePhone, cellPhone, and vanid fields.
   *
   * @param string $county - the county to search in.
   * @param string $needle - the needle to search for.
   * @return array - an array of voter records keyed by vanid.
   */
  public function searchVoters(string $county,string  $needle): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'n');
      $query->join(self::VOTER_ADDRESS_TBL, 'a', 'n.vanid = a.vanid' );
      $query->fields('n');
      $query->addField('a','city');
      $query->addField('a','streetNo');
      $query->addField('a','streetPrefix');
      $query->addField('a','streetName');
      $query->addField('a','streetType');
      $query->addField('a','county');
      $query->addField('a','moved');
      $query->addField('a','turfIndex');
      $query->condition('a.county',$county);
      $orGroup = $query->orConditionGroup()
        ->condition('lastName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('firstName', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('homePhone', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('cellPhone', "%" . $query->escapeLike($needle) . "%", 'LIKE')
        ->condition('n.vanid', "%" . $query->escapeLike($needle) . "%", 'LIKE');
      $query->condition($orGroup);
      $query->orderBy('lastName');
      $query->orderBy('firstName');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('searchVoters', $e);
      return [];
    }
    // Fetch each NL record and build the array of information about each NL
    // needed to build the display table.
    $voterRecords = [];
    do {
      $voterRecord = $result->fetchAssoc();
      if(empty($voterRecord)) {break;}
      $voterRecord['address'] = $this->assembleAddress($voterRecord);
      $vanid = $voterRecord['vanid'];
      $voterRecords[$vanid] = $voterRecord;
    } while (TRUE);
    return $voterRecords;
  }

  /**
   * assembleAddress
   *
   * Assemble the address from the voter record.  The address is assembled from the fields in the voter record.
   * The result is a string of the address.
   *
   * @param array $voter - the voter record to assemble the address from.
   * @return string - the assembled address.
   */
  private function assembleAddress(array $voter): string
  {
    $addressParts = ['streetNo','streetPrefix','streetName','streetType'];
    $assembledAddress = '';
    foreach ($addressParts as $addressPart) {
      if(!empty($voter[$addressPart])) {
        $assembledAddress .= $voter[$addressPart].' ';
      }
    }
    return $assembledAddress;
  }

  /**
   * getDoorCount
   *
   * Get the number of doors in a list of vanids.  The doors are counted by the number of unique addresses in the list.
   * The result is the number of doors.
   *
   * @param array $vanids - an array of vanids to count the doors for.
   * @return int - the number of doors in the list.
   */
  public function getDoorCount(array $vanids): int
  {
    $doorCount = 0;
    $currentAddress = [];
    foreach ($vanids as $vanid) {
      $voterAddresses = $this->getVoterAddresses($vanid);
      if(empty($voterAddresses)) {
        Drupal::logger('nlpservices')->notice('No address for voter: '.$vanid);
        continue;
      }
      $voterAddress = $voterAddresses[0];
      $simpleAddress = $this->addressExtract($voterAddress);
      if (empty($currentAddress)) {
        $doorCount = 1;
        $currentAddress = $simpleAddress;
      } else {
        if($simpleAddress !== $currentAddress) {
          $doorCount++;
          $currentAddress = $simpleAddress;
        }
      }
    }
    return $doorCount;
  }

  /**
   * getVanidByStateId
   *
   * Retrieve the VAN ID associated with the given state file ID. This method queries the voter table
   * to find a matching VAN ID based on the provided state file ID.
   * If no corresponding record is found, or if an error occurs during the query, the method will return 0.
   *
   * @param int $stateFileId The state file ID provided by the SOS.
   * @return int The VAN ID corresponding to the provided state file ID, or 0 if no match is found or on error.
   */
  public function getVanidByStateId( int $stateFileId ): int
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'v');
      $query->addField('v','vanid');
      $query->condition('stateFileId',$stateFileId);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getVanidByStateId', $e);
      return 0;
    }
    $voter = $result->fetchAssoc();
    if(empty($voter)) {return 0;}  // Voter is not known.
    return $voter['vanid'] ?? 0;
  }

  /**
   * updateVoter
   *
   * Update a voter record in the voter table. This is used to modify a specific field with a new value for a given voter.
   *
   * @param int $vanid The unique identifier for the voter being updated.
   * @param string $field The field in the voter table to update.
   * @param mixed $value The new value to assign to the specified field.
   * @return void
   */
  public function updateVoter( int $vanid, string $field, mixed $value ): void
  {
    try {
      $this->connection->merge(self::VOTER_TBL)
        ->keys(['vanid' => $vanid])
        ->fields([$field => $value])
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateVoter', $e);
      return;
    }
  }

}
