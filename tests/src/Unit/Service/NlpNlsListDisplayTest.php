<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpNlsListDisplay;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\DataProvider;
  use Drupal\Core\Routing\UrlGeneratorInterface;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class NlpNlsListDisplayTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private NlpCoordinators $coordinatorsMock;
    private NlpNls $nlsMock;
    private NlpNlsListDisplay $service;

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->coordinatorsMock = $this->createMock(NlpCoordinators::class);
      $this->nlsMock = $this->createMock(NlpNls::class);
      $this->nlsMock->askOptions = [
        'dash' => '-',
        'asked' => 'Asked',
        'yes' => 'Yes',
      ];
      $this->nlsMock->contactList = ['canvass', 'minivan', 'phone', 'mail'];
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      $this->service = new NlpNlsListDisplay(
        $this->drupalUserMock, $this->coordinatorsMock, $this->nlsMock, new RequestStack(),
      );
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function baseNlRecord(array $overrides = []): array {
      return array_replace_recursive([
        'mcid' => 42,
        'hd' => 6,
        'precinct' => '6.1',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'address' => '123 Main St',
        'email' => 'alice@example.com',
        'phone' => '555-1212',
        'vfid' => 'VF-42',
        'status' => [
          'notes' => 'Test note',
          'asked' => 'Asked',
          'turfCut' => 'Y',
          'turfDelivered' => 'N',
          'contact' => 'phone',
        ],
        'progress' => [
          'attempts' => 5,
          'contacts' => 3,
          'voterCount' => 10,
          'doorCount' => 7,
        ],
      ], $overrides);
    }

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('nlpservices.drupal_user', $this->drupalUserMock);
      $container->set('nlpservices.coordinators', $this->coordinatorsMock);
      $container->set('nlpservices.nls', $this->nlsMock);
      $container->set('request_stack', new RequestStack());

      $service = NlpNlsListDisplay::create($container);

      $this->assertInstanceOf(NlpNlsListDisplay::class, $service);
    }

    public function testCreateTableArrayBriefIncludesCheckboxAndName(): void {
      $record = $this->baseNlRecord();
      $this->coordinatorsMock->method('getAssignedCoordinator')
        ->willReturn(['firstName' => 'Jane', 'lastName' => 'Doe']);

      $result = $this->service->createTableArray([42 => $record], true);

      $this->assertArrayHasKey(42, $result);
      $this->assertSame('cb', $result[42]['cb']['type']);
      $this->assertStringContainsString('Smith', $result[42]['name']);
      $this->assertStringContainsString('(Doe,&nbsp;Jane)', $result[42]['name']);
    }

    public function testCreateTableArrayShowsUnassignedCoordinatorWhenMissing(): void {
      $record = $this->baseNlRecord();
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);

      $result = $this->service->createTableArray([42 => $record], true);

      $this->assertStringContainsString('Coordinator&nbsp;not&nbsp;assigned', $result[42]['name']);
    }

    public function testCreateTableArrayFullIncludesStatusAndProgressFields(): void {
      $record = $this->baseNlRecord();
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['access' => 1700000000]);

      $result = $this->service->createTableArray([42 => $record], false);

      $this->assertSame('ta', $result[42]['notes']['type']);
      $this->assertSame('se', $result[42]['nl']['type']);
      $this->assertSame(1, $result[42]['tc']['default']);
      $this->assertSame(0, $result[42]['td']['default']);
      $this->assertSame('se', $result[42]['co']['type']);
      $this->assertSame('10<br>7', $result[42]['voterCount']);
      $this->assertSame('5<br>3', $result[42]['attempts']);
    }

    public function testCreateTableArrayFullUsesNeverWhenNoUserAccess(): void {
      $record = $this->baseNlRecord();
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      $result = $this->service->createTableArray([42 => $record], false);

      $this->assertSame('never', $result[42]['lastAccess']);
    }

    /**
     * @dataProvider highlightProvider
     */
    #[DataProvider('highlightProvider')]
    public function testCreateTableArrayHighlightsByNlStatus(string $asked, string $turfCut, int $attempts, string $expected): void {
      $record = $this->baseNlRecord([
        'status' => ['asked' => $asked, 'turfCut' => $turfCut],
        'progress' => ['attempts' => $attempts],
      ]);
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      $result = $this->service->createTableArray([42 => $record], false);

      $this->assertSame($expected, $result[42]['highlight']);
    }

    public static function highlightProvider(): array {
      return [
        'dropout wins over reports' => [NlpNls::QUIT, 'Y', 5, 'nlp-nl-inactive'],
        'not this election' => [NlpNls::NO, 'N', 0, 'nlp-nl-inactive'],
        'reports win over turf cut' => ['Yes', 'Y', 5, 'nlp-nl-reported'],
        'reports without turf cut' => ['Yes', 'N', 2, 'nlp-nl-reported'],
        'turf cut only' => ['Yes', 'Y', 0, 'nlp-nl-turf-cut'],
        'nothing yet' => ['Asked', 'N', 0, ''],
      ];
    }

    public function testCreateTableArrayBriefHasNoHighlight(): void {
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);

      $result = $this->service->createTableArray([42 => $this->baseNlRecord()], true);

      $this->assertArrayNotHasKey('highlight', $result[42]);
    }

    public function testBuildNlsTableColorsOnlyNameAddressAndEmailCells(): void {
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $requestStack = new RequestStack();
      $requestStack->push(Request::create('/active-nls'));
      $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
      $urlGenerator->method('generateFromRoute')->willReturn('/active-nls');
      \Drupal::getContainer()->set('url_generator', $urlGenerator);
      $service = new NlpNlsListDisplay(
        $this->drupalUserMock, $this->coordinatorsMock, $this->nlsMock, $requestStack,
      );
      $rows = $service->createTableArray([42 => $this->baseNlRecord()], false);

      $result = $service->buildNlsTable($rows);

      $row = $result['contacts'][42];
      foreach (['name-0', 'address-0', 'email-0'] as $cell) {
        $this->assertSame(['class' => ['nlp-nl-reported']], $row[$cell]['#wrapper_attributes']);
      }
      $this->assertArrayNotHasKey('#wrapper_attributes', $row['hd-0']);
      $this->assertArrayNotHasKey('highlight-0', $row);
    }

    public function testValidateNlSelectionReturnsSelectedRows(): void {
      $contacts = [
        ['cb-42' => 'CB_1_42', 'name-42' => 'Alice'],
        ['cb-99' => '', 'name-99' => 'Bob'],
        ['cb-77' => 'CB_3_77'],
      ];

      $result = $this->service->validateNlSelection($contacts);

      $this->assertArrayHasKey('1', $result);
      $this->assertArrayHasKey('3', $result);
      $this->assertSame('42', $result['1']['mcid']);
      $this->assertSame('77', $result['3']['mcid']);
    }

    /** Builds a brief (selection) table with sort links stubbed out. */
    private function buildBriefNlsTable(string $type): array {
      $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
      $urlGenerator->method('generateFromRoute')->willReturn('/sync-turf');
      \Drupal::getContainer()->set('url_generator', $urlGenerator);
      $requestStack = new RequestStack();
      $requestStack->push(Request::create('/sync-turf'));
      $service = new NlpNlsListDisplay(
        $this->drupalUserMock, $this->coordinatorsMock, $this->nlsMock, $requestStack,
      );
      $rows = $service->createTableArray([42 => $this->baseNlRecord(), 77 => $this->baseNlRecord(['mcid' => 77])], true);
      return $service->buildNlsTable($rows, true, $type);
    }

    public function testBuildNlsTableBriefSelectionColumnIsRadio(): void {
      $result = $this->buildBriefNlsTable('search');

      $this->assertSame('radio', $result['search'][42]['cb-42']['#type']);
      $this->assertSame('CB_42_42', $result['search'][42]['cb-42']['#return_value']);
    }

    public function testBuildNlsTableBriefRadiosShareOneGroup(): void {
      $result = $this->buildBriefNlsTable('nlsForm');

      $this->assertSame(['nlsForm', 'selected', 'cb'], $result['nlsForm'][42]['cb-42']['#parents']);
      $this->assertSame($result['nlsForm'][42]['cb-42']['#parents'], $result['nlsForm'][77]['cb-77']['#parents']);
    }

    public function testBuildNlsTableHdHeaderDoesNotWrap(): void {
      $result = $this->buildBriefNlsTable('search');

      $hdHeaders = array_filter($result['search']['#header'],
        fn($cell) => str_contains((string) $cell['data'], '>HD<'));
      $this->assertCount(1, $hdHeaders);
      $this->assertContains('nlp-th-nowrap', reset($hdHeaders)['class']);
    }

    public function testFullTableTcAndTdHeadersHaveHoverNotes(): void {
      $header = (new \ReflectionClass($this->service))->getMethod('headerBuild');
      $header->invoke($this->service, false);
      $cells = array_map(fn($cell) => (string) $cell['data'],
        (new \ReflectionProperty($this->service, 'header'))->getValue($this->service));

      $tc = current(array_filter($cells, fn($data) => str_contains($data, '<p>TC<span')));
      $td = current(array_filter($cells, fn($data) => str_contains($data, '<p>TD<span')));
      $this->assertStringContainsString('class="hint_note', $tc);
      $this->assertStringContainsString('<span class="hint-list">The turf is cut and synced with NLP Services.</span>', $tc);
      $this->assertStringContainsString('<span class="hint-list">The turf was delivered to the NL either by email or in person.</span>', $td);
    }

    public function testValidateNlSelectionReadsRadioGroupValue(): void {
      $result = $this->service->validateNlSelection(['selected' => ['cb' => 'CB_42_42']]);

      $this->assertSame(['42' => ['rowCount' => '42', 'mcid' => '42']], $result);
    }

    public function testValidateNlSelectionReturnsEmptyForEmptyTable(): void {
      // A table with no rows comes back from the form API as an empty string.
      $this->assertSame([], $this->service->validateNlSelection(''));
      $this->assertSame([], $this->service->validateNlSelection(NULL));
    }

    public function testGetSortFieldReturnsExpectedField(): void {
      $this->assertSame('hd', $this->service->getSortField('HD', true));
      $this->assertSame('lastAccess', $this->service->getSortField('Last access', false));
    }

    public function testGetSortFieldPrecinctLabelIsPrecinctInBothTables(): void {
      // Both the brief (Sync Turf selection) and full (Active NLs) tables say "Precinct".
      foreach ([true, false] as $brief) {
        $this->assertSame('precinct', $this->service->getSortField('Precinct', $brief));
        $this->assertSame('', $this->service->getSortField('Pct', $brief));
      }
    }

    public function testGetSortFieldUnknownReturnsEmptyString(): void {
      $this->assertSame('', $this->service->getSortField('Unknown Header', false));
    }

    public function testSortNlsTableSortsAscendingBySelectedColumn(): void {
      $table = [
        ['hd' => 8, 'name' => 'Z'],
        ['hd' => 6, 'name' => 'A'],
      ];

      $this->service->sortNlsTable($table, 'asc', 'HD', false);

      $this->assertSame(6, $table[0]['hd']);
      $this->assertSame(8, $table[1]['hd']);
    }

    public function testSortNlsTableSortsDescendingBySelectedColumn(): void {
      $table = [
        ['name' => 'Alice'],
        ['name' => 'Zara'],
      ];

      $this->service->sortNlsTable($table, 'desc', 'Name<br>MCID<br>(Coordinator)', false);

      $this->assertSame('Zara', $table[0]['name']);
      $this->assertSame('Alice', $table[1]['name']);
    }

    public function testSortNlsTableAcceptsFirstLineOfLabel(): void {
      $table = [
        ['name' => 'Zara'],
        ['name' => 'Alice'],
      ];

      $this->service->sortNlsTable($table, 'asc', 'Name', true);

      $this->assertSame('Alice', $table[0]['name']);
      $this->assertSame('Zara', $table[1]['name']);
    }

    public function testSortNlsTableUnknownOrderLeavesTableUnsorted(): void {
      $table = [
        ['name' => 'Zara'],
        ['name' => 'Alice'],
      ];

      $this->service->sortNlsTable($table, 'asc', 'Unknown Header', true);

      $this->assertSame('Zara', $table[0]['name']);
      $this->assertSame('Alice', $table[1]['name']);
    }

    public function testSortNlsTableRowMissingColumnLeavesTableUnsorted(): void {
      $table = [
        ['name' => 'Zara'],
        ['hd' => 6],
      ];

      $this->service->sortNlsTable($table, 'asc', 'Name', true);

      $this->assertSame('Zara', $table[0]['name']);
      $this->assertSame(6, $table[1]['hd']);
    }

  }

}
