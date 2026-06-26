<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiSavedLists;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpSyncTurfBuilder;
  use Drupal\nlpservices\NlpNlsListDisplay;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfListDisplayVoteBuilder;
  use Drupal\nlpservices\ApiMapRegions;
  use Drupal\nlpservices\Form\SyncTurfForm;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\RequestStack;

  class SyncTurfFormTest extends UnitTestCase {

    private ApiSavedLists $savedListsMock;
    private ApiFolders $foldersMock;
    private NlpNls $nlsMock;
    private NlpTurfs $turfsMock;
    private NlpSyncTurfBuilder $syncTurfBuilderMock;
    private NlpNlsListDisplay $nlsListDisplayMock;
    private NlpConfiguration $configurationMock;
    private NlpSessionData $sessionDataMock;
    private NlpTurfListDisplayVoteBuilder $turfListDisplayMock;
    private ApiMapRegions $mapRegionsMock;
    private MessengerInterface $messengerMock;
    private RequestStack $requestStackMock;

    protected function setUp(): void {
      parent::setUp();
      $this->savedListsMock     = $this->createMock(ApiSavedLists::class);
      $this->foldersMock        = $this->createMock(ApiFolders::class);
      $this->nlsMock            = $this->createMock(NlpNls::class);
      $this->turfsMock          = $this->createMock(NlpTurfs::class);
      $this->syncTurfBuilderMock = $this->createMock(NlpSyncTurfBuilder::class);
      $this->nlsListDisplayMock = $this->createMock(NlpNlsListDisplay::class);
      $this->configurationMock  = $this->createMock(NlpConfiguration::class);
      $this->sessionDataMock    = $this->createMock(NlpSessionData::class);
      $this->turfListDisplayMock = $this->createMock(NlpTurfListDisplayVoteBuilder::class);
      $this->mapRegionsMock     = $this->createMock(ApiMapRegions::class);
      $this->messengerMock      = $this->createMock(MessengerInterface::class);
      $this->requestStackMock   = $this->createMock(RequestStack::class);

      // logger.factory is needed by any validateForm path that hits turfName-missing.
      $loggerMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['notice', 'warning', 'error'])
        ->getMock();
      $loggerFactoryMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['get'])
        ->getMock();
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      unset($_FILES['files']);
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): SyncTurfForm {
      return new SyncTurfForm(
        $this->savedListsMock,
        $this->foldersMock,
        $this->nlsMock,
        $this->turfsMock,
        $this->syncTurfBuilderMock,
        $this->nlsListDisplayMock,
        $this->configurationMock,
        $this->sessionDataMock,
        $this->turfListDisplayMock,
        $this->mapRegionsMock,
        $this->requestStackMock,
      );
    }

    /** Base turf-option record used by validateForm / submitForm tests. */
    private function baseTurfOption(): array {
      return [
        'turfId'            => 101,
        'turfName'          => '123rd Ave',
        'turfAssigned'      => FALSE,
        'voterCount'        => 50,
        'doorCount'         => 25,
        'mapRegionId'       => 3,
        'mapRegionName'     => 'Region A',
        'minivanNumber'     => '',
        'minivanCreateDate' => '',
      ];
    }

    /**
     * Returns a FormStateInterface mock whose set() records every call so
     * assertions can use $captured to inspect what was stored.
     */
    private function capturingFormState(array &$captured, array $getMap = []): FormStateInterface {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap($getMap);
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void {
          $captured[] = [$key, $value];
        }
      );
      return $formState;
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_sync_new_turf_form', $this->buildForm()->getFormId());
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
    // buildForm — reenter=TRUE skips county / HD / PCT setup
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsCountySetup(): void {
      // getCounty() must never be called when reenter=TRUE.
      $this->sessionDataMock->expects($this->never())->method('getCounty');
      $this->nlsMock->expects($this->never())->method('getHdList');

      // Satisfy the nl_search_by_district page (uses form_state county, not sessionData).
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);
      $this->sessionDataMock->method('getSavedPrecinctIndex')->willReturn(0);
      $this->nlsMock->method('getNls')->willReturn([]);
      $this->nlsListDisplayMock->method('createTableArray')->willReturn([]);
      $this->syncTurfBuilderMock->method('buildNlSearchByDistrict')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
        ['page',    'nl_search_by_district'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('#attached', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — page = nl_select
    // -------------------------------------------------------------------------

    public function testBuildFormNlSelectPageContainsNlSelectElement(): void {
      $this->turfsMock->method('getTurfAssignment')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
        ['page',    'nl_select'],
        ['turfId',  5],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('nl_select', $result);
      $this->assertArrayHasKey('nl_search_by_name', $result['nl_select']);
    }

    // -------------------------------------------------------------------------
    // buildForm — page = nl_search_by_name
    // -------------------------------------------------------------------------

    public function testBuildFormNlSearchByNamePageContainsNlSelectElement(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->nlsMock->method('searchNls')->willReturn([]);
      $this->nlsListDisplayMock->method('createTableArray')->willReturn([]);
      $this->syncTurfBuilderMock->method('buildNlSearchByName')->willReturn(['#type' => 'fieldset']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
        ['page',    'nl_search_by_name'],
        ['needle',  ''],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('nl_select', $result);
      $this->assertArrayHasKey('nl_search', $result['nl_select']);
    }

    // -------------------------------------------------------------------------
    // buildForm — turf_select page, showLists=false → map-region button shown
    // -------------------------------------------------------------------------

    public function testBuildFormTurfSelectShowMapsShowsRevealListsButton(): void {
      // Initial-entry path (reenter=null).
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->nlsMock->method('getHdList')->willReturn([]);
      $this->nlsMock->method('getPctList')->willReturn([]);

      // buildTurfFilter() dependencies.
      $this->foldersMock->method('getFolderOptions')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   NULL],
        ['county',    'Denver'],
        ['page',      'turf_select'],
        ['showLists', FALSE],
        ['turfOptions',    []],
        ['mapRegionOptions', []],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('turf_submit', $result);
      // When showLists=false the "show lists" toggle button should be present.
      $this->assertArrayHasKey('saved_lists_reveal', $result);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'folder': stores folderId and resets mapRegionId
    // -------------------------------------------------------------------------

    public function testValidateFormFolderStoresFolderIdAndResetsMapRegion(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'folder']);
      $formState->method('getValues')->willReturn(['folder' => 7]);

      $this->sessionDataMock->method('set')
        ->willReturnCallback(function ($key, $value) use (&$captured): void {
          $captured[] = [$key, $value];
        });

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['folderId', 7], $captured);
      $this->assertContains(['mapRegionId', 0], $captured);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'map_region_select': stores mapRegionId
    // -------------------------------------------------------------------------

    public function testValidateFormMapRegionSelectStoresMapRegionId(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'map_region_select']);
      $formState->method('getValues')->willReturn(['map_region_select' => 3]);

      $this->sessionDataMock->method('set')
        ->willReturnCallback(function ($key, $value) use (&$captured): void {
          $captured[] = [$key, $value];
        });

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['mapRegionId', 3], $captured);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'change_to_lists' / 'change_to_turfs'
    // -------------------------------------------------------------------------

    public function testValidateFormChangeToListsSetsShowListsTrue(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'change_to_lists']);
      $formState->method('getValues')->willReturn([]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['showLists', TRUE], $captured);
    }

    public function testValidateFormChangeToTurfsSetsShowListsFalse(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'change_to_turfs']);
      $formState->method('getValues')->willReturn([]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['showLists', FALSE], $captured);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': too many selections → error
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitTooManySelectionsSetsError(): void {
      $turfOption = $this->baseTurfOption();
      $selections = [101 => ['turfId' => 101], 102 => ['turfId' => 102]];

      $this->turfListDisplayMock->method('validateTurfSelection')->willReturn($selections);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption, 102 => array_merge($turfOption, ['turfId' => 102])]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1, 102 => 1]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('turf_submit', $this->stringContains('select one turf'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': turf already assigned → error
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitAlreadyAssignedSetsError(): void {
      $turfOption = array_merge($this->baseTurfOption(), ['turfAssigned' => TRUE]);

      $this->turfListDisplayMock->method('validateTurfSelection')
        ->willReturn([101 => ['turfId' => 101]]);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('turf_submit', $this->stringContains('already assigned'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': turfName missing → error
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitMissingTurfNameSetsError(): void {
      $turfOption = array_merge($this->baseTurfOption(), ['turfName' => '']);

      $this->turfListDisplayMock->method('validateTurfSelection')
        ->willReturn([101 => ['turfId' => 101]]);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('turf_submit', $this->stringContains('turf name is missing'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': voterCount > 100 → error (and warning)
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitMoreThan100VotersSetsError(): void {
      $turfOption = array_merge($this->baseTurfOption(), ['voterCount' => 110]);

      $this->turfListDisplayMock->method('validateTurfSelection')
        ->willReturn([101 => ['turfId' => 101]]);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('turf_submit', $this->stringContains('100 voters or less'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': 76-100 voters → warning only, no error
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitBetween76And100VotersAddsWarningNotError(): void {
      $turfOption = array_merge($this->baseTurfOption(), ['voterCount' => 80]);

      $this->turfListDisplayMock->method('validateTurfSelection')
        ->willReturn([101 => ['turfId' => 101]]);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1]);
      $formState->expects($this->never())->method('setErrorByName');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'turf_submit': ≤75 voters, valid → no errors, no warnings
    // -------------------------------------------------------------------------

    public function testValidateFormTurfSubmitValidTurfSetsNoErrors(): void {
      $turfOption = $this->baseTurfOption(); // voterCount = 50

      $this->turfListDisplayMock->method('validateTurfSelection')
        ->willReturn([101 => ['turfId' => 101]]);
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions', [101 => $turfOption]],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);
      $formState->method('getValue')->willReturn([101 => 1]);
      $formState->expects($this->never())->method('setErrorByName');
      $this->messengerMock->expects($this->never())->method('addWarning');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'nl_select_hd': index=0 → hdSelected=FALSE
    // -------------------------------------------------------------------------

    public function testValidateFormHdSelectZeroSetsHdSelectedFalse(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_select_hd']);
      $formState->method('getValues')->willReturn(['nl_select_hd' => 0]);

      $this->sessionDataMock->method('set')
        ->willReturnCallback(function ($k, $v) use (&$captured): void {
          $captured[] = [$k, $v];
        });
      $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(0);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['hdSelected', FALSE], $captured);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'nl_select_hd': index=2 → hdSelected=TRUE, saveHdIndex(1)
    // -------------------------------------------------------------------------

    public function testValidateFormHdSelectNonzeroDecrementsAndSavesIndex(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_select_hd']);
      $formState->method('getValues')->willReturn(['nl_select_hd' => 2]);

      // Index is decremented by 1 before saving: saveHdIndex(2-1) = saveHdIndex(1).
      $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(1);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'nl_select_precinct': index=0 → precinctSelected=FALSE
    // -------------------------------------------------------------------------

    public function testValidateFormPrecinctSelectZeroSetsPrecinctSelectedFalse(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_select_precinct']);
      $formState->method('getValues')->willReturn(['nl_select_precinct' => 0]);

      $this->sessionDataMock->method('set')
        ->willReturnCallback(function ($k, $v) use (&$captured): void {
          $captured[] = [$k, $v];
        });
      $this->sessionDataMock->expects($this->once())->method('savePrecinctIndex')->with(0);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['precinctSelected', FALSE], $captured);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'nl_select_precinct': index=3 → precinctSelected=TRUE, savePrecinctIndex(2)
    // -------------------------------------------------------------------------

    public function testValidateFormPrecinctSelectNonzeroDecrementsAndSavesIndex(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_select_precinct']);
      $formState->method('getValues')->willReturn(['nl_select_precinct' => 3]);

      // Index is decremented by 1: savePrecinctIndex(3-1) = savePrecinctIndex(2).
      $this->sessionDataMock->expects($this->once())->method('savePrecinctIndex')->with(2);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'search_submit': empty needle → error
    // -------------------------------------------------------------------------

    public function testValidateFormSearchSubmitEmptyNeedleSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValues')->willReturn(['needle' => '']);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('needle', $this->stringContains('specify a value'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — 'search_submit': non-empty needle stored
    // -------------------------------------------------------------------------

    public function testValidateFormSearchSubmitNonEmptyNeedleStoresValue(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getUserInput')->willReturn(['needle' => 'Smith']);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertContains(['needle', 'Smith'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'turf_submit': advances to nl_select, stores turfId
    // -------------------------------------------------------------------------

    public function testSubmitFormTurfSubmitAdvancesToNlSelectAndStoresTurfId(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured, [
        ['turfSelection', ['turfId' => 101]],
      ]);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'turf_submit']);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'nl_select'], $captured);
      $this->assertContains(['turfId', 101], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'nl_search_by_name': sets page
    // -------------------------------------------------------------------------

    public function testSubmitFormNlSearchByNameSetsPage(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_search_by_name']);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'nl_search_by_name'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'nl_search_by_district': sets page
    // -------------------------------------------------------------------------

    public function testSubmitFormNlSearchByDistrictSetsPage(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'nl_search_by_district']);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'nl_search_by_district'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'search_goback': returns to nl_select
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchGobackReturnsToNlSelectPage(): void {
      $captured = [];
      $formState = $this->capturingFormState($captured);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_goback']);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'nl_select'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — syncTurf=TRUE, turfName missing → warning, page reset to turf_select
    // -------------------------------------------------------------------------

    public function testSubmitFormSyncTurfMissingTurfNameAddsWarningAndResetsPage(): void {
      $turfOptions = [101 => array_merge($this->baseTurfOption(), ['turfName' => ''])];

      $this->sessionDataMock->method('get')->willReturnMap([
        ['folderId',     5],
        ['folderOptions',[5 => 'My Folder']],
        ['turfOptions',  $turfOptions],
      ]);

      $captured = [];
      $formState = $this->capturingFormState($captured, [
        ['previouslyAssignedMcid', 42],
        ['mcid',    42],
        ['county',  'Denver'],
        ['turfId',  101],
        ['showLists',  FALSE],
        ['walksheetInfo', []],
      ]);
      $formState->method('getTriggeringElement')
        ->willReturn(['#name' => 'previously_assigned_nl']);

      $this->messengerMock->expects($this->once())->method('addWarning');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'turf_select'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'search_select': sets mcid, calls batch_set
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSelectSyncsTurfAndCallsBatchSet(): void {
      $turfOption = $this->baseTurfOption();
      $folderId   = 5;

      $this->sessionDataMock->method('get')->willReturnMap([
        ['folderId',     $folderId],
        ['folderOptions',[$folderId => 'My Folder']],
        ['turfOptions',  [101 => $turfOption]],
      ]);

      // Wire extension.list.module into the existing container.
      $moduleListMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['getPath'])
        ->getMock();
      $moduleListMock->method('getPath')->willReturn('/modules/nlpservices');
      Drupal::getContainer()->set('extension.list.module', $moduleListMock);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_select']);
      $formState->method('get')->willReturnMap([
        ['nlSelection',   ['mcid' => 42]],
        ['mcid',          42],
        ['county',        'Denver'],
        ['turfId',        101],
        ['showLists',     FALSE],
        ['walksheetInfo', []],
      ]);

      // batch_set is a global no-op stub; reaching this line means the sync succeeded.
      $form = [];
      $this->buildForm()->submitForm($form, $formState);
      $this->assertTrue(TRUE); // Execution reached here without error.
    }

    // -------------------------------------------------------------------------
    // validateWalksheet — no files → ok with empty info
    // -------------------------------------------------------------------------

    public function testValidateWalksheetNoFilesReturnsOkWithEmptyInfo(): void {
      unset($_FILES['files']);
      $result = $this->buildForm()->validateWalksheet('field_name');
      $this->assertSame('ok', $result['result']);
      $this->assertSame('', $result['walksheetInfo']['fileName']);
      $this->assertSame('', $result['walksheetInfo']['fileType']);
    }

    // -------------------------------------------------------------------------
    // validateWalksheet — valid PDF
    // -------------------------------------------------------------------------

    public function testValidateWalksheetValidPdfReturnsOkWithFileType(): void {
      $_FILES['files'] = [
        'name'     => ['my_sheet' => 'canvass.pdf'],
        'tmp_name' => ['my_sheet' => '/tmp/abc123'],
      ];
      $result = $this->buildForm()->validateWalksheet('my_sheet');
      $this->assertSame('ok', $result['result']);
      $this->assertSame('pdf', $result['walksheetInfo']['fileType']);
      $this->assertSame('canvass', $result['walksheetInfo']['fileNameBase']);
    }

    // -------------------------------------------------------------------------
    // validateWalksheet — invalid file type → error
    // -------------------------------------------------------------------------

    public function testValidateWalksheetInvalidTypeReturnsError(): void {
      $_FILES['files'] = [
        'name'     => ['my_sheet' => 'notes.txt'],
        'tmp_name' => ['my_sheet' => '/tmp/def456'],
      ];
      $result = $this->buildForm()->validateWalksheet('my_sheet');
      $this->assertSame('error', $result['result']);
      $this->assertStringContainsString('PDF, XLS, or XLSX', $result['message']);
    }

    // -------------------------------------------------------------------------
    // validateWalksheet — xlsx is allowed
    // -------------------------------------------------------------------------

    public function testValidateWalksheetXlsxIsAllowed(): void {
      $_FILES['files'] = [
        'name'     => ['my_sheet' => 'data.xlsx'],
        'tmp_name' => ['my_sheet' => '/tmp/ghi789'],
      ];
      $result = $this->buildForm()->validateWalksheet('my_sheet');
      $this->assertSame('ok', $result['result']);
      $this->assertSame('xlsx', $result['walksheetInfo']['fileType']);
    }

    // -------------------------------------------------------------------------
    // buildNlSelectPage — pure function with both submit buttons
    // -------------------------------------------------------------------------

    public function testBuildNlSelectPageContainsBothSearchButtons(): void {
      $result = $this->buildForm()->buildNlSelectPage();
      $this->assertArrayHasKey('nl_search_by_name', $result);
      $this->assertSame('nl_search_by_name', $result['nl_search_by_name']['#name']);
      $this->assertArrayHasKey('nl_search_by_district', $result);
      $this->assertSame('nl_search_by_district', $result['nl_search_by_district']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildTurfSelectPage — showLists=true, empty turfOptions
    // -------------------------------------------------------------------------

    public function testBuildTurfSelectPageShowListsEmptyOptionsShowsNoListsMessage(): void {
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions',    []],
        ['mapRegionOptions', []],
      ]);

      $result = $this->buildForm()->buildTurfSelectPage(TRUE);
      $this->assertStringContainsString('no saved lists', $result['turf_select']['turf-empty']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildTurfSelectPage — showLists=false, no turfOptions, no mapRegionOptions
    // -------------------------------------------------------------------------

    public function testBuildTurfSelectPageNoTurfsNoMapRegionsShowsNoMapRegionsMessage(): void {
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions',    []],
        ['mapRegionOptions', []],
      ]);

      $result = $this->buildForm()->buildTurfSelectPage(FALSE);
      $this->assertStringContainsString('no map regions', $result['turf_select']['turf-empty']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildTurfSelectPage — showLists=false, no turfOptions, has mapRegionOptions
    // -------------------------------------------------------------------------

    public function testBuildTurfSelectPageNoTurfsWithMapRegionShowsNoTurfsMessage(): void {
      $this->sessionDataMock->method('get')->willReturnMap([
        ['turfOptions',    []],
        ['mapRegionOptions', [1 => 'Region A']],
      ]);

      $result = $this->buildForm()->buildTurfSelectPage(FALSE);
      $this->assertStringContainsString('no turfs in the map region', $result['turf_select']['turf-empty']['#markup']);
    }

    // -------------------------------------------------------------------------
    // previouslyAssignedNl — no prior assignment → empty array
    // -------------------------------------------------------------------------

    public function testPreviouslyAssignedNlNoAssignmentReturnsEmptyArray(): void {
      $this->turfsMock->method('getTurfAssignment')->willReturn([]);
      $mcid = 0;
      $this->assertSame([], $this->buildForm()->previouslyAssignedNl(5, $mcid));
    }

    // -------------------------------------------------------------------------
    // previouslyAssignedNl — active NL found → form includes submit button, mcid set
    // -------------------------------------------------------------------------

    public function testPreviouslyAssignedNlWithActiveNlIncludesSubmitButton(): void {
      $this->turfsMock->method('getTurfAssignment')->willReturn(['mcid' => 42]);
      $this->nlsMock->method('getNlById')->willReturn([
        'firstName' => 'Alice', 'lastName' => 'Smith',
        'mcid' => 42, 'hd' => '06', 'precinct' => '6.1',
      ]);
      $this->syncTurfBuilderMock->method('walksheet')->willReturn(['#type' => 'file']);

      $mcid = 0;
      $result = $this->buildForm()->previouslyAssignedNl(5, $mcid);

      $this->assertSame(42, $mcid);
      $this->assertArrayHasKey('previously_assigned', $result);
      $this->assertArrayHasKey('previously_assigned_nl', $result['previously_assigned']);
      $this->assertSame('previously_assigned_nl', $result['previously_assigned']['previously_assigned_nl']['#name']);
    }

    // -------------------------------------------------------------------------
    // previouslyAssignedNl — NL not in active list → inactive message
    // -------------------------------------------------------------------------

    public function testPreviouslyAssignedNlWithInactiveNlShowsInactiveMessage(): void {
      $this->turfsMock->method('getTurfAssignment')->willReturn(['mcid' => 42]);
      $this->nlsMock->method('getNlById')->willReturn([]);

      $mcid = 0;
      $result = $this->buildForm()->previouslyAssignedNl(5, $mcid);

      $this->assertArrayHasKey('previously_assigned', $result);
      $this->assertStringContainsString('active NL list', $result['previously_assigned']['inactive_nl']['#markup']);
    }

    // -------------------------------------------------------------------------
    // Callbacks
    // -------------------------------------------------------------------------

    public function testFolderSelectedCallbackReturnsTurfSelectPageElement(): void {
      $form = ['turf_select_page' => ['#type' => 'fieldset']];
      $this->assertSame($form['turf_select_page'], $this->buildForm()->folderSelectedCallback($form, NULL));
    }

    public function testDistrictCallbackReturnsNlDistrictSelectElement(): void {
      $form = ['nl_district' => ['nl_select_district' => ['#type' => 'table']]];
      $this->assertSame($form['nl_district']['nl_select_district'], $this->buildForm()->districtCallback($form, NULL));
    }

    public function testSelectboxCallbackReturnsNlSelectElement(): void {
      $form = ['nl_select' => ['#type' => 'fieldset']];
      $this->assertSame($form['nl_select'], $this->buildForm()->selectboxCallback($form, NULL));
    }

  }

}
