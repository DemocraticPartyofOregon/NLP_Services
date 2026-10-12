<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom::class)]
#[CoversMethod(\Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\RemoveCoordinatorRoleFrom::class, 'submitForm')]
#[Group('nlpservices')]
class RemoveCoordinatorRoleFromTest extends UnitTestCase {

  private NlpCoordinators $coordinatorsMock;
  private NlpNls $nlsMock;
  private DrupalUser $drupalUserMock;
  private MagicWord $magicWordMock;
  private NlpCoordinatorsListDisplay $coordinatorsListDisplayMock;
  private NlpSessionData $sessionDataMock;
  private NlpSearchBar $searchBarMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->coordinatorsMock           = $this->createMock(NlpCoordinators::class);
    $this->nlsMock                    = $this->createMock(NlpNls::class);
    $this->drupalUserMock             = $this->createMock(DrupalUser::class);
    $this->magicWordMock              = $this->createMock(MagicWord::class);
    $this->coordinatorsListDisplayMock = $this->createMock(NlpCoordinatorsListDisplay::class);
    $this->sessionDataMock            = $this->createMock(NlpSessionData::class);
    $this->searchBarMock              = $this->createMock(NlpSearchBar::class);
    $this->messengerMock              = $this->createMock(MessengerInterface::class);

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

  private function buildFormInstance(): RemoveCoordinatorRoleFrom {
    $form = new RemoveCoordinatorRoleFrom(
      $this->coordinatorsMock,
      $this->nlsMock,
      $this->drupalUserMock,
      $this->magicWordMock,
      $this->coordinatorsListDisplayMock,
      $this->sessionDataMock,
      $this->searchBarMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function configureStandardBuildMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->nlsMock->method('getHdList')->willReturn(['1', '2']);
    $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '1', '2']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->drupalUserMock->method('getCoordinators')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
  }

  private function baseCoordinator(int $mcid, string $hd = '1'): array {
    return [
      'mcid'      => $mcid,
      'firstName' => 'Test',
      'lastName'  => 'Coordinator',
      'hd'        => $hd,
    ];
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame(
      'nlpservices_remove_coordinator_role_form',
      $this->buildFormInstance()->getFormId()
    );
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormSetsReenterAndScopeOnFirstRender(): void {
    $this->configureStandardBuildMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame('unknown', $formState->get('scope'));
  }

  public function testBuildFormSetsCountyFromSessionOnFirstRender(): void {
    $this->configureStandardBuildMocks('Denver');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('Denver', $formState->get('county'));
  }

  public function testBuildFormAddsWarningAndReturnsEmptyWhenCountyIsEmpty(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  public function testBuildFormSkipsInitialisationWhenReenterAlreadySet(): void {
    $this->sessionDataMock->expects($this->never())->method('getCounty');
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->drupalUserMock->method('getCoordinators')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormContainsExpectedFormElements(): void {
    $this->configureStandardBuildMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('options_display', $form);
    $this->assertArrayHasKey('manageCoordinators', $form);
    $this->assertArrayHasKey('submit', $form);
  }

  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureStandardBuildMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $libs = $form['#attached']['library'];
    $this->assertContains('nlpservices/nlp-table', $libs);
    $this->assertContains('nlpservices/info-boxes', $libs);
    $this->assertContains('nlpservices/search-boxes', $libs);
  }

  public function testBuildFormFiltersCoordinatorsByHdWhenNotAll(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getHdList')->willReturn(['1', '2']);
    $this->sessionDataMock->method('getHdOptions')->willReturn(['All', '1', '2']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(1);
    $this->sessionDataMock->method('getSavedHd')->willReturn(1);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);

    $coordinators = [
      10 => $this->baseCoordinator(10, '1'),
      20 => $this->baseCoordinator(20, '2'),
    ];
    $this->drupalUserMock->method('getCoordinators')->willReturn($coordinators);

    $capturedTable = NULL;
    $this->coordinatorsListDisplayMock->method('buildCoordinatorTableArray')
      ->willReturnCallback(function ($mode, $coords) use (&$capturedTable) {
        $capturedTable = $coords;
        return [];
      });
    $this->coordinatorsListDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey(10, $capturedTable);
    $this->assertArrayNotHasKey(20, $capturedTable);
  }

  public function testBuildFormExcludesAssignedCoordinatorsFromUnassignedList(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn(['All']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);

    // mcid 10 is formally assigned, mcid 20 is not.
    $this->coordinatorsMock->method('getCoordinators')
      ->willReturn([['mcid' => 10, 'firstName' => 'Assigned', 'lastName' => 'Coord', 'hd' => '1']]);
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

    $coordinators = [
      10 => $this->baseCoordinator(10),
      20 => $this->baseCoordinator(20),
    ];
    $this->drupalUserMock->method('getCoordinators')->willReturn($coordinators);

    $capturedTable = NULL;
    $this->coordinatorsListDisplayMock->method('buildCoordinatorTableArray')
      ->willReturnCallback(function ($mode, $coords) use (&$capturedTable) {
        $capturedTable = $coords;
        return [];
      });
    $this->coordinatorsListDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayNotHasKey(10, $capturedTable);
    $this->assertArrayHasKey(20, $capturedTable);
  }

  public function testBuildFormFiltersUnassignedCoordinatorsBySearchNeedleAndClearsNeedle(): void {
    $this->configureStandardBuildMocks();

    $unassigned = [20 => $this->baseCoordinator(20)];
    $filtered   = [20 => $this->baseCoordinator(20)];

    $this->searchBarMock->expects($this->once())
      ->method('searchInfoArray')
      ->with('Test', $this->anything())
      ->willReturn($filtered);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('search-needle', 'Test');

    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertNull($formState->get('search-needle'));
  }

  public function testBuildFormEnrichesAssignedCoordinatorsWithDrupalUserData(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn(['All']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);

    $this->coordinatorsMock->method('getCoordinators')
      ->willReturn([['mcid' => 10, 'firstName' => 'Coord', 'lastName' => 'One', 'hd' => '1']]);
    $this->drupalUserMock->expects($this->atLeastOnce())
      ->method('getUserByMcid')
      ->with(10)
      ->willReturn(['uid' => 99, 'roles' => ['nlp_coordinator' => 'nlp_coordinator']]);
    $this->drupalUserMock->method('getCoordinators')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->coordinatorsListDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $this->buildFormInstance()->buildForm([], new FormState());
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFormHdSelectSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(3);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
    $formState->method('getValue')->with('hd-select')->willReturn(3);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormUserSelectSetsErrorWhenNoSelections(): void {
    $this->coordinatorsListDisplayMock->method('validateCoordinatorSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'user_select']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->expects($this->once())->method('setErrorByName')
      ->with('user_select', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormUserSelectStoresSelectionsWhenValid(): void {
    $selections = [['mcid' => 10]];
    $this->coordinatorsListDisplayMock->method('validateCoordinatorSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'user_select']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['selections']);
  }

  public function testValidateFormUserSelectDoesNotSetErrorWhenSelectionsExist(): void {
    $this->coordinatorsListDisplayMock->method('validateCoordinatorSelection')
      ->willReturn([['mcid' => 10]]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'user_select']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->expects($this->never())->method('setErrorByName');
    $formState->method('set')->willReturn(NULL);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormSetsReenterAndCallsSetRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->setValue('search-needle', '');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  public function testSubmitFormHdChangeSavesNewHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);

    $formState = new FormState();
    $formState->setValue('hd-change', 2);
    $formState->setTriggeringElement(['#name' => 'hd-change']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormSearchSubmitStoresNeedle(): void {
    $formState = new FormState();
    $formState->setValue('search-needle', 'Smith');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Smith', $formState->get('search-needle'));
  }

  public function testSubmitFormUserSelectCallsRemoveCoordinatorRoleForEachSelection(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturnCallback(
      fn($mcid) => ['uid' => $mcid * 10]
    );
    $this->magicWordMock->expects($this->exactly(2))
      ->method('removeCoordinatorRole')
      ->with($this->logicalOr(10, 20));
    $this->drupalUserMock->method('removeNlpRoles')->willReturn(TRUE);

    $formState = new FormState();
    $formState->set('selections', [['mcid' => 10], ['mcid' => 20]]);
    $formState->setTriggeringElement(['#name' => 'user_select']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormUserSelectCallsRemoveNlpRolesWithCoordinatorRoleId(): void {
    $this->drupalUserMock->method('getUserByMcid')
      ->with(10)
      ->willReturn(['uid' => 99]);
    $this->magicWordMock->method('removeCoordinatorRole');

    $this->drupalUserMock->expects($this->once())
      ->method('removeNlpRoles')
      ->with(99, [NLP_COORDINATOR_ROLE_ID]);

    $formState = new FormState();
    $formState->set('selections', [['mcid' => 10]]);
    $formState->setTriggeringElement(['#name' => 'user_select']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormUserSelectProcessesAllSelectionsInOrder(): void {
    $processedMcids = [];
    $this->drupalUserMock->method('getUserByMcid')
      ->willReturnCallback(fn($mcid) => ['uid' => $mcid * 10]);
    $this->magicWordMock->method('removeCoordinatorRole')
      ->willReturnCallback(function ($mcid) use (&$processedMcids): void {
        $processedMcids[] = $mcid;
      });
    $this->drupalUserMock->method('removeNlpRoles')->willReturn(TRUE);

    $formState = new FormState();
    $formState->set('selections', [['mcid' => 5], ['mcid' => 7], ['mcid' => 9]]);
    $formState->setTriggeringElement(['#name' => 'user_select']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame([5, 7, 9], $processedMcids);
  }

}
