<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpDataEntryPrint
{
  const string DD_CALL_FILE = 'nl_call_list';
  const string DD_POSTCARD_FILE = 'nl_postcard_list';
  
  protected NlpCoordinators $coordinators;
  protected NlpSessionData $sessionData;
  protected NlpNls $nlsObj;
  protected NlpVoters $votersObj;
  protected NlpTurfs $turfsObj;
  protected NlpPaths $pathsObj;
  protected NlpInstructions $instructionsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpCreateWalksheet $createWalksheetObj;
  
  public function __construct($nlsObj, $sessionData, $coordinators, $votersObj,
                              $turfsObj, $pathsObj, $instructionsObj, $drupalUserObj,
                              $createWalksheetObj ) {
    $this->nlsObj = $nlsObj;
    $this->sessionData = $sessionData;
    $this->coordinators = $coordinators;
    $this->votersObj = $votersObj;
    $this->turfsObj = $turfsObj;
    $this->pathsObj = $pathsObj;
    $this->instructionsObj = $instructionsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->createWalksheetObj = $createWalksheetObj;
  }
  
  public static function create(ContainerInterface $container): NlpDataEntryPrint
  {
    return new static(
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.instructions'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.create_walksheet')
    );
  }
  
  /**
   * getVoters
   *
   * Returns an array of voters for the turf index.
   *
   * @param $turfIndex
   * @return array
   */
  public function getVoters($turfIndex): array
  {
    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    foreach ($voters as $vanid=>$voter) {
      $voterStatus = $this->votersObj->getVoterStatus($vanid);
      $voters[$vanid]['status'] = $voterStatus;
    }
    return $voters;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * dataEntryPrint
   *
   * Creates a long string of HTML that represents a printable page with links
   * for the walksheet PDF, the canvass instructions PDF, the postcard instructions PDF,
   * the postcard mailing list and the GOTV calling list.
   *
   * The postcard and call lists are in HTML format and are displayed in the browser as
   * very simple tables.  The user can print these pages using the browser print function. Some
   * day it may be preferable to create a PDF of these lists, but for now, the HTML is enough.
   *
   * The walksheet PDF is created by the coordinator using the print function of VoteBuilder.  The
   * print function creates a map of the contact addresses, and this map is very difficult to
   * reproduce.   However, after an update of the VoterFile, the list of voters may change and
   * the walksheet and map may be stale.   Some day, it may be possible to request a new walksheet.
   *
   * @return string
   */
  public function dataEntryPrint(): string
  {
    $messenger = Drupal::messenger();
    
    $county = $this->sessionData->getCounty();
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    
    $instructions = $this->instructionsObj->getInstructions($county);
    $page = '<p>The links below provide access to printable documents for voter contact instructions, canvassing
a walk sheet, mailing postcards, and GOTV calling for voters whose ballot is not yet received by the county election
division.  </p>';
    
    $page .= "<h2>Canvass Instructions</h2>";
  
    $page .= '<p>The "Instructions for canvassing" document is a PDF that can be downloaded and printed from
your computer. (For some counties, the document can be an Excel spreadsheet.) This PDF was created at the time the
turf was created and may not be the latest information on
your voters. NLP Services uses VoterBuilder for it\'s source of information, and it is regularly updated by the party.
The PDF\'s value is primarily the map for a canvass.  If you display your turf, you may have more recent
information.  The instructions are usually for a walking canvass of your neighborhood, but occasionally it can
be for sending postcards, or both depending on your situation. (Note, if you are using the MiniVAN app, you can get the most
recent information from VoteBuilder.)</p>';
    
    $path = $this->pathsObj->getPath('INST',$county);
    $canvass = $instructions['canvass']['fileName'];
    if(!empty($canvass)) {
      $canvassUrl = Drupal::service('file_url_generator')->generateAbsoluteString($path . $instructions['canvass']['fileName']);
      $page .= '<p><b>Instructions for canvassing:</b> <a href="' . $canvassUrl . '" target="_blank">Click here</a></p> ';
    }
  
    $postcard = $instructions['postcard']['fileName'];
    if(!empty($postcard)) {
      $postcardUrl = Drupal::service('file_url_generator')->generateAbsoluteString($path . $instructions['postcard']['fileName']);
      $page .= '<p><b>Instructions for sending postcards:</b> <a href="' . $postcardUrl . '" target="_blank">Click here</a></p> ';
    }
    
    $turfIndex = $this->sessionData->get('turfIndex');
  
    if(empty($turfIndex)) {
      $turfArray = $this->turfsObj->turfExists($mcid,$county);
      if (empty($turfArray)) {
        $messenger->addWarning("You do not have a turf assigned");
        return '';
      }
      $turfIndex = $turfArray['turfIndex'];
    }
    
    $turf = $this->turfsObj->getTurf($turfIndex);
    
    $page .= "<h2>Printable walksheet for your turf</h2>";
    
    if(!empty($turf['turfPDF'])) {
      $pdfPath = $this->pathsObj->getPath('PDF',$county);
      $pdfUri = $pdfPath . $turf['turfPDF'];
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($pdfUri);
      $page .=  '<br><span style="font-weight:bold; color: blue;"><b>Get your walk sheet:</b> </span><a href="'.$url.
        '" target="_blank"> Click Here</a>';
    } else {
      $page .=  '<p>Your coordinator did not provide a walksheet for you to print, possibly because
you intend to send postcards or are using the MiniVAN.  If you need a printable walksheet, please contact
your county coordinator.</p>';
    }
    
    $page .=  'This link will create a new walksheet for turf in PDF format.   It will match the current
    lst of voters displayed in your data entry page.  The walksheet will not have a map and may be of
    some help to you if there has been an update of registered voters. <br>';
    
    $page .= '<b>Create a new walksheet:</b> <a href="nlp-printable-walksheet-page/' . $turfIndex . '" target="_blank">Click here</a></p> ';
    
    $page .= "<h2>GOTV calling list</h2>";
  
    $page .= "<p>The last update of ballots received by county elections is typically the Friday before
the election.  For those voters who have not voted and for which we have a phone number, it can be
beneficial to call these voters.  However, about 40% of voters insist on voting on election day.  It's
a good idea to be cautious about calling voters that always vote late. </p>";
  
    $page .= '<p><b>GOTV calling list:</b> <a href="nlp-printable-calling-page/' . $turfIndex . '" target="_blank">Click here</a></p> ';
  
    $page .= "<h2>Postcard mailing list</h2>";
    $page .= '<p>The postcard address list is organized by mailing address which can be different from the voting
 address.  The list is also ordered by households, and you should send one card to each household with the names of
  all the voters. </p>';
    
    $page .= '<p><b>Postcard mailing list:</b> <a href="nlp-printable-mailing-page/' . $turfIndex . '" target="_blank">Click here</a></p> ';
    
    return $page;
  }

 
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCallList
   * 
   * @param $voters
   * @return array
   */
  public function buildCallList($voters): array
  {
    $note = '<div class="call-list-display">';
    
    $note .= "<p>The following table contains the list of households from your
turf.  The names in red are those that have not yet voted (also marked with
an asterisk). The column on the right indicates that at least one voter in the
household has not yet voted, and a reminder call might be helpful.</p>";
    
    $note .= '</div>';
    
    $contacts[] = [
      '#markup' => $note,
    ];
    $voterArrays = $this->buildCallTableArray($voters);
    //nlp_debug_msg('$voterArrays',$voterArrays);
    
    $page = $this->buildCallListDisplay($voterArrays);
  
    $listUri = $this->buildListPrint('call',$page,"GOTV Call list");
    
    $header = [
      'names' => 'Named(s)',
      'phoneNumbers' => 'Phone Number(s)',
      'address' => 'Address',
      'someOneToCall' => 'Call',
    ];
    
    $rows = [];
    foreach ($voterArrays as $voterArray) {
      $row = [];
      $row['names'] = t('<span style="font-family:Calibri,sans-serif;">'.$voterArray['names'].'</span>');
      $row['phoneNumbers'] = t('<span style="font-family:Calibri,sans-serif;">'.$voterArray['phoneNumbers'].'</span>');
      $row['address'] = t('<span style="font-family:Calibri,sans-serif;">'.$voterArray['address'].'</span>');
      $call = ($voterArray['someOneToCall'])?'<span class="help-call">call</span>':'';
      $row['someOneToCall'] = t('<span style="font-family:Calibri,sans-serif;">'.$call.'</span>');
      $rows[] = $row;
    }
    
    // Create the table as a render array.
    $contacts[] = [
      '#theme' => 'table',
      '#tableselect' => TRUE,
      '#header' => $header,
      '#rows' => $rows,
      '#empty' =>t('No files found'),
      '#attributes' => array (
        'class' => array('table table-striped',"call-table"),
      ),
      '#prefix' => '<div class="call-list-display">',
      '#suffix' => '</div>',
    ];
    
    $url = Drupal::service('file_url_generator')->generateAbsoluteString($listUri);
    $text =  "<p> * Hasn't voted yet. &nbsp; ** SMS texting optin unknown.</p></div>";
    $text .= "<div class='call-list-display'>".'<p><a href="'.$url.'"><b>PRINT</b></a>';
    $text .= "<br>If you left-click the print, you will get a new page that you can print with your browser.
If you right-click on the print, you can save the table on your system.  This saved file can be read by
Microsoft Word and edited or printed.</p></div>";
    
    $contacts[] = [
      '#markup' => $text,
    ];
    
    return $contacts;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCallListDisplay
   *
   * @param $voterArrays
   * @return array
   */
  public function buildCallListDisplay($voterArrays): array
  {
  
    $page = [];
    $page[] = '<div class="call-list-display">';

    $page[] = "<p>The following table contains the list of households from your
turf.  The names in red are those that have not yet voted (also marked with
an asterisk). The column on the right indicates that at least one voter in the
household has not yet voted, and a reminder call might be helpful.</p>";
    // Get the list of voters for this turf, ordered by voting address.
    $page[] = '<table class="call-table">';
    
    // Create the display of voter's, grouped BY ADDRESS if more than one at the same address.
    foreach ($voterArrays as $voterArray) {
      $page[] = "<tr>";
      $page[] = '<td class="name">'."<span style='font-family:Calibri,sans-serif; font-size: medium;'>".$voterArray['names'].'</span></td>';
      $page[] = '<td class="phoneNumbers">'."<span style='font-family:Calibri,sans-serif; font-size: medium;'>".$voterArray['phoneNumbers'].'</span></td>';
      $page[] = '<td class="address">'."<span style='font-family:Calibri,sans-serif; font-size: medium;'>".$voterArray['address'].'</span></td>';
      $call = ($voterArray['someOneToCall'])?'<span class="help-call">call</span>':'';
      $page[] = '<td class="call-status">'."<span style='font-family:Calibri,sans-serif; font-size: medium;'>".$call.'</span></td>';
      $page[] = "</tr>";
    }
    $page[] =  "</table>";
    $page[] =  "<p> * Hasn't voted yet. &nbsp; ** SMS texting optin unknown.</p></div>";
    return $page;
  }
  
  /**
   * buildCallTableArray
   *
   * Returns an array of arrays with information to build the call list table.
   *
   * @param $voters
   * @return array
   */
  function buildCallTableArray($voters): array
  {
    $voterArrays = [];
    // Create the display of voter's, grouped BY ADDRESS if more than one at the same address.
    $someOneToCall = FALSE;
    $savedHousehold = [];
    foreach ($voters as $voter) {
      $voterArray = [];
      
      $exclude = ($voter['status']['deceased'] OR $voter['status']['hostile']);
      // Extracted the name, address and age info from the vtr record.
      $voterNickname = " [".$voter['nickname']."]";
      $voterName = $voter['firstName']." ".$voter['lastName'];
      $exclusion = '';
      if($exclude) {
        $voterName = '<del>'.$voterName.'</del>';
        $exclusion = ($voter['status']['deceased'])?'DECEASED':'HOSTILE';
      }
      $voterAge = " - Age(".$voter['age'].")";
      $voterSex = " ".$voter['sex'];
      $status = $voter['status'];
      if(empty($status['voted']) AND !$exclude) {
        $householdNames = '<span class="help-not-voted">'
          .$voterName.$voterNickname.'</span>'.$voterSex.$voterAge.' *';
      } else {
        $householdNames = $voterName.$voterNickname.$voterSex.$voterAge.' '.$exclusion;
      }
      $votingHistory = $voter['votingHistory'];
      $formattedVotingHistory = str_replace(array('@Y','@N'),array('Yes','No'),$votingHistory);
      $householdNames .= '<br>&nbsp;&nbsp;'.$formattedVotingHistory;
     
      if(!empty(empty($status['voted']))) {
        if(!empty($voter['homePhone']) OR !empty($voter['cellPhone'])) {
          // This voter hasn't voted yet, and there is a phone number.
          $someOneToCall = TRUE;
        }
      }
      
      $phoneNumbers = "H:";
      if(!empty($voter['homePhone'])) {
        $phoneNumbers .= $this->formatTelephone($voter['homePhone']);
      }
      $phoneNumbers .= "<br>C:";
      if(!empty($voter['cellPhone'])) {
        $phoneNumbers .= $this->formatTelephone($voter['cellPhone']);
        $optIn = (!empty($voter['smsOptInStatus']))?$voter['smsOptInStatus']:'**';
        $phoneNumbers .= '<span class="voter-sms-status"> '.$optIn.'</span>';
      }
      
      $voterAddress = $voter['address'];
      $householdAddress = $voterAddress['streetNo'].' '
        .$voterAddress['streetPrefix'].' '.$voterAddress['streetName'].' '.$voterAddress['streetType'];
      if(!empty($voterAddress['aptType']) OR !empty($voterAddress['aptNo'])) {
        $householdAddress .= '<br>'.$voterAddress['aptType'].' '.$voterAddress['aptNo'];
      }
      $householdAddress .= '<br>'.$voterAddress['city'];
      // If the first voter in the household, remember name and address in case
      // there are others.
      if (empty($savedHousehold['address'])) {
        $savedHousehold['address'] = $householdAddress;
        $savedHousehold['names'] = $householdNames;
        $savedHousehold['phoneNumbers'] = $phoneNumbers;
        $savedHousehold['someOneToCall'] = $someOneToCall;
      } else {
        // If not the first voter in the household, then if another voter at the
        // same address, then add the name to the list.
        if($householdAddress == $savedHousehold['address']) {
          $savedHousehold['names'] .= "<br>".$householdNames;
          $savedHousehold['phoneNumbers'] .= "<br>".$phoneNumbers;
          if(!$savedHousehold['someOneToCall']) {
            $savedHousehold['someOneToCall'] = $someOneToCall;
          }
        } else {
         
          $voterArray['names'] = $savedHousehold['names'];
          $voterArray['phoneNumbers'] = $savedHousehold['phoneNumbers'];
          $voterArray['address'] = $savedHousehold['address'];
          $voterArray['someOneToCall'] = $savedHousehold['someOneToCall'];
          
          $savedHousehold['address'] = $householdAddress;
          $savedHousehold['names'] = $householdNames;
          $savedHousehold['phoneNumbers'] = $phoneNumbers;
          $savedHousehold['someOneToCall'] = $someOneToCall;
          $voterArrays[] = $voterArray;
        }
      }
      $someOneToCall = FALSE;
    }
    if (!empty($savedHousehold['address'])) {
      $voterArray['names'] = $savedHousehold['names'];
      $voterArray['phoneNumbers'] = $savedHousehold['phoneNumbers'];
      $voterArray['address'] = $savedHousehold['address'];
      $voterArray['someOneToCall'] = $savedHousehold['someOneToCall'];
      $voterArrays[] = $voterArray;
    }
    
    return $voterArrays;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCallListPrint
   *
   * @param $type
   * @param $page
   * @param $title
   * @return string
   */
  public function buildListPrint($type,$page,$title): string
  {
    $messenger = Drupal::messenger();
    
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    
    $createDate = date('Y-m-d-H-i-s', time());
    $fileType = ($type == 'call')?self::DD_CALL_FILE:self::DD_POSTCARD_FILE;
    $listUri = "public://temp/$fileType-$mcid-$createDate.html";
    $file = Drupal::service('file.repository')->writeData('', $listUri, FileSystemInterface::EXISTS_REPLACE);
    $file->setTemporary();
    try {
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('e',$e->getMessage());
      return '';
    }
    $fh = fopen($listUri, "w");
  
    fputs($fh, '<html lang="EN-uS"><head><title></title>');
    
    // Get the CSS file.
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $cssFile = $modulePath.'/css/call-print.css';
    $cssFh = fopen($cssFile, "r");
    if (empty($cssFh)) {
      $messenger->addError('Failed to open CSS file');
      return '';
    }
    fputs($fh,"<style>");
    $skip = false;
    do {
      $css = fgets($cssFh);
      if(empty($css)) {break;}
      if(empty(trim($css))) {continue;}  // Blank line.
      if($skip) {
        if ((bool)strpos($css, '*/') === true) {$skip = false;}
        continue;
      }
      if ((bool)strpos($css, '/*') === true) {
        if ((bool)strpos($css, '*/') === false) {$skip = true;}
        continue;
      }
      fputs($fh,$css);
    } while (TRUE);
    fclose($cssFh);
    fputs($fh,"</style>");
    
    fputs($fh, "</head><body>");
  
    fputs($fh,"<h1>$title</h1>");
  
    fputs($fh,"<span style='font-family:Calibri,sans-serif'>");
  
    foreach ($page as $line) {
      //nlp_debug_msg('$line',$line);
      fputs($fh,$line."\n");
    }
  
    fputs($fh,"</span></body></html>");
    fclose($fh);
    return $listUri;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * formatTelephone
   *
   * Returns a phone number formatted as (xxx) xxx-xxxx.
   *
   * @param $phone_number
   * @return string
   */
  function formatTelephone($phone_number): string
  {
    if(empty($phone_number)) {return'';}
    $cleaned = preg_replace('/[^[:digit:]]/', '', $phone_number);
    $matches = [];
    preg_match('/(\d{3})(\d{3})(\d{4})/', $cleaned, $matches);
    return "($matches[1]) $matches[2]-$matches[3]";
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildMailingList
   *
   * Create a mailing address list for each household.   The names and ages of
   * every voter in the household will be listed to help address the postcard.
   *
   * @param $voters
   * @return array
   */
  function buildMailingList($voters): array
  {
    $tableArrays = $this->buildMailListArray($voters);
    $page = $this->buildMailListDisplay($tableArrays);
    $listUri = $this->buildListPrint('postcard',$page,"Postcard Mailing List");
    
    $url = Drupal::service('file_url_generator')->generateAbsoluteString($listUri);
    $text = "<div class='call-list-display'>".'<p><a href="'.$url.'"><b>PRINT</b></a>';
    $text .= "<br>If you left-click the print, you will get a new page that you can print with your browser.
If you right-click on the print, you can save the table on your system.  This saved file can be read by
Microsoft Word and edited or printed.</p></div>";
    
    $header = [
      'names' => 'Named(s)',
      'address' => 'Address',
    ];
    
    $rows = [];
    foreach($tableArrays as $tableArray) {
      $rows[] = [
        'names' => t((string)$tableArray['names']),
        'address' => t((string)$tableArray['address']),
      ];
    }
    
    // Create the table as a render array.
    $content[] = [
      '#theme' => 'table',
      '#tableselect' => TRUE,
      
      '#header' => $header,
      '#rows' => $rows,
      '#empty' =>t('No files found'),
      '#attributes' => array (
        'class' => array('table table-striped',"mail-table"),
      ),
      '#prefix' => '<div class="mail-list-display">',
      '#suffix' => '</div>',
    ];
    
    $content[] = [
      '#markup' => $text,
    ];
    return $content;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildMailListDisplay
   *
   * Create an HTML table for the mailing list.
   *
   * @param $tableArrays
   * @return array
   */
  public function buildMailListDisplay($tableArrays): array
  {
    $page = [];
    $page[] = '<div class="call-list-display">';
    $page[] =  '<table class="mail-table">';
  
    $page[] = '<thead><tr>';
  
    $page[] = '<th class="name">Name(s)</th><th class="address">Mailing Address</th>';
    $page[] =  '</tr></thead>';
    $page[] =  '<tbody>';
  
    // Create the display of the voter's mailing address, grouped if more than one at the same address.
    foreach ($tableArrays as $tableArray) {
      
      $page[] = "<tr>";
      $page[] = '<td class="name">' . $tableArray['names'] . "</td>";
      $page[] = '<td class="address">' . $tableArray['address'] . "</td>";
      $page[] = "</tr>";
    }
    $page[] =  "</tbody></table></div>";
    return $page;
  }
  
  /**
   * buildMailListArray
   *
   * Build an array of mailing addresses for each household.
   *
   * @param $voters
   * @return array
   */
  public function buildMailListArray($voters): array
  {
    $voterArrays = [];
    $current = [];
    foreach ($voters as $voter) {
      
      $voterArray = [];
      $exclude = ($voter['status']['deceased'] OR $voter['status']['hostile']);
      // Extracted the name, address and age info from the vtr record.
      $salutation = " [".$voter['nickname']."]";
      $name = $voter['firstName']." ".$voter['lastName'];
      $exclusion = '';
      if($exclude) {
        $name = '<del>'.$name.'</del>';
        $exclusion = ($voter['status']['deceased'])?'DECEASED':'HOSTILE';
      }
      $age = "- Age(".$voter['age'].")";
      $voterName = $name.$salutation.$age;
      if($exclude) {
        $voterName .= ' '.$exclusion;
      }
      
      $votingHistory = $voter['votingHistory'];
      $formattedVotingHistory = str_replace(array('@Y','@N'),array('Yes','No'),$votingHistory);
      
      $voterName .= '<br>&nbsp;&nbsp;'.$formattedVotingHistory;
      
      if(empty($voter['address']['mAddress'])) {
        $mailingAddress = 'Not available.';
      } else {
        $mailingAddress = $voter['address']['mAddress'].'<br>'.$voter['address']['mCity']. ', '
          .$voter['address']['mState'].' '.$voter['address']['mZip'];
      }
      
      // If the first voter in the household, remember name and address in case there are others.
      if (empty($current['address'])) {
        $current['address'] = $mailingAddress;
        $current['name'] = $voterName;
      } else {
        // If not the first voter in the household, then if another voter at the
        // same address, then add the name to the list.
        if($mailingAddress == $current['address']) {
          $current['name'] .= "<br>".$voterName;
          //nlp_debug_msg('$current',$current);
        } else {
          // If this voter is registered at a different address, write the
          // mailing address record and start over with this voter.
          
          $voterArray['names'] = $current['name'];
          $voterArray['address'] = $current['address'];
          $current['address'] = $mailingAddress;
          $current['name'] = $voterName;
          $voterArrays[] = $voterArray;
          
        }
      }
    }
    if(!empty($current['name'])) {
      $voterArray['names'] = $current['name'];
      $voterArray['address'] = $current['address'];
      $voterArrays[] = $voterArray;
    }
    return $voterArrays;
  }
  
  public function buildWalksheetPage($turfIndex): array
  {
    $walksheetData = $this->createWalksheetObj->createWalksheetArray($turfIndex);
    //nlp_debug_msg('$walksheetData',$walksheetData);
    if(empty($walksheetData)) {
      return [
        '#markup' => '<p>No walksheet data available.</p>',
      ];
    }
    $pdfUrl = $this->createWalksheetObj->createWalksheetPdf($walksheetData);
    
    return [
      '#markup' => '<p><a href="'.$pdfUrl.'" target="_blank">Click here to view or download your walk sheet</a></p>',
    ];
  }
  
}
