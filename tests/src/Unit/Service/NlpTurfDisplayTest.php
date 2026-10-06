<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\NlpTurfDisplay;
  use Drupal\Tests\UnitTestCase;

  class NlpTurfDisplayTest extends UnitTestCase {

    private NlpTurfDisplay $service;

    protected function setUp(): void {
      parent::setUp();
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      $this->service = new NlpTurfDisplay();
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseTurf(): array {
      return [
        'turfIndex'        => 7,
        'turfHd'           => 6,
        'turfPrecinct'     => '6.1',
        'nlLastName'       => 'Doe',
        'nlFirstName'      => 'Jane',
        'mcid'             => 42,
        'turfName'         => 'Denver-001',
        'mapRegionName'    => 'Region A',
        'folderName'       => 'Folder1',
        'turfPDF'          => 'some.pdf',
        'minivanNumber'    => '',
        'minivanCreateDate'=> '',
        'email'            => 'jane@example.com',
      ];
    }

    // -------------------------------------------------------------------------
    // buildTurfTableArray
    // -------------------------------------------------------------------------

    public function testBuildTurfTableArrayOtherTypeIncludesCbColumn(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertArrayHasKey('cb', $rows[7]);
    }

    public function testBuildTurfTableArrayOtherTypeIncludesNlLastName(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertArrayHasKey('nlLastName', $rows[7]);
    }

    public function testBuildTurfTableArrayCbColumnHasTurfIndex(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertSame(7, $rows[7]['cb']['turfIndex']);
    }

    public function testBuildTurfTableArrayCbColumnHasRowCount(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertSame(1, $rows[7]['cb']['rowCount']);
    }

    public function testBuildTurfTableArrayRowsKeyedByTurfIndex(): void {
      $second = array_merge($this->baseTurf(), ['turfIndex' => 9]);
      $rows = $this->service->buildTurfTableArray([$this->baseTurf(), $second]);
      $this->assertSame([7, 9], array_keys($rows));
      $this->assertSame(2, $rows[9]['cb']['rowCount']);
    }

    public function testDisplayTurfsCheckboxForRemovedTurfIsAbsent(): void {
      // A rebuilt table without turf 7 must not expose a checkbox that a stale
      // contacts[7][cb] submission could map onto a different turf.
      $second = array_merge($this->baseTurf(), ['turfIndex' => 9]);
      $result = $this->service->displayTurfs($this->service->buildTurfTableArray([$second]));
      $this->assertArrayNotHasKey(7, $result['contacts']);
      $this->assertStringStartsWith('CB_9_', $result['contacts'][9]['cb']['#return_value']);
    }

    public function testBuildTurfTableArrayNlLastNameContainsBothNames(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertStringContainsString('Doe', $rows[7]['nlLastName']);
      $this->assertStringContainsString('Jane', $rows[7]['nlLastName']);
    }

    public function testBuildTurfTableArrayTurfNameContainsMapRegion(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertStringContainsString('Region A', $rows[7]['turfName']);
    }

    public function testBuildTurfTableArrayFolderNameSet(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $this->assertSame('Folder1', $rows[7]['folderName']);
    }

    public function testBuildTurfTableArrayEmptyTurfsReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->buildTurfTableArray([]));
    }

    public function testBuildTurfTableArrayDisplayTypeExcludesCb(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'display');
      $this->assertArrayNotHasKey('cb', $rows[7]);
    }

    public function testBuildTurfTableArrayDisplayTypeIncludesMinivanNumberEmpty(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'display');
      $this->assertArrayHasKey('minivanNumber', $rows[7]);
      $this->assertSame('', $rows[7]['minivanNumber']);
    }

    public function testBuildTurfTableArrayDisplayTypeShowsMinivanWhenSet(): void {
      $turf = array_merge($this->baseTurf(), [
        'minivanNumber'     => 99,
        'minivanCreateDate' => '2024-10-15 00:00:00',
      ]);
      $rows = $this->service->buildTurfTableArray([$turf], 'display');
      $this->assertStringContainsString('99', $rows[7]['minivanNumber']);
      $this->assertStringContainsString('2024-10-15', $rows[7]['minivanNumber']);
    }

    public function testBuildTurfTableArrayDisplayTypeIncludesNlLastName(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'display');
      $this->assertArrayHasKey('nlLastName', $rows[7]);
    }

    public function testBuildTurfTableArrayDisplayTypeShowsVotersOverDoors(): void {
      $turf = array_merge($this->baseTurf(), ['voterCount' => 84, 'doorCount' => 51]);
      $rows = $this->service->buildTurfTableArray([$turf], 'display');
      $this->assertSame('84<br>51', $rows[7]['voterDoorCount']);
    }

    public function testBuildTurfTableArrayDisplayTypeMissingCountsAreBlank(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'display');
      $this->assertSame('<br>', $rows[7]['voterDoorCount']);
    }

    public function testBuildTurfTableArrayOtherTypesExcludeVoterDoorCount(): void {
      foreach (['other', 'walksheet', 'replacement'] as $type) {
        $rows = $this->service->buildTurfTableArray([$this->baseTurf()], $type);
        $this->assertArrayNotHasKey('voterDoorCount', $rows[7], $type);
      }
    }

    public function testDisplayTurfsDisplayTypeLastHeaderIsVotersDoors(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'display');
      $result = $this->service->displayTurfs($rows, 'display');
      $header = $result['contacts']['#header'];
      $this->assertSame('Voters<br>Doors', (string) end($header)['data']);
      // One header cell per row cell, so the new column lines up.
      $this->assertCount(count($rows[7]), $header);
    }

    public function testBuildTurfTableArrayReplacementTypeExcludesNlLastName(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'replacement');
      $this->assertArrayNotHasKey('nlLastName', $rows[7]);
    }

    public function testBuildTurfTableArrayReplacementTypeIncludesCb(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'replacement');
      $this->assertArrayHasKey('cb', $rows[7]);
    }

    public function testBuildTurfTableArrayWalksheetTypeIncludesEmail(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'walksheet');
      $this->assertSame('jane@example.com', $rows[7]['email']);
    }

    public function testBuildTurfTableArrayWalksheetTypeAddsNoPdfWhenPdfEmpty(): void {
      $turf = array_merge($this->baseTurf(), ['turfPDF' => '']);
      $rows = $this->service->buildTurfTableArray([$turf], 'walksheet');
      $this->assertStringContainsString('&#134;', $rows[7]['turfIndex']);
    }

    public function testBuildTurfTableArrayWalksheetTypeNoMarkerWhenPdfSet(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()], 'walksheet');
      $this->assertSame((string) 7, (string) $rows[7]['turfIndex']);
    }

    // -------------------------------------------------------------------------
    // displayTurfs
    // -------------------------------------------------------------------------

    public function testDisplayTurfsReturnsContactsKey(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertArrayHasKey('contacts', $result);
    }

    public function testDisplayTurfsContactsIsTableType(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertSame('table', $result['contacts']['#type']);
    }

    public function testDisplayTurfsHasHeader(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertNotEmpty($result['contacts']['#header']);
    }

    public function testDisplayTurfsHeaderLabels(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $labels = array_map(fn($cell) => (string) $cell['data'], array_slice($result['contacts']['#header'], 0, 4));
      // The selection column has no label.
      $this->assertSame(['', 'turfIndex', 'HD', 'Precinct'], $labels);
    }

    public function testDisplayTurfsCbColumnIsCheckbox(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertSame('checkbox', $result['contacts'][7]['cb']['#type']);
    }

    public function testDisplayTurfsCbReturnValueContainsTurfIndex(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertStringContainsString('7', $result['contacts'][7]['cb']['#return_value']);
    }

    public function testDisplayTurfsNonCbColumnHasMarkup(): void {
      $rows = $this->service->buildTurfTableArray([$this->baseTurf()]);
      $result = $this->service->displayTurfs($rows);
      $this->assertArrayHasKey('#markup', $result['contacts'][7]['turfIndex']);
    }

    public function testDisplayTurfsEmptyRowsReturnsTableWithNoRows(): void {
      $result = $this->service->displayTurfs([]);
      $this->assertSame('table', $result['contacts']['#type']);
      $this->assertCount(0, array_filter(array_keys($result['contacts']), 'is_int'));
    }

    // -------------------------------------------------------------------------
    // validateTurfSelection
    // -------------------------------------------------------------------------

    public function testValidateTurfSelectionEmptyContactsReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->validateTurfSelection([]));
    }

    public function testValidateTurfSelectionCheckedContactReturnsSelection(): void {
      $contacts = [['cb' => 'CB_7_1']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertArrayHasKey('7', $result);
      $this->assertSame('7', $result['7']['turfIndex']);
      $this->assertSame('1', $result['7']['rowCount']);
    }

    public function testValidateTurfSelectionUncheckedContactSkipped(): void {
      $contacts = [['cb' => ''], ['cb' => 'CB_7_1']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertCount(1, $result);
    }

    public function testValidateTurfSelectionMultipleCheckedReturnsAll(): void {
      $contacts = [['cb' => 'CB_7_1'], ['cb' => 'CB_8_2']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertArrayHasKey('7', $result);
      $this->assertArrayHasKey('8', $result);
    }

    // -------------------------------------------------------------------------
    // getSortField
    // -------------------------------------------------------------------------

    public function testGetSortFieldReturnsTurfIndexForTurfIndex(): void {
      $this->assertSame('turfIndex', $this->service->getSortField('turfIndex'));
    }

    public function testGetSortFieldReturnsHdForHD(): void {
      $this->assertSame('hd', $this->service->getSortField('HD'));
    }

    public function testGetSortFieldReturnsPrecinctForPrecinct(): void {
      $this->assertSame('precinct', $this->service->getSortField('Precinct'));
    }

    public function testGetSortFieldReturnsNlLastNameForNameMcid(): void {
      $this->assertSame('nlLastName', $this->service->getSortField('Name<br>MCID'));
    }

    public function testGetSortFieldReturnsTurfNameForTurfNameLabel(): void {
      $this->assertSame('turfName', $this->service->getSortField('Turf name<br>Map Region Name'));
    }

    public function testGetSortFieldReturnsFolderNameForFolderName(): void {
      $this->assertSame('folderName', $this->service->getSortField('Folder name'));
    }

    public function testGetSortFieldReturnsEmptyStringForUnknownLabel(): void {
      $this->assertSame('', $this->service->getSortField('Unknown'));
    }

    public function testGetSortFieldDisplayTypeExcludesCbFromSearchableFields(): void {
      $this->assertSame('', $this->service->getSortField('', 'display'));
    }

    public function testGetSortFieldReplacementTypeExcludesNlLastName(): void {
      $this->assertSame('', $this->service->getSortField('Name<br>MCID', 'replacement'));
    }

    // -------------------------------------------------------------------------
    // sortTurfTable
    // -------------------------------------------------------------------------

    private function buildTurfTable(): array {
      return [
        1 => ['turfIndex' => '10', 'hd' => '8', 'precinct' => '8.2', 'nlLastName' => 'Zara'],
        2 => ['turfIndex' => '5',  'hd' => '6', 'precinct' => '6.1', 'nlLastName' => 'Alice'],
        3 => ['turfIndex' => '7',  'hd' => '7', 'precinct' => '7.0', 'nlLastName' => 'Mike'],
      ];
    }

    public function testSortTurfTableSortsAscendingByTurfIndex(): void {
      $table = $this->buildTurfTable();
      $this->service->sortTurfTable($table, 'asc', 'turfIndex');
      $values = array_column($table, 'turfIndex');
      $this->assertSame('5', $values[0]);
      $this->assertSame('10', $values[2]);
    }

    public function testSortTurfTableSortsDescendingByTurfIndex(): void {
      $table = $this->buildTurfTable();
      $this->service->sortTurfTable($table, 'desc', 'turfIndex');
      $values = array_column($table, 'turfIndex');
      $this->assertSame('10', $values[0]);
      $this->assertSame('5', $values[2]);
    }

    public function testSortTurfTableSortsAscendingByNlLastName(): void {
      $table = $this->buildTurfTable();
      $this->service->sortTurfTable($table, 'asc', 'Name<br>MCID');
      $values = array_column($table, 'nlLastName');
      $this->assertSame('Alice', $values[0]);
      $this->assertSame('Zara', $values[2]);
    }

    public function testSortTurfTableEmptyTableDoesNotError(): void {
      $table = [];
      $this->service->sortTurfTable($table, 'asc', 'turfIndex');
      $this->assertSame([], $table);
    }

    public function testSortTurfTableUnknownOrderDoesNotSort(): void {
      $table = $this->buildTurfTable();
      $original = $table;
      $this->service->sortTurfTable($table, 'asc', 'Unknown');
      $this->assertSame($original, $table);
    }

    public function testSortTurfTablePreservesKeysAfterSort(): void {
      $table = $this->buildTurfTable();
      $this->service->sortTurfTable($table, 'asc', 'turfIndex');
      $this->assertArrayHasKey(2, $table);
    }

  }

}
