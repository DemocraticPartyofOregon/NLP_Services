<?php

namespace Drupal\nlpservices;

//use Drupal;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpAwards
{
  public const string AWARDS_TBL = 'nlp_awards';
  //private array $awardsHeader = ['mcid','nickname','lastName','electionCount','participation'];
  private array $awardsHeader;
  protected Connection $connection;
  protected NlpConfiguration $configurationObj;
  protected NlpNls $nlsObj;
  protected DatabaseFunctions $dbFunctionsObj;

  public function __construct($connection, $configurationObj, $nlsObj, $dbFunctionsObj) {
    $this->connection = $connection;
    $this->configurationObj = $configurationObj;
    $this->nlsObj = $nlsObj;
    $this->dbFunctionsObj = $dbFunctionsObj;

    $this->awardsHeader = $this->dbFunctionsObj->getColumnNames(self::AWARDS_TBL);
  }

  public static function create(ContainerInterface $container): NlpAwards
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.database_functions'),
    );
  }
  
  /**
   * mergeAward
   * Merges the award record with the participation information into the database.
   * @param array $award
   * @return bool
   */
  public function mergeAward(array $award): bool
  {
    if(empty($award['mcid'])) {return FALSE;}
    $award['participation'] = json_encode($award['participation']);
    try {
      $this->connection->merge(self::AWARDS_TBL)
        ->keys(['mcid' => $award['mcid']])
        ->fields($award)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('mergeAward', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * fetchAwardRecord
   * Fetches the award record for an NL.
   * @param int $mcid
   * @return array - the raw award record, the participation information is still in JSON form.
   */
  public function fetchAwardRecord(int $mcid): array
  {
    try {
      $query = $this->connection->select(self::AWARDS_TBL, 'n');
      $query->condition('mcid',$mcid);
      $query->fields('n');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('e', $e);
      return $this->newAwardRecord($mcid);
    }
    if(!$award = $result->fetchAssoc()) {
      $award = $this->newAwardRecord($mcid);
    }
    return $award;
  }
  
  /**
   * getAward
   *
   * Returns the award record with the participation converted from JSON to an array.
   * It also corrects historical records where the completed status was boolean.
   *
   * @param int $mcid
   * @return array -
   */
  public function getAward(int $mcid): array
  {
    $award = $this->fetchAwardRecord($mcid);
    if(empty($award)) {
      return $this->newAwardRecord($mcid);
    }
    $participation = (!empty($award['participation']))?
      (array) json_decode($award['participation']):[];
    $award['participation'] = $participation;
    $electionCount = 0;
    foreach($award['participation'] as $cycle => $value) {
      if($award['participation'][$cycle] == 'Completed') {
        $electionCount++;
      } elseif($value === TRUE) {
        $award['participation'][$cycle] = 'Completed';
        $electionCount++;
      }
    }
    if($award['electionCount'] != $electionCount) {
      $award['electionCount'] = $electionCount;
      $this->mergeAward($award);
    }
    $award['electionCount'] = $electionCount;
    return $award;
  }
  
  
  /**
   * nlParticipation
   * Adds participation in an election to the award record.
   * @param int $mcid
   * @param string $progress
   * @return array - adds participation in an election to previous participation.
   */
  public function nlParticipation(int $mcid, string $progress): array
  {
    $cycle = $this->configurationObj->getCycle();
    $award = $this->fetchAwardRecord($mcid);
    $participation = (!empty($award['participation']))?
      (array) json_decode($award['participation']):[];
    if(empty($participation[$cycle]) OR $participation[$cycle]!='Completed' ) {
      $participation[$cycle] = $progress;
      $award['participation'] = $participation;
      if($progress == 'Completed') {$award['electionCount']++;}
      $this->mergeAward($award);
    }
    return $award;
  }
  
  /**
   * nlParticipationDisplay
   *
   * Returns a string with the participation information for an NL.
   *
   * @param int $mcid
   * @return string
   */
  public function nlParticipationDisplay(int $mcid): string
  {
    $award = $this->fetchAwardRecord($mcid);
    if (empty($award)) {return '';}
    $nlParticipation = '';
    $awards = (!empty($award['participation']))?$award['participation']:'';
    $participation = (array) json_decode($awards);
    foreach ($participation as $cycle => $value) {
      if($value === TRUE) {$value = 'Completed';}
      if(!empty($nlParticipation)) {
        $nlParticipation .= '<br>';
      }
      $nlParticipation .= $cycle.' '.$value;
    }
    return $nlParticipation;
  }
  
  
  /**
   * decodeAwardHeader
   *
   * Checks the header of the award file to make sure it contains the correct columns.
   *
   * Returns an array with the column number for the relevant fields.
   *
   * @param array $fileHdr
   * @return array - used to import awards records.
   */
  public function decodeAwardHeader(array $fileHdr): array
  {
    $hdrErr = $hdrPos =  [];
    foreach ($this->awardsHeader as $columnName) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol=>$fileColName) {
        if(trim($fileColName) == $columnName) {
          $hdrPos[$columnName] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if(!$found) {
        $hdrErr[] = 'The awards column "'.$columnName.'" is missing.';
      }
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }
  
  /**
   * deleteAwards
   *
   * Deletes the award record for a NL.
   *
   * @param int $mcid
   * @return bool
   */
  public function deleteAwards(int $mcid): bool
  {
    try {
      $this->connection->delete(self::AWARDS_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteAwards', $e);
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * newAwardRecord
   *
   * Creates a new award record for an NL.
   *
   * Returns an array with the fields:
   *   `mcid`
   *   `nickname`
   *   `lastName`
   *   `electionCount` - set to zero for a new NL.
   *   `participation` - empty but will contain a json encoded array of participation.
   *   `preferredContactMethod`
   *
   * @param int $mcid
   * @return array
   */
  private function newAwardRecord(int $mcid): array
  {
    $award = [
      'mcid' => $mcid,
      'electionCount' => 0,
      'participation' => NULL,
      'nickname' => '',
      'lastName' => '',
      'preferredContactMethod' => '',
      'notes' => '',
    ];
    $nl = $this->nlsObj->getNlById($mcid);
    if(!empty($nl)) { // Should never be empty.
      $award['nickname'] = $nl['nickname'];
      $award['lastName'] = $nl['lastName'];
      $nlsStatus = $this->nlsObj->getNlsStatus($mcid);
      $award['preferredContactMethod'] = (empty($nlsStatus))?'':$nlsStatus['preferredContactMethod'];
    }
    return $award;
  }
  
}
