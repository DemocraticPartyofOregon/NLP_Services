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
  const int MATCHBACK_BATCH_SIZE = 1000;

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
        nlp_debug_msg('Insert Matchback fail.', $e);
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
      nlp_debug_msg('Flush Matchback fail.', $e);
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
      nlp_debug_msg('matchbackExists', $e);
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
      nlp_debug_msg('getMatchbackDate', $e);
      return '';
    }
    $matchback = $result->fetchAssoc();
    if(empty($matchback)) {return '';}
    return empty( $matchback['date'])?'': $matchback['date'];
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
      nlp_debug_msg('setLatestMatchbackDate', $e);
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
      nlp_debug_msg('getLatestMatchbackDate', $e);
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
   * matchbackEmailFetch
   *
   * Check the email inbox for a new matchback email, download the attachment,
   * save it to a temp file, and return the file path.  All CSV parsing is
   * deferred to the queue worker, so hook_cron returns quickly.
   *
   * @return string  Path to temp file, or '' if no email was found.
   */
  public function matchbackEmailFetch(): string
  {
    $items = $this->imapObj->checkEmail('matchback');
    if (empty($items)) { return ''; }
    foreach ($items as $item) {
      if (empty($item['reports'])) { continue; }
      $columnNames = str_getcsv($item['header'], escape: '');
      $fieldPos = $this->headerValidate($columnNames);
      if (!$fieldPos['ok']) { continue; }
      $filePath = sys_get_temp_dir() . '/nlp_matchback_' . time() . '.csv';
      $fh = fopen($filePath, 'w');
      if (!$fh) {
        Drupal::logger('nlpservices')->notice('matchbackEmailFetch: Failed to create temp file.');
        return '';
      }
      fwrite($fh, $item['header'] . "\n");
      foreach ($item['reports'] as $row) {
        fwrite($fh, $row . "\n");
      }
      fclose($fh);
      return $filePath;
    }
    return '';
  }

  /**
   * processMatchbackFile
   *
   * Read the temp CSV file written by matchbackEmailFetch() and import its
   * records into the matchback table using INSERT IGNORE, so no per-record
   * SELECT is needed.  Deletes the file when done.
   *
   * @param string $filePath
   * @return array  Counts: recordCount, newRecordCount, skippedRecordCount, rejectedRecordCount
   */
  public function processMatchbackFile(string $filePath): array
  {
    $counts = ['recordCount' => 0, 'newRecordCount' => 0, 'skippedRecordCount' => 0, 'rejectedRecordCount' => 0];
    if (!file_exists($filePath)) {
      Drupal::logger('nlpservices')->notice("processMatchbackFile: File not found: $filePath");
      return $counts;
    }
    $fh = fopen($filePath, 'r');
    if (!$fh) {
      Drupal::logger('nlpservices')->notice("processMatchbackFile: Cannot open: $filePath");
      return $counts;
    }
    $headerRow = fgetcsv($fh, escape: '');
    if (empty($headerRow)) {
      fclose($fh);
      return $counts;
    }
    $fieldPos = $this->decodeMatchbackHdr($headerRow);
    if (!$fieldPos['ok']) {
      fclose($fh);
      return $counts;
    }
    $pos = $fieldPos['pos'];
    $latestMatchbackDate = $this->getLatestMatchbackDate();
    $latestMatchbackTime = empty($latestMatchbackDate) ? 0 : strtotime($latestMatchbackDate);
    $batch = [];
    while (($row = fgetcsv($fh, escape: '')) !== FALSE) {
      $counts['recordCount']++;
      $ballotReceived = isset($pos['ballotReceived']) ? ($row[$pos['ballotReceived']] ?? '') : '';
      if (empty($ballotReceived)) {
        $counts['skippedRecordCount']++;
        continue;
      }
      $vanid = (int) ($row[$pos['vanid']] ?? 0);
      if (empty($vanid)) {
        $counts['rejectedRecordCount']++;
        continue;
      }
      $ballotReceivedTime = strtotime($ballotReceived);
      if (empty($ballotReceivedTime)) {
        $counts['rejectedRecordCount']++;
        continue;
      }
      $convertedDate = date('Y-m-d', $ballotReceivedTime);
      if ($ballotReceivedTime > $latestMatchbackTime) {
        $latestMatchbackTime = $ballotReceivedTime;
        $latestMatchbackDate = $convertedDate;
      }
      $batch[] = ['vanid' => $vanid, 'date' => $convertedDate];
      if (count($batch) >= self::MATCHBACK_BATCH_SIZE) {
        $inserted = $this->insertIgnoreMatchbacks($batch);
        $counts['newRecordCount'] += $inserted;
        $counts['skippedRecordCount'] += count($batch) - $inserted;
        $batch = [];
      }
    }
    if (!empty($batch)) {
      $inserted = $this->insertIgnoreMatchbacks($batch);
      $counts['newRecordCount'] += $inserted;
      $counts['skippedRecordCount'] += count($batch) - $inserted;
    }
    fclose($fh);
    unlink($filePath);
    if (!empty($latestMatchbackDate)) {
      $this->setLatestMatchbackDate($latestMatchbackDate);
    }
    return $counts;
  }

  /**
   * insertIgnoreMatchbacks
   *
   * Bulk-insert a batch of vanid/date records using INSERT IGNORE so that
   * duplicate vanids (already in the table) are silently skipped without
   * requiring a prior SELECT per record.  Returns the number of rows actually
   * inserted.
   *
   * @param array $records  Each element: ['vanid' => int, 'date' => string]
   * @return int
   */
  private function insertIgnoreMatchbacks(array $records): int
  {
    if (empty($records)) { return 0; }
    $placeholders = [];
    $args = [];
    foreach ($records as $i => $record) {
      $placeholders[] = "(:vanid$i, :date$i)";
      $args[":vanid$i"] = $record['vanid'];
      $args[":date$i"] = $record['date'];
    }
    try {
      $result = $this->connection->query(
        'INSERT IGNORE INTO {nlp_matchback} (vanid, date) VALUES ' . implode(',', $placeholders),
        $args
      );
      return (int) $result->rowCount();
    } catch (Exception $e) {
      Drupal::logger('nlpservices')->notice('insertIgnoreMatchbacks: ' . $e->getMessage());
      return 0;
    }
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

}
