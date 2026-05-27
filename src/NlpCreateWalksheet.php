<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpCreateWalksheet
{
  
  public const array WALKSHEET_COLUMNS_NAMES = [
    'control', 'address', 'name', 'votingHistory', 'results', 'question', 'notes'
  ];
  public const array WALKSHEET_COLUMNS_WIDTHS = [
    'address' => '26%',
    'name' => '18%',
    'votingHistory' => '7%',
    'results' => '14%',
    'question' => '15%',
    'notes' => '20%'
  ];
  public const string WALKSHEET_COLUMN_SPAN = '40%';

  public const string WALKSHEET_RESULTS = '<b>Canvass results</b><br>';
  public const string WALKSHEET_QUESTION = '<b>Survey Question</b><br>';
  
  private string $walksheetQuestionResponseList = '';
  private string $walksheetCanvassResponseList = '';
  private array $turfInfo = [];
  
  protected NlpVoters $votersObj;
  protected NlpPaths $pathsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected PdfGenerator $pdfGeneratorObj;
  protected ApiResponseCodes $apiResponseCodesObj;
  protected NlpTurfs $turfObj;
  protected ApiVoter $apiVoterObj;
  public function __construct($votersObj, $pathsObj, $nlpConfigurationObj, $nlpSessionDataObj,
                              $pdfGeneratorObj, $apiResponseCodesObj, $turfObj, $apiVoterObj)
  {
    $this->votersObj = $votersObj;
    $this->pathsObj = $pathsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->pdfGeneratorObj = $pdfGeneratorObj;
    $this->apiResponseCodesObj = $apiResponseCodesObj;
    $this->turfObj = $turfObj;
    $this->apiVoterObj = $apiVoterObj;
  }
  
  public static function create(ContainerInterface $container): NlpCreateWalksheet
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.pdf_generator'),
      $container->get('nlpservices.api_response_codes'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.api_voter')
    );
  }
  
  /**
   * createWalksheetArray
   *
   * Creates an array of households and voters for a walk sheet.
   *
   * @param int $turfIndex - The index of the turf to create the walk sheet for.
   *
   * @return array - An array of households with voter data.
   * The array is indexed first for a household, then for each voter in the household.
   * The household is an associative array with keys for householdLineCount and votersInHousehold.
   * The votersInHousehold is an indexed array of voters in the household.
   * Each voter in the votersInHousehold is an associative array with keys for control,
   * address, name, votingHistory, results, question, and notes.
   */
  public function createWalksheetArray(int $turfIndex): array
  {
    $turf = $this->turfObj->getTurf($turfIndex);
    
    $this->turfInfo = [
      'county' => $this->nlpSessionDataObj->getCounty(),
      'turfName' => $turf['turfName'],
      'voterCount' => $turf['voterCount'],
      'doorCount' => $turf['doorCount'],
      'electionName' => $this->nlpConfigurationObj->getElectionName(),
      'ballotDropDate' => $this->nlpConfigurationObj->getBallotDropDate(),
      'electionDate' => $this->nlpConfigurationObj->getElectionDate(),
    ];
    
    $expectedAbbreviations = $this->apiResponseCodesObj->getApiExpectedAbbreviations();
    $contactResponses = $this->nlpConfigurationObj->getContactResponses(['name' => 'Walk']);
    
    //Construct both a list of abbreviations for data entry and a legend to identify the abbreviations.
    $sortable = [];
    foreach ($expectedAbbreviations as $contactResponse => $abbreviation) {
      if (!empty($contactResponses[$contactResponse])) {
        $sortable[$contactResponse] = $abbreviation;
        unset($expectedAbbreviations[$contactResponse]);
      }
    }
    $responsesList = '';
    $responseDisplay = [];
    $column = $row = 0;
    foreach ($sortable as $name => $abbreviation) {
      if (!empty($responsesList)) {
        $responsesList .= ' ';
      }
      $responsesList .= $abbreviation;
      $responseDisplay[$row][$column] = "$name = $abbreviation";
      if ($column == 3) {
        $column = 0;
        $row++;
      } else {
        $column++;
      }
    }
    $this->turfInfo['responseDisplay'] = $responseDisplay;
    
    $this->walksheetCanvassResponseList = self::WALKSHEET_RESULTS . $responsesList;
    
    // Get the text of the survey question and the list of responses.  Also, create the list for
    // the data entry in the walksheet.
    $pledgeSurveyQuestion = $this->nlpConfigurationObj->getPledgeSurveyQuestion();
    $walksheetQuestion = strip_tags($pledgeSurveyQuestion['scriptQuestion']);
    $this->turfInfo['surveyQuestion'] = $walksheetQuestion;
    
    $responseList = '';
    $responseDisplay = [];
    foreach ($pledgeSurveyQuestion['responses'] as $response) {
      $responseList .= $response['name'] . ' ';
      $responseDisplay[] = "&nbsp;&nbsp;= " . $response['name'];
    }
    $surveyResponseText = implode('<br>', $responseDisplay);
    
    $this->walksheetQuestionResponseList = self::WALKSHEET_QUESTION . $responseList;
    $this->turfInfo['surveyQuestionResponseDisplay'] = $surveyResponseText;
    
    // Build the cell data for each column for each voter
    $voters = $this->votersObj->fetchVotersByTurf($turfIndex);
    $voterData = [];
    $row = 0;
    foreach ($voters as $voter) {
      foreach (self::WALKSHEET_COLUMNS_NAMES as $column) {
        switch ($column) {
          case 'control':
            $voterData[$row]['control'] = ['type' => 'voter'];
            $voterData[$row]['control']['vanid'] =  $voter['vanid'];
            $voterData[$row]['control']['householdAddress'] = [
              'addressLine1' => $voter['address']['addressLine1'],
              'addressLine2' => $voter['address']['addressLine2'],
              'city' => $voter['address']['city'],
            ];
            
            break;
          case 'address':
            $voterData[$row]['address'] = $this->voterAddress($voter);
            break;
          case 'name':
            $voterData[$row]['name'] = $this->voterName($voter);
            break;
          case 'votingHistory':
            $voterData[$row]['votingHistory'] = str_replace('@', '', $voter['votingHistory']);
            break;
          case 'results':
            $voterData[$row]['results'] = '#results';
            break;
          case 'question':
            $voterData[$row]['question'] = '#question';
            break;
          case 'notes':
            $voterData[$row]['notes'] = 'Notes';
            break;
        }
      }
      $row++;
    }
    // Combine the voters into households.
    $households = $household = $householdVanids = [];
    $voterInHousehold = $householdInTurf = $householdLineCount = 0;
    $firstInHousehold = true;
    $householdAddress = (!empty($voterData[0]['address'])) ? $voterData[0]['address'] : '';
    $lastRow = $row-1;
    for ($row = 0; $row <= $lastRow; $row++) {
      $household[$voterInHousehold] = $voterData[$row];
      $householdVanids[] = $voterData[$row]['control']['vanid'];
      if($firstInHousehold) {
        $householdAddress = (!empty($voterData[$row]['address'])) ? $voterData[$row]['address'] : '';
      }
      
      if ($row < $lastRow and $householdAddress == $voterData[$row + 1]['address']) {
        // The next voter is in the same household.
        $household[$voterInHousehold]['control']['row'] = 'dashed';
        if (!$firstInHousehold) {
          $household[$voterInHousehold]['address'] = '';
        }
        $firstInHousehold = false;
        $voterInHousehold++;
        $householdLineCount = $householdLineCount + 2;
      } else {
        // New household, check if other nontarget voters live at the same address.
        if (!$firstInHousehold) {
          $household[$voterInHousehold]['address'] = '';
        }
        
        $householdAddress = $household[0]['control']['householdAddress'];
        $householdLineCount = $householdLineCount + 2;
        $otherVotersExist = $this->otherVotersInHousehold($householdAddress,$householdVanids);
        if ($otherVotersExist['exist']) {
          // Other non-target voters exist at this address.  Create a line for each voter.
          $household[$voterInHousehold]['control']['row'] = 'dashed';
          $voterInHousehold++;
          $household[$voterInHousehold] = [
            'control' => ['row' => 'solid', 'type' => 'household'],
            'address' => $otherVotersExist['address'],
            'name' => $otherVotersExist['name'],
            'votingHistory' => $otherVotersExist['votingHistory'],
            'results' => $otherVotersExist['results'],
            'question' => '',
            'notes' => ''
          ];
          $householdLineCount = $householdLineCount + $otherVotersExist['voterCount'];
          
        } else {
          $voterData[$row]['control']['row'] = 'solid';  // The voter row is solid.
          $household[$voterInHousehold]['control']['row'] = 'solid';
        }
        $households[$householdInTurf] = [
          'householdLineCount' => $householdLineCount,
          'householdAddress' => $household[0]['control']['householdAddress'],
          'votersInHousehold' => $household];
        $householdInTurf++;
        $voterInHousehold = $householdLineCount = 0;
        $household = [];
        $firstInHousehold = true;
        $householdVanids = [];
      }
    }
    return $households;
  }
  
  
  /**
   * voterAddress
   *
   * Returns the formatted address of a voter.
   *
   * @param array $voter - An array containing voter information.
   *
   * @return string - The formatted address string.
   */
  private function voterAddress(array $voter): string
  {
    $address = $voter['address']['addressLine1'];
    if(empty($address)) {
      $address = '';
      nlp_debug_msg('$voter', $voter);
    }
    if (!empty($voter['address']['addressLine2'])) {
      $address .= '<br>' . $voter['address']['addressLine2'];
    }
    return $address;
  }
  
  /**
   * voterName
   *
   * Returns the formatted name of a voter.
   *
   * @param array $voter - An array containing voter information.
   *
   * @return string - The formatted name string.
   */
  private function voterName(array $voter): string
  {
    $name = '<b>' . $voter['lastName'] . ', ' . $voter['firstName'] . '</b>';
    $nickname = (!empty($voter['nickname'])) ? $voter['nickname'] : $voter['firstName'];
    $age = $voter['age'];
    $party = $voter['party'];
    $sex = $voter['sex'];
    $name .= "<br>($nickname) $age $sex $party";
    return $name;
  }
  
  /**
   * otherVotersInHousehold
   *
   * Checks for other voters in the same household and returns their information.
   *
   * @param array $address - An array containing the address information.
   * @param array $householdVanids - An array of vanIds for the household members.
   *
   * @return array - An associative array with keys 'exist', 'address', 'name',
   * 'votingHistory', 'results', and 'voterCount'.
   */
  private function otherVotersInHousehold(array $address, array $householdVanids): array
  {
    //$county = $this->nlpSessionDataObj->getCounty();
    //$allInHousehold = $this->apiVoterObj->getVotersByAddress($address,$county);
    
    $name = $alsoAddress = $votingHistory1 = $votingHistory2 = '';
    $voterCount = 0;
    return [
      'exist' => false,
      'address' => $alsoAddress,
      'name' => $name,
      'votingHistory' => $votingHistory1,
      'results' => $votingHistory2,
      'voterCount' => $voterCount,
    ];
  }
  
  /**
   * createWalksheetPdf
   *
   * Generates a PDF for the walk sheet using the provided data.
   *
   * @param array $walksheetData - An array containing the walk sheet data.
   *
   * @return string - URL for PDF the file.
   */
  public function createWalksheetPdf(array $walksheetData): string
  {
    
    $county = $this->turfInfo['county'];
    $turfName = $this->turfInfo['turfName'];
    $html = $this->createTheWalksheetInfo($this->turfInfo);
    
    $html .= $this->createWalksheetInstructions($this->turfInfo);
    
    $newPage = true;
    $pageLineCount = 0;
    $pageLineLimit = 48; // Set a limit for the number of lines per page.
    foreach ($walksheetData as $household) {
      $householdLineCount = $household['householdLineCount'];
      if($householdLineCount + $pageLineCount > $pageLineLimit) {
        // If adding this household exceeds the page line limit, start a new page.
        $newPage = true;
        $html .= '</table>';
      }
      if($newPage) {
        $html .= '<pagebreak />';
        $pageLineCount = 0;
        $html .= '<table style="width:100%; border-collapse:collapse; ">';
      }
      $pageLineCount = $pageLineCount + $household['householdLineCount'];
      
      foreach ($household['votersInHousehold'] as $voter) {
        if ($voter['control']['row'] == 'dashed') {
          $html .= '<tr style="border-bottom: 1px dashed LightGray;">';
        } else {
          $html .= '<tr style="border-bottom: 1px solid Black;">';
        }
        
        switch ($voter['control']['type']) {
          case 'voter':
            $html .= $this->buildRowForVoter($voter,$newPage);
            $newPage = false; // Reset newPage flag after first row of household.
            
            break;
          case 'household':
            $html .= $this->buildOthersInHousehold($voter);
            break;
        }
        $html .= '</tr>';
      }
    }
    if(!$newPage) {
      $html .= '</table>';
    }
   
    $autoGeneratedPdfName = 'walksheet_'.$county .'_'.$turfName.'_'.date('Ymd_His');
    $pathToPdf = "public://temp/$autoGeneratedPdfName.pdf";
    return $this->pdfGeneratorObj->generatePdf($html, $pathToPdf);
  }
  
  /**
   * canvassResultsCodeTable
   *
   * Creates a table of canvass results codes.
   *
   * @param array $responseDisplay - An array of response display data.
   *
   * @return string - The HTML table as a string.
   */
  private function canvassResultsCodeTable(array $responseDisplay): string
  {
    $canvassResultsCodeTable = '<table style="width:3in; border-collapse:collapse; ">';
    $canvassResultsCodeTable .= '<tr>';
    foreach ($responseDisplay as $column) {
      $columnText = implode('<br>&nbsp;&nbsp;', $column);
      $columnText = "&nbsp;&nbsp;". $columnText;
      $canvassResultsCodeTable .= '<td style="vertical-align: top; width:1.5in; font-size:8pt; font-family: Arial, Helvetica, sans-serif; ">';
      $canvassResultsCodeTable .= $columnText;
      $canvassResultsCodeTable .= '</td>';
    }
    $canvassResultsCodeTable .= '</tr>';
    $canvassResultsCodeTable .= '</table>';
    return $canvassResultsCodeTable;
  }
  
  /**
   * createTheWalksheetInfo
   *
   * Creates the HTML content for the walk sheet information.
   *
   * @param array $turfInfo - An array containing turf information.
   *
   * @return string - The HTML content as a string.
   */
  private function createTheWalksheetInfo(array $turfInfo): string
  {
    $county = $turfInfo['county'];
    $turfName = $turfInfo['turfName'];
    $people = $turfInfo['voterCount'];
    $doors = $turfInfo['doorCount'];
    $electionName = $turfInfo['electionName'];
    
    $html = '<div style="font-size:10pt; font-family: Arial, Helvetica, sans-serif;" >';
    $html .= "<p><b>Turf Name:</b> $turfName<br>";
    $html .= "<b>County:</b> $county<br>";
    $html .= "<b>Election:</b> $electionName<br>";
    $html .= 'Generated on: ' . date('Y-m-d H:i:s') . '<br>';
    $html .= "People: $people<br>";
    $html .= "Doors: $doors</p></div>";
    
    return $html;
  }
  
  /**
   * createWalksheetInstructions
   *
   * Creates the HTML content for the walk sheet instructions.
   *
   * @param array $turfInfo - An array containing turf information.
   *
   * @return string - The HTML content as a string.
   */
  private function createWalksheetInstructions(array $turfInfo): string
  {
    $electionName = $turfInfo['electionName'];
    $ballotDropDate = $turfInfo['ballotDropDate'];
    $ballotDropTime = strtotime($ballotDropDate);
    $ballotIsLate = strtotime('+10 days', $ballotDropTime);
    $LateBallotDate = date('l jS \of F Y',$ballotIsLate);
    $electionDate = $turfInfo['electionDate'];
    $surveyQuestion = $turfInfo['surveyQuestion'];
    $surveyQuestionResponseDisplay = $turfInfo['surveyQuestionResponseDisplay'];
    $responseDisplay = $turfInfo['responseDisplay'];
    
    $canvassResultsCodeTable = $this->canvassResultsCodeTable($responseDisplay);
    
    $html = '<div style="font-size:10pt; font-family: Arial, Helvetica, sans-serif; ">';
    $html .= "<p>Hi, My name is ________________. I'm your neighbor from _________________ Street,
and I'm a Democratic Neighborhood Leader. I wanted to thank you for being a
voter, and I wanted to make sure you received your ballot for the $electionName Election.<br>";
    $html .= "IF YES: Great! <br>
IF NO: It's probably in the mail. If you don't receive it in the mail by $LateBallotDate, call your County
Elections Office.<br>";
    $html .= "Have you filled out your ballot yet? <br>
THEN: Do you usually drop your ballot off or put it in the mail? We're recommending that folks vote as
early as possible this year. <br>
[Tell the voter your Voting Plan, i.e., I usually sit down with my housemate/spouse/partner at the kitchen
table and fill out my ballot as soon as I get it. When we're done, I drop them off at my county clerks
office on my way to work.]<br>";
    $html .= "IF YES: Great! <br>
What's your Voting Plan? Remember, your ballot is due no later than 8 p.m. on $electionDate.<br>
The closest ballot drop box is _______________ [the location can be found at the top of your original
walk sheets].<br>";
    $html .= 'IF NO: No problem, we can help you with that! </p>';
    
    $html .= "<p><b>Survey Question:</b><br>
$surveyQuestion<br>
$surveyQuestionResponseDisplay</p>";
    
    $html .= "<p>IF YES: Great! <br>
That's great, thanks! Were asking folks to turn in their ballots as early as possible. That way you'll
be taken off the call lists of the campaigns, and they can focus on people who haven't voted yet.<br>
Have a great day!</p>";
    
    $html .= "<p>Canvass result key, if unable to meet voter:<br>
$canvassResultsCodeTable</p>";
    
    $html .= '<p>If the voter said they were not planning to vote for Democratic candidates, mark them
NLP Hostile in the canvass dispositions.</p>
</div>';
    
    return $html;
  }
  
  /**
   * buildOthersInHousehold
   *
   * Builds the HTML for other voters in the household.
   *
   * @param array $row - An associative array containing household data.
   *
   * @return string - The HTML content as a string.
   */
  private function buildOthersInHousehold(array $row): string
  {
    $borderStyle = ($row['control']['row'] == 'dashed') ? 'border-bottom: 1px dashed LightGray;' : 'border-bottom: 1px solid #000;';
    $cellData = $row['address'];
    $width = self::WALKSHEET_COLUMNS_WIDTHS['address'];
    $html = '<td style="vertical-align: top; width:' . $width .
      '; font-size:8pt; font-family: Arial, Helvetica, sans-serif;' . $borderStyle .
      '">' . $cellData . '</td>';
    
    $cellData = $row['name'];
    $width = self::WALKSHEET_COLUMNS_WIDTHS['name'];
    $html .= '<td style="vertical-align: top; width:' . $width .
      '; font-size:8pt; font-family: Arial, Helvetica, sans-serif;' . $borderStyle .
      '">' . $cellData . '</td>';
    
    $cellData = $row['votingHistory'];
    $width = self::WALKSHEET_COLUMNS_WIDTHS['votingHistory'];
    $html .= '<td style="vertical-align: top;  width:' . $width .
      '; font-size:8pt; font-family: Arial, Helvetica, sans-serif;' . $borderStyle .
      '">' . $cellData . '</td>';
    
    $cellData = $row['results'];
    $width = self::WALKSHEET_COLUMN_SPAN;
    $html .= '<td colspan="3" style="vertical-align: top;  width:' . $width .
      '; font-size:8pt; font-family: Arial, Helvetica, sans-serif;' . $borderStyle .
      '">' . $cellData . '</td>';
    
    return $html;
  }
  
  /**
   * buildRowForVoter
   *
   * Builds the HTML for a single voter's row in the walk sheet.
   *
   * @param array $voter - An associative array containing voter data.
   * @param bool $newPage - Indicates if this is a new page.
   *
   * @return string - The HTML content as a string.
   */
  private function buildRowForVoter(array $voter,bool $newPage): string
  {
    $html = '';
    foreach ($voter as $column => $cellData) {
      switch ($column) {
        case 'control':
          break;
        case 'results':
          if ($cellData == '#results') {
            $cellData = $this->walksheetCanvassResponseList;
          }
          break;
        case 'question':
          if ($cellData == '#question') {
            $cellData = $this->walksheetQuestionResponseList;
          }
          break;
      }
      
      if ($column != 'control') {
        $borderStyle = ($voter['control']['row'] == 'dashed') ? 'border-bottom: 1px dashed LightGray;' : 'border-bottom: 1px solid #000;';
        // Add the top border if this is the first row after a new page
        if ($newPage) {
          $borderStyle = 'border-top: 1px solid #000;' . $borderStyle;
        }
        $width = self::WALKSHEET_COLUMNS_WIDTHS[$column];
        $html .= '<td style="vertical-align: top; width:' . $width .
          '; font-size:8pt; font-family: Arial, Helvetica, sans-serif;' . $borderStyle .
          '">' . $cellData . '</td>';
      }
    }
    return $html;
  }
}
