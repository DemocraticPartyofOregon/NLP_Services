<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\NlpTurfListDisplayVoteBuilder;
  use Drupal\Tests\UnitTestCase;

  class NlpTurfListDisplayVoteBuilderTest extends UnitTestCase {

    private NlpTurfListDisplayVoteBuilder $service;

    protected function setUp(): void {
      parent::setUp();
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      $this->service = new NlpTurfListDisplayVoteBuilder();
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
        'mcid'             => 42,
        'turfAssigned'     => 'Y',
        'nl'               => 'Jane Doe',
        'turfName'         => 'Denver-001',
        'mapRegionName'    => 'Region A',
        'doorCount'        => 10,
        'voterCount'       => 20,
        'minivanNumber'    => '',
        'minivanCreateDate'=> '',
        'turfId'           => 7,
      ];
    }

    // -------------------------------------------------------------------------
    // displayVoteBuilderTurfs — empty turfs
    // -------------------------------------------------------------------------

    public function testDisplayVoteBuilderTurfsEmptyTurfsNoListsReturnsMarkup(): void {
      $result = $this->service->displayVoteBuilderTurfs([], FALSE);
      $this->assertArrayHasKey('turf-empty', $result);
      $this->assertStringContainsString('no turfs', $result['turf-empty']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsEmptyTurfsShowListsReturnsMarkup(): void {
      $result = $this->service->displayVoteBuilderTurfs([], TRUE);
      $this->assertArrayHasKey('turf-empty', $result);
      $this->assertStringContainsString('no saved lists', $result['turf-empty']['#markup']);
    }

    // -------------------------------------------------------------------------
    // displayVoteBuilderTurfs — with turfs
    // -------------------------------------------------------------------------

    public function testDisplayVoteBuilderTurfsReturnsContactsKey(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertArrayHasKey('contacts', $result);
    }

    public function testDisplayVoteBuilderTurfsContactsIsTableType(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertSame('table', $result['contacts']['#type']);
    }

    public function testDisplayVoteBuilderTurfsHasHeader(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertNotEmpty($result['contacts']['#header']);
    }

    public function testDisplayVoteBuilderTurfsCbColumnIsRadio(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertSame('radio', $result['contacts'][0]['cb']['#type']);
    }

    public function testDisplayVoteBuilderTurfsRadiosShareOneGroup(): void {
      $turfs = [7 => $this->baseTurf(), 8 => $this->baseTurf()];
      $result = $this->service->displayVoteBuilderTurfs($turfs, FALSE);
      $this->assertSame(['contacts', 'selected', 'cb'], $result['contacts'][0]['cb']['#parents']);
      $this->assertSame($result['contacts'][0]['cb']['#parents'], $result['contacts'][1]['cb']['#parents']);
    }

    public function testValidateTurfSelectionReadsRadioGroupValue(): void {
      $result = $this->service->validateTurfSelection(['selected' => ['cb' => 'CB_7_42']]);
      $this->assertSame([7 => ['turfId' => 7, 'mcid' => 42]], $result);
    }

    public function testDisplayVoteBuilderTurfsCbReturnValueContainsTurfId(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertStringContainsString('7', $result['contacts'][0]['cb']['#return_value']);
    }

    public function testDisplayVoteBuilderTurfsCbReturnValueContainsMcid(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertStringContainsString('42', $result['contacts'][0]['cb']['#return_value']);
    }

    public function testDisplayVoteBuilderTurfsNonCbColumnHasMarkup(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertArrayHasKey('#markup', $result['contacts'][0]['turfName']);
    }

    public function testDisplayVoteBuilderTurfsReturnsFootnote(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertArrayHasKey('footnote', $result);
    }

    public function testDisplayVoteBuilderTurfsEmptyMapRegionNameUsesSpace(): void {
      $turf = array_merge($this->baseTurf(), ['mapRegionName' => '']);
      $result = $this->service->displayVoteBuilderTurfs([7 => $turf], FALSE);
      $this->assertSame(' ', $result['contacts'][0]['mapRegionName']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsMapRegionNameSet(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertSame('Region A', $result['contacts'][0]['mapRegionName']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsEmptyMinivanNumberUsesEmptyString(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertSame('', $result['contacts'][0]['minivanNumber']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsMinivanNumberSet(): void {
      $turf = array_merge($this->baseTurf(), ['minivanNumber' => 99]);
      $result = $this->service->displayVoteBuilderTurfs([99 => $turf], FALSE);
      $this->assertSame(99, $result['contacts'][0]['minivanNumber']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsMinivanCreateDateFormattedAsDate(): void {
      $turf = array_merge($this->baseTurf(), ['minivanCreateDate' => '2024-10-15 00:00:00']);
      $result = $this->service->displayVoteBuilderTurfs([7 => $turf], FALSE);
      $this->assertSame('2024-10-15', $result['contacts'][0]['minivanCreateDate']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsEmptyMinivanCreateDateUsesEmptyString(): void {
      $result = $this->service->displayVoteBuilderTurfs([7 => $this->baseTurf()], FALSE);
      $this->assertSame('', $result['contacts'][0]['minivanCreateDate']['#markup']);
    }

    public function testDisplayVoteBuilderTurfsMultipleTurfsProducesMultipleRows(): void {
      $turfs = [7 => $this->baseTurf(), 8 => array_merge($this->baseTurf(), ['turfId' => 8])];
      $result = $this->service->displayVoteBuilderTurfs($turfs, FALSE);
      $intKeys = array_filter(array_keys($result['contacts']), 'is_int');
      $this->assertCount(2, $intKeys);
    }

    // -------------------------------------------------------------------------
    // validateTurfSelection
    // -------------------------------------------------------------------------

    public function testValidateTurfSelectionEmptyContactsReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->validateTurfSelection([]));
    }

    public function testValidateTurfSelectionCheckedContactReturnsSelection(): void {
      $contacts = [['cb' => 'CB_7_42']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertArrayHasKey('7', $result);
      $this->assertSame(7, $result['7']['turfId']);
      $this->assertSame(42, $result['7']['mcid']);
    }

    public function testValidateTurfSelectionUncheckedContactSkipped(): void {
      $contacts = [['cb' => ''], ['cb' => 'CB_7_42']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertCount(1, $result);
    }

    public function testValidateTurfSelectionMultipleCheckedReturnsAll(): void {
      $contacts = [['cb' => 'CB_7_42'], ['cb' => 'CB_8_99']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertArrayHasKey('7', $result);
      $this->assertArrayHasKey('8', $result);
    }

    public function testValidateTurfSelectionMcidWithLessThanStripped(): void {
      $contacts = [['cb' => 'CB_7_42<b>']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertSame(42, $result['7']['mcid']);
    }

    public function testValidateTurfSelectionMissingMcidPartDefaultsToZero(): void {
      $contacts = [['cb' => 'CB_7_']];
      $result = $this->service->validateTurfSelection($contacts);
      $this->assertSame(0, $result['7']['mcid']);
    }

    // -------------------------------------------------------------------------
    // getSortField
    // -------------------------------------------------------------------------

    public function testGetSortFieldReturnsTurfAssignedForA(): void {
      $this->assertSame('turfAssigned', $this->service->getSortField('A'));
    }

    public function testGetSortFieldReturnsNlForName(): void {
      $this->assertSame('nl', $this->service->getSortField('Previous NL'));
    }

    public function testGetSortFieldReturnsTurfNameForTurfName(): void {
      $this->assertSame('turfName', $this->service->getSortField('Turf Name'));
    }

    public function testGetSortFieldReturnsMapRegionNameForMapRegionName(): void {
      $this->assertSame('mapRegionName', $this->service->getSortField('Map Region Name'));
    }

    public function testGetSortFieldReturnsTurfIdForTurfID(): void {
      $this->assertSame('turfId', $this->service->getSortField('Turf ID'));
    }

    public function testGetSortFieldReturnsMcidForMCID(): void {
      $this->assertSame('mcid', $this->service->getSortField('MCID'));
    }

    public function testGetSortFieldReturnsEmptyStringForUnknownLabel(): void {
      $this->assertSame('', $this->service->getSortField('Unknown'));
    }

    public function testGetSortFieldReturnsEmptyStringForCbLabel(): void {
      $this->assertSame('', $this->service->getSortField('cb'));
    }

    // -------------------------------------------------------------------------
    // sortTurfs
    // -------------------------------------------------------------------------

    private function buildTurfs(): array {
      return [
        7 => ['turfAssigned' => 'Z', 'nl' => 'Zara', 'turfName' => 'Turf-C', 'mapRegionName' => 'Region Z', 'turfId' => 7, 'mcid' => 99],
        8 => ['turfAssigned' => 'A', 'nl' => 'Alice', 'turfName' => 'Turf-A', 'mapRegionName' => 'Region A', 'turfId' => 8, 'mcid' => 11],
        9 => ['turfAssigned' => 'M', 'nl' => 'Mike', 'turfName' => 'Turf-B', 'mapRegionName' => 'Region M', 'turfId' => 9, 'mcid' => 55],
      ];
    }

    public function testSortTurfsAscendingByNl(): void {
      $turfs = $this->buildTurfs();
      $this->service->sortTurfs($turfs, 'asc', 'Previous NL');
      $values = array_column($turfs, 'nl');
      $this->assertSame('Alice', $values[0]);
      $this->assertSame('Zara', $values[2]);
    }

    public function testSortTurfsDescendingByNl(): void {
      $turfs = $this->buildTurfs();
      $this->service->sortTurfs($turfs, 'desc', 'Previous NL');
      $values = array_column($turfs, 'nl');
      $this->assertSame('Zara', $values[0]);
      $this->assertSame('Alice', $values[2]);
    }

    public function testSortTurfsAscendingByTurfName(): void {
      $turfs = $this->buildTurfs();
      $this->service->sortTurfs($turfs, 'asc', 'Turf Name');
      $values = array_column($turfs, 'turfName');
      $this->assertSame('Turf-A', $values[0]);
      $this->assertSame('Turf-C', $values[2]);
    }

    public function testSortTurfsUnknownOrderDoesNotSort(): void {
      $turfs = $this->buildTurfs();
      $original = $turfs;
      $this->service->sortTurfs($turfs, 'asc', 'Unknown');
      $this->assertSame($original, $turfs);
    }

    public function testSortTurfsEmptyTurfsDoesNotError(): void {
      $turfs = [];
      $this->service->sortTurfs($turfs, 'asc', 'Name');
      $this->assertSame([], $turfs);
    }

    public function testSortTurfsPreservesKeysAfterSort(): void {
      $turfs = $this->buildTurfs();
      $this->service->sortTurfs($turfs, 'asc', 'Name');
      $this->assertArrayHasKey(8, $turfs);
    }

  }

}
