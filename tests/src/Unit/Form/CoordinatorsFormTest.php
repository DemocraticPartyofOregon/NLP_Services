<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\CoordinatorsForm;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\CoordinatorsForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\CoordinatorsForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\CoordinatorsForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\CoordinatorsForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\CoordinatorsForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\CoordinatorsForm::class, 'createCoordinatorAccount')]
#[Group('nlpservices')]
class CoordinatorsFormTest extends UnitTestCase {

  private NlpCoordinators $coordinatorsMock;
  private NlpNls $nlsMock;
  private DrupalUser $drupalUserMock;
  private MagicWord $magicWordMock;
  private NlpCoordinatorsListDisplay $listDisplayMock;
  private NlpSessionData $sessionDataMock;
  private NlpSearchBar $searchBarMock;
  private MessengerInterface $messengerMock;
  private object $emailValidatorMock;

  protected function setUp(): void {
    parent::setUp();

    $this->coordinatorsMock     = $this->createMock(NlpCoordinators::class);
    $this->nlsMock              = $this->createMock(NlpNls::class);
    $this->drupalUserMock       = $this->createMock(DrupalUser::class);
    $this->magicWordMock        = $this->createMock(MagicWord::class);
    $this->listDisplayMock      = $this->createMock(NlpCoordinatorsListDisplay::class);
    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->searchBarMock        = $this->createMock(NlpSearchBar::class);
    $this->messengerMock        = $this->createMock(MessengerInterface::class);

    $this->emailValidatorMock = $this->createMock(EmailValidatorInterface::class);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('email.validator', $this->emailValidatorMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): CoordinatorsForm {
    $form = new CoordinatorsForm(
      $this->coordinatorsMock,
      $this->nlsMock,
      $this->drupalUserMock,
      $this->magicWordMock,
      $this->listDisplayMock,
      $this->sessionDataMock,
      $this->searchBarMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Configure all mocks needed for a successful buildForm call.
   * Only use when reenter is NOT already set, so that the full first-render
   * code path executes.
   */
  private function configureBuildFormMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn(['HD1', 'HD2']);
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->nlsMock->method('getCountyNls')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All', 1 => 'HD1']);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
  }

  /**
   * Configure all mocks needed when buildForm is called with reenter already set.
   * Skips the county/NL initialisation mocks that only run on first render.
   */
  private function configureBuildFormReenterMocks(string $county = 'TestCounty'): void {
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->nlsMock->method('getCountyNls')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All', 1 => 'HD1']);
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
  }

  private function baseNlRecord(): array {
    return [
      'mcid'      => 42,
      'firstName' => 'Alice',
      'nickname'  => 'Ali',
      'lastName'  => 'Smith',
      'email'     => 'ali@example.com',
      'phone'     => '555-1234',
      'county'    => 'TestCounty',
      'hd'        => 'HD1',
    ];
  }

  private function baseCoordinatorArg(): array {
    return [
      'mcid'      => 42,
      'email'     => 'ali@example.com',
      'firstName' => 'Ali',
      'lastName'  => 'Smith',
      'phone'     => '555-1234',
      'county'    => 'TestCounty',
      'hd'        => 'HD1',
    ];
  }

  /**
   * Build a FormStateInterface mock for add_coordinator_assignment validate tests.
   * The triggering element, contacts getValue, county, and scope are pre-wired.
   * altEmail defaults to '' so isValid is never called unless overridden.
   * Extra value map entries are placed FIRST so they take precedence over defaults
   * in willReturnMap (first matching entry wins in PHPUnit 9).
   */
  private function addCoordinatorFormState(
    string $scope,
    array  $extraValueMap = [],
    array  $extraGetMap   = [],
  ): FormStateInterface {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')
      ->willReturn(['#name' => 'add_coordinator_assignment']);
    $valueMap = array_merge(
      $extraValueMap,
      [['contacts', NULL, []], ['altEmail', NULL, '']],
    );
    $formState->method('getValue')->willReturnMap($valueMap);
    $getMap = array_merge(
      [['county', 'TestCounty'], ['scope', $scope]],
      $extraGetMap,
    );
    $formState->method('get')->willReturnMap($getMap);
    return $formState;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_coordinators_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormReturnsEmptyArrayAndAddsWarningWhenCountyMissing(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  public function testBuildFormContainsExpectedKeysWhenCountyIsSet(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('help', $form);
    $this->assertArrayHasKey('help1', $form);
    $this->assertArrayHasKey('manageCoordinators', $form);
  }

  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $libraries = $form['#attached']['library'];
    $this->assertContains('nlpservices/info-boxes', $libraries);
    $this->assertContains('nlpservices/nlp-table', $libraries);
    $this->assertContains('nlpservices/search-boxes', $libraries);
  }

  public function testBuildFormSetsReenterScopeAndCountyOnFirstCall(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame('unknown', $formState->get('scope'));
    $this->assertSame('TestCounty', $formState->get('county'));
  }

  public function testBuildFormSkipsNlInitialisationWhenReenterAlreadySet(): void {
    $this->configureBuildFormReenterMocks();
    $this->nlsMock->expects($this->never())->method('getNls');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('scope', 'unknown');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormEnrichesAssignedCoordinatorsWithRolesAndUid(): void {
    // Set up mocks directly (not via configureBuildFormMocks) so that the
    // coordinators stub below is the only one registered for that method.
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->nlsMock->method('getCountyNls')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $this->coordinatorsMock->method('getCoordinators')->willReturn([
      ['mcid' => 10, 'firstName' => 'Bob'],
      ['mcid' => 11, 'firstName' => 'Carol'],
    ]);
    $this->drupalUserMock->method('getUserByMcid')->willReturnCallback(
      static fn($mcid) => ($mcid === 10)
        ? ['uid' => 99, 'roles' => [NLP_COORDINATOR_ROLE_ID]]
        : []
    );

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $assigned = $formState->get('assignedCoordinators');
    $this->assertSame(99, $assigned[0]['uid']);
    $this->assertSame([NLP_COORDINATOR_ROLE_ID], $assigned[0]['roles']);
    $this->assertSame('', $assigned[1]['uid']);
    $this->assertSame([], $assigned[1]['roles']);
  }

  public function testBuildFormFiltersAvailableNlsByNeedleWhenNeedleIsSet(): void {
    // Set up mocks directly (not via a helper) so that getCountyNls is only
    // configured once, allowing willReturnOnConsecutiveCalls to work correctly.
    $allNls   = [1 => ['mcid' => 1, 'firstName' => 'Smith']];
    $filtered = [1 => ['mcid' => 1, 'firstName' => 'Smith']];

    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    // First call returns the HD-filtered display list; second returns all NLs for search.
    $this->nlsMock->method('getCountyNls')->willReturnOnConsecutiveCalls([], $allNls);
    $this->searchBarMock->expects($this->once())
      ->method('searchInfoArray')
      ->with('Smith', $allNls)
      ->willReturn($filtered);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('scope', 'unknown');
    $formState->set('search-needle', 'Smith');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  // -------------------------------------------------------------------------
  // validateForm — hd-select-add
  // -------------------------------------------------------------------------

  public function testValidateFormHdSelectAddSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(3);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select-add']);
    $formState->method('getValue')->with('hd-select-add')->willReturn(3);
    $formState->method('get')->with('county')->willReturn('TestCounty');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // validateForm — add_coordinator_assignment
  // -------------------------------------------------------------------------

  public function testValidateFormAddCoordinatorNoSelectionSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([]);

    $formState = $this->addCoordinatorFormState('county');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('add_coordinator_assignment', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormAddCoordinatorMultipleSelectionsSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')
      ->willReturn([['mcid' => 1], ['mcid' => 2]]);

    $formState = $this->addCoordinatorFormState('county');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('add_coordinator_assignment', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormAddCoordinatorUnknownScopeSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn([]);

    $formState = $this->addCoordinatorFormState('unknown');
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('scope', $this->anything());
    $formState->method('set');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormAddCoordinatorCountyScopeNoDuplicateSetsAssignmentRole(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(FALSE);

    $captured = [];
    $formState = $this->addCoordinatorFormState('county');
    $formState->expects($this->never())->method('setErrorByName');
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertArrayHasKey('assignmentRole', $captured);
    $this->assertSame('county', $captured['assignmentRole']['scope']);
    $this->assertNull($captured['assignmentRole']['hd']);
  }

  public function testValidateFormAddCoordinatorCountyScopeDuplicateSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')
      ->willReturn(['TestCounty' => [['scope' => 'county', 'value' => 'TestCounty']]]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(TRUE);

    $formState = $this->addCoordinatorFormState('county');
    $formState->method('set');
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('scope', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormAddCoordinatorHdScopeNoDuplicateSetsHdInAssignmentRole(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(FALSE);
    $this->nlsMock->method('getHdList')->willReturn(['HD1', 'HD2']);

    $captured = [];
    $formState = $this->addCoordinatorFormState('hd', [['hd-assigned', NULL, 'HD1']]);
    $formState->expects($this->never())->method('setErrorByName');
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame('HD1', $captured['assignmentRole']['hd']);
    $this->assertSame('hd', $captured['assignmentRole']['scope']);
  }

  public function testValidateFormAddCoordinatorHdScopeDuplicateSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(TRUE);
    $this->nlsMock->method('getHdList')->willReturn(['HD1', 'HD2']);

    $formState = $this->addCoordinatorFormState('hd', [['hd-assigned', NULL, 'HD1']]);
    $formState->method('set');
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('scope', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormAddCoordinatorPrecinctScopeMissingPctListSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(FALSE);
    $this->nlsMock->method('getHdList')->willReturn(['HD1']);

    $formState = $this->addCoordinatorFormState('precinct', [
      ['pct-list', NULL, ''],     // empty — triggers error
      ['hd-assigned', NULL, 'HD1'],
    ]);
    $formState->method('set');

    $errors = [];
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name) use (&$errors): void { $errors[] = $name; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertContains('pct-list', $errors);
  }

  public function testValidateFormAddCoordinatorPrecinctScopeMissingHdSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);

    $formState = $this->addCoordinatorFormState('precinct', [
      ['pct-list', NULL, '101,102'],
      ['hd-assigned', NULL, 0],   // 0 is empty() — triggers error and breaks
    ]);
    $formState->method('set');

    $errors = [];
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name) use (&$errors): void { $errors[] = $name; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertContains('hd-assigned', $errors);
  }

  public function testValidateFormAddCoordinatorPrecinctScopeDuplicateSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(TRUE);
    $this->nlsMock->method('getHdList')->willReturn(['HD1']);

    $formState = $this->addCoordinatorFormState('precinct', [
      ['pct-list', NULL, '101'],
      ['hd-assigned', NULL, 'HD1'],
    ]);
    $formState->method('set');

    $errors = [];
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name) use (&$errors): void { $errors[] = $name; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertContains('scope', $errors);
  }

  public function testValidateFormAddCoordinatorInvalidAltEmailSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([['mcid' => 42]]);
    $this->coordinatorsMock->method('getAllCoordinators')->willReturn(['TestCounty' => []]);
    $this->coordinatorsMock->method('duplicateScope')->willReturn(FALSE);
    $this->emailValidatorMock->method('isValid')->willReturn(FALSE);

    $formState = $this->addCoordinatorFormState('county', [
      ['altEmail', NULL, 'not-an-email'],
    ]);
    $formState->method('set');

    $errors = [];
    $formState->method('setErrorByName')->willReturnCallback(
      function ($name) use (&$errors): void { $errors[] = $name; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertContains('altEmail', $errors);
  }

  // -------------------------------------------------------------------------
  // validateForm — remove_coordinator_assignment
  // -------------------------------------------------------------------------

  public function testValidateFormRemoveCoordinatorNoSelectionSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')
      ->willReturn(['#name' => 'remove_coordinator_assignment']);
    $formState->method('getValue')->with('display')->willReturn([]);
    $formState->method('get')->with('county')->willReturn('TestCounty');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('remove_coordinator_assignment', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormRemoveCoordinatorValidSelectionStoresSelections(): void {
    $selections = [['cIndex' => 5, 'mcid' => 42]];
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')
      ->willReturn(['#name' => 'remove_coordinator_assignment']);
    $formState->method('getValue')->with('display')->willReturn([]);
    $formState->method('get')->with('county')->willReturn('TestCounty');
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['removeCoordinators']);
  }

  // -------------------------------------------------------------------------
  // validateForm — scope-select
  // -------------------------------------------------------------------------

  public function testValidateFormScopeSelectUpdatesScopeInFormState(): void {
    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'scope-select']);
    $formState->method('getValue')->with('scope-select')->willReturn('hd');
    $formState->method('get')->with('county')->willReturn('TestCounty');
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame('hd', $captured['scope']);
  }

  // -------------------------------------------------------------------------
  // submitForm — search_submit
  // -------------------------------------------------------------------------

  public function testSubmitFormSearchSubmitStoresNeedleAndSetsRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->atLeastOnce())->method('setRebuild');
    $formState->setValue('search-needle', 'Jones');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Jones', $formState->get('search-needle'));
  }

  // -------------------------------------------------------------------------
  // submitForm — remove_coordinator_assignment
  // -------------------------------------------------------------------------

  public function testSubmitFormRemoveCoordinatorDeletesEachSelectedCoordinator(): void {
    $deleted = [];
    $this->coordinatorsMock->expects($this->exactly(2))
      ->method('deleteCoordinator')
      ->willReturnCallback(function ($cIndex) use (&$deleted): void {
        $deleted[] = $cIndex;
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('removeCoordinators', [['cIndex' => 5], ['cIndex' => 10]]);
    $formState->setTriggeringElement(['#name' => 'remove_coordinator_assignment']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame([5, 10], $deleted);
  }

  // -------------------------------------------------------------------------
  // submitForm — add_coordinator_assignment
  // -------------------------------------------------------------------------

  public function testSubmitFormAddCoordinatorNoEmailAddsWarningAndSkipsCreate(): void {
    $nl = array_merge($this->baseNlRecord(), ['email' => '']);
    $this->nlsMock->method('getNlById')->willReturn($nl);
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->coordinatorsMock->expects($this->never())->method('createCoordinator');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('addCoordinators', [['mcid' => 42]]);
    $formState->set('altEmail', '');
    $formState->set('assignmentRole', [
      'county' => 'TestCounty', 'scope' => 'county', 'hd' => NULL, 'precinctList' => NULL,
    ]);
    $formState->setTriggeringElement(['#name' => 'add_coordinator_assignment']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormAddCoordinatorUsesAltEmailOverNlEmail(): void {
    $this->nlsMock->method('getNlById')->willReturn($this->baseNlRecord());
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->method('createMagicWord')->willReturn('abc');
    $this->drupalUserMock->method('addUser')
      ->willReturn(['status' => 'complete', 'userName' => 'ali.smith']);
    $this->magicWordMock->method('setMagicWord');

    $capturedReq = NULL;
    $this->coordinatorsMock->expects($this->once())
      ->method('createCoordinator')
      ->willReturnCallback(function ($req) use (&$capturedReq): void {
        $capturedReq = $req;
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('addCoordinators', [['mcid' => 42]]);
    $formState->set('altEmail', 'alt@example.com');
    $formState->set('assignmentRole', [
      'county' => 'TestCounty', 'scope' => 'county', 'hd' => NULL, 'precinctList' => NULL,
    ]);
    $formState->setTriggeringElement(['#name' => 'add_coordinator_assignment']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('alt@example.com', $capturedReq['email']);
  }

  public function testSubmitFormAddCoordinatorUsesNicknameOverFirstName(): void {
    $this->nlsMock->method('getNlById')->willReturn($this->baseNlRecord()); // nickname='Ali', firstName='Alice'
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->method('createMagicWord')->willReturn('abc');
    $this->drupalUserMock->method('addUser')
      ->willReturn(['status' => 'complete', 'userName' => 'ali.smith']);
    $this->magicWordMock->method('setMagicWord');

    $capturedReq = NULL;
    $this->coordinatorsMock->expects($this->once())
      ->method('createCoordinator')
      ->willReturnCallback(function ($req) use (&$capturedReq): void {
        $capturedReq = $req;
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('addCoordinators', [['mcid' => 42]]);
    $formState->set('altEmail', '');
    $formState->set('assignmentRole', [
      'county' => 'TestCounty', 'scope' => 'county', 'hd' => NULL, 'precinctList' => NULL,
    ]);
    $formState->setTriggeringElement(['#name' => 'add_coordinator_assignment']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Ali', $capturedReq['firstName']);
  }

  // -------------------------------------------------------------------------
  // createCoordinatorAccount
  // -------------------------------------------------------------------------

  public function testCreateCoordinatorAccountAddsWarningWhenEmailMissing(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->drupalUserMock->expects($this->never())->method('getUserByMcid');

    $coordinator = array_merge($this->baseCoordinatorArg(), ['email' => '']);
    $this->buildFormInstance()->createCoordinatorAccount($coordinator);
  }

  public function testCreateCoordinatorAccountDoesNothingWhenUserAlreadyHasRole(): void {
    // The form checks in_array(NLP_COORDINATOR_ROLE_ID, $roles) which tests VALUES.
    $this->drupalUserMock->method('getUserByMcid')->willReturn([
      'uid'   => 10,
      'mcid'  => 42,
      'roles' => [NLP_COORDINATOR_ROLE_ID],
    ]);
    $this->drupalUserMock->expects($this->never())->method('updateUser');
    $this->drupalUserMock->expects($this->never())->method('addUser');
    $this->messengerMock->expects($this->never())->method('addStatus');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountAddsRoleAndStatusWhenUserLacksIt(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([
      'uid' => 10, 'mcid' => 42, 'roles' => [],
    ]);
    $this->drupalUserMock->expects($this->once())->method('updateUser');
    $this->messengerMock->expects($this->once())->method('addStatus');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountAddsWarningOnMcidMismatch(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([
      'uid' => 10, 'mcid' => 99, 'roles' => [],   // mcid 99 ≠ 42
    ]);
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->drupalUserMock->expects($this->never())->method('updateUser');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountFallsBackToEmailLookup(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->expects($this->once())
      ->method('getUserByEmail')
      ->willReturn([
        'uid'   => 20,
        'mcid'  => 42,
        'roles' => [NLP_COORDINATOR_ROLE_ID],
      ]);

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountFallsBackToNameLookupWhenEmailEmpty(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->expects($this->once())
      ->method('getUserByName')
      ->willReturn([
        'uid'   => 30,
        'mcid'  => 42,
        'roles' => [NLP_COORDINATOR_ROLE_ID],
      ]);

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountCreatesNewAccountAndAddsStatus(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->method('createMagicWord')->willReturn('secret123');
    $this->drupalUserMock->expects($this->once())
      ->method('addUser')
      ->willReturn(['status' => 'complete', 'userName' => 'ali.smith']);
    $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(42, 'secret123');
    $this->messengerMock->expects($this->once())->method('addStatus');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountAddsErrorWhenNewAccountFails(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->method('createMagicWord')->willReturn('secret');
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'error']);
    $this->messengerMock->expects($this->once())->method('addError');
    $this->magicWordMock->expects($this->never())->method('setMagicWord');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

  public function testCreateCoordinatorAccountAddsErrorWhenUsernameAlreadyExists(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->method('createMagicWord')->willReturn('secret');
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'exists']);
    $this->messengerMock->expects($this->once())->method('addError');
    $this->magicWordMock->expects($this->never())->method('setMagicWord');

    $this->buildFormInstance()->createCoordinatorAccount($this->baseCoordinatorArg());
  }

}
