<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpMinivan {

  const int MINIVAN_MAX_QUEUE = 200;
  const int CANVASSED = 11;  // Report from VAN that voters were canvassed - redundant.
  const string MISSING_NL_STATE = 'nlpservices.minivan_missing_nl_notified';
  const int MISSING_NL_NOTIFY_INTERVAL = 86400;  // Warn a coordinator at most once a day per NL.

  private array $minivanHdr = [
    'survey' => [
      'vanid' => 'myv_van_id',
      'dateCanvassed' => 'date_canvassed',
      'dateCreated' => 'date_created',
      'inputTypeId' => 'input_type_id',
      'surveyQuestionId' => 'survey_question_id',
      'surveyResponseId' => 'survey_response_id',
      'contactId' => 'contacts_contact_id',
    ],
    'canvass' => [
      'vanid' => 'myv_van_id',
      'dateCanvassed' => 'date_canvassed',
      'dateCreated' => 'date_created',
      'inputTypeId' => 'input_type_id',
      'contactTypeId' => 'contact_type_id',
      'resultId' => 'result_id',
      'contactId' => 'contacts_contact_id',
      'noteText' => 'note_text',
    ],
    'activist' => [
      'vanid' => 'myv_van_id',
      'dateCreated' => 'date_created',
      'activistCodeId' => 'activist_code_id',
      'contactId' => 'contacts_activist_code_id',
    ],
  ];

  private array $fieldTypeInt = ['vanid', 'inputTypeId', 'contactTypeId', 'activistCodeId',
    'contactId', 'surveyQuestionId', 'surveyResponseId', ];

  public array $minivanCountKeys = [
    'recordCount','newRecordCount','skippedRecordCount','rejectedRecordCount','notNlpCount',
  ];

  private NlpReports $nlpReportsObj;
  private NlpVoters $voterObj;
  private NlpNls $nlsObj;
  protected NlpImap $imapObj;
  protected ConfigFactoryInterface $config;
  protected ApiSurveyQuestion $apiSurveyQuestionObj;
  protected DrupalUser $drupalUserObj;
  protected ApiNls $apiNlsObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpSendEmail $sendEmailObj;
  protected StateInterface $state;


  public function __construct( $config, $nlpReportsObj, $voterObj, $nlsObj, $imapObj,
                               $apiSurveyQuestionObj, $drupalUserObj, $apiNlsObj, $coordinatorsObj,
                               $sendEmailObj, $state ) {
    $this->config = $config;
    $this->nlpReportsObj = $nlpReportsObj;
    $this->voterObj = $voterObj;
    $this->nlsObj = $nlsObj;
    $this->imapObj = $imapObj;
    $this->apiSurveyQuestionObj = $apiSurveyQuestionObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->apiNlsObj = $apiNlsObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->sendEmailObj = $sendEmailObj;
    $this->state = $state;
  }

  public static function create(ContainerInterface $container): NlpMinivan
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.imap'),
      $container->get('nlpservices.api_survey_question'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.api_nls'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.send_email'),
      $container->get('state'),
    );
  }

  /**
   * decodeMinivanHdr
   *
   * Given an array of required fields, get the position of the fields in the header
   * so the values can get retrieved in the following records.
   *
   * Returns an array of field positions indexed by field name that are relevant for this particular
   * file type.  The keys are:
   *  - pos - array of field positions indexed by field name that are relevant fields.
   *  - err - array of errors that occurred.
   *  - ok - TRUE if there were no errors.
   *
   * @param array $fileHdr
   * @param array $requiredFields
   * @return array
   */
  private function decodeMinivanHdr(array $fileHdr,array $requiredFields): array
  {
    $hdrErr = $hdrPos = [];
    foreach ($requiredFields as $nlpKey => $vanField) {
      $found = FALSE;
      foreach ($fileHdr as $fileCol=>$fileColName) {
        if(trim($fileColName) == trim($vanField)) {
          $hdrPos[$nlpKey] = $fileCol;
          $found = TRUE;
          break;
        }
      }
      if(!$found) {
        $hdrErr[] = 'The MyCampaign export option "'.$vanField.'" is missing.';
      }
    }
    $fieldPos['pos'] = $hdrPos;
    $fieldPos['err'] = $hdrErr;
    $fieldPos['ok'] = empty($hdrErr);
    return $fieldPos;
  }

  /**
   * extractMinivanFields
   *
   * The raw record contains many fields, but only a few are needed to update the NLP Services database
   * for voter contacts reported by MiniVAN.  Extract just those fields.
   *
   * Returns an array of field values indexed by field name that are relevant for this particular
   * file type.
   *
   * @param array $rawRecord - array of field values from the DPO export.
   * @param array $hdrPos - array of field positions indexed by field name that are relevant fields.
   * @return array
   */
  public function extractMinivanFields(array $rawRecord,array $hdrPos): array
  {
    $record = [];
    foreach ($hdrPos as $nlpKey => $vanField) {
      if(in_array($nlpKey,$this->fieldTypeInt)) {
        $record[$nlpKey] = (int) ($rawRecord[$vanField] ?? '');
      } else {
        $record[$nlpKey] = $rawRecord[$vanField] ?? '';
      }
    }
    return $record;
  }

  /**
   * attachNlToResult
   *
   * Look up the NL and voter CD and populate the standard NL identity fields on $result.
   * Returns $result unchanged if the NL record is not found.
   *
   * @param array $result
   * @param int $vanid
   * @param int $mcid
   * @return array
   */
  private function attachNlToResult(array $result, int $vanid, int $mcid): array
  {
    $cd = $this->voterObj->getVoterCd($vanid);
    $nl = $this->nlsObj->getNlById($mcid);
    if(!empty($nl)) {
      $result['nickname'] = (empty($nl['nickname']))?$nl['firstName']:$nl['nickname'];
      $result['firstName'] = $nl['firstName'];
      $result['lastName'] = $nl['lastName'];
      $result['county'] = $nl['county'];
      $result['cd'] = $cd;
    }
    return $result;
  }

  /**
   * @param array $report
   * @param array $result
   * @param int $mcid
   * @param array $pledgeSurveyQuestion
   * @param int $cid
   * @return array
   * Used in ImportMinivanBatch.php
   * @noinspection PhpUnused
   */
  public function processSurvey(array $report,array $result, int $mcid, array $pledgeSurveyQuestion ,int $cid): array
  {
    $contactId = $report['contactId'];
    if($this->nlpReportsObj->reportExists('Survey',$contactId)) {  // The record was already processed.
      $action['processReport'] = FALSE;
      $action['counts']['skippedRecordCount'] = 1;
      return $action;
    }

    $qid = $report['surveyQuestionId'];
    $rid = $report['surveyResponseId'];
    if(empty($pledgeSurveyQuestion)) {  // No survey question is defined.
      $action['processReport'] = FALSE;
      $action['counts']['rejectedRecordCount'] = 1;
      return $action;
    }
    $vanid = $report['vanid'];
    if($pledgeSurveyQuestion['surveyQuestionId'] == $qid) {
      $title = $pledgeSurveyQuestion['name'];
      $surveyResponseList = $pledgeSurveyQuestion['responses'];
    } else {  // Not the defined survey question.
      $action['processReport'] = FALSE;
      $action['counts']['rejectedRecordCount'] = 1;
      return $action;
    }

    $response = '';
    if(!empty($surveyResponseList)) {
      if(!empty($surveyResponseList[$rid])) {
        $response = $surveyResponseList[$rid]['mediumName'];
      }
    }
    $result['type'] = 'Survey';
    $result['value'] = $response;
    $result['text'] = $title;
    $result['qid'] = $qid;
    $result['rid'] = $rid;
    $result['contactType'] = 'Walk';
    $result['cid'] = $cid;
    $result['contactId'] = $contactId;

    $result = $this->attachNlToResult($result, $vanid, $mcid);

    $action['processReport'] = TRUE;
    $action['mergeReport'] = FALSE;
    $action['type'] = 'pledgedToVote';
    $action['result'] = $result;
    $action['counts']['newRecordCount'] = 1;
    return $action;
  }

  /**
   * processCanvass
   *
   * Process a report for a voter contact attempt.  Also, a report may contain a note.
   *
   * @param array $report
   * @param array $result - Array of common values for all reports.
   * @param int $mcid
   * @param array $allowedResponseCodes
   * @param int $cid
   * @return array
   * Used in ImportMinivanBatch.php
   * @noinspection PhpUnused
   */
  public function processCanvass(array $report, array $result, int $mcid, array $allowedResponseCodes, int $cid): array
  {
    $action = [];

    if($report['resultId'] == self::CANVASSED) { // Remove the redundant report.
      $action['processReport'] = FALSE;
      return $action;
    }

    $contactId = $report['contactId'];
    $vanid = $report['vanid'];
    $reportExists = $this->nlpReportsObj->reportExists('contact',$contactId);
    if($reportExists) {
      $action['processReport'] = FALSE;
      $action['counts']['skippedRecordCount'] = 1;
      $action['counts']['newRecordCount'] = 0;
      return $action;
    }

    if(!empty($report['noteText'])) { // A note is included.
      $cd = $this->voterObj->getVoterCd($vanid);
      $nl = $this->nlsObj->getNlById($mcid);
      if(!empty($nl)) {  // The voter's turf can outlive the NL's record.
        $result['nickname'] = (empty($nl['nickname']))?$nl['firstName']:$nl['nickname'];
        $result['county'] = $nl['county'];
      }
      $result['cd'] = $cd;
      $report['mcid'] = $mcid;
      $action = $this->processNote($report,$result);
    }

    $rid = $report['resultId'];
    $contactTypeId = $report['contactTypeId'];

    $knownContactTypeName = $knownResponse = '';
    foreach ($allowedResponseCodes as $contactTypeName=>$responseCode) {
      if($responseCode['code'] == $contactTypeId) {
        if(in_array($rid,$responseCode['responses'])) {
          $knownContactTypeName = $contactTypeName;
          $knownResponses = array_flip($responseCode['responses']);
          $knownResponse = $knownResponses[$rid];
        }
        break;
      }
    }

    if(empty($knownContactTypeName)) {
      $action['processReport'] = FALSE;
      $action['counts']['rejectedRecordCount'] = 1;
      $action['counts']['newRecordCount'] = 0;
      unset($action[0]);
      return $action;
    }

    $result['contactType'] = 'Walk';
    $result['cid'] = $cid;
    $result['contactId'] = $contactId;
    $result['type'] = 'contact';
    $result['value'] = $knownResponse;
    $result['text'] = '';
    $result['qid'] = NULL;
    $result['rid'] = $rid;
    $result['rIndex'] = NULL;

    $result = $this->attachNlToResult($result, $vanid, $mcid);

    switch ($knownResponse) {
      case 'Not Home':
      case 'Refused':
      case 'Left Message/Lit':
        break;

      case 'Moved':
        $request['vanid'] = $vanid;
        $request['type'] = 'contact';
        $request['value'] = 'moved';
        $request['cycle'] = $result['cycle'];
        $report = $this->nlpReportsObj->getReport($request);
        $existingRIndex = (!empty($report['reportIndex']))?$report['reportIndex']:NULL;  // An entry for the Moved status exists.
        if(!empty($existingRIndex)) {
          $action['counts']['newRecordCount'] = 1;
          $action['processReport'] = FALSE;
          return $action;
        }
        $voterAddresses = $this->voterObj->getVoterAddresses($vanid);
        $addressEncode = [];
        foreach ($voterAddresses as $voterAddress) {
          $simpleAddress = $this->voterObj->addressExtract($voterAddress);
          $addressEncode = json_encode($simpleAddress);
          $movedStatus = 1;
          $turfIndex = $voterAddress['turfIndex'];
          $this->voterObj->setMovedStatus($turfIndex,$vanid,$movedStatus);
        }

        $result['rIndex'] = NULL;
        $result['value'] = 'moved';
        $result['text'] = $addressEncode;
        $action['processReport'] = TRUE;
        $action['mergeReport'] = FALSE;
        $action['result'] = $result;
        $action['type'] = 'attemptedContact';
        $action['counts']['newRecordCount'] = 1;
        return $action;

      case 'Deceased':

        // Check if this voter has already been reported as deceased.
        $request['vanid'] = $vanid;
        $request['type'] = 'contact';
        $request['value'] = 'deceased';
        $request['cycle'] = $result['cycle'];
        $report = $this->nlpReportsObj->getReport($request);

        $existingRIndex = (!empty($report['reportIndex']))?$report['reportIndex']:NULL;  // An entry for the Deceased status exists.
        if(!empty($existingRIndex)) {
          $action['processReport'] = FALSE;
          return $action;
        }

        // Remember the state of the NLP Deceased activist code in VoteBuilder.
        $voterStatus = $this->voterObj->getVoterStatus($vanid);
        $voterStatus['deceased'] = 1;
        $this->voterObj->setVoterStatus($vanid, $voterStatus);

        // This voter is being reported as deceased.  Record in NLP Services.
        $action['value'] = 'deceased';
        $action['processReport'] = TRUE;
        $action['mergeReport'] = FALSE;
        $action['type'] = 'attemptedContact';
        $action['result'] = $result;
        $action['counts']['newRecordCount'] = 1;
        return $action;
    }
    $action['processReport'] = TRUE;
    $action['mergeReport'] = FALSE;
    $action['type'] = 'attemptedContact';
    $action['result'] = $result;
    $action['counts']['newRecordCount'] = 1;
    return $action;
  }

  /**
   * processActivist
   *
   * Process a report to set or remove the NLPHostile activist code.
   *
   * @param array $report
   * @param array $result - Array of common values for all reports.
   * @param int $mcid
   * @param array $nlpHostile
   * @param int $cid - contactId.
   * @return array
   * @noinspection PhpUnused
   * Used in ImportMinivanBatch.php
   */
  public function processActivist(array $report, array $result, int $mcid, array $nlpHostile, int $cid): array
  {
    // Check if this report has already been seen before.
    $contactId = $report['contactId'];
    $exists = $this->nlpReportsObj->reportExists('Activist',$contactId);
    if($exists) {
      $action['processReport'] = FALSE;
      $action['counts']['skippedRecordCount'] = 1;
      return $action;
    }
    $vanid = $report['vanid'];

    // Currently, the only code used by NLP is NLPHostile.
    $activistCodeId = $report['activistCodeId'];
    if($activistCodeId != $nlpHostile['activistCodeId']) {
      $action['processReport'] = FALSE;
      $action['counts']['rejectedRecordCount'] = 1;
      return $action;
    }

    $result = $this->attachNlToResult($result, $vanid, $mcid);
    $result['mcid'] = $mcid;

    $rIndex = $this->nlpReportsObj->getAcReportIndex($vanid,'NLPHostile');
    if(!empty($rIndex)) {
      $action['processReport'] = FALSE;
      $action['counts']['skippedRecordCount'] = 1;
      return $action;
    }

    $result['reportIndex'] = NULL;
    $result['rid'] = $nlpHostile['activistCodeId'];
    $result['contactType'] = 'Walk';
    $result['type'] = 'Activist';
    $result['value'] = 1;
    $result['text'] = 'NLPHostile';
    $result['cid'] = $cid;
    $result['qid'] = NULL;
    $result['contactId'] = $contactId;
    $this->nlpReportsObj->setNlReport($result);

    // Results reported by this NL.
    $this->nlsObj->resultsReported($mcid,$result['county']);

    // Remember the state of the NLP Hostile.
    $voterStatus = $this->voterObj->getVoterStatus($vanid);
    $voterStatus['hostile'] = 1;
    $this->voterObj->setVoterStatus($vanid, $voterStatus);

    $action['processReport'] = FALSE;
    $action['counts']['newRecordCount'] = 1;
    return $action;
  }

  /**
   * processNote
   *
   * A note field was detected in the canvass report record.   Save the note in the NLO database
   * so the NL can see and edit it.
   *
   * Returns an array with the following keys:
   *   `processReport` - TRUE if the report should be processed, FALSE if it should be ignored.
   *   `counts` - array with keys:
   *   `skippedRecordCount` - number of duplicate reports
   *
   * @param array $report - Array of report data.  Either to set of remove the activist code.
   * @param array $result - Array of common values for all reports.
   * @return array
   */
  private function processNote( array $report, array $result ): array
  {
    // Check if this report has already been seen before.
    $contactId = $report['contactId'];
    $exists = $this->nlpReportsObj->reportExists('Comment',$contactId);
    if($exists) {
      $action['processReport'] = FALSE;
      $action['counts']['skippedRecordCount'] = 1;
      return $action;
    }

    $vanid = $report['vanid'];

    $noteString = $report['noteText'];
    $commentMax = 190;
    if (strlen($noteString) > $commentMax) {
      $note = substr($noteString,0,$commentMax);  // Truncate the comment.
    } else {
      $note = $noteString;
    }
    $note = str_replace("\r\n", "<br>", $note);
    $turfIndex = $this->voterObj->getTurfIndexByVanid($vanid);
    $mcid = $report['mcid'];

    // Add a new comment record.
    $result['type'] = 'Comment';
    $result['value'] = '';
    $result['text'] = $note;
    $result['contactId'] = $report['contactId'];
    $result['contactType'] = 'Walk';
    $result['cid'] = NULL;
    $result['rid'] = NULL;
    $result['qid'] = NULL;

    $nl = $this->nlsObj->getNlById($mcid);
    if(!empty($nl)) {
      $result['county'] = $nl['county'];
    }

    // Record the new note.
    $rIndex = $this->nlpReportsObj->setNlReport($result);
    $this->voterObj->updateTurfNote($turfIndex,$vanid,$note,$rIndex,0);
    $action['processReport'] = FALSE;
    $action['counts']['newRecordCount'] = 1;
    return $action;
  }

  /**
   * headerValidate
   *
   * The first record of the email attachment is the header record.  This record contains the
   * keys for the columns in the following records.  There are three types of files, each with
   * specific required fields
   *
   * Returns an array with the following keys:
   *   ok - TRUE if the header is valid, FALSE if it is not valid
   *  err - array of error messages
   *  pos - array of field positions
   *
   * @param string $fileType - survey, canvass, activist
   * @param string $headerRaw - CSV string of column names
   * @return array
   */
  public function headerValidate(string $fileType,string $headerRaw): array
  {
    $headerRecord = trim(strip_tags(htmlentities(stripslashes($headerRaw),ENT_QUOTES)));
    // Extract the column headers.
    $columnHeader = str_getcsv($headerRecord, escape: '');
    $fieldPos['ok'] = FALSE;

    if(empty($this->minivanHdr[$fileType])) {
      return $fieldPos;
    }
    $fieldPos = $this->decodeMinivanHdr($columnHeader, $this->minivanHdr[$fileType]);

    if(!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        Drupal::logger('nlpservices')->notice($errMsg);
      }
    }
    return $fieldPos;
  }

  /**
   * fetchReports
   *
   * Extract the relevant fields from the reports and put them in a single array.
   * Each encoded report includes a 'fileType' key.
   *
   * @param array $reportsRaw
   * @param string $fileType
   * @param int $activistCode
   * @param array $pos
   * @return array
   */
  public function fetchReports(array $reportsRaw, string $fileType, int $activistCode, array $pos): array
  {
    return $this->buildReportChunks($reportsRaw, $fileType, $activistCode, $pos, true);
  }

  /**
   * processMinivanEmails
   *
   * Fetch and process all pending MiniVAN emails directly in the cron hook without queuing.
   * Loops until no unseen emails remain.  Returns aggregated counts across all emails processed.
   *
   * @return array  Keys: recordCount, newRecordCount, skippedRecordCount, rejectedRecordCount, notNlpCount
   * @noinspection PhpUnused
   * Used in nlpservices.module
   */
  public function processMinivanEmails(): array
  {
    $totals = array_fill_keys($this->minivanCountKeys, 0);
    $nlpConfigurationObj = Drupal::service('nlpservices.configuration');
    $nlpHostileAC = $nlpConfigurationObj->getActivistCode('NLPHostileAC');
    while (TRUE) {
      $items = $this->imapObj->checkEmail('minivan');
      if (empty($items)) { break; }
      foreach ($items as $item) {
        $fileType = $item['fileType'];
        $fieldPos = $this->headerValidate($fileType, $item['header']);
        if (!$fieldPos['ok']) { continue; }
        if (count($item['reports']) <= 1) { continue; }
        $reportChunks = $this->buildReportChunks($item['reports'], $fileType, $nlpHostileAC, $fieldPos['pos']);
        foreach ($reportChunks as $chunk) {
          $counts = $this->processMinivanQueueReports($chunk, $fileType);
          foreach ($this->minivanCountKeys as $key) {
            $totals[$key] += $counts[$key] ?? 0;
          }
        }
      }
    }
    return $totals;
  }

  /**
   * minivanEmailCheck
   *
   * Check the minivan email inbox for new emails with attachments that contain a list of
   * recent voter contact reports.   There are three different email types, each with an attachment with
   * a different set of fields.  The contents of the email attachments are converted to
   * records and added to the array of queue elements.
   *
   * Returns an indexed array of queueItems.  Each queueItem with keys:
   *   reportCount - number of reports in the queue items
   *   header - CSV string of column names
   *   reports - array of reports
   *
   * @return array
   * @noinspection PhpUnused - used on nlpservices.module.
   */
  public function minivanEmailCheck(): array
  {
    $queueItems = [];
    $items = $this->imapObj->checkEmail('minivan');
    if(empty($items)) {return [];}  // No new emails.

    $nlpConfigurationObj = Drupal::service('nlpservices.configuration');
    $nlpHostileAC = $nlpConfigurationObj->getActivistCode('NLPHostileAC');

    // Each item has an attachment, but there will only be one for each email.
    foreach ($items as $item) {
      $fileType = $item['fileType'];
      $fieldPos = $this->headerValidate($fileType,$item['header']);
      if(!$fieldPos['ok']) {continue;}

      // A target email with an attachment with a good header record.
      $reportCount = count($item['reports']);
      $item['reportCount'] = $reportCount;
      $pos = $fieldPos['pos'];
      $posKeys = array_keys($pos);
      $item['header'] = implode(',',$posKeys);
      // Extract the relevant fields from the reports and put them in a single array.
      $reportChunks = $this->buildReportChunks($item['reports'], $fileType, $nlpHostileAC, $pos);
      if (empty($reportChunks)) {
        $queueItems[0] = $item;
        $queueItems[0]['reports'] = NULL;
        continue;
      }
      unset($item['reports']);  // Raw reports are no longer needed.
      $queueIndex = 0;
      $item['progress'] = 'chunk';
      foreach ($reportChunks as $reportChunk) {
        $queueItems[$queueIndex] = $item;
        $queueItems[$queueIndex]['reports'] = $reportChunk;
        $queueIndex++;
      }
    }
    return $queueItems;
  }

  /**
   * buildReportChunks
   *
   * Sanitize raw CSV rows, extract the relevant fields, filter by file type, and divide
   * into chunks of self::MINIVAN_MAX_QUEUE records.
   *
   * @param array $reportsRaw - reports from the email attachment.
   * @param string $fileType - survey, canvass, activist, or note.
   * @param int $activistCode - activist code ID to filter on (activist type only).
   * @param array $pos - column positions for the relevant fields.
   * @param bool $tagFileType - when true, add a 'fileType' key to each encoded report.
   * @return array
   */
  private function buildReportChunks(array $reportsRaw, string $fileType, int $activistCode, array $pos, bool $tagFileType = false): array
  {
    $reports = [];
    $blockIndex = $recordCount = 0;
    foreach ($reportsRaw as $rowRaw) {
      $fieldsRaw = str_getcsv($rowRaw, escape: '');
      // Remove any stuff that might be a security risk.
      $record = [];
      foreach ($fieldsRaw as $fieldRaw) {
        $record[] = trim(strip_tags(htmlentities(stripslashes($fieldRaw),ENT_QUOTES)));
      }
      $report = $this->extractMinivanFields($record,$pos);
      if($tagFileType) {
        $report['fileType'] = $fileType;
      }
      switch ($fileType) {
        case 'survey':
        case 'canvass':
          // Ignore reports not marked as from a MiniVAN user.
          if(!empty($report['inputTypeId']) && $report['inputTypeId'] == 14)  { // MiniVAN report.
            $reports[$blockIndex][] = json_encode($report);
            $recordCount++;
          }
          break;
        case 'activist':
          // Process only the reports for NLP Hostile.
          if($report['activistCodeId'] == $activistCode) {
            $reports[$blockIndex][] = json_encode($report);
            $recordCount++;
          }
          break;
        case 'note':
          $reports[$blockIndex][] = json_encode($report);
          $recordCount++;
          break;
      }
      if($recordCount == self::MINIVAN_MAX_QUEUE) {
        $blockIndex++;
        $recordCount = 0;
      }
    }
    return $reports;
  }

  /**
   * processMinivanQueueReports
   *
   * This function is invoked from the processItem function of the minivan queue worker.
   * It processes the reports in one queue item at a time.  The reports are in a single array.
   * Each report is a JSON string.  The reports are processed in the order they were received.
   *
   * @param $reports
   * @param $fileType
   * @return int[]
   * @noinspection PhpUnused
   * Used in minivan queueWorker
   */
  public function processMinivanQueueReports($reports,$fileType): array
  {
    $nlpConfigurationObj = Drupal::service('nlpservices.configuration');
    $nlpHostile['activistCodeId'] = $nlpConfigurationObj->getActivistCode('NLPHostileAC');
    $allowedResponseCodes = $nlpConfigurationObj->getCanvassResponseCodes();
    $pledgeSurveyQuestion = $nlpConfigurationObj->getPledgeSurveyQuestion();

    $cid = ApiSurveyQuestion::CONTACT_TYPE_WALK;

    $commonResult['cycle'] = $nlpConfigurationObj->getCycle();

    $counts = [];
    foreach ($this->minivanCountKeys as $minivanCountKey) {
      $counts[$minivanCountKey] = 0;
    }
    if(empty($reports)) {return $counts;}

    foreach ($reports as $reportRaw) {
      $report = json_decode($reportRaw,true);
      $counts['recordCount']++;
      // Clear the previous report's NL so it isn't credited for this one.
      $commonResult['county'] = NULL;
      $commonResult['firstName'] = NULL;
      $commonResult['lastName'] = NULL;
      $vanid = $report['vanid'];
      $mcid = $this->voterObj->getMcidByVanid($vanid);
      if (!empty($mcid)) {
        $nl = $this->nlsObj->getNlById($mcid);
        if(!empty($nl)) {
          $commonResult['firstName'] = $nl['firstName'];
          $commonResult['lastName'] = $nl['lastName'];
          $commonResult['county'] = $nl['county'];
          $user = $this->drupalUserObj->getUserByMcid($mcid);
          if(!empty($user)) {
            $update = [
              'uid' => $user['uid'],
              'access' => time(),
            ];
            $this->drupalUserObj->updateUser($update);
          }
        } else {
          // The voter's turf is assigned to an NL who is no longer in the NLs table.
          $this->notifyMissingNl($mcid);
        }
      } else {
        // There is no NL assigned to this voter.
        $counts['notNlpCount']++;
        continue;
      }
      $commonResult['mcid'] = $mcid;

      if(!empty($report['dateCanvassed'])) {
        $canvassTime = strtotime($report['dateCanvassed']);
      } elseif(!empty($report['dateCreated'])) {
        $canvassTime = strtotime($report['dateCreated']);
      } else {
        continue;
      }
      $commonResult['contactDate'] = date('Y-m-d',$canvassTime);
      $commonResult['vanid'] = $report['vanid'];
      $action = [];
      switch ($fileType) {
        case 'survey':
          $action = $this->processSurvey($report,$commonResult,$mcid,$pledgeSurveyQuestion,$cid);
          break;
        case 'canvass':
          $action = $this->processCanvass($report,$commonResult,$mcid,$allowedResponseCodes,$cid);
          break;
        case 'activist':
          $action = $this->processActivist($report,$commonResult,$mcid,$nlpHostile,$cid);
          break;
      }

      foreach ($action['counts'] as $countType => $value) {
        $counts[$countType] += $value;
      }

      if ($action['processReport']) {
        if (!$action['mergeReport']) {
          $rIndex = $this->nlpReportsObj->setNlReport($action['result']);
        } else {
          $this->nlpReportsObj->mergeReport($action['result']);
          $rIndex = $action['result']['reportIndex'];
        }
        // Mark Contact attempt for this voter.
        $turfVoter = [];
        $turfVoter['vanid'] = $vanid;
        $turfVoter['mcid'] = $mcid;
        if (!empty($action['type'])) {
          $turfVoter[$action['type']] = $rIndex;
        }
        $nl = $this->nlsObj->getNlById($mcid);
        $nlCounty = $nl['county'] ?? '';
        $turfVoter['county'] = $nlCounty;
        $turfIndex = $this->voterObj->getTurfIndexByVanid($vanid);

        $turfVoter['turfIndex'] = $turfIndex;
        $this->voterObj->updateTurfVoter($turfVoter);
        // This NL has reported results.
        $this->nlsObj->resultsReported($mcid, $nlCounty);
      }

    }

    return $counts;
  }

  /**
   * notifyMissingNl
   *
   * A MiniVAN user reported a result for a voter in a turf, but the NL who owns the turf is
   * missing from the NLs table.  Get the NL's name and region from VAN, and warn the NL's
   * coordinator so the NL can be added back to the list of active NLs.  Each missing NL is
   * looked up at most once a day, whether or not the email can be sent, since a MiniVAN email
   * can hold hundreds of reports for the same NL and cron would call VAN for each one.
   *
   * @param int $mcid
   * @return bool - TRUE if the coordinator was sent the email.
   */
  public function notifyMissingNl(int $mcid): bool
  {
    $notified = $this->state->get(self::MISSING_NL_STATE, []);
    if(!empty($notified[$mcid]) && time() - $notified[$mcid] < self::MISSING_NL_NOTIFY_INTERVAL) {
      return FALSE;
    }
    $notified[$mcid] = time();
    $this->state->set(self::MISSING_NL_STATE, $notified);

    $nl = $this->apiNlsObj->getApiNls('', $mcid);
    if(empty($nl) || empty($nl['county'])) {
      Drupal::logger('nlpservices')->error('MiniVAN report for missing NL, mcid: '.$mcid.
        '. VAN has no record or county for this NL, so no coordinator was notified.');
      return FALSE;
    }
    $region = [
      'county' => $nl['county'],
      'hd' => !empty($nl['hd']) ? ltrim($nl['hd'], '0') : 0,
      'precinct' => !empty($nl['precinct']) ? trim($nl['precinct']) : 0,
    ];
    $coordinator = $this->coordinatorsObj->getAssignedCoordinator($region);
    $nlName = $nl['firstName'].' '.$nl['lastName'];
    if(empty($coordinator)) {
      Drupal::logger('nlpservices')->error('MiniVAN report for missing NL '.$nlName.', mcid: '.$mcid.
        '. No coordinator is assigned for '.$nl['county'].' county.');
      return FALSE;
    }

    $firstName = $coordinator['firstName'];
    $subject = "NL missing from the list of active NLs";
    $message = "<p>$firstName,</p>";
    $message .= "<p>$nlName (MCID: $mcid) reported results with MiniVAN for a voter in their turf, but $nlName
 is missing from the list of active NLs in NLP Services.  These results can't be credited to $nlName
 until this is corrected.</p>";
    $message .= "<p>To correct this, please add $nlName back to the list of active NLs in VAN and then sync
 the list of active NLs with NLP Services again.</p>";
    if(!$this->sendEmailObj->sendEmail('missing_nl_notification', $coordinator['email'], $subject, $message)) {
      return FALSE;
    }

    Drupal::logger('nlpservices')->notice('Missing NL notification sent to: '.$firstName.' '.
      $coordinator['lastName'].' ['.$coordinator['email'].'] for '.$nlName.', mcid: '.$mcid);
    return TRUE;
  }

  public function getMinivanCountKeys(): array {
    return $this->minivanCountKeys;
  }

}
