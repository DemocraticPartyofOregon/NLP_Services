<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Logger\LoggerChannelInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfDisplay;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpTurfStatus;
  use Drupal\nlpservices\Form\DeleteTurfForm;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class DeleteTurfFormTest extends UnitTestCase {

    private NlpTurfs $turfsMock;
    private NlpAwards $awardsMock;
    private NlpSearchBar $searchBarMock;
    private NlpTurfDisplay $turfDisplayMock;
    private NlpSessionData $sessionDataMock;
    private NlpConfiguration $configurationMock;
    private ApiFolders $foldersMock;
    private NlpTurfStatus $turfStatusMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfsMock        = $this->createMock(NlpTurfs::class);
      $this->awardsMock       = $this->createMock(NlpAwards::class);
      $this->searchBarMock    = $this->createMock(NlpSearchBar::class);
      $this->turfDisplayMock  = $this->createMock(NlpTurfDisplay::class);
      $this->sessionDataMock  = $this->createMock(NlpSessionData::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->foldersMock      = $this->createMock(ApiFolders::class);
      $this->turfStatusMock   = $this->createMock(NlpTurfStatus::class);
      $this->turfStatusMock->method('turfStatusCheck')->willReturn(TRUE);
      $this->messengerMock    = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(array $queryParams = []): DeleteTurfForm {
      $requestStack = new RequestStack();
      $requestStack->push(new Request($queryParams));

      return new DeleteTurfForm(
        $this->turfsMock,
        $this->awardsMock,
        $this->searchBarMock,
        $this->turfDisplayMock,
        $this->sessionDataMock,
        $this->configurationMock,
        $this->foldersMock,
        $this->turfStatusMock,
        $requestStack,
      );
    }

    private function baseTurfRecord(): array {
      return [
        'turfIndex'  => 7,
        'syncDate'   => '2025-01-01',
        'nickname'   => 'Alice',
        'nlLastName' => 'Smith',
        'turfName'   => '123rd Ave',
        'mcid'       => 42,
      ];
    }

    /**
     * Makes getTurf return the given turfs, as if they are unchanged in the database.
     */
    private function stubDatabaseTurfs(array $turfs): void {
      $this->turfsMock->method('getTurf')
        ->willReturnCallback(fn(int $turfIndex) => $turfs[$turfIndex] ?? []);
    }

    /**
     * Expects one notice whose placeholder values, joined with ' | ', match the pattern.
     */
    private function expectLoggedNotice(string $pattern): void {
      $loggerChannel = $this->createMock(LoggerChannelInterface::class);
      $loggerChannel->expects($this->once())->method('notice')
        ->with($this->anything(), $this->callback(function (array $context) use ($pattern) {
          return (bool) preg_match($pattern, implode(' | ', array_map('strval', $context)));
        }));
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->with('nlpservices')->willReturn($loggerChannel);
      \Drupal::getContainer()->set('logger.factory', $loggerFactory);
      \Drupal::getContainer()->set('string_translation', $this->getStringTranslationStub());
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_delete_turf_form', $this->buildForm()->getFormId());
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
    // buildForm — county set but no turfs for the selected HD
    // -------------------------------------------------------------------------

    public function testBuildFormNoTurfsReturnsNoTurfMarkup(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01', 2 => '02']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(10); // < TURF_ALL_MAX → prepends 'All'
      $this->sessionDataMock->method('getSavedHd')->willReturn(1);
      $this->turfsMock->method('getTurfs')->willReturn([]);

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
      $this->sessionDataMock->method('getSavedHd')->willReturn(0);
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
      $this->assertArrayNotHasKey('submit', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set with turfs → full form with submit button
    // -------------------------------------------------------------------------

    public function testBuildFormWithTurfsIncludesSubmitButton(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn(1);
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([1 => []]);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('submit', $result);
      $this->assertSame('delete_submit', $result['submit']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — sort query param calls sortTurfTable
    // -------------------------------------------------------------------------

    public function testBuildFormWithSortQueryCallsSortTurfTable(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn(1);
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([7 => []]);
      $this->turfDisplayMock->expects($this->once())
        ->method('sortTurfTable')
        ->with($this->anything(), 'desc', 'HD');
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $this->buildForm(['sort' => 'desc', 'order' => 'HD'])->buildForm([], $formState);
    }

    public function testBuildFormWithoutSortQueryDoesNotSort(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn(1);
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([7 => []]);
      $this->turfDisplayMock->expects($this->never())->method('sortTurfTable');
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter=TRUE skips county/HD initialisation
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsCountySetup(): void {
      $this->sessionDataMock->expects($this->never())->method('getCounty');
      $this->sessionDataMock->method('getSavedHd')->willReturn(0);
      // No turfs → no-turf markup returned.
      $this->turfsMock->method('getTurfs')->willReturn([]);

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
    // buildForm — search needle causes searchInfoArray to be called
    // -------------------------------------------------------------------------

    public function testBuildFormWithNeedleCallsSearchInfoArray(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn(1);
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->searchBarMock->expects($this->once())
        ->method('searchInfoArray')
        ->with('Smith', $this->isType('array'))
        ->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', 'Smith'],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — turfs whose NL was removed from the active NLs are included
    // -------------------------------------------------------------------------

    public function testBuildFormIncludesTurfsOfRemovedNls(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->turfsMock->expects($this->once())
        ->method('getTurfs')
        ->with('Denver', '12', TRUE)
        ->willReturn([]);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    public function testBuildFormSearchIncludesTurfsOfRemovedNls(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->turfsMock->expects($this->once())
        ->method('getTurfs')
        ->with('Denver', 'All', TRUE)
        ->willReturn([]);
      $this->searchBarMock->method('searchInfoArray')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       TRUE],
        ['county',        'Denver'],
        ['search-needle', 'Smith'],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — precinct selector
    // -------------------------------------------------------------------------

    private function precinctFormState(): FormStateInterface {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       TRUE],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);
      return $formState;
    }

    private function turfInPrecinct(int $turfIndex, string $precinct): array {
      return ['turfIndex' => $turfIndex, 'turfPrecinct' => $precinct] + $this->baseTurfRecord();
    }

    public function testBuildFormSingleHdListsOnlyThatHdsPrecincts(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '12', '13']);
      $this->turfsMock->expects($this->once())
        ->method('getTurfPrecinctNames')
        ->with('Denver', 12)
        ->willReturn(['P-101' => 'P-101', 'P-102' => 'P-102']);
      $this->searchBarMock->expects($this->once())
        ->method('searchBar')
        ->with(['All', '12', '13'], $this->anything(), ['All', 'P-101', 'P-102'], 0)
        ->willReturn(['#type' => 'select']);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    public function testBuildFormAllHdsListsAllPrecincts(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(0);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '12', '13']);
      $this->turfsMock->expects($this->once())
        ->method('getTurfPrecinctNames')
        ->with('Denver', 0)
        ->willReturn(['P-101' => 'P-101', 'P-201' => 'P-201']);
      $this->searchBarMock->expects($this->once())
        ->method('searchBar')
        ->with($this->anything(), $this->anything(), ['All', 'P-101', 'P-201'], 0)
        ->willReturn(['#type' => 'select']);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    public function testBuildFormSelectedPrecinctShowsOnlyItsTurfs(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '12']);
      $this->sessionDataMock->method('getSavedPrecinctIndex')->willReturn(2);
      $this->sessionDataMock->method('getSavedPct')->willReturn('P-102');
      $this->turfsMock->method('getTurfPrecinctNames')
        ->willReturn(['P-101' => 'P-101', 'P-102' => 'P-102']);
      $this->turfsMock->method('getTurfs')->willReturn([
        7 => $this->turfInPrecinct(7, 'P-101'),
        8 => $this->turfInPrecinct(8, 'P-102'),
        9 => $this->turfInPrecinct(9, 'P-102'),
      ]);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->expects($this->once())
        ->method('buildTurfTableArray')
        ->with($this->callback(fn($turfs) => array_keys($turfs) === [8, 9]))
        ->willReturn([8 => [], 9 => []]);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    public function testBuildFormAllPrecinctsShowsEveryTurfInHd(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '12']);
      $this->sessionDataMock->method('getSavedPct')->willReturn('');
      $this->turfsMock->method('getTurfPrecinctNames')
        ->willReturn(['P-101' => 'P-101', 'P-102' => 'P-102']);
      $this->turfsMock->method('getTurfs')->willReturn([
        7 => $this->turfInPrecinct(7, 'P-101'),
        8 => $this->turfInPrecinct(8, 'P-102'),
      ]);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->expects($this->once())
        ->method('buildTurfTableArray')
        ->with($this->callback(fn($turfs) => array_keys($turfs) === [7, 8]))
        ->willReturn([7 => [], 8 => []]);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    public function testBuildFormStalePrecinctIndexFallsBackToAll(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn(12);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '12']);
      $this->sessionDataMock->method('getSavedPrecinctIndex')->willReturn(5);
      $this->turfsMock->method('getTurfPrecinctNames')->willReturn(['P-101' => 'P-101']);
      $this->sessionDataMock->expects($this->once())->method('savePrecinctIndex')->with(0);
      $this->searchBarMock->expects($this->once())
        ->method('searchBar')
        ->with($this->anything(), $this->anything(), ['All', 'P-101'], 0)
        ->willReturn(['#type' => 'select']);

      $this->buildForm()->buildForm([], $this->precinctFormState());
    }

    // -------------------------------------------------------------------------
    // validateForm — hd-select clicked → saves index, skips selection check
    // -------------------------------------------------------------------------

    public function testValidateFormHdSelectSavesHdIndexAndReturns(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
      $formState->method('getValue')->with('hd-select')->willReturn(2);

      $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);
      $this->turfDisplayMock->expects($this->never())->method('validateTurfSelection');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — search clicked with no turf selected → no error
    // -------------------------------------------------------------------------

    public function testValidateFormSearchWithoutSelectionDoesNotSetError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [], 'search-needle' => 'Smith']);
      $formState->expects($this->never())->method('setErrorByName');

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — delete clicked, no selection → form error
    // -------------------------------------------------------------------------

    public function testValidateFormNoSelectionSetsFormError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => []]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('submit', $this->stringContains('select at least one'));

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — delete clicked, valid selection → no error set
    // -------------------------------------------------------------------------

    public function testValidateFormWithValidSelectionDoesNotSetError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [7 => 1]]);
      $formState->method('get')->with('turfArray')->willReturn([7 => $this->baseTurfRecord()]);
      $formState->expects($this->never())->method('setErrorByName');

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([7 => 7]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — selected turf already deleted (e.g. a double submit) → warning, no error
    // -------------------------------------------------------------------------

    public function testValidateFormSelectionAlreadyDeletedWarnsWithoutError(): void {
      $this->expectLoggedNotice('/^73 \| 73 \| 1 \| Denver \| HD1 \| none \| \d+ \| form-abc \| yes \| 73: not in database$/');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [73 => 1]]);
      $formState->method('get')->willReturnMap([
        ['turfArray', [7 => $this->baseTurfRecord()]],
        ['turfListInfo', ['builtAt' => time() - 30, 'hd' => 'HD1', 'needle' => '']],
        ['county', 'Denver'],
      ]);
      $formState->method('isCached')->willReturn(TRUE);
      $formState->expects($this->never())->method('setErrorByName');
      $savedSelections = NULL;
      $formState->method('set')->willReturnCallback(
        function (string $key, $value) use (&$savedSelections, $formState) {
          if ($key == 'selections') {
            $savedSelections = $value;
          }
          return $formState;
        });

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([73 => 73]);
      $this->turfsMock->method('getTurf')->with(73)->willReturn([]);
      $this->messengerMock->expects($this->once())->method('addWarning')
        ->with($this->callback(fn($message) => str_contains((string) $message, '73')
          && str_contains((string) $message, 'already been deleted')));

      $form = ['#build_id' => 'form-abc'];
      $this->buildForm()->validateForm($form, $formState);
      $this->assertSame([], $savedSelections);
    }

    // -------------------------------------------------------------------------
    // validateForm — selected turf still in the database but not in the list → form error
    // -------------------------------------------------------------------------

    public function testValidateFormMissingTurfStillInDatabaseSetsFormError(): void {
      $this->expectLoggedNotice('/73: county=Denver hd=HD2 mcid=99$/');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [73 => 1]]);
      $formState->method('get')->willReturnMap([
        ['turfArray', [7 => $this->baseTurfRecord()]],
      ]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('submit', $this->anything());

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([73 => 73]);
      $this->turfsMock->method('getTurf')->with(73)
        ->willReturn(['turfIndex' => 73, 'county' => 'Denver', 'turfHd' => 'HD2', 'mcid' => 99]);
      $this->messengerMock->expects($this->never())->method('addWarning');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    public function testValidateFormOneDeletedAndOneStillInDatabaseSetsFormError(): void {
      $this->expectLoggedNotice('/73: not in database; 74: county=Denver hd=HD2 mcid=99$/');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [73 => 1, 74 => 1]]);
      $formState->method('get')->willReturnMap([
        ['turfArray', [7 => $this->baseTurfRecord()]],
      ]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('submit', $this->anything());

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([73 => 73, 74 => 74]);
      $this->stubDatabaseTurfs([74 => ['turfIndex' => 74, 'county' => 'Denver', 'turfHd' => 'HD2', 'mcid' => 99]]);
      $this->messengerMock->expects($this->never())->method('addWarning');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — selection missing from turfArray → skipped, nothing deleted
    // -------------------------------------------------------------------------

    public function testSubmitFormSkipsSelectionMissingFromTurfArray(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  [7 => $this->baseTurfRecord()]],
        ['selections', [73 => 73]],
      ]);

      $this->turfsMock->expects($this->never())->method('deleteTurf');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    public function testSubmitFormSkipsTurfNoLongerInDatabase(): void {
      $this->expectLoggedNotice('/^7 \| it no longer exists$/');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  [7 => $this->baseTurfRecord()]],
        ['selections', [7 => 7]],
      ]);

      $this->stubDatabaseTurfs([]);
      $this->turfsMock->expects($this->never())->method('deleteTurf');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    public function testSubmitFormSkipsTurfWhoseNlChanged(): void {
      $this->expectLoggedNotice('/^7 \| its NL changed from 42 to 99$/');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  [7 => $this->baseTurfRecord()]],
        ['selections', [7 => 7]],
      ]);

      $this->stubDatabaseTurfs([7 => array_merge($this->baseTurfRecord(), ['mcid' => 99])]);
      $this->turfsMock->expects($this->never())->method('deleteTurf');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — search_submit → stores needle
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('search-needle')->willReturn('Smith');
      $formState->expects($this->atLeast(1))
        ->method('set')
        ->with($this->logicalOr(
          $this->equalTo('reenter'),
          $this->equalTo('search-needle'),
        ), $this->anything());

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — delete succeeds → nlParticipation called, status added
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteSuccessCallsNlParticipationAndAddsStatus(): void {
      $turfArray = [7 => $this->baseTurfRecord()];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7]],
      ]);

      $this->stubDatabaseTurfs($turfArray);
      $this->turfsMock->expects($this->once())->method('deleteTurf')->willReturn(TRUE);
      $this->awardsMock->expects($this->once())->method('nlParticipation')->with(42, 'Yes');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — turf of an NL removed from the active NLs can be deleted
    // -------------------------------------------------------------------------

    public function testSubmitFormDeletesTurfOfRemovedNl(): void {
      // A removed NL has no NLs-table row, so the NL columns are NULL.
      $turf = ['nickname' => NULL, 'nlFirstName' => 'Alicia'] + $this->baseTurfRecord();
      \Drupal::getContainer()->set('string_translation', $this->getStringTranslationStub());

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  [7 => $turf]],
        ['selections', [7 => 7]],
      ]);

      $this->stubDatabaseTurfs([7 => $turf]);
      $this->turfsMock->expects($this->once())
        ->method('deleteTurf')
        ->with(['mcid' => 42, 'county' => 'Denver', 'turfIndex' => 7])
        ->willReturn(TRUE);
      $this->messengerMock->expects($this->once())
        ->method('addStatus')
        ->with($this->callback(fn($msg) => str_contains((string) $msg, 'Alicia, Smith')));

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — delete fails → error added, nlParticipation NOT called
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteFailureAddsErrorAndSkipsAwards(): void {
      $turfArray = [7 => $this->baseTurfRecord()];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7]],
      ]);

      $this->stubDatabaseTurfs($turfArray);
      $this->turfsMock->expects($this->once())->method('deleteTurf')->willReturn(FALSE);
      $this->awardsMock->expects($this->never())->method('nlParticipation');
      $this->messengerMock->expects($this->once())->method('addError');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — multiple turfs deleted → nlParticipation called once per turf
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteMultipleTurfsCallsNlParticipationForEach(): void {
      $turfArray = [
        7 => $this->baseTurfRecord(),
        8 => array_merge($this->baseTurfRecord(), ['turfIndex' => 8, 'mcid' => 99]),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7, 8 => 8]],
      ]);

      $this->stubDatabaseTurfs($turfArray);
      $this->turfsMock->method('deleteTurf')->willReturn(TRUE);
      $this->awardsMock->expects($this->exactly(2))->method('nlParticipation');
      $this->messengerMock->expects($this->exactly(2))->method('addStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // selectboxCallback — returns form['hd-change']
    // -------------------------------------------------------------------------

    public function testSelectboxCallbackReturnsHdChangeElement(): void {
      $form = ['hd-change' => ['#type' => 'fieldset', '#title' => 'Select HD']];
      $formState = $this->createMock(FormStateInterface::class);
      $result = $this->buildForm()->selectboxCallback($form, $formState);
      $this->assertSame($form['hd-change'], $result);
    }

  }

}
