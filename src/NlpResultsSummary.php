<?php

namespace Drupal\nlpservices;

use Drupal\Component\Utility\Html;
use Drupal\Core\File\FileExists;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

/**
 * Builds the election results summary for the state, a CD, a county or an HD.
 *
 * Used in:
 *   ElectionSummaryForm.php
 *
 * @noinspection PhpUnused
 */
class NlpResultsSummary
{
  public const string LEVEL_STATE = 'state';
  public const string LEVEL_CD = 'cd';
  public const string LEVEL_COUNTY = 'county';
  public const string ALL = 'all';
  public const string COUNTY_SUMMARY = 'summary';
  public const string HD_NOTE = 'VoteBuilder counts by HD are not available; the crosstab file had no HD column.';

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
                              $sessionObj, $htmlTextObj,
                              private readonly FileUrlGeneratorInterface $fileUrlGenerator,
                              private readonly FileRepositoryInterface $fileRepository,
                              private readonly PdfGenerator $pdfGenerator ) {
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
      $container->get('nlpservices.crosstab_counts'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.html_text'),
      $container->get('file_url_generator'),
      $container->get('file.repository'),
      $container->get('nlpservices.pdf_generator'),
    );
  }

  private function initNlpCounts(): array
  {
    return [
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
  }

  private function initParticipation(): array
  {
    return [
      'participatingNls' => 0,
      'resultsReported' => 0,
      'numberOfTurfs' => 0
    ];
  }

  /**
   * getCdOptions
   *
   * The CDs with VoteBuilder counts, in numerical order.
   *
   * @return array - indexed array of CD numbers.
   */
  public function getCdOptions(): array
  {
    $cds = array_map('intval', $this->crosstabsObj->getCds());
    sort($cds);
    return $cds;
  }

  /**
   * getCountyOptions
   *
   * An NLP admin can choose any county with voters assigned to turfs.  Anyone
   * else gets only the county they are managing.
   *
   * @return array - indexed array of county names.
   */
  public function getCountyOptions(): array
  {
    if ($this->drupalUser->isNlpAdminUser()) {
      return $this->votersObj->getParticipatingCounties();
    }
    $county = $this->sessionObj->getCounty();
    return empty($county) ? [] : [$county];
  }

  /**
   * getHdOptions
   *
   * The house districts in a county that have turfs.
   *
   * @param string $county
   * @return array - indexed array of HD numbers.
   */
  public function getHdOptions(string $county): array
  {
    return array_values($this->turfsObj->getTurfHdNames($county));
  }

  /**
   * createElectionSummary
   *
   * Creates the election results summary for the request, writes every section
   * of it to a PDF and a CSV file, and builds the display.  The display shows
   * only the top-level summary: the state for all CDs or all counties, the
   * county for all of its HDs, otherwise the one district requested.
   *
   * @param string $level - LEVEL_STATE, LEVEL_CD, or LEVEL_COUNTY.
   * @param string $district - ALL, a CD number, or a county name.
   * @param string $hd - for one county: COUNTY_SUMMARY, ALL, or an HD number.
   * @return array - 'display' => render array, or 'error' => message.
   */
  public function createElectionSummary(string $level, string $district = self::ALL,
                                        string $hd = self::COUNTY_SUMMARY): array
  {
    $summary = $this->buildSummarySections($level, $district, $hd);
    if (!empty($summary['error'])) {
      return ['error' => $summary['error']];
    }
    $sections = $summary['sections'];

    $fileTag = $this->fileTag($level, $district, $hd);
    $csvUrl = $this->writeCsvFile($fileTag, $sections);
    $pdfUri = 'public://temp/' . $fileTag . '-' . date('Y-m-d-H-i-s') . '.pdf';
    $pdfUrl = $this->pdfGenerator->generatePdf($this->buildPdfHtml($summary['title'], $sections), $pdfUri, 'P');

    $output = [];
    $output['links'] = [
      '#markup' => $this->buildFileLinks($summary['title'], count($sections), $pdfUrl, $csvUrl),
    ];
    $output['legend'] = [
      '#markup' => $this->buildLegend(),
    ];
    $displayed = $sections[$summary['displayIndex']];
    $output['summary'] = $this->buildDistrictDisplay($displayed['type'], $displayed['value'],
      $displayed['rows'], $displayed['participation']);
    $output['summary']['table-start']['#attached']['library'][] = 'nlpservices/info-boxes';
    return ['display' => $output];
  }

  /**
   * buildSummarySections
   *
   * Gathers the counts for each district in the request.
   *
   * @param string $level - LEVEL_STATE, LEVEL_CD, or LEVEL_COUNTY.
   * @param string $district - ALL, a CD number, or a county name.
   * @param string $hd - for one county: COUNTY_SUMMARY, ALL, or an HD number.
   * @return array - 'title' => description of the request, 'sections' => list of
   *   sections (type, value, title, participation, rows), and 'displayIndex' =>
   *   the section to display; or 'error' => message.
   */
  public function buildSummarySections(string $level, string $district = self::ALL,
                                       string $hd = self::COUNTY_SUMMARY): array
  {
    return match ($level) {
      self::LEVEL_STATE => $this->buildStateSections(),
      self::LEVEL_CD => $this->buildCdSections($district),
      self::LEVEL_COUNTY => $this->buildCountySections($district, $hd),
      default => ['error' => 'Unknown request.'],
    };
  }

  /**
   * The state summary is the sum of the participating counties.
   */
  private function buildStateSections(): array
  {
    if (!$this->drupalUser->isNlpAdminUser()) {
      return ['error' => 'Only an NLP admin can display the state summary.'];
    }
    $counties = $this->votersObj->getParticipatingCounties();
    if (empty($counties)) {
      return ['error' => 'No county has voters assigned to turfs.'];
    }
    $nlpCounts = $this->initNlpCounts();
    $nlpParticipation = $this->initParticipation();
    foreach ($counties as $county) {
      $this->buildDistrictSection('County', $county, $nlpCounts, $nlpParticipation);
    }
    $sections = [$this->buildStateSection('State summary of the participating counties',
      $nlpCounts, $nlpParticipation)];
    return ['title' => 'State summary', 'sections' => $sections, 'displayIndex' => 0];
  }

  /**
   * One CD, or every CD followed by the state summary of the CDs.
   */
  private function buildCdSections(string $district): array
  {
    $cds = $this->getCdOptions();
    if (empty($cds)) {
      return ['error' => 'The crosstab file needs to be loaded prior to this report.'];
    }
    if ($district !== self::ALL) {
      if (!in_array((int) $district, $cds, TRUE)) {
        return ['error' => 'There are no counts for CD ' . $district . '.'];
      }
      $cds = [(int) $district];
    }
    $nlpCounts = $this->initNlpCounts();
    $nlpParticipation = $this->initParticipation();
    $sections = [];
    foreach ($cds as $cd) {
      $sections[] = $this->buildDistrictSection('CD', $cd, $nlpCounts, $nlpParticipation);
    }
    if ($district !== self::ALL) {
      return ['title' => 'Congressional District ' . $district, 'sections' => $sections, 'displayIndex' => 0];
    }
    $sections[] = $this->buildStateSection('State summary of the participating CDs',
      $nlpCounts, $nlpParticipation);
    return ['title' => 'All congressional districts', 'sections' => $sections,
      'displayIndex' => count($sections) - 1];
  }

  /**
   * Every county followed by the state summary, one county, the county
   * followed by each of its HDs, or one HD.
   */
  private function buildCountySections(string $district, string $hd): array
  {
    $counties = $this->getCountyOptions();
    if (empty($counties)) {
      return ['error' => 'No county has voters assigned to turfs.'];
    }
    $nlpCounts = $this->initNlpCounts();
    $nlpParticipation = $this->initParticipation();
    $sections = [];

    if ($district === self::ALL) {
      if (!$this->drupalUser->isNlpAdminUser()) {
        return ['error' => 'Only an NLP admin can display all the counties.'];
      }
      foreach ($counties as $county) {
        $sections[] = $this->buildDistrictSection('County', $county, $nlpCounts, $nlpParticipation);
      }
      $sections[] = $this->buildStateSection('State summary of the participating counties',
        $nlpCounts, $nlpParticipation);
      return ['title' => 'All participating counties', 'sections' => $sections,
        'displayIndex' => count($sections) - 1];
    }

    if (!in_array($district, $counties, TRUE)) {
      return ['error' => 'There are no voters assigned to turfs in ' . $district . ' County.'];
    }
    if ($hd === self::COUNTY_SUMMARY || $hd === self::ALL) {
      $sections[] = $this->buildDistrictSection('County', $district, $nlpCounts, $nlpParticipation);
    }
    if ($hd === self::COUNTY_SUMMARY) {
      return ['title' => $district . ' County', 'sections' => $sections, 'displayIndex' => 0];
    }

    $hdOptions = array_map('intval', $this->getHdOptions($district));
    if ($hd !== self::ALL) {
      if (!in_array((int) $hd, $hdOptions, TRUE)) {
        return ['error' => 'There are no turfs in HD ' . $hd . ' of ' . $district . ' County.'];
      }
      $hdOptions = [(int) $hd];
    }
    // The county section already has the totals, so the HD sums are discarded.
    $hdCounts = $this->initNlpCounts();
    $hdParticipation = $this->initParticipation();
    foreach ($hdOptions as $hdNumber) {
      $sections[] = $this->buildDistrictSection('HD', ['county' => $district, 'hd' => $hdNumber],
        $hdCounts, $hdParticipation);
    }
    $title = ($hd === self::ALL)
      ? $district . ' County, all house districts'
      : $district . ' County, House District ' . $hd;
    return ['title' => $title, 'sections' => $sections, 'displayIndex' => 0];
  }

  /**
   * Builds the section for one CD, County, or HD, and adds its counts to the
   * totals.
   *
   * @param string $type - 'CD', 'County', or 'HD'.
   * @param mixed $value - the CD number, county name, or ['county', 'hd'].
   * @param array $nlpCounts - accumulator for the vote counts.
   * @param array $nlpParticipation - accumulator for the participation counts.
   * @return array - the section.
   */
  private function buildDistrictSection(string $type, mixed $value, array &$nlpCounts,
                                        array &$nlpParticipation): array
  {
    $participation = $this->gatherCountyParticipationData($type, $value, $nlpParticipation);
    $rows = $this->buildResultsTableArray($type, $value, $nlpCounts);
    return [
      'type' => $type,
      'value' => $value,
      'title' => $this->sectionTitle($type, $value),
      'participation' => $participation,
      'rows' => $rows,
    ];
  }

  /**
   * Builds the section for the sum of the districts.
   *
   * @param string $title
   * @param array $nlpCounts - the summed vote counts.
   * @param array $nlpParticipation - the summed participation counts.
   * @return array - the section.
   */
  private function buildStateSection(string $title, array $nlpCounts, array $nlpParticipation): array
  {
    return [
      'type' => 'NLP',
      'value' => $title,
      'title' => $title,
      'participation' => $this->gatherCountyParticipationData('NLP', '', $nlpParticipation),
      'rows' => $this->buildSummaryTableArray($nlpCounts),
    ];
  }

  /**
   * The title of a section.
   *
   * @param string $type - 'CD', 'County', 'HD', or 'NLP'.
   * @param mixed $value - the CD number, county name, ['county', 'hd'], or for
   *   NLP an optional title.
   * @return string
   */
  private function sectionTitle(string $type, mixed $value): string
  {
    return match ($type) {
      'CD' => 'Congressional District ' . $value,
      'County' => $value . ' County',
      'HD' => $value['county'] . ' County, House District ' . $value['hd'],
      default => empty($value) ? 'NLP Results Summary' : (string) $value,
    };
  }

  /**
   * An HD section without VoteBuilder rows gets a note saying why.
   */
  private function needsHdNote(string $type, array $rows): bool
  {
    return $type === 'HD' && !isset($rows['all']);
  }

  /**
   * A tag for the file names of the request, safe for a file name.
   */
  private function fileTag(string $level, string $district, string $hd): string
  {
    $parts = ['nlp-summary', $level];
    if ($level !== self::LEVEL_STATE) {
      $parts[] = $district;
      if ($level === self::LEVEL_COUNTY && $district !== self::ALL && $hd !== self::COUNTY_SUMMARY) {
        $parts[] = 'hd-' . $hd;
      }
    }
    $tag = strtolower(implode('-', $parts));
    return trim(preg_replace('/[^a-z0-9-]+/', '-', $tag), '-');
  }

  /**
   * Writes the legend and every section to a CSV file.
   *
   * @param string $fileTag
   * @param array $sections
   * @return string - the URL of the file, or '' if it couldn't be written.
   */
  private function writeCsvFile(string $fileTag, array $sections): string
  {
    $tempUri = $this->createTempFile($fileTag);
    if (empty($tempUri)) {
      return '';
    }
    $fh = fopen($tempUri, "w");
    if (!$fh) {
      return '';
    }
    $this->writeToTempFile($fh, $this->buildLegend(FALSE));
    foreach ($sections as $section) {
      $this->writeToTempFile($fh, [['']]);
      $this->writeToTempFile($fh, [[$section['title'], 'Registered', 'Voted', 'Participation']]);
      $this->writeToTempFile($fh, array_values($section['participation']));
      if ($this->needsHdNote($section['type'], $section['rows'])) {
        $this->writeToTempFile($fh, [self::HD_NOTE]);
      }
      $this->writeToTempFile($fh, array_map('array_values', array_values($section['rows'])));
    }
    fclose($fh);
    return $this->fileUrlGenerator->generateAbsoluteString($tempUri);
  }

  /**
   * Builds the HTML for the PDF with the legend and every section.
   *
   * @param string $title - description of the request.
   * @param array $sections
   * @return string
   */
  public function buildPdfHtml(string $title, array $sections): string
  {
    $electionName = Html::escape($this->configurationObj->getElectionName());
    $html = '<style>
      body { font-family: sans-serif; font-size: 10pt; }
      h1 { font-size: 16pt; }
      h2 { font-size: 13pt; margin: 4mm 0 1mm 0; }
      table.summary { border-collapse: collapse; width: 120mm; margin-bottom: 4mm; }
      table.summary th, table.summary td { border: 1px solid #555; padding: 1mm 2mm; }
      table.summary td.num { text-align: right; }
      table.summary caption { text-align: left; font-style: italic; }
      </style>';
    $html .= '<h1>Election results summary: ' . Html::escape($title) . '</h1>';
    $html .= $this->buildLegend();
    foreach ($sections as $section) {
      $html .= '<div style="page-break-inside: avoid;">';
      $html .= '<h2>' . Html::escape($section['title']) . '</h2>';
      $lines = array_map([Html::class, 'escape'], array_values($section['participation']));
      if ($this->needsHdNote($section['type'], $section['rows'])) {
        $lines[] = '<i>' . Html::escape(self::HD_NOTE) . '</i>';
      }
      $html .= '<p>' . implode('<br>', $lines) . '</p>';
      $html .= '<table class="summary" autosize="1"><caption>Summary of results for the '
        . $electionName . '</caption>';
      $html .= '<thead><tr><th></th><th>Registered</th><th>Voted</th><th>Participation</th></tr></thead><tbody>';
      foreach ($section['rows'] as $row) {
        $html .= '<tr><td>' . Html::escape((string) $row['name']) . '</td>'
          . '<td class="num">' . Html::escape((string) $row['registered']) . '</td>'
          . '<td class="num">' . Html::escape((string) $row['voted']) . '</td>'
          . '<td class="num">' . Html::escape((string) $row['pct']) . '</td></tr>';
      }
      $html .= '</tbody></table></div>';
    }
    return $html;
  }

  /**
   * Builds the links to the PDF and CSV files.
   */
  private function buildFileLinks(string $title, int $sectionCount, string $pdfUrl, string $csvUrl): string
  {
    $contents = ($sectionCount == 1)
      ? 'the summary shown below'
      : "$sectionCount summaries, including the one shown below";
    $links = '<h2>' . Html::escape($title) . '</h2>';
    $links .= '<p>The files contain ' . $contents . '.</p><ul>';
    $links .= empty($pdfUrl)
      ? '<li>The PDF file could not be created.</li>'
      : '<li><a href="' . Html::escape($pdfUrl) . '" target="_blank">PDF of the summary report.</a></li>';
    $links .= empty($csvUrl)
      ? '<li>The CSV file could not be created.</li>'
      : '<li><a href="' . Html::escape($csvUrl) . '">CSV of the summary report (right-click to download).</a></li>';
    $links .= '</ul>';
    return $links;
  }

  /**
   * Create a temporary file in the public://temp directory with a unique name.
   * The file is created as a managed file, so Drupal will delete it after 6 hours.
   *
   * @param string $prefix - the prefix for the temporary file name.
   * @return string - the URI of the temporary file.
   */
  private function createTempFile(string $prefix): string
  {
    $tempDir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s');
    $tempUri = "$tempDir/$prefix-$contactDate.csv";
    // Create a managed file for temporary use.  Drupal will delete it after 6 hours.
    try{
      $file = $this->fileRepository->writeData('', $tempUri, FileExists::Replace);
      $file->setTemporary();
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('createTempFile', $e);
      return '';
    }
    return $tempUri;
  }

  /**
   * Write the results to the temporary file.  The rows are either an
   * array of arrays that are to be converted to CSV format, or a single
   * array of values that are to be written as a single row in the CSV file.
   *
   * @param resource $fh - file handle for the temporary file.
   * @param array $rows - the rows of data to write.
   */
  private function writeToTempFile($fh, array $rows): void
  {
    if (!$fh) {return;}
    $row = reset($rows);
    if(is_array($row)) {
      foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
      }
    } else {
      foreach ($rows as $row) {
        $rowAsArray = [$row];
        fputcsv($fh, $rowAsArray, escape: '');
      }
    }
  }

  /**
   * Build the legend for the result summary.  When returned as HTML, the array of
   * lines is concatenated into a single string with HTML tags.  When returned as plain text,
   * the array of lines is converted to plain text by removing HTML tags and returning an array
   * of lines.  These lines are added to the CSV file.
   *
   * @param bool $html - whether to return HTML or plain text.
   * @return string|array - the legend text, either as HTML or plain text.
   */
  private function buildLegend(bool $html=TRUE): string|array
  {
    $crosstabCreateTime = $this->configurationObj->getCrosstabCreateTime();
    $crosstabDate = empty($crosstabCreateTime)?'Not yet':date("F d Y H:i.", $crosstabCreateTime);
    $matchbackDate = $this->matchbacksObj->getLatestMatchbackDate();
    $legendText = [
      "<p>The last update of the counts from VoteBuilder: <b>$crosstabDate</b><br>",
      "The latest matchback date: <b>$matchbackDate</b></p>",
      '<h2>Legend</h2>',
      '<p><span class="nlp-legend-label">All Voters *</span> all registered voters in the participating district.<br>',
      '<span class="nlp-legend-label">Republicans *</span> the registered Republicans.<br>',
      '<span class="nlp-legend-label">Non-Affiliated *</span> the registered Non-Affiliated voters.<br>',
      '<span class="nlp-legend-label">Democrats *</span> the registered Democrats.<br>',
      '<span class="nlp-legend-label">NLP Voters</span> the voters in the assigned turfs.<br>',
      '<span class="nlp-legend-label">Attempted</span> the voters who were attempted to be contacted, but did not have a personal contact.<br>',
      '<span class="nlp-legend-label">Contacted</span> the voters who had a personal contact.<br>',
      '<span class="nlp-legend-label">Postcard</span> the voters who were sent postcards.</p>',
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
   * Build the form elements for the display of the results for a county, CD, HD, or NLP total.
   *
   * @param string $type - the type of display: 'County', 'CD', 'HD', or 'NLP'.
   * @param mixed $value - the County, CD, ['county', 'hd'], or for NLP an optional title.
   * @param array $rows - the rows of data to display.
   * @param array $countyParticipation - optional participation summary lines.
   * @return array - renderable array for Drupal.
   */
  public function buildDistrictDisplay( string $type,  mixed $value, array $rows, array $countyParticipation=[] ): array
  {
    $title = Html::escape($this->sectionTitle($type, $value));
    $output['cdName'] = [
      '#markup' => "<h1>$title</h1>",
    ];

    if($this->needsHdNote($type, $rows)) {
      $countyParticipation[] = '<i>' . self::HD_NOTE . '</i>';
    }
    if(!empty($countyParticipation)) {
      $participation = '<p>'.implode('<br>', $countyParticipation).'</p>';
      $output['participation'] = [
        '#markup' => $participation,
      ];
    }

    $output['table-start'] = [
      '#markup' => '<div class="summary-report">',
    ];
    $header = [
      'type' => '',
      'registered' => 'Registered',
      'voted' => 'Voted',
      'participation' => 'Participation',
    ];
    $electionName = $this->configurationObj->getElectionName();
    $output['table-rows'] = [
      '#type' => 'table',
      '#caption' => "Summary of results for the $electionName",
      '#header' => $header,
      '#rows' => $rows,
    ];
    $output['table-end'] = [
      '#markup' => '</div>',
    ];
    return $output;
  }

  /**
   * Build the result table array for a county, CD, or HD.
   *
   * @param string $type - 'County', 'CD', or 'HD'.
   * @param mixed $value - the County, CD, or ['county', 'hd'].
   * @param array $nlpCounts - accumulator for totals across districts.
   * @return array - the rows of data for the result table.
   */
  public function buildResultsTableArray(string $type, mixed $value, array &$nlpCounts): array
  {
    // HD counts exist only if the crosstab file had an HD column.
    $counts = match ($type) {
      'County' => $this->crosstabsObj->getCountsByCounty($value),
      'CD' => $this->crosstabsObj->getCountsByCd($value),
      'HD' => $this->crosstabsObj->getCountsByHd($value['county'], (int) $value['hd']),
      default => [],
    };
    $rows = $this->buildBallotCounts($counts, $nlpCounts);

    $row = [];
    // Get the count of voters in assigned turfs.
    $row['name'] = 'NLP Voters';
    $voterCount = $this->turfsObj->getVoterCounts($type, $value, 'All');
    $row['registered'] = $voterCount;
    // Count the number of these voters who returned ballots.
    $votedCount = $this->turfsObj->getVoterCounts($type, $value, 'All', TRUE);
    $row['voted'] = $votedCount;
    $row['pct'] = (!empty($voterCount)) ? round($votedCount / $voterCount * 100, 1) . '%' : '0%';
    $rows['nlp'] = $row;

    // Count the number of voters who we attempted to contact but left lit.
    $row['name'] = 'Attempted';
    $voterAttempts = $this->turfsObj->getVoterCounts($type, $value, 'Attempted');
    $row['registered'] = $voterAttempts;
    $attemptedAndVotedCount = $this->turfsObj->getVoterCounts($type, $value, 'Attempted', TRUE);
    $row['voted'] = $attemptedAndVotedCount;
    $row['pct'] = (!empty($voterAttempts)) ?
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
   * Build the data for a summary table array for either a county or a cd.
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
   * Build 4 rows for a display of voting activity.  The rows are for all
   * participating districts, either County or CD. The 4 rows
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
   * Gather the participation data for a County, CD, HD, or the total NLP program.
   *
   * @param string $type - the type of participation: 'CD', 'County', 'HD', or 'NLP'.
   * @param mixed $value - the County, CD, or ['county', 'hd'].
   * @param array $nlpParticipation - the array to accumulate the participation data.
   * @return array - an array with the participation data for display.
   */
  private function gatherCountyParticipationData(string $type, mixed $value, array &$nlpParticipation): array
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
      case 'HD':
        $participatingNls = $this->turfsObj->countOfNlsWithTurfsInHd($value['county'], (int) $value['hd']);
        $resultsReported = $this->turfsObj->countOfNlsInHdThatReportedResults($value['county'], (int) $value['hd']);
        $numberOfTurfs = $this->turfsObj->getCountyTurfCount($value['county'], (int) $value['hd']);
        break;
      default:  // All NLP districts, the sums are already complete.
        $participatingNls = $nlpParticipation['participatingNls'];
        $resultsReported = $nlpParticipation['resultsReported'];
        $numberOfTurfs = $nlpParticipation['numberOfTurfs'];
        break;
    }

    $percentage = (!empty($participatingNls))?
      round($resultsReported/$participatingNls*100,1).'%':'0%';
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
