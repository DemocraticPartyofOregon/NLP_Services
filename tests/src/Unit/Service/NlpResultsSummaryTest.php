<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\file\FileRepositoryInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\HtmlText;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpCrosstabCounts;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpResultsSummary;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\nlpservices\PdfGenerator;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpResultsSummaryTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpMatchbacks $matchbacksMock;
    private NlpNls $nlsMock;
    private DrupalUser $drupalUserMock;
    private NlpCrosstabCounts $crosstabsMock;
    private NlpReports $reportsMock;
    private NlpTurfs $turfsMock;
    private NlpConfiguration $configurationMock;
    private NlpSessionData $sessionMock;
    private HtmlText $htmlTextMock;
    private FileUrlGeneratorInterface $fileUrlGeneratorMock;
    private FileRepositoryInterface $fileRepositoryMock;
    private PdfGenerator $pdfGeneratorMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock = $this->createMock(NlpVoters::class);
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
      $this->nlsMock = $this->createMock(NlpNls::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->crosstabsMock = $this->createMock(NlpCrosstabCounts::class);
      $this->reportsMock = $this->createMock(NlpReports::class);
      $this->turfsMock = $this->createMock(NlpTurfs::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->sessionMock = $this->createMock(NlpSessionData::class);
      $this->htmlTextMock = $this->createMock(HtmlText::class);
      $this->fileUrlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $this->fileRepositoryMock = $this->createMock(FileRepositoryInterface::class);
      $this->pdfGeneratorMock = $this->createMock(PdfGenerator::class);
    }

    private function buildService(): NlpResultsSummary {
      return new NlpResultsSummary(
        $this->votersMock,
        $this->matchbacksMock,
        $this->nlsMock,
        $this->drupalUserMock,
        $this->crosstabsMock,
        $this->reportsMock,
        $this->turfsMock,
        $this->configurationMock,
        $this->sessionMock,
        $this->htmlTextMock,
        $this->fileUrlGeneratorMock,
        $this->fileRepositoryMock,
        $this->pdfGeneratorMock,
      );
    }

    private function blankNlpCounts(): array {
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
        'nlpVotersVoted' => 0,
      ];
    }

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.voters', $this->votersMock);
      $container->set('nlpservices.matchbacks', $this->matchbacksMock);
      $container->set('nlpservices.nls', $this->nlsMock);
      $container->set('nlpservices.drupal_user', $this->drupalUserMock);
      $container->set('nlpservices.crosstab_counts', $this->crosstabsMock);
      $container->set('nlpservices.reports', $this->reportsMock);
      $container->set('nlpservices.turfs', $this->turfsMock);
      $container->set('nlpservices.configuration', $this->configurationMock);
      $container->set('nlpservices.session_data', $this->sessionMock);
      $container->set('nlpservices.html_text', $this->htmlTextMock);
      $container->set('file_url_generator', $this->fileUrlGeneratorMock);
      $container->set('file.repository', $this->fileRepositoryMock);
      $container->set('nlpservices.pdf_generator', $this->pdfGeneratorMock);

      $service = NlpResultsSummary::create($container);

      $this->assertInstanceOf(NlpResultsSummary::class, $service);
    }

    public function testBuildDistrictDisplayReturnsCaptionAndRows(): void {
      $this->configurationMock->method('getElectionName')->willReturn('General Election 2026');
      $rows = [
        ['name' => 'All Voters', 'registered' => 100, 'voted' => 70, 'pct' => '70%'],
      ];

      $result = $this->buildService()->buildDistrictDisplay('County', 'Denver', $rows, ['line a', 'line b']);

      $this->assertStringContainsString('Denver County', $result['cdName']['#markup']);
      $this->assertStringContainsString('line a', $result['participation']['#markup']);
      $this->assertSame($rows, $result['table-rows']['#rows']);
      $this->assertStringContainsString('General Election 2026', $result['table-rows']['#caption']);
    }

    public function testBuildResultsTableArrayCountyBuildsNlpRowsAndAccumulatesTotals(): void {
      $this->crosstabsMock->method('getCountsByCounty')->with('Denver')->willReturn([
        'ALL' => ['regVoters' => 100, 'regVoted' => 60],
        'Democrats' => ['regVoters' => 40, 'regVoted' => 30],
        'Republicans' => ['regVoters' => 30, 'regVoted' => 15],
        'Non-Affiliated' => ['regVoters' => 30, 'regVoted' => 15],
      ]);
      $this->turfsMock->method('getVoterCounts')
        ->willReturnMap([
          ['County', 'Denver', 'All', false, 80],
          ['County', 'Denver', 'All', true, 50],
          ['County', 'Denver', 'Attempted', false, 20],
          ['County', 'Denver', 'Attempted', true, 12],
          ['County', 'Denver', 'Contacted', false, 30],
          ['County', 'Denver', 'Contacted', true, 24],
          ['County', 'Denver', 'Postcard', false, 10],
          ['County', 'Denver', 'Postcard', true, 6],
        ]);

      $counts = $this->blankNlpCounts();
      $rows = $this->buildService()->buildResultsTableArray('County', 'Denver', $counts);

      $this->assertSame('All Voters', $rows['all']['name']);
      $this->assertSame(80, $rows['nlp']['registered']);
      $this->assertSame(50, $rows['nlp']['voted']);
      $this->assertSame(20, $counts['attempted']);
      $this->assertSame(24, $counts['contactedVoted']);
      $this->assertSame(10, $counts['postcards']);
      $this->assertSame(100, $counts['allVoters']);
    }

    public function testBuildResultsTableArrayUsesCdCountsWhenTypeCd(): void {
      $this->crosstabsMock->expects($this->once())->method('getCountsByCd')->with(1)->willReturn([
        'ALL' => ['regVoters' => 10, 'regVoted' => 5],
      ]);
      $this->crosstabsMock->expects($this->never())->method('getCountsByCounty');
      $this->turfsMock->method('getVoterCounts')->willReturn(0);

      $counts = $this->blankNlpCounts();
      $rows = $this->buildService()->buildResultsTableArray('CD', 1, $counts);

      $this->assertSame(10, $rows['all']['registered']);
      $this->assertSame(5, $rows['all']['voted']);
    }

    public function testCreateElectionSummaryReturnsErrorWhenNoCds(): void {
      $this->crosstabsMock->method('getCds')->willReturn([]);

      $result = $this->buildService()->createElectionSummary(NlpResultsSummary::LEVEL_CD);

      $this->assertStringContainsString('crosstab file needs to be loaded', $result['error']);
    }

    public function testGetCdOptionsSortsNumerically(): void {
      $this->crosstabsMock->method('getCds')->willReturn(['5', '1', '10']);

      $this->assertSame([1, 5, 10], $this->buildService()->getCdOptions());
    }

    public function testGetCountyOptionsLimitsNonAdminToSessionCounty(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');
      $this->votersMock->expects($this->never())->method('getParticipatingCounties');

      $this->assertSame(['Lane'], $this->buildService()->getCountyOptions());
    }

    public function testAllCdsAddsStateSummaryAndDisplaysIt(): void {
      $this->crosstabsMock->method('getCds')->willReturn([2, 1]);
      $this->crosstabsMock->method('getCountsByCd')->willReturn([
        'ALL' => ['regVoters' => 100, 'regVoted' => 40],
      ]);
      $this->turfsMock->method('getVoterCounts')->willReturn(10);
      $this->turfsMock->method('countOfNlsWithTurfsInCd')->willReturn(3);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_CD);

      $this->assertCount(3, $result['sections']);
      $this->assertSame('Congressional District 1', $result['sections'][0]['title']);
      $this->assertSame(2, $result['displayIndex']);
      $state = $result['sections'][2];
      $this->assertSame('NLP', $state['type']);
      $this->assertSame(200, $state['rows']['ALL']['registered']);
      $this->assertSame(20, $state['rows']['allNLP']['registered']);
      $this->assertStringContainsString('6', $state['participation']['participatingNls']);
    }

    public function testOneCdHasOnlyThatCd(): void {
      $this->crosstabsMock->method('getCds')->willReturn([1, 2]);
      $this->crosstabsMock->method('getCountsByCd')->willReturn([]);
      $this->turfsMock->method('getVoterCounts')->willReturn(0);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_CD, '2');

      $this->assertCount(1, $result['sections']);
      $this->assertSame(2, $result['sections'][0]['value']);
      $this->assertSame(0, $result['displayIndex']);
    }

    public function testUnknownCdReturnsError(): void {
      $this->crosstabsMock->method('getCds')->willReturn([1, 2]);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_CD, '7');

      $this->assertArrayHasKey('error', $result);
    }

    public function testStateSummaryIsSumOfCountiesOnly(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
      $this->votersMock->method('getParticipatingCounties')->willReturn(['Benton', 'Lane']);
      $this->crosstabsMock->method('getCountsByCounty')->willReturn([
        'ALL' => ['regVoters' => 50, 'regVoted' => 25],
      ]);
      $this->turfsMock->method('getVoterCounts')->willReturn(4);
      $this->nlsMock->method('getCountyNlsParticipation')
        ->willReturn(['participatingNls' => 2, 'resultsReported' => 1]);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_STATE);

      $this->assertCount(1, $result['sections']);
      $this->assertSame(100, $result['sections'][0]['rows']['ALL']['registered']);
      $this->assertStringContainsString('4', $result['sections'][0]['participation']['participatingNls']);
    }

    public function testStateSummaryRequiresAdmin(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_STATE);

      $this->assertArrayHasKey('error', $result);
    }

    public function testAllCountiesRequiresAdmin(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY);

      $this->assertArrayHasKey('error', $result);
    }

    public function testAllCountiesAddsStateSummaryAndDisplaysIt(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
      $this->votersMock->method('getParticipatingCounties')->willReturn(['Benton', 'Lane']);
      $this->crosstabsMock->method('getCountsByCounty')->willReturn([]);
      $this->turfsMock->method('getVoterCounts')->willReturn(0);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY);

      $this->assertCount(3, $result['sections']);
      $this->assertSame(2, $result['displayIndex']);
      $this->assertSame('State summary of the participating counties', $result['sections'][2]['title']);
    }

    public function testOneCountyAllHdsHasCountyThenEachHd(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');
      $this->turfsMock->method('getTurfHdNames')->with('Lane')->willReturn([7 => 7, 8 => 8]);
      $this->crosstabsMock->method('getCountsByCounty')->willReturn([
        'ALL' => ['regVoters' => 50, 'regVoted' => 25],
      ]);
      $this->crosstabsMock->expects($this->never())->method('getCountsByCd');
      $this->turfsMock->method('getVoterCounts')->willReturn(0);

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY, 'Lane',
        NlpResultsSummary::ALL);

      $this->assertCount(3, $result['sections']);
      $this->assertSame(0, $result['displayIndex']);
      $this->assertSame('Lane County', $result['sections'][0]['title']);
      $this->assertSame('Lane County, House District 8', $result['sections'][2]['title']);
      // No VoteBuilder counts by HD, so only the NLP rows.
      $this->assertArrayNotHasKey('all', $result['sections'][1]['rows']);
      $this->assertArrayHasKey('nlp', $result['sections'][1]['rows']);
    }

    public function testOneHdHasOnlyThatHd(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');
      $this->turfsMock->method('getTurfHdNames')->willReturn([7 => 7, 8 => 8]);
      $this->turfsMock->method('getVoterCounts')->willReturn(0);
      $this->turfsMock->expects($this->once())->method('countOfNlsWithTurfsInHd')->with('Lane', 7)->willReturn(2);
      $this->crosstabsMock->expects($this->never())->method('getCountsByCounty');

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY, 'Lane', '7');

      $this->assertCount(1, $result['sections']);
      $this->assertSame('HD', $result['sections'][0]['type']);
    }

    public function testOtherCountyIsRejectedForNonAdmin(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');

      $result = $this->buildService()->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY, 'Benton');

      $this->assertArrayHasKey('error', $result);
    }

    public function testHdSectionUsesHdCountsWhenImported(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionMock->method('getCounty')->willReturn('Lane');
      $this->turfsMock->method('getTurfHdNames')->willReturn([7 => 7]);
      $this->turfsMock->method('getVoterCounts')->willReturn(0);
      $this->crosstabsMock->expects($this->once())->method('getCountsByHd')->with('Lane', 7)->willReturn([
        'ALL' => ['regVoters' => 30, 'regVoted' => 12],
      ]);

      $service = $this->buildService();
      $result = $service->buildSummarySections(NlpResultsSummary::LEVEL_COUNTY, 'Lane', '7');
      $section = $result['sections'][0];
      $display = $service->buildDistrictDisplay('HD', $section['value'], $section['rows'], $section['participation']);

      $this->assertSame(30, $section['rows']['all']['registered']);
      $this->assertStringNotContainsString(NlpResultsSummary::HD_NOTE, $display['participation']['#markup']);
    }

    public function testBuildDistrictDisplayHdAddsNote(): void {
      $result = $this->buildService()->buildDistrictDisplay('HD', ['county' => 'Lane', 'hd' => 7], [], ['line a']);

      $this->assertStringContainsString('Lane County, House District 7', $result['cdName']['#markup']);
      $this->assertStringContainsString(NlpResultsSummary::HD_NOTE, $result['participation']['#markup']);
    }

    public function testBuildPdfHtmlIncludesEverySectionEscaped(): void {
      $this->configurationMock->method('getElectionName')->willReturn('General');
      $sections = [
        ['type' => 'County', 'title' => 'A&B County', 'participation' => ['x' => 'NLs: 1'],
          'rows' => [['name' => 'NLP Voters', 'registered' => 5, 'voted' => 2, 'pct' => '40%']]],
        ['type' => 'NLP', 'title' => 'State summary', 'participation' => [], 'rows' => []],
      ];

      $html = $this->buildService()->buildPdfHtml('Request', $sections);

      $this->assertStringContainsString('A&amp;B County', $html);
      $this->assertStringContainsString('State summary', $html);
      $this->assertStringContainsString('40%', $html);
    }

  }

}
