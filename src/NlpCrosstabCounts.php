<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpCrosstabCounts
{
  protected Connection $connection;

  public function __construct( $connection) {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpCrosstabCounts
  {
    return new static(
      $container->get('database'),
    );
  }

  const string CROSSTAB_COUNTS_TBL = 'nlp_ballot_count';

  private array $crosstabCountVanHdr1 = [
    'cd' => 'CD',
    'county' => 'County',
    'party' => 'Party',
    'voteDate' => 'Vote Return Date',
    'total' => 'Total People',
    ];

  private array $crosstabCountVanHdr2 = [
    'balRet' => 'Bal Ret',
    ];
  
  /**
   * decodeCrosstabsHeader
   *
   * @param $fileHdr
   * @param $fileHdr2
   * @return array - array of column indexes for the ballot returned counts.
   *
   * The header is represented by the first two rows.  Also, it verifies we have the right file.
   */
  public function decodeCrosstabsHeader($fileHdr,$fileHdr2): array
  {
    $vanField['err'] = 'Unknown';
    $hdrErr = $hdrPos =  [];
    foreach ($this->crosstabCountVanHdr1 as $nlpKey => $vanField) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol=>$fileColName) {
        $fileColName = preg_replace("/[^A-Za-z0-9 ]/", '', $fileColName);
        if(trim($fileColName) == $vanField) {
          $hdrPos[$nlpKey] = $fileCol;  // Identifies the column for county, party and total people.
          $found = TRUE;
          break;
        }
      }
      if(!$found) {
        $hdrErr[] = 'The crosstab export header "'.$vanField.'" is missing.';
      }
    }
    $name = $this->crosstabCountVanHdr2['balRet'];
    $found = FALSE;
    foreach ($fileHdr2 as $fileCol=>$fileColName) {
      if($fileColName == $name) {
        $hdrPos['balRet'] = $fileCol;  // The column with the ballot returned counts.
        $found = TRUE;
        break;
      }
    }
    if(!$found) {
      $hdrErr[] = 'The crosstab export header "'.$name.'" is missing.';
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }
  
  /**
   * @param $counts
   * @return bool
   */
  public function updateCrosstabCounts($counts): bool
  {
    try {
      $this->connection->merge(self::CROSSTAB_COUNTS_TBL)
        ->keys(array(
          'cd' => $counts['cd'],
          'county' => $counts['county'],
          'party' => $counts['party']))
        ->fields(array(
          'registeredVoters' => $counts['regVoters'],
          'registeredVoted' => $counts['regVoted']))
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateCrosstabCounts',$e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * @return array - array of counts from VotedBuilder to report voter turnout by county and party.
   */
  public function fetchCrosstabCounts(): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('fetchCrosstabCounts',$e->getMessage());
      return [];
    }
    
    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if(empty($count)) {break;}
      $counts[$count['cd']][$count['county']][$count['party']]['regVoted'] = $count['registeredVoted'];
      $counts[$count['cd']][$count['county']][$count['party']]['regVoters'] = $count['registeredVoters'];
    } while (TRUE);
    return $counts;
  }
  
  /**
   * getCountsByCd
   *
   * @param $cd - the congressional district to get counts for.
   * @return array - array of counts by party for the given congressional district.
   */
  function getCountsByCd($cd): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    $query->condition('cd', $cd);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCdCounts',$e->getMessage());
      return [];
    }
    
    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if(empty($count)) {break;}
      if($count['cd'] != $cd ) {continue;}
      if(empty($counts[$count['party']])) {
        $counts[$count['party']]['regVoted'] = $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] = $count['registeredVoters'];
      } else {
        $counts[$count['party']]['regVoted'] += $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] += $count['registeredVoters'];
      }
    } while (TRUE);
    
    return $counts;
  }
  
  /**
   * getCountsByCounty
   *
   * @param $county - the county to get counts for.
   * @return array - array of counts by party for the given county.
   */
  public function getCountsByCounty($county): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->fields('c');
    $query->condition('county', $county);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCdCountsByCounty',$e->getMessage());
      return [];
    }
    
    $counts = [];
    do {
      $count = $result->fetchAssoc();
      if(empty($count)) {break;}
      if($count['county'] != $county ) {continue;}
      if(empty($counts['party'])) {
        $counts[$count['party']]['regVoted'] = $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] = $count['registeredVoters'];
      } else {
        $counts[$count['party']]['regVoted'] += $count['registeredVoted'];
        $counts[$count['party']]['regVoters'] += $count['registeredVoters'];
      }
    } while (TRUE);
    
    return $counts;
  }
  
  /**
   * getCds
   *
   * @return array - array of unique cds from the crosstab counts table.
   *
   * This is used to populate the select list for the Crosstab Counts page.
   */
  public function getCds(): array
  {
    $query = $this->connection->select(self::CROSSTAB_COUNTS_TBL, 'c');
    $query->distinct();
    $query->addField('c', 'cd');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCds',$e->getMessage());
      return [];
    }
    
    $cds = [];
    do {
      $cd = $result->fetchAssoc();
      if(empty($cd)) {break;}
      if($cd['cd'] == 0 ) {continue;}
      $cds[] = $cd['cd'];
    } while (TRUE);
    
    return $cds;
  }
  
  

}
