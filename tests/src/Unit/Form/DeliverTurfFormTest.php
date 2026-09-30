<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\ApiMapRegions;
use Drupal\nlpservices\Form\DeliverTurfForm;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCredentialsEmail;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpPaths;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\nlpservices\NlpTurfDisplay;
use Drupal\nlpservices\NlpTurfs;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\DeliverTurfForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'notifyUserCredentials')]
#[CoversMethod(\Drupal\nlpservices\Form\DeliverTurfForm::class, 'updateMinivanInfo')]
#[Group('nlpservices')]
class DeliverTurfFormTest extends UnitTestCase {

  private NlpTurfs $turfsMock;
  private NlpPaths $pathsMock;
  private FileSystemInterface $fileSystemMock;
  private NlpInstructions $instructionsMock;
  private NlpCoordinators $coordinatorsMock;
  private NlpCredentialsEmail $credentialsEmailMock;
  private NlpTurfDeliveryMessage $turfMsgMock;
  private NlpTurfDisplay $turfDisplayMock;
  private NlpSearchBar $searchBarMock;
  private NlpSessionData $sessionDataMock;
  private NlpNls $nlsMock;
  private ApiMapRegions $foldersMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->turfsMock          = $this->createMock(NlpTurfs::class);
    $this->pathsMock          = $this->createMock(NlpPaths::class);
    $this->fileSystemMock     = $this->createMock(FileSystemInterface::class);
    $this->instructionsMock   = $this->createMock(NlpInstructions::class);
    $this->coordinatorsMock   = $this->createMock(NlpCoordinators::class);
    $this->credentialsEmailMock = $this->createMock(NlpCredentialsEmail::class);
    $this->turfMsgMock        = $this->createMock(NlpTurfDeliveryMessage::class);
    $this->turfDisplayMock    = $this->createMock(NlpTurfDisplay::class);
    $this->searchBarMock      = $this->createMock(NlpSearchBar::class);
    $this->sessionDataMock    = $this->createMock(NlpSessionData::class);
    $this->nlsMock            = $this->createMock(NlpNls::class);
    $this->foldersMock        = $this->createMock(ApiMapRegions::class);
    $this->messengerMock      = $this->createMock(MessengerInterface::class);

    $loggerMock        = $this->createMock(LoggerInterface::class);
    $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($loggerMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('logger.factory', $loggerFactoryMock);
    Drupal::setContainer($container);

  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): DeliverTurfForm {
    $requestStack = new RequestStack();
    $requestStack->push(new Request([], [], [], [], [], ['SERVER_NAME' => 'test.example.com']));

    $form = new DeliverTurfForm(
      $this->turfsMock,
      $this->pathsMock,
      $this->fileSystemMock,
      $this->instructionsMock,
      $this->coordinatorsMock,
      $this->credentialsEmailMock,
      $this->turfMsgMock,
      $this->turfDisplayMock,
      $this->searchBarMock,
      $this->sessionDataMock,
      $this->nlsMock,
      $this->foldersMock,
      $requestStack,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function baseTurfChoice(int $turfIndex = 10): array {
    return [
      'turfIndex'        => $turfIndex,
      'turfHd'           => 'HD1',
      'turfPrecinct'     => '0042',
      'nlFirstName'      => 'Jane',
      'nlLastName'       => 'Doe',
      'mcid'             => 99,
      'minivanNumber'    => '',
      'minivanCreateDate'=> '',
    ];
  }

  /**
   * Configure the mocks common to a successful buildForm first render.
   */
  private function configureBuildFormMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->instructionsMock->method('getInstructions')
      ->willReturn(['canvass' => ['fileName' => 'walk.pdf'], 'postcard' => ['fileName' => '']]);
    $this->turfsMock->method('getTurfHD')->willReturn(['HD1', 'HD2']);
    $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All', 1 => 'HD1']);
    $this->turfsMock->method('getTurfs')->willReturn([10 => $this->baseTurfChoice()]);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);
    $this->turfDisplayMock->method('displayTurfs')->willReturn([]);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_deliver_turf_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormReturnsEmptyAndWarnsWhenCountyMissing(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  public function testBuildFormReturnsEmptyAndErrorsWhenNoInstructions(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->instructionsMock->method('getInstructions')
      ->willReturn(['canvass' => ['fileName' => ''], 'postcard' => ['fileName' => '']]);
    $this->messengerMock->expects($this->once())->method('addError');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  public function testBuildFormSetsCountyOnFirstRender(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county'));
  }

  public function testBuildFormSkipsInitialisationWhenReenterIsSet(): void {
    $this->configureBuildFormMocks();
    $this->instructionsMock->expects($this->never())->method('getInstructions');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');

    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormReturnsNoTurfMarkupWhenHdOptionsEmpty(): void {
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('turf-table', $form);
    $this->assertStringContainsString('No turf to display', $form['turf-table']['turf-form']['#markup']);
  }

  public function testBuildFormContainsExpectedFormElements(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('turf-form', $form);
    $this->assertArrayHasKey('footnote', $form);
    $this->assertArrayHasKey('turf_pdf', $form);
    $this->assertArrayHasKey('note', $form);
    $this->assertArrayHasKey('submit', $form);
  }

  public function testBuildFormShowsHdSelectorAndNoTurfMarkupWhenHdHasNoTurfs(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->instructionsMock->method('getInstructions')
      ->willReturn(['canvass' => ['fileName' => 'walk.pdf'], 'postcard' => ['fileName' => '']]);
    $this->turfsMock->method('getTurfHD')->willReturn([3, 5]);
    $this->turfsMock->method('getCountyTurfCount')->willReturn(500);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn('3');
    $this->sessionDataMock->method('getHdOptions')->willReturn([3, 5]);
    $this->turfsMock->method('getTurfs')->willReturn([]);
    $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame(['#type' => 'select'], $form['options_display']);
    $this->assertStringContainsString('no turf to display', $form['turf-table']['turf-form']['#markup']);
    $this->assertArrayNotHasKey('submit', $form);
  }

  public function testBuildFormDoesNotQueryTurfsWhenSavedHdIsEmpty(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->instructionsMock->method('getInstructions')
      ->willReturn(['canvass' => ['fileName' => 'walk.pdf'], 'postcard' => ['fileName' => '']]);
    $this->turfsMock->method('getTurfHD')->willReturn([3]);
    $this->turfsMock->method('getCountyTurfCount')->willReturn(500);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn('');
    $this->sessionDataMock->method('getHdOptions')->willReturn([3]);
    $this->turfsMock->expects($this->never())->method('getTurfs');
    $this->searchBarMock->method('searchBar')->willReturn([]);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertStringContainsString('no turf to display', $form['turf-table']['turf-form']['#markup']);
  }

  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertContains('nlpservices/nlp-table', $form['#attached']['library']);
    $this->assertContains('nlpservices/search-boxes', $form['#attached']['library']);
    $this->assertContains('nlpservices/hint-note', $form['#attached']['library']);
  }

  public function testBuildFormFiltersTurfsBySearchNeedle(): void {
    $allTurfs = [1 => ['turfIndex' => 1], 2 => ['turfIndex' => 2]];
    $filtered = [1 => ['turfIndex' => 1]];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getHdOptions')->willReturn([0 => 'All']);
    $this->turfsMock->method('getTurfs')->willReturn($allTurfs);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([]);
    $this->turfDisplayMock->method('displayTurfs')->willReturn([]);
    $this->searchBarMock->expects($this->once())
      ->method('searchInfoArray')
      ->with('Smith', $allTurfs)
      ->willReturn($filtered);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('search-needle', 'Smith');

    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertNull($formState->get('search-needle'));
    $this->assertSame($filtered, $formState->get('turfArray'));
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFormSearchSubmitStoresNeedle(): void {
    $this->sessionDataMock->expects($this->never())->method('saveHdIndex');

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
    $formState->method('getValue')->with('search-needle')->willReturn('Jones');

    $captured = [];
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame('Jones', $captured['search-needle'] ?? NULL);
    $this->assertTrue($captured['reenter'] ?? FALSE);
  }

  public function testValidateFormHdSelectSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
    $formState->method('getValue')->with('hd-select')->willReturn(2);
    $formState->method('set');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormNoSelectionSetsError(): void {
    $this->turfDisplayMock->method('validateTurfSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'submit']);
    $formState->method('getValues')->willReturn(['contacts' => []]);
    $formState->method('set');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('submit', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormValidSelectionStoresSelections(): void {
    $selections = [10 => ['turfIndex' => 10, 'mcid' => 99]];
    $this->turfDisplayMock->method('validateTurfSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'submit']);
    $formState->method('getValues')->willReturn(['contacts' => []]);
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['selections']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormSearchSubmitStoresNeedleAndSetsRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->atLeastOnce())->method('setRebuild');
    $formState->setValue('search-needle', 'Smith');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Smith', $formState->get('search-needle'));
    $this->assertTrue($formState->get('reenter'));
  }

  // -------------------------------------------------------------------------
  // notifyUserCredentials
  // -------------------------------------------------------------------------

  public function testNotifyUserCredentialsReturnsFalseWhenNoCoordinator(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->coordinatorsMock->method('getCoordinator')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addError');

    $result = $this->buildFormInstance()->notifyUserCredentials($this->baseTurfChoice(), '');

    $this->assertFalse($result);
  }

  public function testNotifyUserCredentialsReturnsFalseWhenEmailFails(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->coordinatorsMock->method('getCoordinator')->willReturn([
      'firstName' => 'Alice', 'lastName' => 'Smith', 'phone' => '555-1111', 'email' => 'a@b.com',
    ]);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('Hello @nickname @nlpLogin @coordinatorContactInfo @plainNote');
    $this->credentialsEmailMock->method('sendCredentialsEmail')->willReturn(FALSE);
    $this->messengerMock->expects($this->once())->method('addMessage');

    $result = $this->buildFormInstance()->notifyUserCredentials($this->baseTurfChoice(), '');

    $this->assertFalse($result);
  }

  public function testNotifyUserCredentialsReturnsTrueAndUpdatesStatusOnSuccess(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $coordinator = ['firstName' => 'Alice', 'lastName' => 'Smith', 'phone' => '555-1111', 'email' => 'a@b.com'];
    $this->coordinatorsMock->method('getCoordinator')->willReturn($coordinator);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('Hello @nlpLogin @coordinatorContactInfo @plainNote');

    $this->credentialsEmailMock->expects($this->once())
      ->method('sendCredentialsEmail')
      ->with(99, 'Hello @nlpLogin @coordinatorContactInfo @plainNote', '', [], $coordinator, 'deliver_turf')
      ->willReturn(TRUE);

    $this->nlsMock->expects($this->once())->method('updateNlStatus')->with(99, ['turfDelivered' => 'Y']);
    $this->turfsMock->expects($this->once())->method('setTurfDelivered')->with(10);
    $this->messengerMock->expects($this->once())->method('addMessage');

    $result = $this->buildFormInstance()->notifyUserCredentials($this->baseTurfChoice(), '');

    $this->assertTrue($result);
  }

  public function testNotifyUserCredentialsPassesMinivanInfoWhenPresent(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $coordinator = ['firstName' => 'Alice', 'lastName' => 'Smith', 'phone' => '555-1111', 'email' => 'a@b.com'];
    $this->coordinatorsMock->method('getCoordinator')->willReturn($coordinator);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('@nlpLogin @coordinatorContactInfo @plainNote @miniVanInfo');

    $turfChoice = $this->baseTurfChoice();
    $turfChoice['minivanNumber'] = '12345';
    $turfChoice['minivanCreateDate'] = '2026-05-01';

    $this->credentialsEmailMock->expects($this->once())
      ->method('sendCredentialsEmail')
      ->with(99, $this->anything(), '', ['minivanNumber' => '12345', 'minivanCreateDate' => '2026-05-01'], $coordinator, 'deliver_turf')
      ->willReturn(TRUE);
    $this->nlsMock->method('updateNlStatus');
    $this->turfsMock->method('setTurfDelivered');
    $this->messengerMock->method('addMessage');

    $this->buildFormInstance()->notifyUserCredentials($turfChoice, '');
  }

  // -------------------------------------------------------------------------
  // updateMinivanInfo
  // -------------------------------------------------------------------------

  public function testUpdateMinivanInfoReturnsTurfFromGetTurf(): void {
    $turf = ['turfIndex' => 7, 'mcid' => 99, 'minivanNumber' => '', 'minivanCreateDate' => ''];
    $this->turfsMock->method('getTurf')->willReturn($turf);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->foldersMock->method('updateMinivanInfo');

    $result = $this->buildFormInstance()->updateMinivanInfo(7);

    $this->assertSame($turf, $result);
  }

  public function testUpdateMinivanInfoCallsUpdateTurfFieldsWhenMinivanNumberPresent(): void {
    $turf = [
      'turfIndex'         => 7,
      'mcid'              => 99,
      'minivanNumber'     => '12345',
      'minivanCreateDate' => '2026-05-01',
    ];
    $this->turfsMock->method('getTurf')->willReturn($turf);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->foldersMock->method('updateMinivanInfo');
    $this->turfsMock->expects($this->once())
      ->method('updateTurfFields')
      ->with(7, $this->arrayHasKey('minivanNumber'));

    $this->buildFormInstance()->updateMinivanInfo(7);
  }

  public function testUpdateMinivanInfoSkipsUpdateTurfFieldsWhenNoMinivanNumber(): void {
    $turf = ['turfIndex' => 7, 'mcid' => 99, 'minivanNumber' => '', 'minivanCreateDate' => ''];
    $this->turfsMock->method('getTurf')->willReturn($turf);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->foldersMock->method('updateMinivanInfo');
    $this->turfsMock->expects($this->never())->method('updateTurfFields');

    $this->buildFormInstance()->updateMinivanInfo(7);
  }

}
