<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\NlpCoordinatorsListDisplay;
  use Drupal\Tests\UnitTestCase;

  class NlpCoordinatorsListDisplayTest extends UnitTestCase {

    private NlpCoordinatorsListDisplay $service;

    protected function setUp(): void {
      parent::setUp();
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      $this->service = new NlpCoordinatorsListDisplay();
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseNlRecord(): array {
      return [
        'firstName'    => 'Jane',
        'lastName'     => 'Doe',
        'email'        => 'jane@example.com',
        'phone'        => '555-1234',
        'hd'           => 6,
        'cIndex'       => 5,
        'scope'        => 'county',
        'precinctList' => '6.1',
        'precinct'     => '6.1',
        'roles'        => ['NLP Coordinator'],
      ];
    }

    // -------------------------------------------------------------------------
    // buildCoordinatorTableArray
    // -------------------------------------------------------------------------

    public function testBuildCoordinatorTableArrayCurrentTypeKeyedByCIndex(): void {
      $result = $this->service->buildCoordinatorTableArray('current', [42 => $this->baseNlRecord()]);
      $this->assertArrayHasKey(5, $result);
    }

    public function testBuildCoordinatorTableArrayCurrentTypeIncludesCIndexInCb(): void {
      $result = $this->service->buildCoordinatorTableArray('current', [42 => $this->baseNlRecord()]);
      $this->assertSame(5, $result[5]['cb']['cIndex']);
    }

    public function testBuildCoordinatorTableArrayCurrentTypeIncludesAssignedFields(): void {
      $result = $this->service->buildCoordinatorTableArray('current', [42 => $this->baseNlRecord()]);
      $row = $result[5];
      $this->assertSame('county', $row['assignedScope']);
      $this->assertSame(6, $row['assignedHd']);
      $this->assertSame('6.1', $row['assignedPrecinctList']);
    }

    public function testBuildCoordinatorTableArrayAddTypeKeyedByMcid(): void {
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $this->baseNlRecord()]);
      $this->assertArrayHasKey(42, $result);
    }

    public function testBuildCoordinatorTableArrayAddTypeIncludesPrecinct(): void {
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $this->baseNlRecord()]);
      $this->assertSame('6.1', $result[42]['precinct']);
    }

    public function testBuildCoordinatorTableArrayAddTypeDoesNotIncludeAssignedFields(): void {
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $this->baseNlRecord()]);
      $this->assertArrayNotHasKey('assignedScope', $result[42]);
    }

    public function testBuildCoordinatorTableArrayOtherTypeKeyedByMcid(): void {
      $result = $this->service->buildCoordinatorTableArray('other', [42 => $this->baseNlRecord()]);
      $this->assertArrayHasKey(42, $result);
    }

    public function testBuildCoordinatorTableArrayBuildsFullName(): void {
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $this->baseNlRecord()]);
      $this->assertSame('Jane Doe', $result[42]['name']);
    }

    public function testBuildCoordinatorTableArrayRolesImplodedWhenPresent(): void {
      $record = array_merge($this->baseNlRecord(), ['roles' => ['NLP Admin', 'NLP Coordinator']]);
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $record]);
      $this->assertSame('NLP Admin, NLP Coordinator', $result[42]['roles']);
    }

    public function testBuildCoordinatorTableArrayRolesEmptyStringWhenMissing(): void {
      $record = array_merge($this->baseNlRecord(), ['roles' => []]);
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $record]);
      $this->assertSame('', $result[42]['roles']);
    }

    public function testBuildCoordinatorTableArrayMcidStoredInCb(): void {
      $result = $this->service->buildCoordinatorTableArray('add', [42 => $this->baseNlRecord()]);
      $this->assertSame(42, $result[42]['cb']['mcid']);
    }

    public function testBuildCoordinatorTableArrayEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->buildCoordinatorTableArray('add', []));
    }

    // -------------------------------------------------------------------------
    // buildCoordinatorsTable
    // -------------------------------------------------------------------------

    private function buildCurrentRows(): array {
      return [
        5 => [
          'cb'   => ['type' => 'cd', 'mcid' => 42, 'cIndex' => 5],
          'name' => 'Jane Doe',
        ],
      ];
    }

    private function buildContactRows(): array {
      return [
        42 => [
          'cb'   => ['type' => 'cd', 'mcid' => 42],
          'name' => 'Jane Doe',
        ],
      ];
    }

    public function testBuildCoordinatorsTableCurrentTypeUsesDisplayTableName(): void {
      $result = $this->service->buildCoordinatorsTable('current', $this->buildCurrentRows());
      $this->assertArrayHasKey('display', $result);
    }

    public function testBuildCoordinatorsTableNonCurrentTypeUsesContactsTableName(): void {
      $result = $this->service->buildCoordinatorsTable('add', $this->buildContactRows());
      $this->assertArrayHasKey('contacts', $result);
    }

    public function testBuildCoordinatorsTableCurrentTypeCbReturnValueUsesCIndex(): void {
      $result = $this->service->buildCoordinatorsTable('current', $this->buildCurrentRows());
      $returnValue = $result['display'][5]['cb']['#return_value'];
      $this->assertSame('CB_5_42', $returnValue);
    }

    public function testBuildCoordinatorsTableNonCurrentTypeCbReturnValueUsesCheckbox(): void {
      $result = $this->service->buildCoordinatorsTable('add', $this->buildContactRows());
      $returnValue = $result['contacts'][42]['cb']['#return_value'];
      $this->assertSame('CB_checkbox_42', $returnValue);
    }

    public function testBuildCoordinatorsTableNonCbColumnUsesMarkup(): void {
      $result = $this->service->buildCoordinatorsTable('add', $this->buildContactRows());
      $this->assertSame('Jane Doe', $result['contacts'][42]['name']['#markup']);
    }

    public function testBuildCoordinatorsTableCbColumnIsCheckboxType(): void {
      $result = $this->service->buildCoordinatorsTable('add', $this->buildContactRows());
      $this->assertSame('checkbox', $result['contacts'][42]['cb']['#type']);
    }

    public function testBuildCoordinatorsTableHeaderIsSet(): void {
      $result = $this->service->buildCoordinatorsTable('add', []);
      $this->assertArrayHasKey('#header', $result['contacts']);
      $this->assertNotEmpty($result['contacts']['#header']);
    }

    // -------------------------------------------------------------------------
    // validateCoordinatorSelection
    // -------------------------------------------------------------------------

    public function testValidateCoordinatorSelectionEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->validateCoordinatorSelection([]));
    }

    public function testValidateCoordinatorSelectionCheckedContactReturnsSelection(): void {
      $contacts = [['cb' => 'CB_5_42']];
      $result = $this->service->validateCoordinatorSelection($contacts);
      $this->assertArrayHasKey('42', $result);
      $this->assertSame('5', $result['42']['cIndex']);
      $this->assertSame('42', $result['42']['mcid']);
    }

    public function testValidateCoordinatorSelectionUncheckedContactSkipped(): void {
      $contacts = [['cb' => ''], ['cb' => 'CB_5_42']];
      $result = $this->service->validateCoordinatorSelection($contacts);
      $this->assertCount(1, $result);
    }

    public function testValidateCoordinatorSelectionMultipleCheckedReturnsAll(): void {
      $contacts = [['cb' => 'CB_5_42'], ['cb' => 'CB_7_99']];
      $result = $this->service->validateCoordinatorSelection($contacts);
      $this->assertArrayHasKey('42', $result);
      $this->assertArrayHasKey('99', $result);
    }

    // -------------------------------------------------------------------------
    // getSortField
    // -------------------------------------------------------------------------

    public function testGetSortFieldReturnsFieldForKnownAddHeader(): void {
      $field = $this->service->getSortField('Precinct', 'add');
      $this->assertSame('n.precinct', $field);
    }

    public function testGetSortFieldReturnsFieldForCommonHeader(): void {
      $field = $this->service->getSortField('Name', 'add');
      $this->assertSame('n.name', $field);
    }

    public function testGetSortFieldReturnsFieldForCurrentHeader(): void {
      $field = $this->service->getSortField('Assigned<br>Scope', 'current');
      $this->assertSame('n.assignedScope', $field);
    }

    public function testGetSortFieldReturnsEmptyStringForUnknownLabel(): void {
      $this->assertSame('', $this->service->getSortField('Unknown', 'add'));
    }

    public function testGetSortFieldCurrentHeaderNotAvailableForAddType(): void {
      $this->assertSame('', $this->service->getSortField('Assigned<br>Scope', 'add'));
    }

    // -------------------------------------------------------------------------
    // sortNlsTable
    // -------------------------------------------------------------------------

    private function buildNlsTable(): array {
      return [
        ['name' => 'Zara'],
        ['name' => 'Alice'],
        ['name' => 'Mike'],
      ];
    }

    public function testSortNlsTableSortsAscending(): void {
      $table = $this->buildNlsTable();
      $this->service->sortNlsTable($table, 'asc', 'Name', 'add');
      $this->assertSame('Alice', $table[0]['name']);
      $this->assertSame('Zara', $table[2]['name']);
    }

    public function testSortNlsTableSortsDescending(): void {
      $table = $this->buildNlsTable();
      $this->service->sortNlsTable($table, 'desc', 'Name', 'add');
      $this->assertSame('Zara', $table[0]['name']);
      $this->assertSame('Alice', $table[2]['name']);
    }

    public function testSortNlsTableDoesNothingForUnknownOrder(): void {
      $table = $this->buildNlsTable();
      $original = $table;
      $this->service->sortNlsTable($table, 'asc', 'Unknown', 'add');
      $this->assertSame($original, $table);
    }

    public function testSortNlsTableDoesNothingWhenSortFieldPrefixIsNotN(): void {
      $table = $this->buildNlsTable();
      $original = $table;
      $this->service->sortNlsTable($table, 'asc', 'cb', 'add');
      $this->assertSame($original, $table);
    }

    public function testSortNlsTableEmptyTableDoesNotError(): void {
      $table = [];
      $this->service->sortNlsTable($table, 'asc', 'Name', 'add');
      $this->assertSame([], $table);
    }

  }

}
