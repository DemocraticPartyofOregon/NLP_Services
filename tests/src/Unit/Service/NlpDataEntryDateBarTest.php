<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpDataEntryDateBar;
  use Drupal\nlpservices\NlpDataEntryVoters;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryDateBarTest extends UnitTestCase {

    private NlpTurfs $turfsMock;
    private NlpAwards $awardsMock;
    private NlpDataEntryVoters $dataEntryVotersMock;
    private NlpSessionData $sessionDataMock;
    private NlpConfiguration $configMock;
    private ModuleExtensionList $moduleListMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfsMock           = $this->createMock(NlpTurfs::class);
      $this->awardsMock          = $this->createMock(NlpAwards::class);
      $this->dataEntryVotersMock = $this->createMock(NlpDataEntryVoters::class);
      $this->sessionDataMock     = $this->createMock(NlpSessionData::class);
      $this->configMock          = $this->createMock(NlpConfiguration::class);

      $this->moduleListMock = $this->createMock(ModuleExtensionList::class);
      $this->moduleListMock->method('getPath')->willReturn('modules/custom/nlpservices');

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpDataEntryDateBar {
      return new NlpDataEntryDateBar(
        $this->turfsMock,
        $this->awardsMock,
        $this->dataEntryVotersMock,
        $this->sessionDataMock,
        $this->configMock,
        $this->moduleListMock,
      );
    }

    private function setupDateBarMocks(bool $helpSeen = FALSE): void {
      $this->awardsMock->method('getAward')->willReturn(['electionCount' => 3]);
      $this->dataEntryVotersMock->method('turfCounts')->willReturn([
        'voterCount'    => 10,
        'votedCount'    => 5,
        'attemptedCount'=> 8,
        'contactedCount'=> 7,
      ]);
      $this->turfsMock->method('getTurf')->willReturn(['turfName' => 'Denver-001']);
      $this->turfsMock->method('getMinivanInfo')->willReturn(['minivanNumber' => 5, 'minivanCreateDate' => '2024-10-01']);
      $this->sessionDataMock->method('get')->willReturn($helpSeen);
    }

    // -------------------------------------------------------------------------
    // navigate — window logic
    // -------------------------------------------------------------------------

    public function testNavigateSinglePageCreatesOnePageButton(): void {
      $result = $this->buildService()->navigate(1, 0);
      $this->assertArrayHasKey('pageSelect-1', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-2', $result['nav']);
    }

    public function testNavigateLessThanSevenPagesAllPagesDisplayed(): void {
      $result = $this->buildService()->navigate(5, 0);
      for ($p = 1; $p <= 5; $p++) {
        $this->assertArrayHasKey("pageSelect-$p", $result['nav']);
      }
      $this->assertArrayNotHasKey('pageSelect-6', $result['nav']);
    }

    public function testNavigateSixPagesShowsAllSixNoPreviousNoNext(): void {
      $result = $this->buildService()->navigate(6, 0);
      $this->assertArrayHasKey('pageSelect-6', $result['nav']);
      $this->assertArrayNotHasKey('previous', $result['nav']);
      $this->assertArrayNotHasKey('next', $result['nav']);
    }

    public function testNavigateCurrentPageHasNavCurrentNumberClass(): void {
      // currentPage=1 (0-indexed) → internal page 2 is current.
      $result = $this->buildService()->navigate(5, 1);
      $this->assertStringContainsString('nav_current_number', $result['nav']['pageSelect-2']['#prefix']);
    }

    public function testNavigateNonCurrentPageHasNavNumberClass(): void {
      $result = $this->buildService()->navigate(5, 1);
      $this->assertStringContainsString('nav_number', $result['nav']['pageSelect-1']['#prefix']);
      $this->assertStringNotContainsString('nav_current_number', $result['nav']['pageSelect-1']['#prefix']);
    }

    public function testNavigateAlwaysHasSaveReportsButton(): void {
      $result1 = $this->buildService()->navigate(1, 0);
      $result2 = $this->buildService()->navigate(10, 5);
      $this->assertArrayHasKey('save_reports', $result1['nav']);
      $this->assertArrayHasKey('save_reports', $result2['nav']);
    }

    public function testNavigateSevenPagesNearStartHasNextNotPrevious(): void {
      // pageCount=7, currentPage=0 → internal page 1.
      // windowStart=1, windowEnd=6 → more=true, less=false.
      $result = $this->buildService()->navigate(7, 0);
      $this->assertArrayHasKey('next', $result['nav']);
      $this->assertArrayNotHasKey('previous', $result['nav']);
      $this->assertArrayHasKey('pageSelect-6', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-7', $result['nav']);
    }

    public function testNavigateSevenPagesNearEndHasPreviousNotNext(): void {
      // pageCount=7, currentPage=6 → internal page 7.
      // windowStart=2, windowEnd=7 → less=true, more=false.
      $result = $this->buildService()->navigate(7, 6);
      $this->assertArrayHasKey('previous', $result['nav']);
      $this->assertArrayNotHasKey('next', $result['nav']);
      $this->assertArrayHasKey('pageSelect-2', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-1', $result['nav']);
    }

    public function testNavigateTenPagesInMiddleHasBothPreviousAndNext(): void {
      // pageCount=10, currentPage=4 → internal page 5.
      // windowStart=3, windowEnd=8 → less=true, more=true.
      $result = $this->buildService()->navigate(10, 4);
      $this->assertArrayHasKey('previous', $result['nav']);
      $this->assertArrayHasKey('next', $result['nav']);
      $this->assertArrayHasKey('pageSelect-3', $result['nav']);
      $this->assertArrayHasKey('pageSelect-8', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-2', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-9', $result['nav']);
    }

    public function testNavigateWindowClampsToEndWhenNearLastPage(): void {
      // pageCount=10, currentPage=9 → internal page 10.
      // windowStart=max(1,10-2)=8, windowEnd=8+5=13>10 → windowEnd=10, windowStart=max(1,10-5)=5.
      $result = $this->buildService()->navigate(10, 9);
      $this->assertArrayHasKey('pageSelect-5', $result['nav']);
      $this->assertArrayHasKey('pageSelect-10', $result['nav']);
      $this->assertArrayNotHasKey('pageSelect-4', $result['nav']);
    }

    // -------------------------------------------------------------------------
    // newNl
    // -------------------------------------------------------------------------

    public function testNewNlReturnsFormElementWithMarkup(): void {
      $result = $this->buildService()->newNl();
      $this->assertArrayHasKey('new_nl_info', $result);
      $this->assertArrayHasKey('#markup', $result['new_nl_info']);
    }

    public function testNewNlMarkupContainsArrowImage(): void {
      $result = $this->buildService()->newNl();
      $this->assertStringContainsString('nlp_up_arrow_yellow_40.gif', $result['new_nl_info']['#markup']);
    }

    public function testNewNlMarkupContainsHelpTabText(): void {
      $result = $this->buildService()->newNl();
      $this->assertStringContainsString('help tab', $result['new_nl_info']['#markup']);
    }

    // -------------------------------------------------------------------------
    // createDateBar
    // -------------------------------------------------------------------------

    public function testCreateDateBarHelpNotSeenIncludesNewNlBox(): void {
      $this->setupDateBarMocks(FALSE);
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertArrayHasKey('new_nl_box', $result);
      $this->assertArrayHasKey('new_nl', $result);
    }

    public function testCreateDateBarHelpSeenExcludesNewNlBox(): void {
      $this->setupDateBarMocks(TRUE);
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertArrayNotHasKey('new_nl_box', $result);
      $this->assertArrayNotHasKey('new_nl', $result);
    }

    public function testCreateDateBarSetsHelpSeenWhenNotSeenBefore(): void {
      $this->setupDateBarMocks(FALSE);
      $this->sessionDataMock->expects($this->once())->method('set')->with('helpSeen', TRUE);
      $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
    }

    public function testCreateDateBarAlwaysContainsAwardBar(): void {
      $this->setupDateBarMocks();
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertArrayHasKey('award_bar', $result);
      $this->assertArrayHasKey('voter2_counts', $result);
      $this->assertArrayHasKey('turf_name', $result);
    }

    public function testCreateDateBarCanvassDateElementHasDefaultValue(): void {
      $this->setupDateBarMocks();
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertSame('2024-10-15', $result['canvass_date']['canvassDate']['#default_value']);
    }

    public function testCreateDateBarEmptyCanvassDateHasNoDefaultValue(): void {
      $this->setupDateBarMocks();
      $result = $this->buildService()->createDateBar('', 7, 42, 0, 3);
      $this->assertArrayNotHasKey('#default_value', $result['canvass_date']['canvassDate']);
    }

    public function testCreateDateBarContainsNavigationElement(): void {
      $this->setupDateBarMocks();
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertArrayHasKey('nav', $result);
    }

    public function testCreateDateBarContainsSearchAndPostcardElements(): void {
      $this->setupDateBarMocks();
      $result = $this->buildService()->createDateBar('2024-10-15', 7, 42, 0, 3);
      $this->assertArrayHasKey('search', $result);
      $this->assertArrayHasKey('postcard', $result);
    }

  }

}
