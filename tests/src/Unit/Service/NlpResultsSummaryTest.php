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
      $container->set('nlpservices.crosstabs', $this->crosstabsMock);
      $container->set('nlpservices.reports', $this->reportsMock);
      $container->set('nlpservices.turfs', $this->turfsMock);
      $container->set('nlpservices.configuration', $this->configurationMock);
      $container->set('nlpservices.session_data', $this->sessionMock);
      $container->set('nlpservices.html_text', $this->htmlTextMock);
      $container->set('file_url_generator', $this->fileUrlGeneratorMock);
      $container->set('file.repository', $this->fileRepositoryMock);

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

    public function testDisplayCdResultsSummaryReturnsWarningWhenNoCds(): void {
      $this->crosstabsMock->method('getCds')->willReturn([]);

      $result = $this->buildService()->displayCdResultsSummary();

      $this->assertArrayHasKey('no_cds', $result);
      $this->assertStringContainsString('crosstab file needs to be loaded', $result['no_cds']['#markup']);
    }

  }

}
