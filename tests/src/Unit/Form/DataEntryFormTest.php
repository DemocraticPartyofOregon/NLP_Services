<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
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
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\ApiMapRegions;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\DataEntryForm
 * @group nlpservices
 */
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
  protected $lockMock;
  protected $savedSubmissionsMock;
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

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_data_entry_form', $this->form->getFormId());
  }

  // ---------------------------------------------------------------------------
  // initializeDataReporting
  // ---------------------------------------------------------------------------

  /**
   * @covers ::initializeDataReporting
   */
  public function testInitializeDataReportingReturnsFalseWhenMcidMissing(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->turfsMock->method('getTurfHD')->willReturn([]);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => NULL]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->form->initializeDataReporting(new FormState());

    $this->assertFalse($result);
  }

  /**
   * @covers ::initializeDataReporting
   */
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

  /**
   * @covers ::initializeDataReporting
   */
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

  /**
   * @covers ::initializeDataReporting
   */
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

  /**
   * @covers ::buildForm
   */
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

  /**
   * @covers ::buildForm
   */
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

  /**
   * @covers ::buildForm
   */
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

  /**
   * @covers ::buildForm
   */
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
   * @covers ::buildForm
   */
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

  /**
   * @covers ::buildForm
   */
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
   *
   * @covers ::buildForm
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

  /**
   * @covers ::validateForm
   */
  public function testValidateFormIgnoresNonContactMethodTrigger(): void {
    $this->nlpConfigurationMock->expects($this->never())->method('getContactMethods');
    $this->sessionDataMock->expects($this->never())->method('setVoterContactMethod');

    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => 0]);
    $form = [];
    $this->form->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
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

  /**
   * @covers ::validateForm
   * @covers ::submitForm
   */
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

  /**
   * @covers ::expiredFormCallback
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAlwaysSetsRebuildAndReenter(): void {
    $formState = new FormState();
    $this->configureSubmitBase($formState);
    $formState->setTriggeringElement(['#name' => 'save_reports', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPostcardSubmitSetsPage(): void {
    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->setTriggeringElement(['#name' => 'postcardSubmit', '#value' => '']);

    $form = [];
    $this->form->submitForm($form, $formState);

    $this->assertSame('postcardsSent', $formState->get('page'));
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSavesAndRemembersSubmission(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('pledgeToVote');
    $this->savedSubmissionsMock->expects($this->once())->method('setWithExpire')
      ->with($this->callback('is_string'), TRUE, DataEntryForm::SAVED_SUBMISSION_SECS);
    $this->lockMock->expects($this->once())->method('release');

    $formState = $this->buildPostedActionFormState();
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSurveyResponseWithPostcardContactMethodShowsWarning(): void {
    $this->messengerMock->expects($this->once())->method('addWarning');
    $this->dataEntryReportsMock->expects($this->never())->method('pledgeToVote');
    $formState = $this->buildActionFormState(99999, [
      'type' => 'surveyResponse', 'contactMethod' => 2,
    ]);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNotContactedCallsNoContactResponse(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('noContactResponse');
    $formState = $this->buildActionFormState(99999, ['type' => 'notContacted']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormMovedCallsVoterMoved(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('voterMoved');
    $formState = $this->buildActionFormState(99999, ['type' => 'moved']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormHostileCallsHostileVoter(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('hostileVoter');
    $formState = $this->buildActionFormState(99999, ['type' => 'hostile']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeceasedCallsDeceasedVoter(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('deceasedVoter');
    $formState = $this->buildActionFormState(99999, ['type' => 'deceased']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAddNoteCallsAddNote(): void {
    $this->dataEntryReportsMock->expects($this->once())->method('addNote');
    $formState = $this->buildActionFormState(99999, ['type' => 'note']);
    $form = [];
    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::submitForm
   */
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

  /**
   * @covers ::validatePhoneNumber
   * @dataProvider validPhoneProvider
   */
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

  /**
   * @covers ::validatePhoneNumber
   * @dataProvider invalidPhoneProvider
   */
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
