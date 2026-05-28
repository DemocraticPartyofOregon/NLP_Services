<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Transaction;

class NlpMatchbacks
{
  const string MATCHBACK_TBL = 'nlp_matchback';
  const int MULTI_INSERT = 100;
  const int BATCH = 100;
  const int MATCHBACK_MAX_QUEUE = 2000;
  
  
  private array $records = [];
  private int $sqlCnt = 0;
  private int $batchCnt = 0;
  
  private array $fields = ['vanid','date'];
  
  private array $matchbackVanHdr = [
    'vanid' => ['name'=>'VoterFileVANID','err'=>'Voter File VANID missing.'],
    'ballotReceived' => ['name'=>'BallotReceived','err'=>'BallotReceived missing.'],
    'earlyVoted' => ['name'=>'EarlyVoted','err'=>'BallotReceived missing'],
  ];
  
  protected Connection $connection;
  protected NlpImap $imapObj;
  
  
  public function __construct( $connection, $imapObj ) {
    $this->connection = $connection;
    $this->imapObj = $imapObj;
  }
  
  public static function create(ContainerInterface $container): NlpMatchbacks
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.imap'),
    );
  }
  
  /**
   * decodeMatchbackHdr
   *
   * Verify that the three columns needed for the ballots received records are present and
   * save the column number.
   *
   * Returns an array for finding the column value in the following records. The keys are:
   *   pos - array with the column number for each field
   *   err - array of error messages for the fields not present
   *   ok - true if the field is present
   *
   * The pos field key is:
   *  - The column name for the field
   * and the value is:
   *  - The column number for the field
   *
   * @param array $fileHdr
   * @return array
   */
  public function decodeMatchbackHdr(array $fileHdr): array
  {
    $hdrErr = $hdrPos = [];
    foreach ($this->matchbackVanHdr as $nlpKey => $vanField) {
      foreach ($fileHdr as $fileCol=>$fileColName) {
        $fileColName = preg_replace('/[^A-Za-z0-9\-]/', '', $fileColName);
        if(trim($fileColName) == $vanField['name']) {
          $hdrPos[$nlpKey] = $fileCol;
          break;
        }
      }
    }
    if(!isset($hdrPos['vanid'])) {
      $hdrErr['vanid'] = $this->matchbackVanHdr['vanid']['err'];
    }
    if (empty($hdrPos['ballotReceived']) && empty($hdrPos['earlyVoted'])) {
      $hdrErr['ballotReceived'] = $this->matchbackVanHdr['ballotReceived']['err'];
    }
    return ['pos'=>$hdrPos, 'err'=>$hdrErr, 'ok'=>empty($hdrErr),];
  }

  /**
   * insertMatchbacks
   *
   * Insert a record into a batch of records.  When the batch is full, insert the
   * batch of records into the matchback table.
   *
   * @param int $vanid
   * @param string $date
   * @return bool
   * @noinspection PhpUnused
   * Used in ImportMatchbacksBatch.php
   */
  public function insertMatchbacks(int $vanid, string $date): bool
  {
    $record = [
      'vanid' => $vanid,
      'date' => $date,
    ];
    $batchSubmit = FALSE;
    $this->records[$this->sqlCnt++] = $record;
    // When we reach 100 records, insert all of them in one query.
    if ($this->sqlCnt == self::MULTI_INSERT) {
      $this->sqlCnt = 0;
      $this->batchCnt++;
      $query = $this->connection->insert(self::MATCHBACK_TBL)
        ->fields($this->fields);
      foreach ($this->records as $record) {
        $query->values($record);
      }
      try {
        $query->execute();
      } catch (Exception $e) {
        nlp_debug_msg('Insert Matchback fail.',$e->getMessage());
        return FALSE;
      }
      $this->records = [];
      if($this->batchCnt==self::BATCH) {
        $batchSubmit = TRUE;
      }
    }
    return $batchSubmit;
  }
  
  /**
   * flushMatchbacks
   *
   * At the end of the matchback update, flush the partial batch of records into
   * the matchback table.
   *
   * @return bool
   * @noinspection PhpUnused
   * Used in ImportMatchbacksBatch.php
   */
  public function flushMatchbacks(): bool
  {
    if(empty($this->records)) {return TRUE;}
    $query = $this->connection->insert(self::MATCHBACK_TBL)
      ->fields($this->fields);
    foreach ($this->records as $record) {
      $query->values($record);
    }
    try {
      $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('Flush Matchback fail.',$e->getMessage());
      return FALSE;
    }
    $this->records = [];
    return TRUE;
  }
  
  /**
   * matchbackExists
   *
   * Check if a record exists in the matchback table.  If the record exists, return TRUE.
   *
   * @param int $vanid
   * @return bool
   */
  public function matchbackExists(int $vanid): bool
  {
    try {
      $query = $this->connection->select(self::MATCHBACK_TBL, 'm');
      $query->fields('m');
      $query->condition('vanid',$vanid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('matchbackExists', $e->getMessage() );
      return FALSE;
    }
    $exists = $result->fetchAssoc();
    return !empty($exists);
  }
  
  /**
   * getMatchbackDate
   *
   * Get the ballot-received date for a NLP Voter.
   *
   * @param int $vanid
   * @return string
   */
  public function getMatchbackDate(int $vanid): string
  {
    $query = $this->connection->select(self::MATCHBACK_TBL, 'm');
    $query->fields('m');
    $query->condition('vanid',$vanid);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getMatchbackDate', $e->getMessage() );
      return '';
    }
    $matchback = $result->fetchAssoc();
    if(empty($matchback)) {return '';}
    return $matchback['date'];
  }

  /**
   * setLatestMatchbackDate
   *
   * Set the latest ballot-received date for a NLP Voter.  The date is stored in the
   * database as a string in the format YYYY-MM-DD with a vanid of 0.
   *
   * @param string $date
   * @return void
   * @noinspection PhpUnused
   */
  public function setLatestMatchbackDate(string $date): void
  {
    try {
      $this->connection->merge(self::MATCHBACK_TBL)
        ->keys(['vanid' => 0,])
        ->fields(['date' => $date,])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setLatestMatchbackDate',$e->getMessage());
    }
  }
  
  /**
   * getLatestMatchbackDate
   *
   * The record with 0 (Zero) for the vanid is the latest date for a ballot returned by an NLP Voter.
   *
   * @return string
   * @noinspection PhpUnused
   * Used in ImportMatchbacksBatch.php
   */
  public function getLatestMatchbackDate(): string
  {
    $query = $this->connection->select(self::MATCHBACK_TBL, 'm');
    $query->fields('m');
    $query->condition('vanid',0);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getLatestMatchbackDate', $e->getMessage() );
      return '';
    }
    $lastDate = $result->fetchAssoc();
    if(!$lastDate) {return '';}
    return $lastDate['date'];
  }
  
  /**
   * matchbackTransaction
   *
   * @return Transaction
   * @noinspection PhpUnused
   * used in ImportMatchbacksBatch.php
   */
  public function matchbackTransaction(): Transaction
  {
    return  $this->connection->startTransaction();
  }
  
  /**
   * matchbackEmailCheck
   *
   * Check the email inbox for a new matchback email with an attachment with records
   * for each ballot received.   There will be a lot of duplicates because the counties
   * do not process ballot records sequentially.
   *
   * @return array
   * @noinspection PhpUnused
   * Used in nlpservices.module
   */
  public function matchbackEmailCheck(): array
  {
    $queueItems = [];
    $items = $this->imapObj->checkEmail('matchback');
    if(empty($items)) {return [];}
    
    foreach ($items as $item) {
      $columnNames = str_getcsv($item['header']);
      
      $itemInfo = [];
      $itemInfo['progress'] = 'chunk';
      $itemInfo['subject'] = $item['subject'];
      $itemInfo['month'] = $item['month'];
      $itemInfo['day'] = $item['day'];
  
      $fieldPos = $this->headerValidate($columnNames);
      if(!$fieldPos['ok']) {continue;}
      $reportCount = count($item['reports']);
      if ($reportCount <= 1) {continue;}
      $pos = $fieldPos['pos'];
      $posKeys = array_keys($pos);
      $itemInfo['header'] = implode(',',$posKeys);
  
      $recordCount = 0;
      $queueIndex = 0;
      $itemInfo['reportCount'] = $reportCount;
      $queueItems[$queueIndex] = $itemInfo;
      foreach ($item['reports'] as $rowRaw) {
        $reportRaw = str_getcsv($rowRaw);
        $report = array_map(function ($index) use ($reportRaw) {
          return trim(strip_tags(htmlentities(stripslashes($reportRaw[$index] ?? ''), ENT_QUOTES)));
        }, $pos);
        $queueItems[$queueIndex]['reports'][] = json_encode($report);
        $recordCount++;
        if($recordCount == self::MATCHBACK_MAX_QUEUE) {
          $queueIndex++;
          $itemInfo['reportCount'] = $reportCount;
          $recordCount = 0;
          $queueItems[$queueIndex] = $itemInfo;
          $queueItems[$queueIndex]['reports'] = [];
        }
      }
    }
    return $queueItems;
  }
 
  /**
   * headerValidate
   *
   * Check if the header record contains the required fields.  If not, something is wrong
   * with the script used by the DPO to create the attachment.
   *
   * @param array $headerRaw
   * @return array
   */
  public function headerValidate( array $headerRaw ): array
  {
    $fieldPos = $this->decodeMatchbackHdr($headerRaw);
    if(!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        Drupal::logger('nlpservices')->notice($errMsg);
      }
    }
    return $fieldPos;
  }
  
  /**
   * processMatchbackQueueReports
   *
   * Process a batch of matchback records from the queue. If the ballot-received date
   * is already recorded, skip the record.  If the ballot-received date is newer than
   * the latest recorded date, update the latest recorded date.
   *
   * Returns an array of counts with the following keys:
   *  recordCount - total number of records in this matchback update.
   *  newRecordCount - number of new records written to the database.
   *  skippedRecordCount - the number of records that were already in the database and were skipped.
   *  rejectedRecordCount - the number of records that were rejected, either because the vanid or date was empty.
   *
   * @param array $reports
   * @return array
   * @noinspection PhpUnused
   *  Used in nlpservices_matchback_queue.php
   */
  public function processMatchbackQueueReports(array $reports): array
  {
    if(empty($reports)) {return [];}
    $latestMatchbackDate = $this->getLatestMatchbackDate();
    $latestMatchbackTime = strtotime($latestMatchbackDate);
  
    $recordCount = $newRecordCount = $skippedRecordCount = $rejectedRecordCount = 0;
    foreach ($reports as $reportRaw) {
  
      $report = json_decode($reportRaw,true);
      $recordCount++;
      
      $br = !empty($report['ballotReceived']);
      if ($br) {
        $vanid = $report['vanid'];
        if(empty($vanid)) {
          $rejectedRecordCount++;
          continue;
        }
    
        // If the record already exists, skip the insert.
        $exists = $this->matchbackExists($vanid);
        if($exists) {
          $skippedRecordCount++;
          continue;
        }
        $ballotReceivedDate = $report['ballotReceived'];
        $ballotReceivedTime = strtotime($ballotReceivedDate);  // Convert US date to time.
        if($ballotReceivedTime==0) {
          $rejectedRecordCount++;
          continue;
        }
        $newRecordCount++;
        $convertedDate = date('Y-m-d',$ballotReceivedTime);  // Convert to ISO date.
        if($ballotReceivedTime > $latestMatchbackTime) {
          $latestMatchbackTime = $ballotReceivedTime;
          $latestMatchbackDate = $convertedDate;
        }
        $this->insertMatchbacks($vanid,$convertedDate);
      }
     
    }
    $this->setLatestMatchbackDate($latestMatchbackDate);
    $this->flushMatchbacks();
    return [
      'recordCount'=>$recordCount,
      'newRecordCount'=>$newRecordCount,
      'skippedRecordCount'=>$skippedRecordCount,
      'rejectedRecordCount'=>$rejectedRecordCount,];
  }
  
}
