<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpNlsListDisplay;
  use Drupal\Tests\UnitTestCase;

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
      $this->service = new NlpNlsListDisplay($this->drupalUserMock, $this->coordinatorsMock, $this->nlsMock);
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

    public function testGetSortFieldReturnsExpectedField(): void {
      $this->assertSame('hd', $this->service->getSortField('HD', true));
      $this->assertSame('lastAccess', $this->service->getSortField('Last access', false));
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

  }

}
