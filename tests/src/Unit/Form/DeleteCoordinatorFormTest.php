<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\DeleteCoordinatorForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCoordinatorsListDisplay;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\DeleteCoordinatorForm
 * @group nlpservices
 */
class DeleteCoordinatorFormTest extends UnitTestCase {

  private NlpCoordinators $coordinatorsMock;
  private NlpNls $nlsMock;
  private DrupalUser $drupalUserMock;
  private NlpCoordinatorsListDisplay $listDisplayMock;
  private NlpSessionData $sessionDataMock;
  private NlpSearchBar $searchBarMock;
  private NlpConfiguration $nlpConfigurationMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->coordinatorsMock    = $this->createMock(NlpCoordinators::class);
    $this->nlsMock             = $this->createMock(NlpNls::class);
    $this->drupalUserMock      = $this->createMock(DrupalUser::class);
    $this->listDisplayMock     = $this->createMock(NlpCoordinatorsListDisplay::class);
    $this->sessionDataMock     = $this->createMock(NlpSessionData::class);
    $this->searchBarMock       = $this->createMock(NlpSearchBar::class);
    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    $this->messengerMock       = $this->createMock(MessengerInterface::class);

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

  private function buildFormInstance(): DeleteCoordinatorForm {
    $form = new DeleteCoordinatorForm(
      $this->coordinatorsMock,
      $this->nlsMock,
      $this->drupalUserMock,
      $this->listDisplayMock,
      $this->sessionDataMock,
      $this->searchBarMock,
      $this->nlpConfigurationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Configure mocks needed for a successful buildForm first-render.
   */
  private function configureBuildFormMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn(['HD1', 'HD2']);
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All', 1 => 'HD1']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
  }

  /**
   * Configure mocks for buildForm when reenter is already set (skips first-render init).
   */
  private function configureBuildFormReenterMocks(string $county = 'TestCounty'): void {
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All', 1 => 'HD1']);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_delete_coordinator_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormReturnsEmptyArrayAndAddsWarningWhenCountyMissing(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSetsReenterScopeAndCountyOnFirstRender(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame('unknown', $formState->get('scope'));
    $this->assertSame('TestCounty', $formState->get('county'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSkipsInitialisationWhenReenterAlreadySet(): void {
    $this->configureBuildFormReenterMocks();
    $this->nlsMock->expects($this->never())->method('getNls');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('scope', 'unknown');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsManageCoordinatorsFieldset(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('manageCoordinators', $form);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertContains('nlpservices/info-boxes', $form['#attached']['library']);
    $this->assertContains('nlpservices/nlp-table', $form['#attached']['library']);
    $this->assertContains('nlpservices/search-boxes', $form['#attached']['library']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormEnrichesCoordinatorsWithRolesAndUid(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getNls')->willReturn([]);
    $this->nlsMock->method('getHdList')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);

    $this->coordinatorsMock->method('getCoordinators')->willReturn([
      10 => ['mcid' => 10, 'firstName' => 'Alice'],
      11 => ['mcid' => 11, 'firstName' => 'Bob'],
    ]);
    $this->drupalUserMock->method('getUserByMcid')->willReturnCallback(
      static fn($mcid) => ($mcid === 10)
        ? ['uid' => 55, 'roles' => [NLP_COORDINATOR_ROLE_ID]]
        : []
    );

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $assigned = $formState->get('assignedCoordinators');
    $this->assertSame(55, $assigned[10]['uid']);
    $this->assertSame([NLP_COORDINATOR_ROLE_ID], $assigned[10]['roles']);
    $this->assertSame('', $assigned[11]['uid']);
    $this->assertSame([], $assigned[11]['roles']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFiltersCoordinatorsBySearchNeedle(): void {
    $allCoordinators = [
      10 => ['mcid' => 10, 'firstName' => 'Alice'],
      11 => ['mcid' => 11, 'firstName' => 'Bob'],
    ];
    // buildForm enriches each entry with roles/uid before calling searchInfoArray.
    $enriched = [
      10 => ['mcid' => 10, 'firstName' => 'Alice', 'roles' => [], 'uid' => ''],
      11 => ['mcid' => 11, 'firstName' => 'Bob',   'roles' => [], 'uid' => ''],
    ];
    $filtered = [10 => $enriched[10]];

    $this->coordinatorsMock->method('getCoordinators')->willReturn($allCoordinators);
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->listDisplayMock->method('buildCoordinatorTableArray')->willReturn([]);
    $this->listDisplayMock->method('buildCoordinatorsTable')->willReturn([]);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->searchBarMock->expects($this->once())
      ->method('searchInfoArray')
      ->with('Alice', $enriched)
      ->willReturn($filtered);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('scope', 'unknown');
    $formState->set('search-needle', 'Alice');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormClearsSearchNeedleAfterFiltering(): void {
    $this->configureBuildFormReenterMocks();
    $this->coordinatorsMock->method('getCoordinators')->willReturn([]);
    $this->searchBarMock->method('searchInfoArray')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('scope', 'unknown');
    $formState->set('search-needle', 'Jones');

    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertNull($formState->get('search-needle'));
  }

  // -------------------------------------------------------------------------
  // validateForm — hd-select-remove
  // -------------------------------------------------------------------------

  /**
   * @covers ::validateForm
   */
  public function testValidateFormHdSelectRemoveSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select-remove']);
    $formState->method('getValue')->with('hd-select-remove')->willReturn(2);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // validateForm — delete_current_coordinator
  // -------------------------------------------------------------------------

  /**
   * @covers ::validateForm
   */
  public function testValidateFormDeleteCoordinatorNoSelectionSetsError(): void {
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')
      ->willReturn(['#name' => 'delete_current_coordinator']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('delete_current_coordinator', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormDeleteCoordinatorValidSelectionStoresSelections(): void {
    $selections = [['mcid' => 42, 'cIndex' => 7]];
    $this->listDisplayMock->method('validateCoordinatorSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')
      ->willReturn(['#name' => 'delete_current_coordinator']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['selectionsToDelete']);
  }

  // -------------------------------------------------------------------------
  // submitForm — search_submit
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSearchSubmitSetsReenter(): void {
    $formState = new FormState();
    $formState->setValue('search-needle', 'Smith');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  // -------------------------------------------------------------------------
  // submitForm — delete_current_coordinator
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteCoordinatorCallsDeleteForEachSelection(): void {
    $deleted = [];
    $this->coordinatorsMock->expects($this->exactly(2))
      ->method('deleteCoordinator')
      ->willReturnCallback(function ($cIndex) use (&$deleted): void {
        $deleted[] = $cIndex;
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('selectionsToDelete', [
      ['mcid' => 10, 'cIndex' => 5],
      ['mcid' => 11, 'cIndex' => 9],
    ]);
    $formState->set('assignedCoordinators', [
      10 => ['mcid' => 10, 'cIndex' => 5],
      11 => ['mcid' => 11, 'cIndex' => 9],
    ]);
    $formState->setTriggeringElement(['#name' => 'delete_current_coordinator']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame([5, 9], $deleted);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteCoordinatorSetsReenterAndCallsSetRebuild(): void {
    $this->coordinatorsMock->method('deleteCoordinator');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->set('selectionsToDelete', [['mcid' => 10]]);
    $formState->set('assignedCoordinators', [10 => ['mcid' => 10, 'cIndex' => 3]]);
    $formState->setTriggeringElement(['#name' => 'delete_current_coordinator']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

}
