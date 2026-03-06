<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

/**
 * @noinspection PhpUnused
 */
class NlpResultsSummary
{
  protected NlpVoters $votersObj;
  protected NlpMatchbacks $matchbacksObj;
  protected NlpNls $nlsObj;
  protected DrupalUser $drupalUser;
  protected NlpCrosstabCounts $crosstabsObj;
  protected NlpReports $reportsObj;
  protected NlpTurfs $turfsObj;
  protected NlpConfiguration $configurationObj;
  protected NlpSessionData $sessionObj;
  protected HtmlText $htmlTextObj;
  
  public function __construct($votersObj,$matchbacksObj,$nlsObj,$drupalUser,
                              $crosstabsObj,$reportsObj,$turfsObj, $configurationObj,
                              $sessionObj, $htmlTextObj ) {
    $this->votersObj = $votersObj;
    $this->matchbacksObj = $matchbacksObj;
    $this->nlsObj = $nlsObj;
    $this->drupalUser = $drupalUser;
    $this->crosstabsObj = $crosstabsObj;
    $this->reportsObj = $reportsObj;
    $this->turfsObj = $turfsObj;
    $this->configurationObj = $configurationObj;
    $this->sessionObj = $sessionObj;
    $this->htmlTextObj = $htmlTextObj;
  }

  public static function create(ContainerInterface $container): NlpResultsSummary
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.crosstabs'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.html_text')
    );
  }
  
  /**
   * displayResultsSummary
   *
   * Display the voter turnout for the NL program for each of the
   * known Counties.
   *
   * @return array - render array for display.
   */
  function displayCountyResultsSummary(): array
  {
    $userCounty = $this->sessionObj->getCounty();
    $admin = $this->drupalUser->isNlpAdminUser();
    if ($admin) {
      $counties = $this->votersObj->getParticipatingCounties();
    } else {
      $counties = [$userCounty];
    }
    
    $tempUri = $this->createTempFile('nlp_county_summary_report');
    $fh = fopen($tempUri, "w");
    
    // Start with a legend for the CSV file and the render array.
    $legendLines = $this->buildLegend(FALSE);
    $this->writeToTempFile($fh,$legendLines);
    $output = [];
    $legend = $this->buildLegend();
    $output['legend'] = [
      '#markup' => $legend,
    ];
    // Counts to be assembled for the summary of all NLP counties.
    $nlpParticipation = [
      'participatingNls' => 0,
      'resultsReported' => 0,
      'numberOfTurfs' => 0
    ];
    $nlpCounts = [
      'allVoters' => 0,
      'allVotersVoted' => 0,
      'democrats' => 0,
      'democratsVoted' => 0,
      'republicans' => 0,
      'republicansVoted' => 0,
      'nonAffiliated' => 0,
      'nonAffiliatedVoted' => 0,
      'postcards' => 0,
      'postcardsVoted' => 0,
      'attempted' => 0,
      'attemptedVoted' => 0,
      'contacted' => 0,
      'contactedVoted' => 0,
      'nlpVoters' => 0,
      'nlpVotersVoted' => 0
    ];
    
    // Build the tables.
    foreach ($counties as $county) {
      // A header to identify each county in the CSV file.
      $header = [["$county County", 'Registered', 'Voted', 'Participation']];
      $this->writeToTempFile($fh,  $header);
      
      $countyParticipation = $this->gatherCountyParticipationData('County', $county, $nlpParticipation );
      $rows = $this->buildResultsTableArray('County',$county, $nlpCounts);
      $output[$county] = $this->buildDistrictDisplay('County',$county, $rows, $countyParticipation);
      $this->writeToTempFile($fh,  $rows);
    }
    
    // Create a summary table for all participating counties.
    $allCountyParticipation = $this->gatherCountyParticipationData('NLP', '', $nlpParticipation );
    $rows = $this->buildSummaryTableArray( $nlpCounts );
    $output['nlp'] = $this->buildDistrictDisplay('NLP','', $rows, $allCountyParticipation);
    
    fclose($fh);
    
    // A link to allow the user to download the CSV file.
    $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempUri);
    $link = "<h2>Link to a file in the CSV format of the summary report.</h2>";
    $link .= '<a href="'.$url.'">Right-click to download the summary file.  </a>';
    $output['result_file'] = [
      '#markup' => $link,
    ];
    
    return $output;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * displayResultsSummary
   *
   * Display the voter turnout for the NL program for each of the
   * known CDs.
   *
   * @return array - render array for display.
   */
  function displayCdResultsSummary(): array
  {
    $cds = $this->crosstabsObj->getCds();
    $output = [];

    if(empty($cds)) {
      $output['no_cds'] = [
        '#markup' => "<h2>The crosstab file needs to be loaded prior to this report.</h2>",
      ];
      return $output;
    }

    $tempUri = $this->createTempFile('nlp_cd_summary_report');
    $fh = fopen($tempUri, "w");

    $nlpCounts = [
      'allVoters' => 0,
      'allVotersVoted' => 0,
      'democrats' => 0,
      'democratsVoted' => 0,
      'republicans' => 0,
      'republicansVoted' => 0,
      'nonAffiliated' => 0,
      'nonAffiliatedVoted' => 0,
      'postcards' => 0,
      'postcardsVoted' => 0,
      'attempted' => 0,
      'attemptedVoted' => 0,
      'contacted' => 0,
      'contactedVoted' => 0,
      'nlpVoters' => 0,
      'nlpVotersVoted' => 0
    ];
    
    // Build the table for each CD with participating activity.
    foreach ($cds as $cd) {
      $rows = $this->buildResultsTableArray('CD', $cd, $nlpCounts);
      $output[$cd] = $this->buildDistrictDisplay('CD', $cd, $rows);
      $this->writeToTempFile($fh, $rows);
    }
    fclose($fh);
    
    // A link to allow the user to download the CSV file.
    $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempUri);
    $link = "<h2>Link to a file in the CSV format of the summary report.</h2>";
    $link .= '<a href="'.$url.'">Right-click to download the summary file.  </a>';
    $output['result_file'] = [
      '#markup' => $link,
    ];
    
    return $output;
  }
  
  /**
   * createTempFile
   *
   * Create a temporary file in the public://temp directory with a unique name.
   * The file is created as a managed file, so Drupal will delete it after 6 hours.
   *
   * @param string $prefix - the prefix for the temporary file name.
   * @return string - the URI of the temporary file.
   */
  private function createTempFile(string $prefix): string
  {
    $tempDir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s',time());
    $tempUri = "$tempDir/$prefix-$contactDate.csv";
    // Create a managed file for temporary use.  Drupal will delete it after 6 hours.
    $file = Drupal::service('file.repository')->writeData('', $tempUri, FileSystemInterface::EXISTS_REPLACE);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('createTempFile',$e->getMessage());
      return '';
    }
    return $tempUri;
  }
  
  /**
   * writeToTempFile
   *
   * Write the results to the temporary file.  The rows are either an
   * array of arrays that are to be converted to CSV format, or a single
   * array of values that are to be written as a single row in the CSV file.
   *
   * @param $fh - file handle for the temporary file.
   * @param array $rows - the rows of data to write.
   */
  private function writeToTempFile($fh, array $rows): void
  {
    $row = reset($rows);
    if(is_array($row)) {
      foreach ($rows as $row) {
        fputcsv($fh, $row);
      }
    } else {
      foreach ($rows as $row) {
        $rowAsArray = [$row];
        fputcsv($fh, $rowAsArray);
      }
    }
  }
  
  /**
   * buildLegend
   *
   * Build the legend for the results summary.  When returned as HTML, the array of
   * lines are concatenated into a single string with HTML tags.  When returned as plain text,
   * the array of lines are converted to plain text by removing HTML tags and returning an array
   * of lines.  These lines are added to the CSV file.
   *
   *
   * @param bool $html - whether to return HTML or plain text.
   * @return string|array - the legend text, either as HTML or plain text.
   */
  private function buildLegend(bool $html=TRUE): string|array
  {
    $crosstabCreateTime = $this->configurationObj->getCrosstabCreateTime();
    $crosstabDate = empty($crosstabCreateTime)?'Not yet':date("F d Y H:i.", $crosstabCreateTime);
    $matchbackDate = empty($this->matchbacksObj->getLatestMatchbackDate())?'Voting has not started'
        :date("F d Y H:i.", $this->matchbacksObj->getLatestMatchbackDate());
    
    $legendText = [
      "<p>The last update of the counts from VoteBuilder: <b>$crosstabDate</b><br>",
      "The latest matchback date: <b>$matchbackDate</b></p>",
      '<h2>Legend</h2>',
      '<p>All Voters * - all registered voters in the participating district.<br>',
      'Republicans * - the registered Republicans.<br>',
      'Non-Affiliated * - the registered Non-Affiliated voters.<br>',
      'Democrats * - the registered Democrats.<br>',
      'NLP Voters - the voters in the assigned turfs.<br>',
      'Attempted - the voters who were attempted to be contacted, but did not have a personal contact.<br>',
      'Contacted - the voters who had a personal contact.<br>',
      'Postcard - the voters who were sent postcards.</p>',
      "<p><i>* These fields are created by VoteBuilder.   The nightly counting by VoteBuilder<br>",
      "  Can be a day behind the counts for the NLP rows.   Check the dates of each update.</i></p>",
    ];
    
    if($html) {
      return implode(' ', $legendText);
    }
    
    $legendPlain = [];
    $legendText = array_map(function($item) {
      return str_replace('<br>', '', $item);
    }, $legendText);
    foreach ($legendText as $text) {
      $this->htmlTextObj->setHtml($text);
      $legendPlain[] = $this->htmlTextObj->getText();
    }
    return $legendPlain;
  }
  
  /**
   * buildCdDisplay
   *
   * Build the form elements for the display of the results for a county or CD.
   *
   * @param string $type - the type of display, either 'County' or 'CD'.
   * @param mixed $value - the value of the County or CD.
   * @param array $rows - the rows of data to display.
   * @return array - renderable array for Drupal.
   */
  function buildDistrictDisplay( string $type,  mixed $value, array $rows, array $countyParticipation=[] ): array
  {
    
    $title = match ($type) {
      'CD' => 'Congressional District ' . $value,
      'County' => $value . ' County',
      default => 'NLP Results Summary',
    };
    $form_element['cdName'] = [
      '#markup' => "<H1>$title</H1>",
    ];
    
    if(!empty($countyParticipation)) {
      $participation = '<p>'.implode('<br>', $countyParticipation).'</p>';
      $form_element['participation'] = [
        '#markup' => $participation,
      ];
    }
    
    $form_element['table-start'] = [
      '#markup' => '<div class="summary-report">',
    ];
    $header = [
      'type' => '',
      'registered' => 'Registered',
      'voted' => 'Voted',
      'participation' => 'Participation',
    ];
    $electionName = $this->configurationObj->getElectionName();
    $form_element['table-rows'] = [
      '#type' => 'table',
      '#caption' => "Summary of results for the $electionName",
      '#header' => $header,
      '#rows' => $rows,
    ];
    $form_element['table-end'] = [
      '#markup' => '</div>',
    ];
    return $form_element;
  }
  
  /**
   * buildResultsTableArray
   *
   * Build the result table array for a county or CD.
   *
   * @param string $type - the County or CD.
   * @param mixed $value - the value of the County or CD.
   * @return array - the rows of data for the result table.
   */
  public function buildResultsTableArray(string $type, mixed $value, array &$nlpCounts): array
  {
    $counts = ($type=='County')?
      $this->crosstabsObj->getCountsByCounty($value):
      $this->crosstabsObj->getCountsByCd($value);
    $rows = $this->buildBallotCounts($counts, $nlpCounts);
    
    $row = [];
    // Get the count of voters in assigned turfs.
    $row['name'] = 'NLP Voters';
    $voterCount = $this->turfsObj->getVoterCounts($type, $value, 'All');
    $row['registered'] = $voterCount;
    // Count the number of these voters who returned ballots.
    $votedCount = $this->turfsObj->getVoterCounts($type, $value, 'All', TRUE);
    $row['voted'] = $votedCount;
    // Display the voter participation.
    $row['pct'] = (!empty($voterCount)) ? round($votedCount / $voterCount * 100, 1) . '%' : '0%';
    $rows['nlp'] = $row;
    
    // Count the number of voters who we attempted to contact but left lit.
    $row['name'] = 'Attempted';
    $voterAttempts = $this->turfsObj->getVoterCounts($type, $value, 'Attempted');
    // Count the number of these voters who returned ballots.
    
    $row['registered'] = $voterAttempts;
    // Count the number of these voters who returned ballots.
    $attemptedAndVotedCount = $this->turfsObj->getVoterCounts($type, $value, 'Attempted', TRUE);
    $row['voted'] = $attemptedAndVotedCount;
    $row['pct'] = (!empty($attemptedAndVotedCount)) ?
      round($attemptedAndVotedCount / $voterAttempts * 100, 1) . '%' : '0%';
    $rows['attempted'] = $row;
    
    // Count the number of the voters who had a personal contact and who voted.
    $row['name'] = 'Contacted';
    $voterContacts = $this->turfsObj->getVoterCounts($type, $value, 'Contacted');
    $row['registered'] = $voterContacts;
    $voterContactsVoted = $this->turfsObj->getVoterCounts($type, $value, 'Contacted', TRUE);
    $row['voted'] = $voterContactsVoted;
    $row['pct'] = (!empty($voterContacts)) ?
      round($voterContactsVoted / $voterContacts * 100, 1) . '%' : '0%';
    $rows['contacted'] = $row;
    
    // Count the number of voters who were sent postcards and those who voted.
    $row['name'] = 'Postcard';
    $voterPostcardCount = $this->turfsObj->getVoterCounts($type, $value, 'Postcard');
    $row['registered'] = $voterPostcardCount;
    $voterPostcardCountVoted = $this->turfsObj->getVoterCounts($type, $value, 'Postcard', TRUE);
    $row['voted'] = $voterPostcardCountVoted;
    $row['pct'] = (!empty($voterPostcardCount)) ?
      round($voterPostcardCountVoted / $voterPostcardCount * 100, 1) . '%' : '0%';
    $rows['postcard'] = $row;
    
    $nlpCounts['postcards'] += $voterPostcardCount;
    $nlpCounts['postcardsVoted'] += $voterPostcardCountVoted;
    $nlpCounts['attempted'] += $voterAttempts;
    $nlpCounts['attemptedVoted'] += $attemptedAndVotedCount;
    $nlpCounts['contacted'] += $voterContacts;
    $nlpCounts['contactedVoted'] += $voterContactsVoted;
    $nlpCounts['nlpVoters'] += $voterCount;
    $nlpCounts['nlpVotersVoted'] += $votedCount;

    return $rows;
  }
  
  /**
   * buildSummaryTableArray
   *
   * Build the data for a summary table array for either a county or a cd..
   *
   * @param array $nlpCounts - the counts to be displayed for the progress of NLP,
   * either for CD or County.  The display includes information from VoteBuilder and from
   * summing the NLP activities.
   * @return array - the rows of data for the summary table.
   */
  private function buildSummaryTableArray(array $nlpCounts): array
  {
    
    $summaryRows = $this->buildBallotCountSummary($nlpCounts);
    
    $dataRows = [
      'allNLP' => ['name' => 'NLP Voters',
        'registered' => $nlpCounts['nlpVoters'],
        'voted' => $nlpCounts['nlpVotersVoted'],
        'pct' => (!empty($nlpCounts['nlpVoters'])) ?
          round($nlpCounts['nlpVotersVoted'] / $nlpCounts['nlpVoters'] * 100, 1) . '%' : '0%'],
      'attempted' => ['name' => 'Attempted',
        'registered' => $nlpCounts['attempted'],
        'voted' => $nlpCounts['attemptedVoted'],
        'pct' => (!empty($nlpCounts['attempted'])) ?
          round($nlpCounts['attemptedVoted'] / $nlpCounts['attempted'] * 100, 1) . '%' : '0%'],
      'contacted' => ['name' => 'Contacted',
        'registered' => $nlpCounts['contacted'],
        'voted' => $nlpCounts['contactedVoted'],
        'pct' => (!empty($nlpCounts['contacted'])) ?
          round($nlpCounts['contactedVoted'] / $nlpCounts['contacted'] * 100, 1) . '%' : '0%'],
      'postcard' => ['name' => 'Postcard',
        'registered' => $nlpCounts['postcards'],
        'voted' => $nlpCounts['postcardsVoted'],
        'pct' => (!empty($nlpCounts['postcards'])) ?
          round($nlpCounts['postcardsVoted'] / $nlpCounts['postcards'] * 100, 1) . '%' : '0%']
    ];
    
    return array_merge($summaryRows, $dataRows);
  }
  
  /**
   * buildBallotCountSummary
   *
   * Build the summary of the ballot counts for all voters, Democrats, Republicans, and Non-Affiliated.
   *
   * @param array $nlpCounts - the counts from VoteBuilder, either for CD or County.
   * @return array - an array of rows with the ballot counts.
   */
  private function buildBallotCountSummary(array $nlpCounts): array
  {
    return [
      'ALL' => ['name' => 'All Voters',
        'registered' => $nlpCounts['allVoters'],
        'voted' => $nlpCounts['allVotersVoted'],
        'pct' => ($nlpCounts['allVoters'] > 0) ?
          round($nlpCounts['allVotersVoted'] / $nlpCounts['allVoters'] * 100, 1) . '%' : '0%'],
      'Democrats' => ['name' => 'Democrats',
        'registered' => $nlpCounts['democrats'],
        'voted' => $nlpCounts['democratsVoted'],
        'pct' => ($nlpCounts['democrats'] > 0) ?
          round($nlpCounts['democratsVoted'] / $nlpCounts['democrats'] * 100, 1) . '%' : '0%'],
      'Republicans' => ['name' => 'Republicans',
        'registered' => $nlpCounts['republicans'],
        'voted' => $nlpCounts['republicansVoted'],
        'pct' => ($nlpCounts['republicans'] > 0) ?
          round($nlpCounts['republicansVoted'] / $nlpCounts['republicans'] * 100, 1) . '%' : '0%'],
      'Non-Affiliated' => ['name' => 'Non-Affiliated',
        'registered' => $nlpCounts['nonAffiliated'],
        'voted' => $nlpCounts['nonAffiliatedVoted'],
        'pct' => ($nlpCounts['nonAffiliated'] > 0) ?
          round($nlpCounts['nonAffiliatedVoted'] / $nlpCounts['nonAffiliated'] * 100, 1) . '%' : '0%'],
    ];
  }
  
  /**
   * buildBallotCounts
   *
   * Build 4 rows for a display of voting activity.  The rows are for all
   * participating districts, either County or CD.  The 4 rows
   * are for all voters, Democrats, Republicans, and Non-Affiliated. The
   * counts come from a report created by VoteBuilder and are assembled
   * by County or CD by NLP Services
   *
   * @param array $counts - the counts from VoteBuilder, either for CD or County.
   * @param array $nlpCounts - the array to accumulate the NLP counts, eventually
   * being the totals for NLP.
   * @return array - an array of rows with the ballot counts.
   */
  private function buildBallotCounts(array $counts, array &$nlpCounts): array
  {
    if (empty($counts)) {return [];}
    $rows = [];
    foreach ($counts as $party => $partyCounts) {
      $v = $partyCounts['regVoters'];
      $v_br = $partyCounts['regVoted'];
      $row = [];
      switch ($party) {
        case 'Democrats':
          $row['name'] = 'Democrats';
          $row['registered'] = $v;
          $row['voted'] = $v_br;
          $row['pct'] = ($v > 0) ? round($v_br / $v * 100, 1) . '%' : '0%';
          $rows['dem'] = $row;
          $nlpCounts['democrats'] += $v;
          $nlpCounts['democratsVoted'] += $v_br;
          break;
        case 'Republicans':
          $row['name'] = 'Republicans';
          $row['registered'] = $v;
          $row['voted'] = $v_br;
          $row['pct'] = ($v > 0) ? round($v_br / $v * 100, 1) . '%' : '0%';
          $rows['rep'] = $row;
          $nlpCounts['republicans'] += $v;
          $nlpCounts['republicansVoted'] += $v_br;
          break;
        case 'Non-Affiliated':
          $row['name'] = 'Non-Affiliated';
          $row['registered'] = $v;
          $row['voted'] = $v_br;
          $row['pct'] = ($v > 0) ? round($v_br / $v * 100, 1) . '%' : '0%';
          $rows['na'] = $row;
          $nlpCounts['nonAffiliated'] += $v;
          $nlpCounts['nonAffiliatedVoted'] += $v_br;
          break;
        case 'ALL':
          $row['name'] = 'All Voters';
          $row['registered'] = $v;
          $row['voted'] = $v_br;
          $row['pct'] = ($v > 0) ? round($v_br / $v * 100, 1) . '%' : '0%';
          $rows['all'] = $row;
          $nlpCounts['allVoters'] += $v;
          $nlpCounts['allVotersVoted'] += $v_br;
          break;
      }
    }
    return $rows;
  }
  
  /**
   * gatherCountyParticipationData
   *
   * Gather the participation data for a County, CD, or the total NLP program.
   *
   * @param string $type - the type of participation, either 'CD', 'County', or 'NLP'.
   * @param string $value - the value of the County or CD.
   * @param array $nlpParticipation - the array to accumulate the participation data.
   * @return array - an array with the participation data for display.
   */
  private function gatherCountyParticipationData(string $type, string $value, array &$nlpParticipation): array
  {
    // Get the counts for NLs, NLs reporting results and turfs.
    switch ($type) {
      case 'CD':
        $participatingNls = $this->turfsObj->countOfNlsWithTurfsInCd( $value );
        $resultsReported = $this->turfsObj->countOfNlsInCdThatReportedResults( $value );
        $numberOfTurfs = $this->turfsObj->getCdTurfCount($value);
        break;
      case 'County':
        $nlsParticipation = $this->nlsObj->getCountyNlsParticipation( $value );
        $participatingNls = $nlsParticipation['participatingNls'] ?? 0;
        $resultsReported = $nlsParticipation['resultsReported'] ?? 0;
        $numberOfTurfs = $this->turfsObj->getCountyTurfCount($value);
        break;
      default:  // All NLP counties, the sums are already complete.
        $participatingNls = $nlpParticipation['participatingNls'];
        $resultsReported = $nlpParticipation['resultsReported'];
        $numberOfTurfs = $nlpParticipation['numberOfTurfs'];
        break;
    }
    
    $percentage = (!empty($participatingNls))?
      round($resultsReported/$participatingNls*100,1).'%':'0%';
    // Create the data for a row with 4 columns
    $row = [];
    $row['participatingNls'] = "Number of participating NLs: $participatingNls";
    $row['resultsReported'] = "Number of NLs reporting results: $resultsReported";
    $row['percentage'] = "Percentage of NLs reporting results: $percentage";
    $row['numberOfTurfs'] = "Number of turfs: $numberOfTurfs";
    
    if($type!='NLP') {
      $nlpParticipation['participatingNls'] += $participatingNls;
      $nlpParticipation['resultsReported'] += $resultsReported;
      $nlpParticipation['numberOfTurfs'] += $numberOfTurfs;
    }
    return $row;
  }
  
}
