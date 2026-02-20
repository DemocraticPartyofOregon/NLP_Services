<?php

namespace Drupal\nlpservices;

//use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database;

class NlpReports {
  
  const string NLP_RESULTS_TBL = 'nlp_results';
  const int BATCH_LIMIT = 1000;
  const string CONTACT = 'contact';
  const string SURVEY = 'Survey';
  const string POSTCARD = 'Mailed postcard';
  public const int MAX_COMMENT = 190;
  const int MULTI_INSERT = 200;
  const int BATCH = 50;
  
  public array $records = [];
  private int $sqlCnt = 0;
  private int $batchCnt = 0;
  private array $reportFields;  // Column names from the report table.
  private string $cycle;
  protected NlpConfiguration $nlpConfiguration;
  protected Connection $connection;
  
  public function __construct( $nlpConfiguration,  $connection) {
    $this->nlpConfiguration = $nlpConfiguration;
    $this->connection = $connection;
    $this->reportFields = $this->getColumnNames();
    unset($this->reportFields[0]);  // reportIndex.
    
    $this->cycle = $this->nlpConfiguration->getCycle();
  }
  
  public static function create(ContainerInterface $container): NlpReports
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('database'),
    );
  }
  
  /**
   * getNlpTypeReports
   *
   * Get all the current election NLP reports for a voter for a specific type of report.
   *
   * @param int $vanid
   * @param string $type
   * @return array
   */
  public function getNlpTypeReports(int $vanid,string $type): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanid);
      $query->condition('type',$type);
      $query->condition('cycle',$this->cycle);
      $query->orderBy('contactDate', 'DESC');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlpTypeReports', $e->getMessage() );
      return [];
    }
    $voterReports = [];
    do {
      $report = $result->fetchAssoc();
      if(!$report) {break;}
      $voterReports[] = $report;
    } while (TRUE);
    return $voterReports;
  }
  
  /**
   * getNlpReports
   *
   * Get all the NLP reports for a voter for all known past elections.  Reports for
   * elections more than a decade old are not in the database.
   *
   * @param $vanid
   * @return array
   */
  public function getNlpReports($vanid): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanid);
      $query->orderBy('contactDate', 'DESC');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlpReports', $e->getMessage() );
      return [];
    }
    $voterReports = [];
    do {
      $report = $result->fetchAssoc();
      if(!$report) {break;}
      $voterReports[$report['vanid']][] = $report;
    } while (TRUE);
    return $voterReports;
  }
  
  /**
   * getCanvassResults
   *
   * Get the most recent canvass results for a voter.  Array keys are:
   *  attempt - the latest attempt to contact the voter.
   *  deceased - the latest report that the voter was reported as deceased.
   *  moved - the latest report that the voter was reported as moved.
   *  postcard - the latest report that a postcard was sent.
   *  pledgeToVote - the latest response to the pledge-to-vote survey question.
   *  hostile - the latest report that the voter was hostile.
   *  note - the latest note entered by the voter.
   *
   *  The value of the array is an array of the contact information.  The array keys are:
   *    vanid - the voter's vanid.
   *    contactDate - the date the report was entered.
   *    contactType - the type of contact.
   *    contactMessage - the message entered by the voter.
   *    text - the text of the report.
   *    reportIndex - the database index of the report.
   '
   *
   * @param int $vanid
   * @return array - array of reports for the voter.  The array is indexed by a report type.
   */
  public function getCanvassResults(int $vanid): array
  {
    $cycle = $this->cycle;
    $pledgeSurveyQuestion = $this->nlpConfiguration->getPledgeSurveyQuestion();
    $pledgeToVoteQid = $pledgeSurveyQuestion['surveyQuestionId'];
    
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanid);
      $query->condition('cycle',$cycle);
      $query->orderBy('contactDate', 'DESC');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getCanvassResults', $e->getMessage() );
      return [];
    }
    $latestReports = [];
    do {
      $report = $result->fetchAssoc();
      if(!$report) {break;}
      $type = $report['type'];
      $vanid = $report['vanid'];
      $contactDate = $report['contactDate'];
      $contactMessage = (!empty($report['value']))?$report['value']:'';
      
      $latestContactInfo = [
        'vanid' => $vanid,
        'contactDate' => $contactDate,
        'contactType' => $report['contactType'],
        'contactMessage' => $contactMessage,
      ];
      switch ($type) {
        case 'contact':
        case 'Contact':
          // Get the latest report from the NL for an attempt to contact the voter.
          if (empty($latestReports['attempt'])) {
            $latestReports['attempt'] = $latestContactInfo;
          } elseif ($contactDate > $latestReports['attempt']['contactDate']) {
            $latestReports['attempt'] = $latestContactInfo;
          }
          // The voter was reported as deceased.
          if($contactMessage == 'deceased' OR $contactMessage == 'Deceased') {
            $latestReports['deceased'] = $latestContactInfo;
            break;
          }
          // The voter was reported as moved.
          if($contactMessage == 'moved' OR $contactMessage == 'Moved') {
            $latestReports['moved'] = $latestContactInfo;
            $latestReports['moved']['address'] = $report['text'];
            break;
          }
          // Get the latest report that a postcard was sent.   (Probably not necessary to check for
          // the date as it is unlikely an NL set two cards.)
          if($report['contactType'] == 'Postcard') {
            if (empty($latestReports['postcard'])) {
              $latestReports['postcard'] = $latestContactInfo;
            } elseif ($contactDate > $latestReports['attempt']['contactDate']) {
              $latestReports['postcard'] = $latestContactInfo;
            }
          }
          break;
          
        case 'Survey':
          // Get the latest response to the survey question.
          if($report['qid'] == $pledgeToVoteQid) {
            if (empty($latestReports['pledgeToVote'])) {
              $latestReports['pledgeToVote'] = $latestContactInfo;
            } elseif ($contactDate > $latestReports['pledgeToVote']['contactDate']) {
              $latestReports['pledgeToVote'] = $latestContactInfo;
            }
          }
          break;
          
        case 'Activist':
          // A report that the voter was hostile.
          if($report['text'] == 'NLPHostile' AND $contactMessage == 1) {
            $latestReports['hostile'] = $latestContactInfo;
          }
          break;
          
        case 'Comment':
          // A note was reported, make sure we have the latest note.
          $latestContactInfo['text'] = $report['text'];
          $latestContactInfo['reportIndex'] = $report['reportIndex'];
          if (empty($latestReports['note'])) {
            $latestReports['note'] = $latestContactInfo;
          } elseif ($contactDate > $latestReports['note']['contactDate']) {
            $latestReports['note'] = $latestContactInfo;
          }
          break;
      }
      
    } while (TRUE);
    return $latestReports;
  }
  
  /**
   * getNlReportsForVoters
   *
   * Get the current election reports for each voter in the given array of voters.
   *
   * @param array $voterArray
   * @return array - Array of reports for each voter.  The array is indexed by mcid.
   */
  public function getNlReportsForVoters(array $voterArray): array
  {
    $cycle = $this->cycle;
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid', $voterArray, 'IN');
      $query->condition('cycle',$cycle);
      $query->condition('type','Activist','<>');
      $query->orderBy('contactDate', 'DESC');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlReportsForVoters', $e->getMessage() );
      return [];
    }
    $voterReports = [];
    do {
      $report = $result->fetchAssoc();
      if(!$report) {break;}
      $voterReports[$report['mcid']][] = $report;
    } while (TRUE);
    return $voterReports;
  }
  
  /**
   * reportExists
   *
   * Check if a report with the specified contactId already exists.   This function is used
   * to avoid duplicate reports for MiniVAN users.   It's required since MiniVAN users may not
   * be timely with syncing their canvass results.
   *
   * @param string $type
   * @param int $contactId
   * @return bool
   */
  public function reportExists(string $type,int $contactId): bool
  {
    $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
    $query->fields('r');
    $query->condition('type',$type);
    $query->condition('contactID',$contactId);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('reportExists', $e->getMessage() );
      return FALSE;
    }
    $report = $result->fetchAssoc();
    if(empty($report)) {return FALSE;}
    return TRUE;
  }
  
  /**
   * displayNlReports
   *
   * Convert an array of reports into an array of text suitable for display for a voter.
   *
   * @param array $voterReports
   * @return array
   */
  public function displayNlReports(array $voterReports): array
  {
    $voterReportsDisplay = [];
    $voterReportsDisplay['display'] = '';
    $voterReportsDisplay['displayLines'] = 0;
    if(empty($voterReports)) {return $voterReportsDisplay;}
    foreach ($voterReports as $reports) {
      foreach ($reports as $report) {
        if(empty($report['type'])) {continue;}
        $reportType = strtolower($report['type']);
        switch ($reportType) {
          case 'contact':
            $reportDisplay = $report['value'].' '.$report['text'];
            break;
          case 'comment':
            $reportDisplay = $report['text'];
            break;
          case 'survey':
            $reportDisplay = $report['text'].': '.$report['value'];
            break;
          case 'activist':
            $activistCodeName = $report['text'];
            $activistCodeValue = $report['value'];
            $activistDisplay = ($activistCodeValue)?'Yes':'No';
            $reportDisplay = $activistCodeName.': '.$activistDisplay;
            break;
        }
        if(!empty($reportDisplay)) {
          if (!empty($voterReportsDisplay['display'])) {
            $voterReportsDisplay['display'] .= '<br/>';
          }
          $newReport = $report['contactDate'].'['.$report['contactType'].'] '.$reportDisplay;
          $voterReportsDisplay['display'] .= $newReport;
          $lines = ceil(strlen($newReport)/60);
          $voterReportsDisplay['displayLines'] = $voterReportsDisplay['displayLines'] + $lines;
        }
      }
    }
    return $voterReportsDisplay;
  }
  
  /**
   * getNlpReport
   *
   * Get a voter contact report from the result table given the index.
   *
   * @param int $reportIndex
   * @return array
   */
  public function getNlpReport(int $reportIndex): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('reportIndex',$reportIndex);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('e', $e->getMessage() );
      return [];
    }
    $report = $result->fetchAssoc();
    if(empty($report)) return [];
    return $report;
  }
  
  /**
   * setNlReport
   *
   * Insert a voter contact report into the result table.
   *
   * @param array $canvassResult
   * @return int
   */
  public function setNlReport(array $canvassResult): int
  {
    // Insert the reported information into the result table.
    if(empty($canvassResult['contactId'])) {
      $canvassResult['contactId'] = 0;
    }
    
    if(empty($canvassResult['cycle'])) {
      $cycle = $this->nlpConfiguration->getCycle();
      $canvassResult['cycle'] = $cycle;
    }
    
    $fields = [];
    foreach ($this->reportFields as $field)  {
      if(key_exists($field,$canvassResult)) {
        $fields[$field] = $canvassResult[$field];
      }
    }
    
    try {
      $reportIndex = $this->connection->insert(self::NLP_RESULTS_TBL)
        ->fields($fields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setNlReport', $e->getMessage() );
      return 0;
    }
    return $reportIndex;
  }
  
  /**
   * updateComment
   *
   * Update the comment for a voter.
   *
   * @param array $canvassResult
   * @return void
   */
  public function updateComment(array $canvassResult): void
  {
    try {
      $this->connection->update(self::NLP_RESULTS_TBL)
        ->fields([
          'text' => $canvassResult['text'],
        ])
        ->condition('reportIndex',$canvassResult['reportIndex'])
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateComment', $e->getMessage() );
      return;
    }
  }
  
  /**
   * getComment
   *
   * Get the most recent comment entered by this NL for a voter.  There should be only one, and it can be
   * edited and replaced by the NL.  However, if a comment is entered via MiniVAN, that one
   * takes precedence and becomes the only one that can be edited.
   *
   * @param $req
   * @return array
   */
  public function getComment($req): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->addField('v','text');
      $query->addField('v','reportIndex');
      $query->condition('cycle',$this->cycle);
      $query->condition('mcid',$req['mcid']);
      $query->condition('vanid',$req['vanid']);
      $query->condition('type','Comment');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getComment', $e->getMessage() );
      return [];
    }
    $newestComment = ['reportIndex'=>NULL,'text'=>'', ];
    do {
      $comment = $result->fetchAssoc();
      if(empty($comment)) {break;}
      if($comment['reportIndex'] >= $newestComment['reportIndex']) {
        $newestComment = $comment;
      }
    } while (TRUE);
    return ['reportIndex'=>$newestComment['reportIndex'],'text'=>$newestComment['text']];
  }
  
  /**
   * getNlpAcReport
   *
   * Get the most recent report for an activist code.  This is a generic request, and the only
   * activist code in use for a voter is NLP Hostile.
   *
   * @param $vanId
   * @return array
   */
  public function getNlpAcReport($vanId): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$vanId);
      $query->condition('type','Activist');
      $query->orderBy('contactDate', 'DESC');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getNlpAcReport', $e->getMessage() );
      return [];
    }
    $report = $result->fetchAssoc();
    if(!$report) {return [];}
    $acName = $report['text'];
    $voterReport[$acName] = $report;
    return $voterReport;
  }
  
  /**
   * getAcReportIndex
   *
   * Get the report record for a specific activist code.  While this is
   * a generic request, the only activist code in use for a voter is NLP Hostile.
   *
   * @param int $vanid
   * @param string $activistCodeName
   * @return int - database index for the record with the AC report.
   */
  public function getAcReportIndex(int $vanid,string $activistCodeName): int
  {
    $cycle = $this->cycle;
    try {$query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      $query->addField('r','reportIndex');
      $query->condition('vanid',$vanid);
      $query->condition('cycle',$cycle);
      $query->condition('type','Activist');
      $query->condition('text',$activistCodeName);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getAcReportIndex', $e->getMessage() );
      return 0;
    }
    $report = $result->fetchAssoc();
    if(!$report) {return 0;}
    return $report['reportIndex'];
  }
  
  /**
   * getReport
   *
   * Return the most recent report that meets the criteria for this voter.
   *
   * The request criteria is either a specific database record by contactId or
   * a type and value which targets a specific type of contact.  Also, the election
   * cycle can be specified so only the current election is referenced.
   *
   * @param array $request
   * @return array - The most recent report for this voter.
   */
  public function getReport(array $request): array
  {
    // Get the request reports for this voter.
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->fields('v');
      $query->condition('vanid',$request['vanid']);
      if(!empty($request['contactId'])) {
        $query->condition('contactID',$request['contactId']);
      } else {
        $query->condition('type',$request['type']);
        $query->condition('value',$request['value']);
      }
      if(!empty($request['cycle'])) {
        $query->condition('cycle',$request['cycle']);
      }
      $query->orderBy('reportIndex');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getReport', $e->getMessage() );
      return [];
    }
    // Now find the newest report.
    $report = [];
    $reportIndex = 0;
    do {
      $dbReport = $result->fetchAssoc();
      if(!$dbReport) {break;}
      if($dbReport['reportIndex'] > $reportIndex) {
        $reportIndex = $dbReport['reportIndex'];
        $report = $dbReport;
      }
    } while (TRUE);
    return $report;
  }
  
  /**
   * mergeReport
   *
   * Update one or more fields in a canvass report.
   *
   * @param array $canvassResult
   * @noinspection PhpUnused
   * Used in NlpMinivan
   */
  public function mergeReport(array $canvassResult): void
  {
    $reportIndex = $canvassResult['reportIndex'];
    $fields = [];
    foreach ($this->reportFields as $field)  {
      if(key_exists($field,$canvassResult)) {
        $fields[$field] = $canvassResult[$field];
      }
    }
    try {
      $this->connection->merge(self::NLP_RESULTS_TBL)
        ->keys(['reportIndex' => $reportIndex])
        ->fields($fields)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('mergeReport', $e->getMessage());
      return;
    }
  }
  
  /**
   * setNlAcReport
   *
   * Creates a report that the voter is hostile and should not be on our list of
   * target voters.
   * @param array $canvassResult
   */
  public function setNlAcReport(array $canvassResult): void
  {
    $reportIndex = $canvassResult['reportIndex'];
    $fields = [];
    foreach ($this->reportFields as $field)  {
      if(key_exists($field,$canvassResult)) {
        $fields[$field] = $canvassResult[$field];
      }
    }
    try {
      $this->connection->merge(self::NLP_RESULTS_TBL)
        ->keys(['reportIndex' => $reportIndex])
        ->fields($fields)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('setNlAcReport', $e->getMessage());
      return;
    }
  }
  
  /**
   * contactAttempt
   *
   * Return canvass results for this voter.
   *
   * @param int $vanid
   * @return array - Array of canvass results for this voter.  Array keys are:
   * 'attempt' - boolean indicating if at least one attempt was made to contact the voter.
   * 'survey' - boolean indicating if the voter has responded to the pledge-to-vote survey.
   */
  function contactAttempt(int $vanid): array
  {
    $canvassResult = [
      'attempt'=> FALSE,
      'survey' => FALSE, ];
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      $query->fields('r');
      $query->condition('cycle',$this->cycle);
      $query->condition('vanid',$vanid);
      $orGroup1 = $query->orConditionGroup()
        ->condition ('r.Type', self::SURVEY )
        ->condition ('r.Type', self::CONTACT );
      $query->condition($orGroup1);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('contactAttempt', $e->getMessage() );
      return $canvassResult;
    }
    do {
      $report = $result->fetchAssoc();
      if(empty($report)) {break;}
      $canvassResult['attempt'] = TRUE;
      if($report['type'] == 'Survey') {
        $canvassResult['survey'] = TRUE;
      }
    } while (TRUE);
    return $canvassResult;
  }
  
  /**
   * voterSentPostcard
   *
   * Check if the voter has been sent a postcard by the NL.
   *
   * @param $vanid
   * @return bool
   */
  function voterSentPostcard($vanid): bool
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      $query->addField('r','vanid');
      $query->condition('vanid',$vanid);
      $query->condition('cycle',$this->cycle);
      $query->condition('type',self::CONTACT);
      $orGroup = $query->orConditionGroup()
        ->condition('value',self::POSTCARD)
        ->condition('value','Mailed');
      $query->condition($orGroup);
      $contactCount = $query->countQuery()->execute()->fetchField();
    }
    catch (Exception $e) {
      nlp_debug_msg('voterSentPostcard', $e->getMessage() );
      return FALSE;
    }
    return $contactCount>0;
  }
  
  /**
   * getCommitteeReportCounts
   *
   * Get the count of attempted contacts and count of voters that responded to the
   * pledge to vote survey question for each NL in the county in the current election.
   * The count is for NLs that have made at least one report of a voter contact attempt.
   *
   * @param $committee
   * @return array - array of counts for each NL in the county, indexed by mcid. The keys are
   * 'attempts' and 'contacts'.
   *
   */
  public function getCommitteeReportCounts($committee): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      $query->fields('r');
      $query->condition('committee',$committee);
      $query->condition('cycle',$this->cycle);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getCountyReportCounts', $e->getMessage() );
      return [];
    }
    $voterStatus = [];
    do {
      $report = $result->fetchAssoc();
      if(!$report) {break;}
      $vanid = $report['vanid'];
      $voterStatus[$vanid]['mcid'] = $report['mcid'];
      $voterStatus[$vanid]['attempt'] = TRUE;
      if($report['type']==self::SURVEY) {
        $voterStatus[$vanid]['survey'] = TRUE;
      }
    } while (TRUE);
    $counts = array();
    foreach ($voterStatus as $status) {
      $mcid = $status['mcid'];
      if(empty($counts[$mcid]['attempts'])) {
        $counts[$mcid]['attempts'] = 1;
        $counts[$mcid]['contacts'] = 0;
      } else {
        $counts[$mcid]['attempts']++;
      }
      if(!empty($status['survey'])) {
        $counts[$mcid]['contacts']++;
      }
    }
    return $counts;
  }
  
  /**
   * getColumnNames
   *
   * Get the names of the columns in the report database.
   *
   * @return array
   */
  public function getColumnNames(): array
  {
    try {
      $select = "SHOW COLUMNS FROM  {".self::NLP_RESULTS_TBL.'}';
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
   * getReportCount
   *
   * Get the number of reports in the database for a given election cycle.
   *
   * @param string $cycle
   * @return int
   */
  public function getReportCount(string $cycle=''): int
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      if(!empty($cycle)) {
        $query->condition('cycle',$cycle);
      }
      $numRows = $query->countQuery()->execute()->fetchField();
    }
    catch (Exception $e) {
      nlp_debug_msg('getReportCount', $e->getMessage() );
      return 0;
    }
    return $numRows;
  }
  
  /**
   * selectAllReports
   *
   * Select a batch of report records from the database.
   *
   * @param int $nextRecord - The record number to start at.
   * @param string $cycle - The election cycle to get reports for.
   * @return Database\StatementInterface|null - A query result for later access.
   * @noinspection PhpUnused
   * Used in ExportVoterContactReportsBatch
   */
  public function selectAllReports(int $nextRecord,string $cycle): ?Database\StatementInterface
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'r');
      $query->fields('r');
      if(!empty($cycle)) {
        $query->condition('cycle',$cycle);
      }
      $query->range($nextRecord, self::BATCH_LIMIT);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('selectAllReports', $e->getMessage() );
      return NULL;
    }
    return $result;
  }
  
  /**
   * prepareExportRecord
   *
   * Convert a historical voter contact record to the fields currently used in the
   * report database.
   *
   * @param array $resultArray - An array of the fields from the historical voter contact record.
   * @return array - An array of the fields currently used in the report database.
   * @noinspection PhpUnused
   * Used in ExportVoterContactReportsBatch
   */
  public function prepareExportRecord(array $resultArray): array
  {
    $record = [];
    foreach ($resultArray as $key=>$recordField) {
      switch ($key) {
        case 'text':
          if(!empty($recordField)) {
            $record[$key] = str_replace("\r\n", "<br>", $recordField);
          } else {
            $record[$key] = '';
          }
          break;
        default:
          $record[$key] = $recordField;
          break;
      }
    }
    return $record;
  }
  
  /**
   * emptyNlTable
   *
   * This function will empty the table of historical voter contacts made by NLP volunteers.
   *
   * @return void
   */
  public function emptyNlTable(): void
  {
    try {
      $this->connection->truncate(self::NLP_RESULTS_TBL)->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('emptyNlTable', $e->getMessage() );
    }
  }
  
  /**
   * getKnownCycles
   *
   * Get the cycle names of all the known elections where NLP was active.
   *
   * @return array - An array of cycle names.
   */
  public function getKnownCycles(): array
  {
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->addField('v','cycle');
      $query->distinct();
      $query->orderBy('cycle');
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getKnownCycles', $e->getMessage() );
      return [];
    }
    $cycles = [];
    do {
      $dbReport = $result->fetchAssoc();
      if(!$dbReport) {break;}
      $cycles[] = $dbReport['cycle'];
    } while (TRUE);
    return $cycles;
  }
  
  /**
   * insertNlBatch
   *
   * Insert a batch of reports into the database.
   *
   * @return void
   * @noinspection PhpUnused
   * Used in ImportReportsBatch
   */
  private function insertNlBatch(): void
  {
    $transaction = $this->connection->startTransaction();
    try {
      $query = $this->connection->insert(self::NLP_RESULTS_TBL)
        ->fields($this->reportFields);
      foreach ($this->records as $record) {
        $query->values($record);
      }
      $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('insertNlBatch', $e->getMessage() );
    }
    unset($transaction);
  }
  
  /**
   * insertNlReports
   *
   * Add a record to a batch of reports.  If the batch is full, insert the batch of records
   * to the database.  Used to restore the history of voter contacts, which can take a very long
   * time.
   *
   * Returns TRUE if the batch was submitted, FALSE if it was not.
   *
   * @param array $report
   * @return bool - The record was the last in the batch, and it was submitted to the database.
   * @noinspection PhpUnused
   * Used in ImportReportsBatch
   */
  public function insertNlReports(array $report): bool
  {
    $record = [];
    foreach ($this->reportFields as $key) {
      if(!empty($report[$key]) AND $report[$key]!='NULL') {
        $record[$key] = $report[$key];
      } else {
        $record[$key] = NULL;
      }
    }
    $batchSubmit = FALSE;
    $this->records[$this->sqlCnt++] = $record;
    
    if ($this->sqlCnt == self::MULTI_INSERT) {
      $this->sqlCnt = 0;
      $this->batchCnt++;
      $this->insertNlBatch();
      $this->records = array();
      if($this->batchCnt==self::BATCH) {
        $this->batchCnt=0;
        $batchSubmit = TRUE;
      }
    }
    return $batchSubmit;
  }
  
  /**
   * flushNlReports
   *
   * The restoration of the voter contact history is complete.  If there are any records left
   * in the batch, insert them to the database.
   *
   * @return void
   * @noinspection PhpUnused
   * Used in ImportReportsBatch
   */
  public function flushNlReports(): void
  {
    if(empty($this->records)) {return;}
    $this->insertNlBatch();
    $this->records = [];
  }
  
  /**
   * getReportsById
   *
   * Return an array of database indexes for all voter contacts made by this NL.
   * @param int $mcid - the vanId in MyCampaign of the NL.
   * @return array
   */
  public function getReportsById(int $mcid): array
  {
    // Get the report indexes for this NL.
    try {
      $query = $this->connection->select(self::NLP_RESULTS_TBL, 'v');
      $query->addField('v','reportIndex');
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('getReportsById', $e->getMessage() );
      return [];
    }
    $reports = [];
    do {
      $dbReport = $result->fetchAssoc();
      if(!$dbReport) {break;}
      $reportIndex = $dbReport['reportIndex'];
      $reports[$reportIndex] = $reportIndex;
    } while (TRUE);
    return $reports;
  }
  
  /**
   * deleteReport
   *
   * Delete a report from the database.
   *
   * @param int $reportIndex
   * @return void
   * @noinspection PhpUnused
   * Used in CancelMovedReport
   */
  public function deleteReport(int $reportIndex): void
  {
    try {
      $this->connection->delete(self::NLP_RESULTS_TBL)
        ->condition('reportIndex', $reportIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteReport', $e->getMessage() );
    }
  }
  
}
