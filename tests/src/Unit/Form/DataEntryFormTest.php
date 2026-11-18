<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\DependencyInjection\ReverseContainer;
use Drupal\Core\Form\FormState;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\DataEntryForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryDateBar;
use Drupal\nlpservices\NlpDataEntryReports;
use Drupal\nlpservices\NlpDataEntryVoterListDisplay;
use Drupal\nlpservices\NlpDataEntryVoters;
use Drupal\nlpservices\NlpImpersonation;
use Drupal\nlpservices\NlpContactDate;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(\Drupal\nlpservices\Form\DataEntryForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'initializeDataReporting')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'validatePhoneNumber')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryForm::class, 'expiredFormCallback')]
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
  protected $impersonationMock;
  protected $messengerMock;
  protected $lockMock;
  protected $savedSubmissionsMock;
  protected DataEntryForm $form;
  /** @var array The currentPage values buildForm() stored in the session. */
  protected array $currentPages = [];

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
    $this->impersonationMock             = $this->createMock(NlpImpersonation::class);

    $this->messengerMock  = $this->createMock(MessengerInterface::class);
    $loggerMock           = $this->createMock(LoggerInterface::class);
    $loggerFactoryMock    = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($loggerMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('logger.factory', $loggerFactoryMock);
    \Drupal::setContainer($container);

    $this->lockMock = $this->createMock(LockBackendInterface::class);
    $this->lockMock->method('acquire')->willReturn(TRUE);
    $this->savedSubmissionsMock = $this->createMock(KeyValueStoreExpirableInterface::class);
    $this->form = $this->newForm($this->lockMock, $this->savedSubmissionsMock);
  }

  /**
   * Construct the form with the given save lock and saved-submissions store.
   */
  protected function newForm($lock, $savedSubmissions): DataEntryForm {
    $keyValueFactory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyValueFactory->method('get')->with(DataEntryForm::SAVED_SUBMISSIONS)->willReturn($savedSubmissions);
    $form = new DataEntryForm(
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
      $lock,
      $keyValueFactory,
      $this->impersonationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']));
    $form->setRequestStack($requestStack);
    return $form;
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
    $this->assertContains('core/drupal.form', $form['#attached']['library']);
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
   * when the session or the turf has changed since.
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

  /**
   * Sets up buildForm() for a turf of 25 voters, vanids 1 to 25.
   */
  protected function configureTwentyFiveVoters(): void {
    $this->configureValidSession();
    $voters = [];
    for ($vanid = 1; $vanid <= 25; $vanid++) {
      $voters[$vanid] = ['vanid' => $vanid, 'firstName' => 'V' . $vanid, 'lastName' => 'L'];
    }
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn($voters);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([0 => 'Walk']);
    $this->dataEntryVoterListDisplayMock->method('buildDataEntryVoterArray')
      ->willReturnCallback(static fn($voter) => $voter);
    $this->dataEntryVoterListDisplayMock->method('buildVoterDataEntryTable')
      ->willReturn(['#type' => 'table']);
  }

  /**
   * Returns the user input a browser posts for the given voter rows.
   */
  protected function postedInput(array $vanids): array {
    $dataEntry = [];
    foreach ($vanids as $vanid) {
      $dataEntry['row_2_' . $vanid]['col_2_2']['line_2_2_2'] = '0';
      $dataEntry['row_3_' . $vanid]['col_3_1']['line_3_1_1'] = 'note ' . $vanid;
    }
    return [
      'form_id' => 'nlpservices_data_entry_form',
      'form_build_id' => 'form-expired',
      'dataEntry' => $dataEntry,
    ];
  }

  /**
   * A save from a page whose cached form has expired reaches buildForm() with
   * no form state. The rows the browser posted must be rebuilt, with their
   * entries, or the save finds nothing to report.
   */
  public function testBuildFormRebuildsPostedRowsWhenFormExpired(): void {
    $this->configureTwentyFiveVoters();
    $this->sessionDataMock->method('set')
      ->willReturnCallback(function (string $key, mixed $value): void {
        if ($key === 'currentPage') {
          $this->currentPages[] = $value;
        }
      });

    $formState = new FormState();
    $input = $this->postedInput(range(11, 20));
    $formState->setUserInput($input);

    $this->form->buildForm([], $formState);

    $this->assertSame(range(11, 20), array_keys($formState->get('voterArrays')));
    $this->assertSame(1, $formState->get('currentPage'));
    $this->assertSame(1, end($this->currentPages));
    $this->assertSame($input['dataEntry'], $formState->getUserInput()['dataEntry']);
  }

  public function testBuildFormDropsPostedVotersNoLongerInTurf(): void {
    $this->configureTwentyFiveVoters();
    $this->messengerMock->expects($this->once())->method('addWarning')
      ->with($this->stringContains('no'));

    $formState = new FormState();
    $formState->setUserInput($this->postedInput([3, 99, 4]));

    $this->form->buildForm([], $formState);

    $this->assertSame([3, 4], array_keys($formState->get('voterArrays')));
    $this->assertSame(0, $formState->get('currentPage'));
  }

  public function testBuildFormClearsPostedEntriesAfterSave(): void {
    $this->configureTwentyFiveVoters();

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('page', 'voters');
    $formState->setUserInput($this->postedInput(range(11, 20)));

    $this->form->buildForm([], $formState);

    $this->assertSame(range(1, 10), array_keys($formState->get('voterArrays')));
    $this->assertArrayNotHasKey('dataEntry', $formState->getUserInput());
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

  public function testValidateFormHandlesContactMethodChangeOnExpiredForm(): void {
    $this->sessionDataMock->expects($this->never())->method('setVoterContactMethod');
    $this->sessionDataMock->expects($this->never())->method('set');

    // Drupal rebuilt the expired form on page 1, didn't find the select and
    // fell back to the first button.
    $formState = new FormState();
    $formState->setUserInput([
      '_triggering_element_name' => 'line_2_2_2[row_2_99999]',
      '_triggering_element_value' => '1',
      'last-name' => '',
    ]);
    $formState->setTriggeringElement(['#name' => 'last_name_search', '#value' => 'Search']);

    $form = [];
    $this->form->validateForm($form, $formState);

    $this->assertTrue($formState->get('formExpired'));
    $this->assertSame('::expiredFormCallback', $formState->getTriggeringElement()['#ajax']['callback']);

    $this->form->submitForm($form, $formState);
    $this->assertFalse($formState->isRebuilding());
  }

  public function testExpiredFormCallbackReturnsWarning(): void {
    $commands = $this->form->expiredFormCallback()->getCommands();

    $this->assertCount(1, $commands);
    $this->assertSame('message', $commands[0]['command']);
    $this->assertStringContainsString('Reload the page', $commands[0]['message']);
    $this->assertSame('warning', $commands[0]['messageOptions']['type']);
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

  // ---------------------------------------------------------------------------
  // submitForm — a report's Delete or Save button in the Edit list
  // ---------------------------------------------------------------------------

  /**
   * A report of NL 101 for voter 99999, who is on the page.
   */
  protected function editableReport(): array {
    return ['reportIndex' => 77, 'vanid' => 99999, 'mcid' => 101, 'cycle' => '2026',
      'type' => 'Comment', 'value' => '', 'text' => 'Old note'];
  }

  /**
   * A FormState whose Delete or Save button for report 77 was clicked, with
   * the given text in the report's edit box.
   */
  protected function reportButtonFormState(string $button, string $text = ''): FormState {
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->set('voterArrays', [99999 => ['vanid' => 99999]]);
    $this->reportsMock->method('getNlpReport')->with(77)->willReturn($this->editableReport());
    $item = ['dataEntry', 'row_2_99999', 'col_2_4', 'line_2_4_3', 'edit', 'report_77'];
    $formState->setValue([...$item, 'textArea', 'text'], $text);
    $formState->setTriggeringElement([
      '#name' => $button . '-77',
      '#value' => $button === 'report_delete' ? 'Delete' : 'Save',
      '#parents' => $button === 'report_delete'
        ? [...$item, 'buttons', 'delete'] : [...$item, 'textArea', 'save'],
    ]);
    return $formState;
  }

  public function testReportDeleteDeletesTheNlsOwnReport(): void {
    $formState = $this->reportButtonFormState('report_delete');
    $this->reportsMock->expects($this->once())->method('canEditReport')
      ->with($this->editableReport(), 101, FALSE)->willReturn(TRUE);
    $this->dataEntryReportsMock->expects($this->once())->method('deleteVoterReport')
      ->with($this->editableReport());
    $this->messengerMock->expects($this->once())->method('addStatus')->with('The report was deleted.');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportDeleteByAnImpersonatorAsksWithTheImpersonatingFlag(): void {
    $formState = $this->reportButtonFormState('report_delete');
    $this->impersonationMock->method('isImpersonating')->willReturn(TRUE);
    $this->reportsMock->expects($this->once())->method('canEditReport')
      ->with($this->editableReport(), 101, TRUE)->willReturn(TRUE);
    $this->dataEntryReportsMock->expects($this->once())->method('deleteVoterReport');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportDeleteRefusedForAReportTheUserMayNotChange(): void {
    $formState = $this->reportButtonFormState('report_delete');
    $this->reportsMock->method('canEditReport')->willReturn(FALSE);
    $this->dataEntryReportsMock->expects($this->never())->method('deleteVoterReport');
    $this->messengerMock->expects($this->once())->method('addError');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportDeleteRefusedForAVoterNotOnThePage(): void {
    $formState = $this->reportButtonFormState('report_delete');
    $formState->set('voterArrays', [12345 => ['vanid' => 12345]]);
    $this->reportsMock->method('canEditReport')->willReturn(TRUE);
    $this->dataEntryReportsMock->expects($this->never())->method('deleteVoterReport');
    $this->messengerMock->expects($this->once())->method('addError');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportDeleteWarnsWhenTheReportIsGone(): void {
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $this->reportsMock->method('getNlpReport')->willReturn([]);
    $formState->setTriggeringElement(['#name' => 'report_delete-77', '#value' => 'Delete']);
    $this->dataEntryReportsMock->expects($this->never())->method('deleteVoterReport');
    $this->messengerMock->expects($this->once())->method('addWarning');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportSaveSavesTheTextInTheReportsEditBox(): void {
    $formState = $this->reportButtonFormState('report_save', 'New note');
    $this->reportsMock->method('canEditReport')->willReturn(TRUE);
    $this->reportsMock->method('isTextEditable')->willReturn(TRUE);
    $this->dataEntryReportsMock->expects($this->once())->method('editReportText')
      ->with($this->editableReport(), 'New note');
    $this->messengerMock->expects($this->once())->method('addStatus')->with('The report was changed.');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportSaveWarnsAndSavesNothingForAnInvalidCorrection(): void {
    $formState = $this->reportButtonFormState('report_save', '503-555');
    $this->reportsMock->method('canEditReport')->willReturn(TRUE);
    $this->reportsMock->method('isTextEditable')->willReturn(TRUE);
    $this->dataEntryReportsMock->method('reportTextProblem')->willReturn('Enter a valid phone number.');
    $this->dataEntryReportsMock->expects($this->never())->method('editReportText');
    $this->messengerMock->expects($this->once())->method('addWarning')->with('Enter a valid phone number.');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportSaveRefusedWhenTheTextCantChange(): void {
    $formState = $this->reportButtonFormState('report_save', 'New note');
    $this->reportsMock->method('canEditReport')->willReturn(TRUE);
    $this->reportsMock->method('isTextEditable')->willReturn(FALSE);
    $this->dataEntryReportsMock->expects($this->never())->method('editReportText');
    $this->messengerMock->expects($this->once())->method('addError');

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * Drupal caches the form between requests.  Every service must come back
   * when the cached form is unserialized, or a later submit (e.g. a report's
   * Delete button) fails with "must not be accessed before initialization".
   */
  public function testEveryServiceIsRestoredWhenTheCachedFormIsUnserialized(): void {
    // Every object the form holds is a service, as in Drupal, so the form
    // serializes each one as its service ID.  (The mocks can't be serialized.)
    $container = \Drupal::getContainer();
    $services = [];
    foreach ((new \ReflectionObject($this->form))->getProperties() as $property) {
      if (!$property->isInitialized($this->form) || !is_object($property->getValue($this->form))) {
        continue;
      }
      $container->set('test.' . $property->getName(), $property->getValue($this->form));
      $services[] = $property;
    }
    $container->set(ReverseContainer::class, new ReverseContainer($container));
    // The constructor's services are the ones this test is about.
    $constructorServices = array_map(static fn(\ReflectionParameter $p) => $p->getName(),
      (new \ReflectionMethod(DataEntryForm::class, '__construct'))->getParameters());
    $this->assertNotEmpty(array_intersect($constructorServices,
      array_map(static fn(\ReflectionProperty $p) => $p->getName(), $services)));

    $copy = unserialize(serialize($this->form));

    foreach ($services as $property) {
      $name = $property->getName();
      $this->assertTrue($property->isInitialized($copy), "$name was not restored.");
      $this->assertSame($property->getValue($this->form), $property->getValue($copy), "$name was not restored.");
    }
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
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(5);
    $this->nlpConfigurationMock->method('getContactId')->willReturn(3);
    $this->votersMock->method('getVoterCd')->with(111111)->willReturn(4);

    // Voter 111111 is selected (truthy), 222222 is not (0 = already sent).
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->with($this->callback(static function (array $report): bool {
        return $report['vanid'] === 111111 && $report['lastName'] === 'Doe' && $report['cd'] === 4;
      }));

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
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturnMap([['canvassDate', '2026-10-12']]);
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(5);
    $this->nlpConfigurationMock->method('getContactId')->willReturn(3);

    $reportedDate = NULL;
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->willReturnCallback(static function (array $report) use (&$reportedDate): string {
        $reportedDate = $report['contactDate'];
        return NlpDataEntryReports::SAVED;
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

  public function testSubmitFormPostcardSelectWarnsAndSavesNothingWhenNlMissing(): void {
    $this->nlsMock->method('getNlById')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning')
      ->with(DataEntryForm::NL_MISSING_MSG);
    $this->dataEntryReportsMock->expects($this->never())->method('noContactResponse');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->set('mcid', 101);
    $formState->setValue('postcardsSentList', [111111 => 111111]);
    $formState->setTriggeringElement(['#name' => 'postcardSelect', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);
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

  // ---------------------------------------------------------------------------
  // submitForm — double-submit protection
  // ---------------------------------------------------------------------------

  /**
   * A survey response action posted from the page with the given build id.
   */
  protected function buildPostedActionFormState(string $buildId = 'form-abc'): FormState {
    $formState = $this->buildActionFormState(99999, [
      'type' => 'surveyResponse', 'contactMethod' => 1,
    ]);
    $formState->setUserInput(['form_build_id' => $buildId, 'op' => 'Submit']);
    return $formState;
  }

  public function testSubmitFormSavesAndRemembersSubmission(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('pledgeToVote');
    $this->savedSubmissionsMock->expects($this->once())->method('setWithExpire')
      ->with($this->callback('is_string'), TRUE, DataEntryForm::SAVED_SUBMISSION_SECS);
    $this->lockMock->expects($this->once())->method('release');

    $formState = $this->buildPostedActionFormState();
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormIgnoresSecondCopyOfSavedSubmission(): void {
    $savedSubmissions = $this->createMock(KeyValueStoreExpirableInterface::class);
    $savedSubmissions->method('get')->willReturn(TRUE);
    $form = $this->newForm($this->lockMock, $savedSubmissions);
    $this->dataEntryReportsMock->expects($this->never())->method('pledgeToVote');
    $savedSubmissions->expects($this->never())->method('setWithExpire');
    $this->messengerMock->expects($this->once())->method('addStatus')
      ->with(DataEntryForm::ALREADY_SAVED_MSG);

    $formState = $this->buildPostedActionFormState();
    $build = [];
    $form->submitForm($build, $formState);
  }

  public function testSubmitFormWaitsThenGivesUpWhileOtherCopyIsSaving(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $lock->expects($this->once())->method('wait');
    $form = $this->newForm($lock, $this->savedSubmissionsMock);
    $this->dataEntryReportsMock->expects($this->never())->method('pledgeToVote');
    $this->messengerMock->expects($this->once())->method('addWarning')
      ->with(DataEntryForm::SAVE_IN_PROGRESS_MSG);

    $formState = $this->buildPostedActionFormState();
    $build = [];
    $form->submitForm($build, $formState);
  }

  public function testSubmitFormSavesAfterOtherCopyFinishesWithoutSaving(): void {
    // The other copy held the lock, then died before marking the submission saved.
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $lock->expects($this->once())->method('wait');
    $form = $this->newForm($lock, $this->savedSubmissionsMock);
    $this->dataEntryReportsMock->expects($this->once())->method('pledgeToVote');

    $formState = $this->buildPostedActionFormState();
    $build = [];
    $form->submitForm($build, $formState);
  }

  public function testSubmitFormWithoutChangesSkipsDuplicateCheck(): void {
    $this->lockMock->expects($this->never())->method('acquire');
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $this->configureSubmitBase($formState);
    $formState->setUserInput(['form_build_id' => 'form-abc']);
    $formState->setTriggeringElement(['#name' => 'next', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmissionKeyMatchesOnlyTheSamePost(): void {
    $key = new \ReflectionMethod(DataEntryForm::class, 'submissionKey');
    $post = static function (array $input): FormState {
      return (new FormState())->setUserInput($input);
    };
    $first = $key->invoke($this->form, $post(['form_build_id' => 'form-abc', 'note' => 'x']));

    $this->assertSame($first, $key->invoke($this->form, $post(['form_build_id' => 'form-abc', 'note' => 'x'])));
    $this->assertNotSame($first, $key->invoke($this->form, $post(['form_build_id' => 'form-def', 'note' => 'x'])));
    $this->assertNotSame($first, $key->invoke($this->form, $post(['form_build_id' => 'form-abc', 'note' => 'y'])));
    $this->assertSame('', $key->invoke($this->form, $post(['note' => 'x'])));
  }


  public function testSubmitFormPostcardSelectIgnoresSecondCopy(): void {
    $savedSubmissions = $this->createMock(KeyValueStoreExpirableInterface::class);
    $savedSubmissions->method('get')->willReturn(TRUE);
    $form = $this->newForm($this->lockMock, $savedSubmissions);
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->dataEntryReportsMock->expects($this->never())->method('noContactResponse');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('turfIndex', 42);
    $formState->set('mcid', 101);
    $formState->setValue('postcardsSentList', [111111 => 111111]);
    $formState->setUserInput(['form_build_id' => 'form-abc', 'postcardsSentList' => [111111 => 111111]]);
    $formState->setTriggeringElement(['#name' => 'postcardSelect', '#value' => '']);

    $build = [];
    $form->submitForm($build, $formState);
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
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')->willReturn(NlpDataEntryReports::SAVED);
    $this->messengerMock->expects($this->never())->method('addWarning');
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormNotContactedWarnsWhenResponseNotSaved(): void {
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::NOT_SAVED);
    $this->messengerMock->expects($this->once())->method('addWarning')
      ->with($this->stringContains('Voter Person'));
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // submitForm — each voter's save results and the summary
  // ---------------------------------------------------------------------------

  /**
   * The messages addStatus() was given, as strings.
   */
  protected function captureStatusMessages(): \ArrayObject {
    $messages = new \ArrayObject();
    $this->messengerMock->method('addStatus')->willReturnCallback(
      function ($message) use ($messages) {
        $messages[] = (string) $message;
        return $this->messengerMock;
      });
    return $messages;
  }

  public function testSaveShowsTheVoterTheAttemptWasSaved(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Left message');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::SAVED);
    $messages = $this->captureStatusMessages();
    $formState = $this->buildActionFormState(99999,
      ['type' => 'notContacted', 'contactMethod' => 1, 'notContacted' => 2]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame([99999 => [['status' => NlpDataEntryReports::SAVED, 'text' => '[Phone] Left message']]],
      $formState->get('saveResults'));
    $this->assertSame(['Your reports were saved for Voter Person.  Thank you!'], $messages->getArrayCopy());
  }

  public function testSaveShowsTheVoterADuplicateWasNotSavedAgain(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Left message');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::DUPLICATE);
    $messages = $this->captureStatusMessages();
    $formState = $this->buildActionFormState(99999,
      ['type' => 'notContacted', 'contactMethod' => 1, 'notContacted' => 2]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame(NlpDataEntryReports::DUPLICATE, $formState->get('saveResults')[99999][0]['status']);
    $this->assertSame(['Already reported, so not saved again: Voter Person ([Phone] Left message, already saved for that date).'],
      $messages->getArrayCopy());
  }

  public function testSaveShowsTheVoterASecondPostcardWasNotSaved(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Postcard');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Mailed');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::ALREADY_MAILED);
    $messages = $this->captureStatusMessages();
    $formState = $this->buildActionFormState(99999,
      ['type' => 'notContacted', 'contactMethod' => 2, 'notContacted' => 9]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame(NlpDataEntryReports::ALREADY_MAILED, $formState->get('saveResults')[99999][0]['status']);
    $this->assertSame(['Already reported, so not saved again: Voter Person (a postcard was already reported this election).'],
      $messages->getArrayCopy());
  }

  public function testTheSummaryNamesEachVoterSavedAndEachVoterSkipped(): void {
    $voter = static fn(int $vanid, string $first, string $last) => [
      'vanid' => $vanid, 'cd' => 'CD1', 'firstName' => $first, 'lastName' => $last, 'reportIndex' => 0];
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->set('voterArrays', [
      1 => $voter(1, 'Ann', 'Lee'), 2 => $voter(2, 'Bob', 'Smith'),
      3 => $voter(3, 'Carl', 'Diaz'), 4 => $voter(4, 'Dee', 'Fox'),
    ]);
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 'Save reports']);
    $attempt = ['type' => 'notContacted', 'contactMethod' => 1, 'notContacted' => 2];
    $note = ['type' => 'note', 'note' => 'Call back', 'contactMethod' => 1];
    // Ann: an attempt and a note (named once); Bob: a duplicate attempt;
    // Carl: an attempt; Dee: a duplicate attempt and a saved note.
    $this->dataEntryVoterListDisplayMock = $this->createMock(NlpDataEntryVoterListDisplay::class);
    $this->dataEntryVoterListDisplayMock->method('validateDataEntrySelection')->willReturn([]);
    $this->dataEntryVoterListDisplayMock->method('whatChanged')->willReturn([
      1 => [$attempt, $note], 2 => [$attempt], 3 => [$attempt], 4 => [$attempt, $note]]);
    $this->form = $this->newForm($this->lockMock, $this->savedSubmissionsMock);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([1 => 'Phone']);
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Left message');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturnCallback(
      static fn(array $report) => in_array($report['vanid'], [2, 4], TRUE)
        ? NlpDataEntryReports::DUPLICATE : NlpDataEntryReports::SAVED);
    $messages = $this->captureStatusMessages();

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame([
      'Your reports were saved for Ann Lee, Carl Diaz and Dee Fox.  Thank you!',
      'Already reported, so not saved again: Bob Smith ([Phone] Left message, already saved for that date), '
        . 'Dee Fox ([Phone] Left message, already saved for that date).',
    ], $messages->getArrayCopy());
  }

  public function testAFutureContactDateIsRejectedBeforeAnythingIsSaved(): void {
    $formState = new FormState();
    $formState->setValue('canvassDate', date('Y-m-d', strtotime('+2 days')));
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 'Save reports']);

    $form = [];
    $this->form->validateForm($form, $formState);

    $this->assertStringContainsString(NlpContactDate::FUTURE_MSG,
      (string) $formState->getErrors()['canvassDate']);
  }

  public function testTodayAsTheContactDateIsAccepted(): void {
    $formState = new FormState();
    $formState->setValue('canvassDate', date('Y-m-d'));
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 'Save reports']);

    $form = [];
    $this->form->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testReportsSavedForAnOldContactDateGetTheReminder(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Left message');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::SAVED);
    $oldDate = date('Y-m-d', strtotime('-5 days'));
    $this->sessionDataMock->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'canvassDate' ? $oldDate : NULL);
    $this->messengerMock->expects($this->once())->method('addWarning')->with(NlpContactDate::LATE_MSG);
    $formState = $this->buildActionFormState(99999,
      ['type' => 'notContacted', 'contactMethod' => 1, 'notContacted' => 2]);

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testReportsSavedForARecentContactDateGetNoReminder(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Left message');
    $this->dataEntryReportsMock->method('noContactResponse')->willReturn(NlpDataEntryReports::SAVED);
    $recentDate = date('Y-m-d', strtotime('-3 days'));
    $this->sessionDataMock->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'canvassDate' ? $recentDate : NULL);
    $this->messengerMock->expects($this->never())->method('addWarning');
    $formState = $this->buildActionFormState(99999,
      ['type' => 'notContacted', 'contactMethod' => 1, 'notContacted' => 2]);

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSaveOfNothingLeavesNoResultsAndNoMessage(): void {
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => '']);
    $this->messengerMock->expects($this->never())->method('addStatus');

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame([], $formState->get('saveResults'));
  }

  public function testADuplicateNewCellNumberIsNotSavedAgain(): void {
    $this->reportsMock->method('isDuplicateReport')
      ->with($this->callback(static fn($r) => $r['type'] === 'Change' && $r['value'] === 'New cell number'
        && $r['text'] === '503-555-1234'))
      ->willReturn(TRUE);
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $this->votersMock->expects($this->never())->method('updateVoterPhone');
    $formState = $this->buildActionFormState(99999, ['type' => 'updateContact', 'contactMethod' => 0,
      'updateContact' => ['contactChange' => 'NC', 'newContact' => '503-555-1234']]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame([['status' => NlpDataEntryReports::DUPLICATE, 'text' => 'New cell number 503-555-1234']],
      $formState->get('saveResults')[99999]);
  }

  public function testANewEmailIsSavedWhenNotADuplicate(): void {
    $this->reportsMock->method('isDuplicateReport')->willReturn(FALSE);
    $this->reportsMock->expects($this->once())->method('setNlReport')
      ->with($this->callback(static fn($r) => $r['type'] === 'Change' && $r['text'] === 'a@b.org'));
    $formState = $this->buildActionFormState(99999, ['type' => 'updateContact', 'contactMethod' => 0,
      'updateContact' => ['contactChange' => 'NE', 'newContact' => 'a@b.org']]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame([['status' => NlpDataEntryReports::SAVED, 'text' => 'New email a@b.org']],
      $formState->get('saveResults')[99999]);
  }

  public function testAnInvalidNewEmailIsShownAsNotSaved(): void {
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, ['type' => 'updateContact', 'contactMethod' => 0,
      'updateContact' => ['contactChange' => 'NE', 'newContact' => 'a@b']]);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame(NlpDataEntryReports::NOT_SAVED, $formState->get('saveResults')[99999][0]['status']);
  }

  public function testSubmitFormWarnsAndSavesNothingWhenNlMissing(): void {
    // Configured before buildActionFormState() so this stub matches first.
    $this->nlsMock->method('getNlById')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning')
      ->with(DataEntryForm::NL_MISSING_MSG);
    $this->dataEntryReportsMock->expects($this->never())->method('noContactResponse');
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormSaveReportsUsesChosenContactDate(): void {
    $reportedDate = NULL;
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse')
      ->willReturnCallback(static function (array $report) use (&$reportedDate): string {
        $reportedDate = $report['contactDate'];
        return NlpDataEntryReports::SAVED;
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
      ->willReturnCallback(static function (array $report) use (&$reportedDate): string {
        $reportedDate = $report['contactDate'];
        return NlpDataEntryReports::SAVED;
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

  public function testSubmitFormOptOutOfTextingSavesReportWithVoterCellPhone(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(function ($report) {
        return $report['text'] === '206-555-1234';
      }));
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'OO', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormSavesSubmitterIpWithReport(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(function ($report) {
        return $report['reportIp'] === '203.0.113.7';
      }));
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'OO', 'newContact' => ''],
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormOptOutOfTextingMissingCellPhoneSkipsReport(): void {
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'updateContact',
      'updateContact' => ['contactChange' => 'OO', 'newContact' => ''],
    ]);
    $voterArrays = $formState->get('voterArrays');
    $voterArrays[99999]['cellPhone'] = '';
    $formState->set('voterArrays', $voterArrays);
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
