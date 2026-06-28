<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\TurfDeliveryMessageForm;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\TurfDeliveryMessageForm
 * @group nlpservices
 */
class TurfDeliveryMessageFormTest extends UnitTestCase {

  private ConfigFactoryInterface $configFactoryMock;
  private ImmutableConfig $configMock;
  private NlpTurfDeliveryMessage $turfMsgMock;
  private DrupalUser $drupalUserMock;
  private PrivateTempStoreFactory $tempStoreFactoryMock;
  private PrivateTempStore $tempStoreMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->configMock = $this->createMock(ImmutableConfig::class);
    $this->configMock->method('get')
      ->with('nlpservices-county-names')
      ->willReturn(['State' => 'Oregon', 'TestCounty' => 'TestCounty']);

    $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactoryMock->method('get')
      ->with('nlpservices.configuration')
      ->willReturn($this->configMock);

    $this->turfMsgMock    = $this->createMock(NlpTurfDeliveryMessage::class);
    $this->drupalUserMock = $this->createMock(DrupalUser::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);

    $this->tempStoreMock = $this->createMock(PrivateTempStore::class);
    $this->tempStoreMock->method('get')->with('County')->willReturn('TestCounty');

    $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStoreFactoryMock->method('get')
      ->with('nlpservices.session_data')
      ->willReturn($this->tempStoreMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('tempstore.private', $this->tempStoreFactoryMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): TurfDeliveryMessageForm {
    $form = new TurfDeliveryMessageForm(
      $this->configFactoryMock,
      $this->turfMsgMock,
      $this->drupalUserMock,
    );
    $form->setConfigFactory($this->configFactoryMock);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Return a FormState pre-loaded for re-render on the given page.
   */
  private function reenterState(string $page, array $extras = []): FormState {
    $state = new FormState();
    $state->set('reenter', TRUE);
    $state->set('page', $page);
    $state->set('county', 'TestCounty');
    $state->set('state', 'Oregon');
    $state->set('countyNames', ['State', 'TestCounty']);
    foreach ($extras as $k => $v) {
      $state->set($k, $v);
    }
    return $state;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame(
      'nlpservices_turf_delivery_message_form',
      $this->buildFormInstance()->getFormId()
    );
  }

  // -------------------------------------------------------------------------
  // buildForm — first render, admin user
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderAdminSetsPageToCountySelect(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderAdminSetsAdminTrue(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('admin'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderAdminSetsCountyFromTempStore(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderAdminStoresCountyNamesAsKeys(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame(['State', 'TestCounty'], $formState->get('countyNames'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderAdminStoresStateValue(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('Oregon', $formState->get('state'));
  }

  // -------------------------------------------------------------------------
  // buildForm — first render, non-admin user
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderNonAdminSetsPageToEditCounty(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('edit_county', $formState->get('page'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderNonAdminSetsAdminFalse(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertFalse($formState->get('admin'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderNonAdminFetchesCountyMsg(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->expects($this->once())
      ->method('getTurfMsg')
      ->with('TestCounty')
      ->willReturn('<p>Welcome @coordinatorContactInfo</p>');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('<p>Welcome @coordinatorContactInfo</p>', $formState->get('countyMsg'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFirstRenderNonAdminSetsCountySelected(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county_selected'));
  }

  // -------------------------------------------------------------------------
  // buildForm — skips init on re-render
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSkipsInitWhenReenterAlreadySet(): void {
    $this->drupalUserMock->expects($this->never())->method('isNlpAdminUser');
    $this->turfMsgMock->expects($this->never())->method('getTurfMsg');

    $formState = $this->reenterState('county_select');
    $this->buildFormInstance()->buildForm([], $formState);
  }

  // -------------------------------------------------------------------------
  // buildForm — page rendering
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormCountySelectPageContainsSelectAndSubmit(): void {
    $formState = $this->reenterState('county_select');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('county_select', $form);
    $this->assertSame('select', $form['county_select']['#type']);
    $this->assertArrayHasKey('county_select_submit', $form);
    $this->assertSame('submit', $form['county_select_submit']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormEditStatePageContainsBodyAndSubmit(): void {
    $formState = $this->reenterState('edit_state', ['stateMsg' => '<p>Hello</p>']);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('edit-box', $form);
    $this->assertArrayHasKey('body', $form['edit-box']);
    $this->assertSame('text_format', $form['edit-box']['body']['#type']);
    $this->assertArrayHasKey('state_edit', $form['edit-box']);
    $this->assertSame('submit', $form['edit-box']['state_edit']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormEditCountyPageContainsBodyAndSubmit(): void {
    $formState = $this->reenterState('edit_county', [
      'county_selected' => 'TestCounty',
      'countyMsg'       => '<p>County msg</p>',
    ]);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('edit-box', $form);
    $this->assertArrayHasKey('body', $form['edit-box']);
    $this->assertSame('text_format', $form['edit-box']['body']['#type']);
    $this->assertArrayHasKey('county_edit', $form['edit-box']);
    $this->assertSame('submit', $form['edit-box']['county_edit']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormAttachesLibrary(): void {
    $formState = $this->reenterState('county_select');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertContains('nlpservices/turf-delivery-message', $form['#attached']['library']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSetsReenterAndCallsSetRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->set('page', 'county_select');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('county_select', 1);

    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormCountySelectStateSelectedFetchesStateMsgAndSetsPageToEditState(): void {
    $this->turfMsgMock->expects($this->once())
      ->method('getTurfMsg')
      ->with()
      ->willReturn('<p>State @coordinatorContactInfo</p>');

    $formState = new FormState();
    $formState->set('page', 'county_select');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('county_select', 0);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_state', $formState->get('page'));
    $this->assertSame('<p>State @coordinatorContactInfo</p>', $formState->get('stateMsg'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormCountySelectCountySelectedFetchesCountyMsgAndSetsPageToEditCounty(): void {
    $this->turfMsgMock->expects($this->once())
      ->method('getTurfMsg')
      ->with('TestCounty')
      ->willReturn('<p>County @coordinatorContactInfo</p>');

    $formState = new FormState();
    $formState->set('page', 'county_select');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('county_select', 1);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_county', $formState->get('page'));
    $this->assertSame('TestCounty', $formState->get('county_selected'));
    $this->assertSame('<p>County @coordinatorContactInfo</p>', $formState->get('countyMsg'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditStateMissingTagAddsErrorAndDoesNotCallPutTurfMsg(): void {
    $this->turfMsgMock->expects($this->never())->method('putTurfMsg');
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditStateMissingTagDoesNotChangePageFromEditState(): void {
    $this->messengerMock->method('addError')->willReturn(NULL);
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_state', $formState->get('page'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditStateWithTagCallsPutTurfMsgAndSetsPageToCountySelect(): void {
    $msg = '<p>Hello @coordinatorContactInfo</p>';
    $this->turfMsgMock->expects($this->once())->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditCountyMissingTagAddsErrorAndDoesNotCallPutTurfMsg(): void {
    $this->turfMsgMock->expects($this->never())->method('putTurfMsg');
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditCountyWithTagCallsPutTurfMsg(): void {
    $msg = '<p>Welcome to @coordinatorContactInfo</p>';
    $this->turfMsgMock->expects($this->once())
      ->method('putTurfMsg')
      ->with('TestCounty', $msg);

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditCountyAdminReturnsToCountySelectAfterSave(): void {
    $msg = '<p>@coordinatorContactInfo info here</p>';
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormEditCountyNonAdminStaysOnEditCountyAfterSave(): void {
    $msg = '<p>@coordinatorContactInfo info here</p>';
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', FALSE);
    $formState->set('countyNames', ['State', 'TestCounty']);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_county', $formState->get('page'));
  }

}
