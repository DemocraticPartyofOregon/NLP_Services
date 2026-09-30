<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiMapRegions;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfDisplay;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\Form\DisplayTurfsForm;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class DisplayTurfsFormTest extends UnitTestCase {

    private NlpTurfs $turfsMock;
    private NlpAwards $awardsMock;
    private NlpSearchBar $searchBarMock;
    private NlpTurfDisplay $turfDisplayMock;
    private NlpSessionData $sessionDataMock;
    private ApiFolders $foldersMock;
    private ApiMapRegions $apiMapRegionMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfsMock        = $this->createMock(NlpTurfs::class);
      $this->awardsMock       = $this->createMock(NlpAwards::class);
      $this->searchBarMock    = $this->createMock(NlpSearchBar::class);
      $this->turfDisplayMock  = $this->createMock(NlpTurfDisplay::class);
      $this->sessionDataMock  = $this->createMock(NlpSessionData::class);
      $this->foldersMock      = $this->createMock(ApiFolders::class);
      $this->apiMapRegionMock = $this->createMock(ApiMapRegions::class);
      $this->messengerMock    = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(array $queryParams = []): DisplayTurfsForm {
      $requestStack = new RequestStack();
      $requestStack->push(new Request($queryParams));

      $form = new DisplayTurfsForm(
        $this->turfsMock,
        $this->awardsMock,
        $this->searchBarMock,
        $this->turfDisplayMock,
        $this->sessionDataMock,
        $this->foldersMock,
        $this->apiMapRegionMock,
        $requestStack,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    private function baseTurfRecord(): array {
      return [
        'turfIndex'  => 5,
        'turfName'   => 'Oak St',
        'nlLastName' => 'Jones',
        'mcid'       => 77,
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_display_turfs_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — county not set
    // -------------------------------------------------------------------------

    public function testBuildFormNoCountyAddsWarningAndReturnsEmptyArray(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set, no current HD → no turf markup
    // -------------------------------------------------------------------------

    public function testBuildFormNoCurrentHdReturnsNoTurfMarkup(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(5);
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('no turf to display', $result['turf-table']['turf-form']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — no turfs for the selected HD still shows the HD selector
    // -------------------------------------------------------------------------

    public function testBuildFormNoTurfsStillShowsHdSelector(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn(['0', '01', '02']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(500); // >= TURF_ALL_MAX → no 'All'
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['0', '01', '02']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame(['#type' => 'select'], $result['hd-change']['options_display']);
      $this->assertStringContainsString('no turf to display', $result['turf-table']['turf-form']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set with turfs → full form rendered
    // -------------------------------------------------------------------------

    public function testBuildFormWithTurfsRendersHdChangeFieldset(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(5);
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([5 => $this->baseTurfRecord()]);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([1 => []]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('hd-change', $result);
      $this->assertSame('fieldset', $result['hd-change']['#type']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter=TRUE skips county initialisation
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsCountySetup(): void {
      $this->sessionDataMock->expects($this->never())->method('getCounty');
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       TRUE],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('turf-table', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — search needle calls searchInfoArray
    // -------------------------------------------------------------------------

    public function testBuildFormWithNeedleCallsSearchInfoArray(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(5);
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([5 => $this->baseTurfRecord()]);
      $this->searchBarMock->expects($this->once())
        ->method('searchInfoArray')
        ->with('Jones', $this->isType('array'))
        ->willReturn([]);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', 'Jones'],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — sort query param calls sortTurfTable
    // -------------------------------------------------------------------------

    public function testBuildFormWithSortQueryCallsSortTurfTable(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(5);
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([5 => $this->baseTurfRecord()]);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([1 => []]);
      $this->turfDisplayMock->expects($this->once())
        ->method('sortTurfTable')
        ->with($this->anything(), 'turfName', 'asc');
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $this->buildForm(['sort' => 'turfName', 'order' => 'asc'])->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — 'All' is prepended when turfCount < TURF_ALL_MAX
    // -------------------------------------------------------------------------

    public function testBuildFormPrependsAllWhenBelowMax(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $capturedDistricts = NULL;
      $this->sessionDataMock->expects($this->once())
        ->method('setNewDistricts')
        ->with($this->callback(function ($opts) use (&$capturedDistricts) {
          $capturedDistricts = $opts;
          return TRUE;
        }));

      $this->buildForm()->buildForm([], $formState);
      $this->assertSame('All', reset($capturedDistricts));
    }

    // -------------------------------------------------------------------------
    // submitForm — hd_submit saves hd index
    // -------------------------------------------------------------------------

    public function testSubmitFormHdSubmitSavesHdIndex(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd_submit']);
      $formState->method('getValue')->with('hd-select')->willReturn(3);
      $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(3);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — search_submit stores needle and returns early
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('search-needle')->willReturn('Oak');
      $formState->expects($this->atLeast(1))
        ->method('set')
        ->with($this->logicalOr(
          $this->equalTo('reenter'),
          $this->equalTo('search-needle'),
        ), $this->anything());

      $this->sessionDataMock->expects($this->never())->method('saveHdIndex');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — sets reenter and calls setRebuild
    // -------------------------------------------------------------------------

    public function testSubmitFormSetsReenterAndRebuild(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd_submit']);
      $formState->method('getValue')->willReturn(0);
      $formState->expects($this->atLeastOnce())->method('set')->with('reenter', TRUE);
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

  }

}
