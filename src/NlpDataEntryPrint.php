<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
                              $createWalksheetObj,
                              private readonly FileUrlGeneratorInterface $fileUrlGenerator,
                              private readonly PdfGenerator $pdfGenerator ) {
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
      $container->get('nlpservices.create_walksheet'),
      $container->get('file_url_generator'),
      $container->get('nlpservices.pdf_generator'),
    );
  }

  /**
   * getVoters
   *
   * Returns an array of voters for the turf index.
   *
   * @param int $turfIndex
   * @return array
   */
  public function getVoters( int $turfIndex ): array
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
   * the postcard mailing list, and the GOTV calling list.
   *
   * The postcard mailing list and the GOTV call list are PDFs the user can download and print.
   *
   * The walksheet PDF is created by the coordinator using the print function of VoteBuilder.  The
   * print function creates a map of the contact addresses, and this map is very challenging to
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
    $mcid = $currentUser['mcid'] ?? 0;

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
      $canvassUrl = $this->fileUrlGenerator->generateAbsoluteString($path . $instructions['canvass']['fileName']);
      $page .= '<p><b>Instructions for canvassing:</b> <a href="' . $canvassUrl . '" target="_blank">Click here</a></p> ';
    }

    $postcard = $instructions['postcard']['fileName'];
    if(!empty($postcard)) {
      $postcardUrl = $this->fileUrlGenerator->generateAbsoluteString($path . $instructions['postcard']['fileName']);
      $page .= '<p><b>Instructions for sending postcards:</b> <a href="' . $postcardUrl . '" target="_blank">Click here</a></p> ';
    }

    $turfIndex = $this->sessionData->get('turfIndex');

    if(empty($turfIndex)) {
      $turfArray = (!empty($mcid))?$this->turfsObj->turfExists($mcid,$county):[];
      if (empty($turfArray)) {
        $messenger->addWarning(t("You do not have a turf assigned"));
        return '';
      }
      $turfIndex = $turfArray['turfIndex'];
    }

    $turf = $this->turfsObj->getTurf($turfIndex);

    $page .= "<h2>Printable walksheet (Original with a map)</h2>";

    if(!empty($turf['turfPDF'])) {
      $pdfPath = $this->pathsObj->getPath('PDF',$county);
      $pdfUri = $pdfPath . $turf['turfPDF'];
      $url = $this->fileUrlGenerator->generateAbsoluteString($pdfUri);
      $page .=  '<p>The link below takes you to a printable document with a map and a list of voters for you to
contact.  The document was prepared for you by your coordinator and represents the list of voters in your turf at
the time the list was created.  If you have had this list for more than 30 days, the actual list of voters may
differ.</p>';
      $page .=  '<p></p><span style="font-weight:bold; color: blue;"><b>Get your walk sheet:</b> </span><a href="'.$url.
        '" target="_blank"> Click Here</a></p>';
    } else {
      $page .=  '<p>Your coordinator did not provide a walksheet for you to print, possibly because
you intend to send postcards or are using the MiniVAN.  If you need a printable walksheet, please contact
your county coordinator.</p>';
    }

    $page .= "<h2>Printable walksheet (Latest list of voters but without a map)</h2>";

    $page .=  '<p>Approximately every thirty days, the state
party updates the database of registered voters.   If your turf was created before the latest update of voters, it may have experienced some change.   Some
voters may have left and others moved into your turf.  The link below will create a new walksheet for your turf
in PDF format.   It will match the current list of voters displayed in your data entry page but may differ from
the turf you were originally given.  This walksheet will not have a map but may still be of some help to you if
still attempting to contact voters.  If you are unable to print your walksheet, contact your coordinator for
assistance.</p>';

    $page .= '<p><b>Create a new walksheet: </b> <a href="nlp-printable-walksheet-page/' . $turfIndex . '" target="_blank">Click here</a></p> ';

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
   * Create a PDF GOTV call list for each household and return a render array with a link to
   * download it.
   *
   * @param array $voters
   * @return array
   */
  public function buildCallList( array $voters ): array
  {
    $voterArrays = $this->buildCallTableArray($voters);
    if (empty($voterArrays)) {
      return [
        '#markup' => '<p>No voters are available for a call list.</p>',
      ];
    }
    $html = $this->buildCallListPdfHtml($voterArrays);
    return $this->createListPdf($html, self::DD_CALL_FILE, 'GOTV call list');
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCallListPdfHtml
   *
   * Create the HTML for the call list PDF.  Each household is its own table with a row per
   * voter, so mPDF moves the whole household to the next page when it won't fit.  Only a
   * household too large for a page is split, and autosize="1" keeps mPDF from shrinking it
   * to fit instead.  The column headings are a page header so they repeat on each page.
   *
   * @param array $voterArrays
   * @return string
   */
  public function buildCallListPdfHtml( array $voterArrays ): string
  {
    $font = 'font-size: 10pt; font-family: Calibri, Arial, sans-serif;';
    $cellStyle = 'vertical-align: top; padding: 2pt 4pt; border-left: 1px solid green; border-right: 1px solid green; ' . $font;
    $tableStyle = 'width: 100%; border-collapse: collapse; border: 1px solid green; margin-bottom: 2mm;';
    $widths = ['names' => '45%', 'phoneNumbers' => '22%', 'address' => '26%', 'call' => '7%'];

    $html = '<style>.help-not-voted, .help-call { color: red; } .voter-sms-status { font-size: 7pt; font-style: italic; color: blue; }</style>';
    $html .= '<htmlpageheader name="callListHeader">';
    $html .= '<table style="' . $tableStyle . '"><tr>';
    $html .= '<td style="width: ' . $widths['names'] . '; ' . $cellStyle . '"><b>Name(s)</b></td>';
    $html .= '<td style="width: ' . $widths['phoneNumbers'] . '; ' . $cellStyle . '"><b>Phone Number(s)</b></td>';
    $html .= '<td style="width: ' . $widths['address'] . '; ' . $cellStyle . '"><b>Address</b></td>';
    $html .= '<td style="width: ' . $widths['call'] . '; ' . $cellStyle . '"><b>Call</b></td>';
    $html .= '</tr></table>';
    $html .= '</htmlpageheader>';
    // 'header' is an mPDF extension to @page, which repeats the named header on every page.
    /** @noinspection CssUnknownProperty */
    $html .= '<style>@page { header: html_callListHeader; margin-top: 20mm; }</style>';

    $html .= '<h1 style="font-size: 16pt; font-family: Calibri, Arial, sans-serif;">GOTV Call List</h1>';
    $html .= '<p style="' . $font . '">The following table contains the list of households from your
turf.  The names in red are those that have not yet voted (also marked with an asterisk).  The column
on the right indicates that at least one voter in the household has not yet voted, and a reminder call
might be helpful.<br>* Has not voted yet. &nbsp; ** SMS texting optin unknown.</p>';

    // One row per voter, with the address and call flag on the household's first row.
    foreach ($voterArrays as $voterArray) {
      /** @noinspection HtmlUnknownAttribute */
      $html .= '<table autosize="1" style="' . $tableStyle . ' page-break-inside: avoid;">';
      $address = $voterArray['address'];
      $call = ($voterArray['someOneToCall']) ? '<span class="help-call">call</span>' : '';
      foreach ($voterArray['voters'] as $voter) {
        $html .= '<tr>';
        $html .= '<td style="width: ' . $widths['names'] . '; ' . $cellStyle . '">' . $voter['name'] . '</td>';
        $html .= '<td style="width: ' . $widths['phoneNumbers'] . '; ' . $cellStyle . '">' . $voter['phoneNumbers'] . '</td>';
        $html .= '<td style="width: ' . $widths['address'] . '; ' . $cellStyle . '">' . $address . '</td>';
        $html .= '<td style="width: ' . $widths['call'] . '; ' . $cellStyle . '">' . $call . '</td>';
        $html .= '</tr>';
        $address = $call = '';
      }
      $html .= '</table>';
    }
    return $html;
  }

  /**
   * buildCallTableArray
   *
   * Returns an array of arrays with information to build the call list table.  Each entry is
   * a household with its 'address', 'someOneToCall' flag, its 'voters' (one 'name' and
   * 'phoneNumbers' pair per voter), and 'names'/'phoneNumbers' (those joined by <br>).
   *
   * @param array $voters
   * @return array
   */
  public function buildCallTableArray( array $voters ): array
  {
    $voterArrays = [];
    // Create the display of voter's, grouped BY ADDRESS if more than one at the same address.
    $someOneToCall = FALSE;
    $savedHousehold = [];
    foreach ($voters as $voter) {
      $voterArray = [];

      $exclude = ($voter['status']['deceased'] || $voter['status']['hostile']);
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
      if (empty($status['voted']) && !$exclude) {
        $householdNames = '<span class="help-not-voted">'
          .$voterName.$voterNickname.'</span>'.$voterSex.$voterAge.' *';
      } else {
        $householdNames = $voterName.$voterNickname.$voterSex.$voterAge.' '.$exclusion;
      }
      $votingHistory = $voter['votingHistory'];
      $formattedVotingHistory = str_replace(['@Y','@N'],['Yes','No'],$votingHistory);
      $householdNames .= '<br>&nbsp;&nbsp;'.$formattedVotingHistory;

      if (empty($status['voted'])) {
        if (!empty($voter['homePhone']) || !empty($voter['cellPhone'])) {
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
      if (!empty($voterAddress['aptType']) || !empty($voterAddress['aptNo'])) {
        $householdAddress .= '<br>'.$voterAddress['aptType'].' '.$voterAddress['aptNo'];
      }
      $householdAddress .= '<br>'.$voterAddress['city'];
      // If the first voter in the household, remember name and address in case
      // there are others.
      $householdVoter = ['name' => $householdNames, 'phoneNumbers' => $phoneNumbers];
      if (empty($savedHousehold['address'])) {
        $savedHousehold['address'] = $householdAddress;
        $savedHousehold['names'] = $householdNames;
        $savedHousehold['phoneNumbers'] = $phoneNumbers;
        $savedHousehold['someOneToCall'] = $someOneToCall;
        $savedHousehold['voters'] = [$householdVoter];
      } else {
        // If not the first voter in the household, then if another voter at the
        // same address, then add the name to the list.
        if($householdAddress == $savedHousehold['address']) {
          $savedHousehold['names'] .= "<br>".$householdNames;
          $savedHousehold['phoneNumbers'] .= "<br>".$phoneNumbers;
          $savedHousehold['voters'][] = $householdVoter;
          if(!$savedHousehold['someOneToCall']) {
            $savedHousehold['someOneToCall'] = $someOneToCall;
          }
        } else {

          $voterArray['names'] = $savedHousehold['names'];
          $voterArray['phoneNumbers'] = $savedHousehold['phoneNumbers'];
          $voterArray['address'] = $savedHousehold['address'];
          $voterArray['someOneToCall'] = $savedHousehold['someOneToCall'];
          $voterArray['voters'] = $savedHousehold['voters'];

          $savedHousehold['address'] = $householdAddress;
          $savedHousehold['names'] = $householdNames;
          $savedHousehold['phoneNumbers'] = $phoneNumbers;
          $savedHousehold['someOneToCall'] = $someOneToCall;
          $savedHousehold['voters'] = [$householdVoter];
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
      $voterArray['voters'] = $savedHousehold['voters'];
      $voterArrays[] = $voterArray;
    }

    return $voterArrays;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * createListPdf
   *
   * Create a portrait PDF of a call or mailing list and return a render array with a link
   * to download it.
   *
   * @param string $html - The HTML for the list.
   * @param string $fileType - DD_CALL_FILE or DD_POSTCARD_FILE, used in the file name.
   * @param string $label - The list's name in the link text, e.g. 'GOTV call list'.
   * @return array
   */
  private function createListPdf( string $html, string $fileType, string $label ): array
  {
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'] ?? 0;
    $createDate = date('Y-m-d-H-i-s', time());
    $listUri = "public://temp/$fileType-$mcid-$createDate.pdf";
    $pdfUrl = $this->pdfGenerator->generatePdf($html, $listUri, 'P');
    if (empty($pdfUrl)) {
      return [
        '#markup' => "<p>The $label could not be created.  Please contact your coordinator.</p>",
      ];
    }

    return [
      '#markup' => '<p><a href="' . $pdfUrl . '" target="_blank">Click here to view or download your ' . $label . '</a></p>',
    ];
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * formatTelephone
   *
   * Returns a phone number formatted as (xxx) xxx-xxxx.
   *
   * @param string $phoneNumber
   * @return string
   */
  public function formatTelephone( string $phoneNumber ): string
  {
    if(empty($phoneNumber)) {return'';}
    $cleaned = preg_replace('/[^[:digit:]]/', '', $phoneNumber);
    $matches = [];
    preg_match('/(\d{3})(\d{3})(\d{4})/', $cleaned, $matches);
    return "($matches[1]) $matches[2]-$matches[3]";
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildMailingList
   *
   * Create a PDF mailing address list for each household and return a render array with a
   * link to download it.  The names and ages of every voter in the household will be listed
   * to help address the postcard.
   *
   * @param array $voters
   * @return array
   */
  public function buildMailingList( array $voters ): array
  {
    $tableArrays = $this->buildMailListArray($voters);
    if (empty($tableArrays)) {
      return [
        '#markup' => '<p>No voters are available for a mailing list.</p>',
      ];
    }
    $html = $this->buildMailListPdfHtml($tableArrays);
    return $this->createListPdf($html, self::DD_POSTCARD_FILE, 'postcard mailing list');
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildMailListPdfHtml
   *
   * Create the HTML for the mailing list PDF.  Each household is its own table with a row
   * per voter, so mPDF moves the whole household to the next page when it won't fit.  Only a
   * household too large for a page is split, and autosize="1" keeps mPDF from shrinking it
   * to fit instead.  The column headings are a page header so they repeat on each page.
   *
   * @param array $tableArrays
   * @return string
   */
  public function buildMailListPdfHtml( array $tableArrays ): string
  {
    $font = 'font-size: 10pt; font-family: Calibri, Arial, sans-serif;';
    $cellStyle = 'vertical-align: top; padding: 2pt 4pt; border-left: 1px solid green; border-right: 1px solid green; ' . $font;
    $tableStyle = 'width: 100%; border-collapse: collapse; border: 1px solid green; margin-bottom: 2mm;';

    $html = '<htmlpageheader name="mailListHeader">';
    $html .= '<table style="' . $tableStyle . '"><tr>';
    $html .= '<td style="width: 60%; ' . $cellStyle . '"><b>Name(s)</b></td>';
    $html .= '<td style="width: 40%; ' . $cellStyle . '"><b>Mailing Address</b></td>';
    $html .= '</tr></table>';
    $html .= '</htmlpageheader>';
    // 'header' is an mPDF extension to @page, which repeats the named header on every page.
    /** @noinspection CssUnknownProperty */
    $html .= '<style>@page { header: html_mailListHeader; margin-top: 20mm; }</style>';

    $html .= '<h1 style="font-size: 16pt; font-family: Calibri, Arial, sans-serif;">Postcard Mailing List</h1>';
    $html .= '<p style="' . $font . '">The list is ordered by household.  Send one card to each
household with the names of all the voters.</p>';

    // One row per voter, with the mailing address on the household's first row.
    foreach ($tableArrays as $tableArray) {
      /** @noinspection HtmlUnknownAttribute */
      $html .= '<table autosize="1" style="' . $tableStyle . ' page-break-inside: avoid;">';
      $address = $tableArray['address'];
      foreach ($tableArray['voters'] as $voterName) {
        $html .= '<tr>';
        $html .= '<td style="width: 60%; ' . $cellStyle . '">' . $voterName . '</td>';
        $html .= '<td style="width: 40%; ' . $cellStyle . '">' . $address . '</td>';
        $html .= '</tr>';
        $address = '';
      }
      $html .= '</table>';
    }
    return $html;
  }

  /**
   * buildMailListArray
   *
   * Build an array of mailing addresses for each household.  Each entry has the household's
   * 'address', its 'voters' (one formatted name per voter), and 'names' (those joined by <br>).
   *
   * @param array $voters
   * @return array
   */
  public function buildMailListArray( array $voters ): array
  {
    $voterArrays = [];
    $current = [];
    foreach ($voters as $voter) {

      $voterArray = [];
      $exclude = ($voter['status']['deceased'] || $voter['status']['hostile']);
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
      $formattedVotingHistory = str_replace(['@Y','@N'],['Yes','No'],$votingHistory);

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
        $current['voters'] = [$voterName];
      } else {
        // If not the first voter in the household, then if another voter at the
        // same address, then add the name to the list.
        if($mailingAddress == $current['address']) {
          $current['name'] .= "<br>".$voterName;
          $current['voters'][] = $voterName;
        } else {
          // If this voter is registered at a different address, write the
          // mailing address record and start over with this voter.

          $voterArray['names'] = $current['name'];
          $voterArray['voters'] = $current['voters'];
          $voterArray['address'] = $current['address'];
          $current['address'] = $mailingAddress;
          $current['name'] = $voterName;
          $current['voters'] = [$voterName];
          $voterArrays[] = $voterArray;

        }
      }
    }
    if(!empty($current['name'])) {
      $voterArray['names'] = $current['name'];
      $voterArray['voters'] = $current['voters'];
      $voterArray['address'] = $current['address'];
      $voterArrays[] = $voterArray;
    }
    return $voterArrays;
  }

  /**
   * buildWalksheetPage
   *
   * Generates a walksheet page for a specified turf index. The method retrieves
   * data related to the walksheet, creates a PDF for the walksheet, and returns
   * a render array containing a link to view or download the PDF.
   *
   * @param int $turfIndex
   *   The index of the turf for which the walksheet is to be created.
   *
   * @return array
   *   A render array containing either a link to the walksheet PDF or a message
   *   indicating no walksheet data is available.
   */
  public function buildWalksheetPage( int $turfIndex ): array
  {
    $walksheetData = $this->createWalksheetObj->createWalksheetArray($turfIndex);
    if(empty($walksheetData)) {
      return [
        '#markup' => '<p>No walksheet data is available.</p>',
      ];
    }
    $pdfUrl = $this->createWalksheetObj->createWalksheetPdf($walksheetData);

    return [
      '#markup' => '<p><a href="'.$pdfUrl.'" target="_blank">Click here to view or download your walk sheet</a></p>',
    ];
  }

}
