<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\NlpNlsListDisplay;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpSyncTurfBuilder;
  use Drupal\nlpservices\NlpTurfListDisplayVoteBuilder;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpSyncTurfBuilderTest extends UnitTestCase {

    private NlpTurfListDisplayVoteBuilder $turfListDisplayMock;
    private NlpNlsListDisplay $nlsListDisplayMock;
    private NlpSessionData $sessionDataMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfListDisplayMock = $this->createMock(NlpTurfListDisplayVoteBuilder::class);
      $this->nlsListDisplayMock = $this->createMock(NlpNlsListDisplay::class);
      $this->sessionDataMock = $this->createMock(NlpSessionData::class);
    }

    private function buildService(): NlpSyncTurfBuilder {
      return new NlpSyncTurfBuilder(
        $this->turfListDisplayMock,
        $this->nlsListDisplayMock,
        $this->sessionDataMock,
      );
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.turf_list_display_votebuilder', $this->turfListDisplayMock);
      $container->set('nlpservices.nls_list_display', $this->nlsListDisplayMock);
      $container->set('nlpservices.session_data', $this->sessionDataMock);

      $service = NlpSyncTurfBuilder::create($container);

      $this->assertInstanceOf(NlpSyncTurfBuilder::class, $service);
    }

    // -------------------------------------------------------------------------
    // walksheet()
    // -------------------------------------------------------------------------

    public function testWalksheetReturnsExpectedKeys(): void {
      $result = $this->buildService()->walksheet('pa');

      $this->assertArrayHasKey('walk-sheet-box', $result);
      $this->assertArrayHasKey('file_title', $result);
      $this->assertArrayHasKey('max_file_size_pa', $result);
      $this->assertArrayHasKey('turf_walksheet_previousAssignment', $result);
      $this->assertArrayHasKey('walk-sheet-help', $result);
      $this->assertArrayHasKey('walksheet-box-end', $result);
    }

    public function testWalksheetFileElementIsTypeFile(): void {
      $result = $this->buildService()->walksheet('pa');

      $this->assertSame('file', $result['turf_walksheet_previousAssignment']['#type']);
    }

    public function testWalksheetMaxFileSizeHiddenElementHasCorrectValue(): void {
      $result = $this->buildService()->walksheet('ns');

      $this->assertSame('hidden', $result['max_file_size_ns']['#type']);
      $this->assertSame(10485760, $result['max_file_size_ns']['#value']);
    }

    public function testWalksheetUsesCorrectFormNameForEachType(): void {
      $service = $this->buildService();

      $this->assertArrayHasKey('turf_walksheet_previousAssignment', $service->walksheet('pa'));
      $this->assertArrayHasKey('turf_walksheet_nameSearch', $service->walksheet('ns'));
      $this->assertArrayHasKey('turf_walksheet_districtSelect', $service->walksheet('ds'));
      $this->assertArrayHasKey('turf_walksheet_replacement', $service->walksheet('re'));
    }

    public function testWalksheetIncludesHelpNoteByDefault(): void {
      $result = $this->buildService()->walksheet('pa');

      $this->assertArrayHasKey('walk-sheet-help', $result);
    }

    public function testWalksheetOmitsHelpNoteWhenNoteIsFalse(): void {
      $result = $this->buildService()->walksheet('pa', FALSE);

      $this->assertArrayNotHasKey('walk-sheet-help', $result);
    }

    // -------------------------------------------------------------------------
    // footnotes()
    // -------------------------------------------------------------------------

    public function testFootnotesReturnsFootnoteElement(): void {
      $result = $this->buildService()->footnotes();

      $this->assertArrayHasKey('footnote', $result);
      $this->assertStringContainsString('Table Footnotes', $result['footnote']['#markup']);
    }

    // -------------------------------------------------------------------------
    // legends()
    // -------------------------------------------------------------------------

    public function testLegendsReturnsFootnoteElement(): void {
      $result = $this->buildService()->legends();

      $this->assertArrayHasKey('footnote', $result);
      $this->assertStringContainsString('Table Legend', $result['footnote']['#markup']);
    }

    // -------------------------------------------------------------------------
    // legendBar()
    // -------------------------------------------------------------------------

    public function testLegendBarReturnsExpectedKeys(): void {
      $result = $this->buildService()->legendBar();

      $this->assertArrayHasKey('turf_id_bar', $result);
      $this->assertArrayHasKey('legend_box', $result);
      $this->assertArrayHasKey('legends', $result);
      $this->assertArrayHasKey('legend_end', $result);
      $this->assertArrayHasKey('footnote_box', $result);
      $this->assertArrayHasKey('footnotes', $result);
      $this->assertArrayHasKey('footnote_end', $result);
      $this->assertArrayHasKey('turf_id_bar_end', $result);
    }

    public function testLegendBarLegendsContainsTableLegendText(): void {
      $result = $this->buildService()->legendBar();

      $this->assertArrayHasKey('footnote', $result['legends']);
      $this->assertStringContainsString('Table Legend', $result['legends']['footnote']['#markup']);
    }

    public function testLegendBarFootnotesContainsTableFootnotesText(): void {
      $result = $this->buildService()->legendBar();

      $this->assertArrayHasKey('footnote', $result['footnotes']);
      $this->assertStringContainsString('Table Footnotes', $result['footnotes']['footnote']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildNlSelect()
    // -------------------------------------------------------------------------

    public function testBuildNlSelectReturnsExpectedKeys(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn(['#markup' => 'table']);

      $result = $this->buildService()->buildNlSelect([]);

      $this->assertArrayHasKey('nl_select_header', $result);
      $this->assertArrayHasKey('select_nl_from_list', $result);
      $this->assertArrayHasKey('walksheet', $result);
      $this->assertArrayHasKey('add_selected', $result);
      $this->assertArrayHasKey('search_goback', $result);
    }

    public function testBuildNlSelectCallsBuildNlsTableWithDistrictType(): void {
      $nlsTable = [['mcid' => 1, 'firstName' => 'Alice']];

      $this->nlsListDisplayMock->expects($this->once())
        ->method('buildNlsTable')
        ->with($nlsTable, TRUE, 'nlsForm')
        ->willReturn(['#markup' => 'table']);

      $this->buildService()->buildNlSelect($nlsTable);
    }

    public function testBuildNlSelectAddSelectedIsSubmitType(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn([]);

      $result = $this->buildService()->buildNlSelect([]);

      $this->assertSame('submit', $result['add_selected']['#type']);
      $this->assertSame('add_selected', $result['add_selected']['#name']);
    }

    public function testBuildNlSelectWalksheetUsesDsType(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn([]);

      $result = $this->buildService()->buildNlSelect([]);

      $this->assertArrayHasKey('turf_walksheet_districtSelect', $result['walksheet']);
    }

    // -------------------------------------------------------------------------
    // buildNlSearchByName()
    // -------------------------------------------------------------------------

    public function testBuildNlSearchByNameReturnsBaseKeysWithEmptyNeedle(): void {
      $result = $this->buildService()->buildNlSearchByName('', []);

      $this->assertArrayHasKey('nl_search_title', $result);
      $this->assertArrayHasKey('nl_name_search', $result);
      $this->assertArrayNotHasKey('nl_select_header', $result['nl_name_search']);
      $this->assertArrayNotHasKey('select_nl_from_list', $result['nl_name_search']);
    }

    public function testBuildNlSearchByNameIncludesNeedleTextfield(): void {
      $result = $this->buildService()->buildNlSearchByName('Smith', []);

      $this->assertSame('textfield', $result['nl_name_search']['needle']['#type']);
      $this->assertSame('Smith', $result['nl_name_search']['needle']['#default_value']);
    }

    public function testBuildNlSearchByNameIncludesNlSelectWhenNeedleSet(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn(['#markup' => 'table']);

      $result = $this->buildService()->buildNlSearchByName('Smith', []);

      $this->assertArrayHasKey('nl_select_header', $result['nl_name_search']);
      $this->assertArrayHasKey('select_nl_from_list', $result['nl_name_search']);
      $this->assertArrayHasKey('walksheet', $result['nl_name_search']);
      $this->assertArrayHasKey('search_select', $result['nl_name_search']);
    }

    public function testBuildNlSearchByNameCallsBuildNlsTableWithSearchType(): void {
      $nlsTable = [['mcid' => 5, 'firstName' => 'Bob']];

      $this->nlsListDisplayMock->expects($this->once())
        ->method('buildNlsTable')
        ->with($nlsTable, TRUE, 'search')
        ->willReturn([]);

      $this->buildService()->buildNlSearchByName('Bob', $nlsTable);
    }

    public function testBuildNlSearchByNameWalksheetUsesNsType(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByName('Smith', []);

      $this->assertArrayHasKey('turf_walksheet_nameSearch', $result['nl_name_search']['walksheet']);
    }

    public function testBuildNlSearchByNameIncludesSearchAndGobackSubmits(): void {
      $result = $this->buildService()->buildNlSearchByName('', []);

      $this->assertSame('submit', $result['nl_name_search']['search_submit']['#type']);
      $this->assertSame('search_submit', $result['nl_name_search']['search_submit']['#name']);
      $this->assertSame('submit', $result['nl_name_search']['search_goback']['#type']);
      $this->assertSame('search_goback', $result['nl_name_search']['search_goback']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildNlSearchByDistrict()
    // -------------------------------------------------------------------------

    public function testBuildNlSearchByDistrictReturnsExpectedTopLevelKeys(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertArrayHasKey('nl_select_district_title', $result);
      $this->assertArrayHasKey('nl_select_district', $result);
    }

    public function testBuildNlSearchByDistrictAddsSelectHdAsFirstOption(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn(['HD1', 'HD2']);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $options = $result['nl_select_district']['nl_select_hd']['#options'];
      $this->assertSame('Select HD', $options[0]);
      $this->assertSame('HD1', $options[1]);
      $this->assertSame('HD2', $options[2]);
    }

    public function testBuildNlSearchByDistrictDefaultHdIndexIsZeroWhenNoHdSelected(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn(['HD1', 'HD2']);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertSame(0, $result['nl_select_district']['nl_select_hd']['#default_value']);
    }

    public function testBuildNlSearchByDistrictUsesSessionHdIndexWhenHdSelected(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn(['HD1', 'HD2']);
      $this->sessionDataMock->method('get')
        ->willReturnCallback(fn($key) => $key === 'hdSelected' ? TRUE : NULL);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(1);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      // getSavedHdIndex() returns 1, +1 for the 'Select HD' prefix = 2
      $this->assertSame(2, $result['nl_select_district']['nl_select_hd']['#default_value']);
    }

    public function testBuildNlSearchByDistrictAddsSelectPrecinctAsFirstOption(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn(['Pct1', 'Pct2']);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $options = $result['nl_select_district']['nl_select_precinct']['#options'];
      $this->assertSame('Select Precinct', $options[0]);
      $this->assertSame('Pct1', $options[1]);
    }

    public function testBuildNlSearchByDistrictDefaultPrecinctIndexIsZeroWhenNoPrecinctSelected(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn(['Pct1']);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertSame(0, $result['nl_select_district']['nl_select_precinct']['#default_value']);
    }

    public function testBuildNlSearchByDistrictUsesSessionPrecinctIndexWhenPrecinctSelected(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')
        ->willReturnCallback(fn($key) => $key === 'precinctSelected' ? TRUE : NULL);
      $this->sessionDataMock->method('getSavedPrecinctIndex')->willReturn(2);
      $this->sessionDataMock->method('getPrecinctList')->willReturn(['Pct1', 'Pct2', 'Pct3']);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      // getSavedPrecinctIndex() returns 2, +1 increment in the method = 3
      $this->assertSame(3, $result['nl_select_district']['nl_select_precinct']['#default_value']);
    }

    public function testBuildNlSearchByDistrictOmitsNlSelectionWhenNoHdOrPrecinctChosen(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertArrayNotHasKey('nl_selection', $result['nl_select_district']);
    }

    public function testBuildNlSearchByDistrictIncludesNlSelectionWhenHdSelected(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn([]);
      $this->sessionDataMock->method('getHdOptions')->willReturn(['HD1']);
      $this->sessionDataMock->method('get')
        ->willReturnCallback(fn($key) => $key === 'hdSelected' ? TRUE : NULL);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertArrayHasKey('nl_selection', $result['nl_select_district']);
    }

    public function testBuildNlSearchByDistrictIncludesNlSelectionWhenPrecinctSelected(): void {
      $this->nlsListDisplayMock->method('buildNlsTable')->willReturn([]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')
        ->willReturnCallback(fn($key) => $key === 'precinctSelected' ? TRUE : NULL);
      $this->sessionDataMock->method('getSavedPrecinctIndex')->willReturn(1);
      $this->sessionDataMock->method('getPrecinctList')->willReturn(['Pct1', 'Pct2']);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertArrayHasKey('nl_selection', $result['nl_select_district']);
    }

    public function testBuildNlSearchByDistrictHdSelectElementHasAjaxCallback(): void {
      $this->sessionDataMock->method('getHdOptions')->willReturn([]);
      $this->sessionDataMock->method('get')->willReturn(NULL);
      $this->sessionDataMock->method('getPrecinctList')->willReturn([]);

      $result = $this->buildService()->buildNlSearchByDistrict([]);

      $this->assertSame('select', $result['nl_select_district']['nl_select_hd']['#type']);
      $this->assertArrayHasKey('#ajax', $result['nl_select_district']['nl_select_hd']);
    }

    // -------------------------------------------------------------------------
    // buildTurfSelect()
    // -------------------------------------------------------------------------

    public function testBuildTurfSelectReturnsExpectedTopLevelKeys(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertArrayHasKey('turf_select_title', $result);
      $this->assertArrayHasKey('turf_select', $result);
    }

    public function testBuildTurfSelectTitleShowsTurfMessageWhenNotShowingLists(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertStringContainsString('map region', $result['turf_select_title']['#markup']);
    }

    public function testBuildTurfSelectTitleShowsListMessageWhenShowingLists(): void {
      $result = $this->buildService()->buildTurfSelect(TRUE, [], NULL, [], NULL, []);

      $this->assertStringContainsString('saved list', $result['turf_select_title']['#markup']);
    }

    public function testBuildTurfSelectFolderDropdownIsPresent(): void {
      $folderOptions = [1 => 'Folder A', 2 => 'Folder B'];
      $result = $this->buildService()->buildTurfSelect(FALSE, $folderOptions, 1, [], NULL, []);

      $this->assertSame('select', $result['turf_select']['folder']['#type']);
      $this->assertSame($folderOptions, $result['turf_select']['folder']['#options']);
      $this->assertSame(1, $result['turf_select']['folder']['#default_value']);
    }

    public function testBuildTurfSelectIncludesMapRegionDropdownWhenOptionsProvided(): void {
      $mapRegionOptions = [10 => 'Region X'];
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, $mapRegionOptions, 10, []);

      $this->assertArrayHasKey('map_region_select', $result['turf_select']);
      $this->assertSame('select', $result['turf_select']['map_region_select']['#type']);
      $this->assertSame(10, $result['turf_select']['map_region_select']['#default_value']);
    }

    public function testBuildTurfSelectOmitsMapRegionDropdownWhenNoOptions(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertArrayNotHasKey('map_region_select', $result['turf_select']);
    }

    public function testBuildTurfSelectShowsEmptyMessageWhenNoTurfsAndNoMapRegions(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertArrayHasKey('turf-empty', $result['turf_select']);
      $this->assertStringContainsString('no map regions', $result['turf_select']['turf-empty']['#markup']);
    }

    public function testBuildTurfSelectShowsNoTurfsMessageWhenMapRegionsExistButNoTurfs(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [1 => 'Region'], 1, []);

      $this->assertArrayHasKey('turf-empty', $result['turf_select']);
      $this->assertStringContainsString('no turfs', $result['turf_select']['turf-empty']['#markup']);
    }

    public function testBuildTurfSelectShowsNoListsMessageWhenShowingListsWithNoOptions(): void {
      $result = $this->buildService()->buildTurfSelect(TRUE, [], NULL, [], NULL, []);

      $this->assertArrayHasKey('turf-empty', $result['turf_select']);
      $this->assertStringContainsString('no saved lists', $result['turf_select']['turf-empty']['#markup']);
    }

    public function testBuildTurfSelectCallsDisplayVoteBuilderTurfsWhenTurfsPresent(): void {
      $turfOptions = [['turfId' => 1, 'name' => 'Turf A']];
      $this->turfListDisplayMock->expects($this->once())
        ->method('displayVoteBuilderTurfs')
        ->with($turfOptions, FALSE)
        ->willReturn(['#markup' => 'table']);

      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, $turfOptions);

      $this->assertArrayHasKey('table', $result['turf_select']);
      $this->assertArrayNotHasKey('turf-empty', $result['turf_select']);
    }

    public function testBuildTurfSelectIncludesSavedListsRevealButtonWhenNotShowingLists(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertArrayHasKey('saved_lists_reveal', $result['turf_select']);
      $this->assertSame('change_to_lists', $result['turf_select']['saved_lists_reveal']['#name']);
      $this->assertArrayNotHasKey('map_region_return', $result['turf_select']);
    }

    public function testBuildTurfSelectIncludesMapRegionReturnButtonWhenShowingLists(): void {
      $result = $this->buildService()->buildTurfSelect(TRUE, [], NULL, [], NULL, []);

      $this->assertArrayHasKey('map_region_return', $result['turf_select']);
      $this->assertSame('change_to_turfs', $result['turf_select']['map_region_return']['#name']);
      $this->assertArrayNotHasKey('saved_lists_reveal', $result['turf_select']);
    }

    public function testBuildTurfSelectAlwaysIncludesTurfSubmitAndLegend(): void {
      $result = $this->buildService()->buildTurfSelect(FALSE, [], NULL, [], NULL, []);

      $this->assertSame('submit', $result['turf_select']['turf_submit']['#type']);
      $this->assertArrayHasKey('legend', $result['turf_select']);
    }

  }

}
