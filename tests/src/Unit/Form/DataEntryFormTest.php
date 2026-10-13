<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\DataEntryForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryDateBar;
use Drupal\nlpservices\NlpDataEntryReports;
use Drupal\nlpservices\NlpDataEntryVoterListDisplay;
use Drupal\nlpservices\NlpDataEntryVoters;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\ApiMapRegions;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\DataEntryForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'initializeDataReporting')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'validatePhoneNumber')]
#[Group('nlpservices')]
class DataEntryFormTest extends UnitTestCase {

  protected $nlsMock;
  protected $votersMock;
  protected $turfsMock;
  protected $sessionDataMock;
  protected $reportsMock;
  protected $drupalUserMock;
  protected $dataEntryVotersMock;
  protected $dataEntryDateBarMock;
  protected $nlpConfigurationMock;
  protected $dataEntryVoterListDisplayMock;
  protected $dataEntryReportsMock;
  protected $mapRegionsMock;
  protected $messengerMock;
  protected DataEntryForm $form;

  protected function setUp(): void {
    parent::setUp();

    $this->nlsMock                       = $this->createMock(NlpNls::class);
    $this->votersMock                    = $this->createMock(NlpVoters::class);
    $this->turfsMock                     = $this->createMock(NlpTurfs::class);
    $this->sessionDataMock               = $this->createMock(NlpSessionData::class);
    $this->reportsMock                   = $this->createMock(NlpReports::class);
    $this->drupalUserMock                = $this->createMock(DrupalUser::class);
    $this->dataEntryVotersMock           = $this->createMock(NlpDataEntryVoters::class);
    $this->dataEntryDateBarMock          = $this->createMock(NlpDataEntryDateBar::class);
    $this->nlpConfigurationMock          = $this->createMock(NlpConfiguration::class);
    $this->dataEntryVoterListDisplayMock = $this->createMock(NlpDataEntryVoterListDisplay::class);
    $this->dataEntryReportsMock          = $this->createMock(NlpDataEntryReports::class);
    $this->mapRegionsMock                = $this->createMock(ApiMapRegions::class);

    $this->messengerMock  = $this->createMock(MessengerInterface::class);
    $loggerMock           = $this->createMock(LoggerInterface::class);
    $loggerFactoryMock    = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($loggerMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('logger.factory', $loggerFactoryMock);
    \Drupal::setContainer($container);

    $this->form = new DataEntryForm(
      $this->nlsMock,
      $this->votersMock,
      $this->turfsMock,
      $this->sessionDataMock,
      $this->reportsMock,
      $this->drupalUserMock,
      $this->dataEntryVotersMock,
      $this->dataEntryDateBarMock,
      $this->nlpConfigurationMock,
      $this->dataEntryVoterListDisplayMock,
      $this->dataEntryReportsMock,
      $this->mapRegionsMock,
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->messengerMock);
  }

  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Configure mocks so that initializeDataReporting() succeeds.
   * Use only in buildForm/initializeDataReporting tests — not in submitForm tests.
   */
  protected function configureValidSession(int $mcid = 101, int $turfIndex = 42): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn(['HD1' => 'HD-1']);
    $this->turfsMock->method('getTurf')->willReturn(['turfIndex' => $turfIndex]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn([
      'mcid' => $mcid,
      'county' => 'TestCounty',
    ]);
    $this->sessionDataMock->method('get')->willReturnCallback(
      static function (string $key) use ($turfIndex): mixed {
        return match ($key) {
          'turfIndex'                  => $turfIndex,
          'currentPage'                => 0,
          'canvassDate'                => '2026-05-30',
          'defaultVoterContactMethod'  => NULL,
          default                      => NULL,
        };
      }
    );
    $this->turfsMock->method('turfExists')->willReturn([
      'turfs' => [$turfIndex => ['turfIndex' => $turfIndex]],
    ]);
    $this->turfsMock->method('unreportedChanges')->willReturn(FALSE);
    $this->nlsMock->method('getNlById')->willReturn([
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
      'nickname'  => 'Jane',
    ]);
  }

  /**
   * Configure the minimal mocks needed after the switch block in submitForm().
   * Use in submitForm navigation/action tests in place of configureValidSession().
   */
  protected function configureSubmitBase(FormState $formState, int $mcid = 101, int $turfIndex = 42): void {
    $formState->set('mcid', $mcid);
    $formState->set('turfIndex', $turfIndex);
    $formState->set('voterArrays', []);

    $this->sessionDataMock->method('get')->willReturn(NULL);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getNlById')->willReturn([
      'firstName' => 'Jane', 'lastName' => 'Doe', 'nickname' => 'Jane',
    ]);
    $this->dataEntryVoterListDisplayMock->method('validateDataEntrySelection')->willReturn([]);
    $this->dataEntryVoterListDisplayMock->method('whatChanged')->willReturn([]);
  }

  /**
   * Build a FormState seeded for a single-voter action test inside submitForm().
   * Configures all mocks that submitForm() needs after the navigation switch.
   */
  protected function buildActionFormState(int $vanid, array $action): FormState {
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->set('mcid', 101);
    $formState->set('voterArrays', [
      $vanid => [
        'vanid'       => $vanid,
        'cd'          => 'CD1',
        'firstName'   => 'Voter',
        'lastName'    => 'Person',
        'cellPhone'   => '206-555-1234',
        'cellPhoneId' => 999,
        'homePhone'   => '206-555-5678',
        'homePhoneId' => 888,
        'reportIndex' => 77,
      ],
    ]);
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => '']);

    $this->sessionDataMock->method('get')->willReturn(NULL);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlsMock->method('getNlById')->willReturn([
      'firstName' => 'Jane', 'lastName' => 'Doe', 'nickname' => 'Jane',
    ]);
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone', 2 => 'Postcard']);
    $this->dataEntryVoterListDisplayMock->method('validateDataEntrySelection')->willReturn([]);
    $this->dataEntryVoterListDisplayMock->method('whatChanged')
      ->willReturn([$vanid => [$action]]);

    return $formState;
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_data_entry_form', $this->form->getFormId());
  }

  // ---------------------------------------------------------------------------
  // initializeDataReporting
  // ---------------------------------------------------------------------------

  public function testInitializeDataReportingReturnsFalseWhenMcidMissing(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn([]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => NULL]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->form->initializeDataReporting(new FormState());

    $this->assertFalse($result);
  }

  public function testInitializeDataReportingReturnsFalseWhenNoTurfAssigned(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn([]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 101]);
    $this->sessionDataMock->method('get')->willReturn(NULL);
    $this->turfsMock->method('turfExists')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->form->initializeDataReporting(new FormState());

    $this->assertFalse($result);
  }

  public function testInitializeDataReportingReturnsFalseWhenNlNotFound(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn([]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 101]);
    $this->sessionDataMock->method('get')->willReturn(NULL);
    $this->turfsMock->method('turfExists')->willReturn([
      'turfs' => [42 => ['turfIndex' => 42]],
    ]);
    $this->turfsMock->method('unreportedChanges')->willReturn(FALSE);
    $this->nlsMock->method('getNlById')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->form->initializeDataReporting(new FormState());

    $this->assertFalse($result);
  }

  public function testInitializeDataReportingReturnsTrueAndSetsStateWhenValid(): void {
    $formState = new FormState();
    $this->configureValidSession(101, 42);

    $result = $this->form->initializeDataReporting($formState);

    $this->assertTrue($result);
    $this->assertSame(101, $formState->get('mcid'));
    $this->assertNotEmpty($formState->get('turfArray'));
    $this->assertSame(42, $formState->get('turfIndex'));
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormReturnsEmptyFormWhenInitializeFails(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn([]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => NULL]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $form = $this->form->buildForm([], $formState);

    $this->assertArrayNotHasKey('dateBar', $form);
    $this->assertArrayNotHasKey('dataEntryTable', $form);
  }

  public function testBuildFormShowsUnreportedChangesPageWhenPageSet(): void {
    $this->configureValidSession();
    $this->turfsMock->method('getChangeHistory')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'unreportedChanges');

    $form = $this->form->buildForm([], $formState);

    $this->assertArrayHasKey('unreportedChanges', $form);
    $this->assertArrayNotHasKey('dataEntryTable', $form);
  }

  public function testBuildFormShowsPostcardsPageWhenPageSet(): void {
    $this->configureValidSession();
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([
      111111 => ['vanid' => 111111, 'firstName' => 'Bob', 'lastName' => 'Smith'],
    ]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'postcardsSent');

    $form = $this->form->buildForm([], $formState);

    $this->assertArrayHasKey('postcardsList', $form);
    $this->assertArrayNotHasKey('dataEntryTable', $form);
  }

  public function testBuildFormBuildsVoterTableAndAttachesLibraries(): void {
    $this->configureValidSession();
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([
      1 => ['vanid' => 1, 'firstName' => 'Ann', 'lastName' => 'Jones'],
    ]);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk', 1 => 'Phone']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturn(['#type' => 'table']);
    $this->dataEntryDateBarMock->method('createDateBar')->willReturn(['#markup' => 'bar']);
    $this->dataEntryDateBarMock->method('navigate')->willReturn(['#markup' => 'nav']);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');

    $form = $this->form->buildForm([], $formState);

    $this->assertArrayHasKey('dateBar', $form);
    $this->assertArrayHasKey('dataEntryTable', $form);
    $this->assertArrayHasKey('navigate', $form);
    $this->assertArrayHasKey('#attached', $form);
    $this->assertContains('nlpservices/data-entry-table', $form['#attached']['library']);
  }

  /**
   * Configure mocks for buildForm to draw the voters page with two voters.
   */
  private function configureVoterPage(): void {
    $this->configureValidSession();
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([
      1 => ['vanid' => 1, 'firstName' => 'Ann', 'lastName' => 'Jones'],
      2 => ['vanid' => 2, 'firstName' => 'Bob', 'lastName' => 'Smith'],
    ]);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk', 1 => 'Phone']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturn(['#type' => 'table']);
    $this->dataEntryDateBarMock->method('createDateBar')->willReturn(['#markup' => 'bar']);
    $this->dataEntryDateBarMock->method('navigate')->willReturn(['#markup' => 'nav']);
  }

  /**
   * What the browser sends for two voters who each have entries.
   */
  private function twoVotersInput(): array {
    $row = static fn(int $notContacted) => [
      'col_2_3' => ['line_2_3_3' => 0],
      'col_2_4' => ['line_2_4_2' => $notContacted],
      'col_2_5' => ['line_2_5_2' => 1],
    ];
    return ['dataEntry' => ['row_2_1' => $row(5), 'row_2_2' => $row(7)]];
  }

  public function testBuildFormContactMethodChangeKeepsOtherVotersEntries(): void {
    $this->configureVoterPage();
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');
    $formState->setUserInput($this->twoVotersInput());
    $formState->setTriggeringElement(['#name' => 'dataEntry[row_2_1][col_2_2][line_2_2_2]', '#value' => 1]);

    $this->form->buildForm([], $formState);

    $input = $formState->getUserInput()['dataEntry'];
    // The other voter's entries survive the redraw.
    $this->assertSame(7, $input['row_2_2']['col_2_4']['line_2_4_2']);
    $this->assertSame(1, $input['row_2_2']['col_2_5']['line_2_5_2']);
    // The changed voter keeps everything except the not-contacted pick, whose
    // choices depend on the contact method.
    $this->assertArrayNotHasKey('line_2_4_2', $input['row_2_1']['col_2_4']);
    $this->assertSame(1, $input['row_2_1']['col_2_5']['line_2_5_2']);
  }

  public function testBuildFormAfterSaveClearsAllEntries(): void {
    $this->configureVoterPage();
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');
    $formState->setUserInput($this->twoVotersInput());
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 'Save reports']);

    $this->form->buildForm([], $formState);

    $this->assertArrayNotHasKey('dataEntry', $formState->getUserInput());
  }

  public function testBuildFormAddsHiddenFloatingSaveButton(): void {
    $this->configureVoterPage();

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');

    $form = $this->form->buildForm([], $formState);

    // Saves exactly like the nav bar's Save reports button.
    $this->assertSame('save_reports', $form['save-floating']['save']['#name']);
    $this->assertSame('submit', $form['save-floating']['save']['#type']);
    // The bar is hidden by CSS until unsaved-changes.js sees an edit.
    $this->assertSame('container', $form['save-floating']['#type']);
    $this->assertContains('nlp-floating-save', $form['save-floating']['#attributes']['class']);
    // It must be the last element so position: sticky keeps it inside the form.
    $children = array_filter(array_keys($form), static fn($key) => !str_starts_with((string) $key, '#'));
    $this->assertSame('save-floating', end($children));
    $this->assertContains('nlpservices/unsaved-changes', $form['#attached']['library']);
    $this->assertContains('js-form-changed', $form['#attributes']['class']);
  }

  public function testBuildFormPostcardsPageHasNoUnsavedChangesWarning(): void {
    $this->configureValidSession();
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([
      111111 => ['vanid' => 111111, 'firstName' => 'Bob', 'lastName' => 'Smith'],
    ]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'postcardsSent');

    $form = $this->form->buildForm([], $formState);

    $this->assertArrayNotHasKey('save-floating', $form);
    $this->assertNotContains('nlpservices/unsaved-changes', $form['#attached']['library']);
  }

  public function testBuildFormPaginatesToTenVotersPerPage(): void {
    $this->configureValidSession();

    $voters = [];
    for ($i = 1; $i <= 15; $i++) {
      $voters[$i] = ['vanid' => $i, 'firstName' => "First$i", 'lastName' => "Last$i"];
    }
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn($voters);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturn(['#type' => 'table']);
    $this->dataEntryDateBarMock->method('createDateBar')->willReturn(['#markup' => 'bar']);
    $this->dataEntryDateBarMock->method('navigate')->willReturn(['#markup' => 'nav']);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');

    $this->form->buildForm([], $formState);

    $this->assertCount(10, $formState->get('voterArrays'));
  }

  public function testBuildFormRemembersVotersAndPageItShowed(): void {
    $this->configureValidSession();
    $voters = [1 => ['vanid' => 1, 'firstName' => 'Ann', 'lastName' => 'Jones']];
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn($voters);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturn(['#type' => 'table']);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');

    $this->form->buildForm([], $formState);

    $this->assertSame($voters, $formState->get('voters'));
    $this->assertSame(0, $formState->get('currentPage'));
  }

  /**
   * A contact method change must rebuild the rows the browser is showing, even
   * when the session or the turf has changed since, or the changed select is
   * missing from the rebuilt form and Drupal's AJAX handler throws.
   */
  public function testBuildFormContactMethodRebuildReusesShownVoters(): void {
    // The session now points at another turf and page, and the turf has
    // unreported changes; none of that may reach this rebuild.
    $this->sessionDataMock->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'turfIndex'   => 7,
        'currentPage' => 3,
        default       => NULL,
      }
    );
    $this->turfsMock->method('unreportedChanges')->willReturn(TRUE);
    $this->dataEntryVotersMock->expects($this->never())->method('fetchVoters');
    $this->drupalUserMock->expects($this->never())->method('getCurrentUser');
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturnCallback(static fn(array $voterArrays) => ['rows' => array_keys($voterArrays)]);

    $voters = [];
    for ($i = 1; $i <= 15; $i++) {
      $voters[$i] = ['vanid' => $i, 'firstName' => "First$i", 'lastName' => "Last$i"];
    }
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');
    $formState->set('turfIndex', 42);
    $formState->set('voters', $voters);
    $formState->set('currentPage', 1);
    $formState->set('mcid', 101);
    $formState->set('contactMethodRebuild', TRUE);

    $form = $this->form->buildForm([], $formState);

    $this->assertSame([11, 12, 13, 14, 15], $form['dataEntryTable']['voters']['rows']);
    $this->assertSame(42, $formState->get('turfIndex'));
    $this->assertFalse($formState->get('contactMethodRebuild'));
  }

  // ---------------------------------------------------------------------------
  // validateForm
  // ---------------------------------------------------------------------------

  public function testValidateFormIgnoresNonContactMethodTrigger(): void {
    $this->nlpConfigurationMock->expects($this->never())->method('getContactMethods');
    $this->sessionDataMock->expects($this->never())->method('setVoterContactMethod');

    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 0]);
    $form = [];
    $this->form->validateForm($form, $formState);
  }

  public function testValidateFormDetectsContactMethodChangeAndStoresInSession(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone']);

    $this->sessionDataMock->expects($this->once())
      ->method('setVoterContactMethod')
      ->with(['vanid' => '99999', 'name' => 'Phone', 'code' => 1]);

    // Use a partial mock so we can assert setRebuild() is called.
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->setTriggeringElement(['#name' => 'line_2_2_2[row_2_99999]', '#value' => 1]);

    $form = [];
    $this->form->validateForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertTrue($formState->get('contactMethodRebuild'));
  }

  // ---------------------------------------------------------------------------
  // submitForm — early-return branches
  // ---------------------------------------------------------------------------

  public function testSubmitFormAlwaysSetsRebuildAndReenter(): void {
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  public function testSubmitFormAcknowledgeUnreportedChangesUpdatesTurf(): void {
    $this->turfsMock->expects($this->once())
      ->method('updateTurfFields')
      ->with(42, ['unreportedChanges' => 0]);
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->setTriggeringElement(['#name' => 'acknowledgeUnreportedChanges', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('unreportedChanges', $formState->get('page'));
  }

  public function testSubmitFormPostcardSubmitSetsPage(): void {
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->setTriggeringElement(['#name' => 'postcardSubmit', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('postcardsSent', $formState->get('page'));
  }

  public function testSubmitFormPostcardSelectProcessesOnlyCheckedVoters(): void {
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane']);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(5);
    $this->nlpConfigurationMock->method('getContactId')->willReturn(3);

    // Voter 111111 is selected (truthy), 222222 is not (0 = already sent).
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->set('mcid', 101);
    $formState->setValue('postcardsSentList', [111111 => 111111, 222222 => 0]);
    $formState->setTriggeringElement(['#name' => 'postcardSelect', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('voters', $formState->get('page'));
  }

  public function testSubmitFormPostcardSelectReportsDateChosenOnPostcardPage(): void {
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane']);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturnMap([['canvassDate', '2026-10-12']]);
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(5);
    $this->nlpConfigurationMock->method('getContactId')->willReturn(3);

    $reportedDate = NULL;
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->willReturnCallback(static function (array $report) use (&$reportedDate): void {
        $reportedDate = $report['contactDate'];
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->set('mcid', 101);
    $formState->setValue('canvassDate', '2026-10-09');
    $formState->setValue('postcardsSentList', [111111 => 111111]);
    $formState->setTriggeringElement(['#name' => 'postcardSelect', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('2026-10-09', $reportedDate);
  }

  // ---------------------------------------------------------------------------
  // submitForm — navigation
  // ---------------------------------------------------------------------------

  public function testSubmitFormPageSelectSetsCorrectPage(): void {
    $setCalls = [];
    $this->sessionDataMock->method('set')
      ->willReturnCallback(static function ($key, $value) use (&$setCalls): void {
        $setCalls[$key] = $value;
      });

    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setTriggeringElement(['#name' => 'pageSelect-3', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertArrayHasKey('currentPage', $setCalls);
    $this->assertSame(2, $setCalls['currentPage']);
  }

  public function testSubmitFormLastNameSearchNavigatesWhenFound(): void {
    $this->dataEntryVotersMock->method('voterSearch')
      ->with(42, 'Smith', DataEntryForm::DE_PAGE_SIZE)
      ->willReturn(['found' => TRUE, 'page' => 4]);

    $setCalls = [];
    $this->sessionDataMock->method('set')
      ->willReturnCallback(static function ($key, $value) use (&$setCalls): void {
        $setCalls[$key] = $value;
      });

    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setValue('last-name', 'Smith');
    $formState->setTriggeringElement(['#name' => 'last_name_search', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame(4, $setCalls['currentPage'] ?? NULL);
  }

  public function testSubmitFormLastNameSearchDoesNotNavigateWhenNotFound(): void {
    $this->dataEntryVotersMock->method('voterSearch')
      ->willReturn(['found' => FALSE, 'page' => 0]);

    $setCalls = [];
    $this->sessionDataMock->method('set')
      ->willReturnCallback(static function ($key, $value) use (&$setCalls): void {
        $setCalls[$key] = $value;
      });

    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setValue('last-name', 'NoSuchName');
    $formState->setTriggeringElement(['#name' => 'last_name_search', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertArrayNotHasKey('currentPage', $setCalls);
  }

  // ---------------------------------------------------------------------------
  // submitForm — voter contact actions
  // ---------------------------------------------------------------------------

  public function testSubmitFormSurveyResponseCallsPledgeToVote(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('pledgeToVote');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'surveyResponse', 'contactMethod' => 1,
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormSurveyResponseWithPostcardContactMethodShowsWarning(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->dataEntryReportsMock->expects($this->never())->method('pledgeToVote');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'surveyResponse', 'contactMethod' => 2,
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNotContactedCallsNoContactResponse(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse');
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormSaveReportsUsesChosenContactDate(): void {
    $reportedDate = NULL;
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->willReturnCallback(static function (array $report) use (&$reportedDate): void {
        $reportedDate = $report['contactDate'];
      });
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $formState->setValue('canvassDate', '2026-10-09');
    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('2026-10-09', $reportedDate);
  }

  public function testSubmitFormSaveReportsDefaultsContactDateToToday(): void {
    $reportedDate = NULL;
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->willReturnCallback(static function (array $report) use (&$reportedDate): void {
        $reportedDate = $report['contactDate'];
      });
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame(date('Y-m-d'), $reportedDate);
  }

  public function testSubmitFormMovedCallsVoterMoved(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('voterMoved');
    $formState = $this->buildActionFormState(99999, ['type' => 'moved']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormHostileCallsHostileVoter(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('hostileVoter');
    $formState = $this->buildActionFormState(99999, ['type' => 'hostile']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormDeceasedCallsDeceasedVoter(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('deceasedVoter');
    $formState = $this->buildActionFormState(99999, ['type' => 'deceased']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormBadCellCallsBadPhoneWithCellPhone(): void {
    $this->dataEntryReportsMock->expects($this->once())
      ->method('badPhone')
      ->with('cellPhone', $this->anything(), $this->anything());
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'BC', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormBadHomeCallsBadPhoneWithHomePhone(): void {
    $this->dataEntryReportsMock->expects($this->once())
      ->method('badPhone')
      ->with('homePhone', $this->anything(), $this->anything());
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'BH', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewCellValidPhoneSavesReportAndUpdatesVoter(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())->method('setNlReport');
    $this->votersMock->expects($this->once())->method('updateVoterPhone');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NC', 'newContact' => '(206) 555-1234'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewCellEmptyPhoneShowsWarningAndSkipsReport(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NC', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewCellInvalidPhoneShowsWarningAndSkipsReport(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NC', 'newContact' => 'not-a-phone'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewHomeValidPhoneSavesReportAndUpdatesVoter(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())->method('setNlReport');
    $this->votersMock->expects($this->once())->method('updateVoterPhone');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NH', 'newContact' => '206-555-5678'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewHomeInvalidPhoneShowsWarning(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NH', 'newContact' => 'bad'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewEmailValidCallsSetNlReport(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NE', 'newContact' => 'voter@example.com'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNewEmailInvalidShowsWarningAndSkipsReport(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'NE', 'newContact' => 'not-an-email'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormAddNoteCallsAddNote(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('addNote');
    $formState = $this->buildActionFormState(99999, ['type' => 'note']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormLawnSignWithCandidatesSavesReport(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'LS', 'newContact' => 'Jane Smith'],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormLawnSignWithoutCandidatesShowsWarningAndSkipsReport(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'LS', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormRideToPollsSavesReport(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'RTP', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // validatePhoneNumber
  // ---------------------------------------------------------------------------

  #[DataProvider('validPhoneProvider')]
  public function testValidatePhoneNumberReturnsTrueForValidFormats(string $phone): void {
    $this->assertGreaterThan(0, $this->form->validatePhoneNumber($phone));
  }

  public static function validPhoneProvider(): array {
    return [
      'ten digits'          => ['2065551234'],
      'standard US format'  => ['(206) 555-1234'],
      'dashes'              => ['206-555-1234'],
      'dots'                => ['206.555.1234'],
      'spaces'              => ['206 555 1234'],
      'with country code'   => ['+1 206-555-1234'],
    ];
  }

  #[DataProvider('invalidPhoneProvider')]
  public function testValidatePhoneNumberReturnsFalseForInvalidFormats(string $phone): void {
    $this->assertFalse((bool) $this->form->validatePhoneNumber($phone));
  }

  public static function invalidPhoneProvider(): array {
    return [
      'empty string'   => [''],
      'letters only'   => ['abcdefghij'],
      'too short'      => ['206-555'],
      'just dashes'    => ['----------'],
      'alphanumeric'   => ['206-abc-1234'],
    ];
  }

}
