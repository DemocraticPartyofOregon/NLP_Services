<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\TurfDeliveryMessageForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\TurfDeliveryMessageForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\TurfDeliveryMessageForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\TurfDeliveryMessageForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\TurfDeliveryMessageForm::class, 'submitForm')]
#[Group('nlpservices')]
class TurfDeliveryMessageFormTest extends UnitTestCase {

  private NlpConfiguration $nlpConfigurationMock;
  private NlpSessionData $sessionDataMock;
  private NlpTurfDeliveryMessage $turfMsgMock;
  private DrupalUser $drupalUserMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    $this->nlpConfigurationMock->method('getCountyNames')->willReturn(['TestCounty']);
    $this->nlpConfigurationMock->method('getStateName')->willReturn('Oregon');

    $this->sessionDataMock = $this->createMock(NlpSessionData::class);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');

    $this->turfMsgMock    = $this->createMock(NlpTurfDeliveryMessage::class);
    $this->drupalUserMock = $this->createMock(DrupalUser::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);

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

  private function buildFormInstance(): TurfDeliveryMessageForm {
    $form = new TurfDeliveryMessageForm(
      $this->nlpConfigurationMock,
      $this->sessionDataMock,
      $this->turfMsgMock,
      $this->drupalUserMock,
    );
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
    foreach ($extras as $k => $v) {
      $state->set($k, $v);
    }
    return $state;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame(
      'nlpservices_turf_delivery_message_form',
      $this->buildFormInstance()->getFormId()
    );
  }

  // -------------------------------------------------------------------------
  // buildForm — first render, admin user
  // -------------------------------------------------------------------------

  public function testBuildFormFirstRenderAdminSetsPageToCountySelect(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  public function testBuildFormFirstRenderAdminSetsAdminTrue(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('admin'));
  }

  // -------------------------------------------------------------------------
  // buildForm — first render, non-admin user
  // -------------------------------------------------------------------------

  public function testBuildFormFirstRenderNonAdminSetsPageToEditCounty(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('edit_county', $formState->get('page'));
  }

  public function testBuildFormFirstRenderNonAdminSetsAdminFalse(): void {
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertFalse($formState->get('admin'));
  }

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

  public function testBuildFormSkipsInitWhenReenterAlreadySet(): void {
    $this->drupalUserMock->expects($this->never())->method('isNlpAdminUser');
    $this->turfMsgMock->expects($this->never())->method('getTurfMsg');

    $formState = $this->reenterState('county_select');
    $this->buildFormInstance()->buildForm([], $formState);
  }

  // -------------------------------------------------------------------------
  // buildForm — page rendering
  // -------------------------------------------------------------------------

  public function testBuildFormCountySelectPageContainsSelectAndSubmit(): void {
    $formState = $this->reenterState('county_select');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('county_select', $form);
    $this->assertSame('select', $form['county_select']['#type']);
    $this->assertArrayHasKey('county_select_submit', $form);
    $this->assertSame('submit', $form['county_select_submit']['#type']);
  }

  public function testBuildFormCountySelectOffersStateOptionFirst(): void {
    $formState = $this->reenterState('county_select');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $options = $form['county_select']['#options'];
    $this->assertSame(TurfDeliveryMessageForm::STATE_OPTION, array_key_first($options));
    $this->assertStringContainsString('Oregon', $options[TurfDeliveryMessageForm::STATE_OPTION]);
    $this->assertSame('TestCounty', $options['TestCounty']);
  }

  public function testBuildFormEditCountyHeadingUsesSelectedCounty(): void {
    $formState = $this->reenterState('edit_county', [
      'county_selected' => 'OtherCounty',
      'countyMsg'       => '',
    ]);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertStringContainsString('OtherCounty', $form['edit-box']['county-name']['#markup']);
  }

  public function testBuildFormEditStatePageContainsBodyAndSubmit(): void {
    $formState = $this->reenterState('edit_state', ['stateMsg' => '<p>Hello</p>']);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('edit-box', $form);
    $this->assertArrayHasKey('body', $form['edit-box']);
    $this->assertSame('text_format', $form['edit-box']['body']['#type']);
    $this->assertArrayHasKey('state_edit', $form['edit-box']);
    $this->assertSame('submit', $form['edit-box']['state_edit']['#type']);
  }

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

  public function testBuildFormAttachesLibrary(): void {
    $formState = $this->reenterState('county_select');

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertContains('nlpservices/turf-delivery-message', $form['#attached']['library']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormSetsReenterAndCallsSetRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->set('page', 'county_select');
    $formState->setValue('county_select', 'TestCounty');

    $this->turfMsgMock->method('getTurfMsg')->willReturn('');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  public function testSubmitFormCountySelectStateSelectedFetchesStateMsgAndSetsPageToEditState(): void {
    $this->turfMsgMock->expects($this->once())
      ->method('getTurfMsg')
      ->with()
      ->willReturn('<p>State @coordinatorContactInfo</p>');

    $formState = new FormState();
    $formState->set('page', 'county_select');
    $formState->setValue('county_select', TurfDeliveryMessageForm::STATE_OPTION);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_state', $formState->get('page'));
    $this->assertSame('<p>State @coordinatorContactInfo</p>', $formState->get('stateMsg'));
  }

  public function testSubmitFormCountySelectCountySelectedFetchesCountyMsgAndSetsPageToEditCounty(): void {
    $this->turfMsgMock->expects($this->once())
      ->method('getTurfMsg')
      ->with('TestCounty')
      ->willReturn('<p>County @coordinatorContactInfo</p>');

    $formState = new FormState();
    $formState->set('page', 'county_select');
    $formState->setValue('county_select', 'TestCounty');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_county', $formState->get('page'));
    $this->assertSame('TestCounty', $formState->get('county_selected'));
    $this->assertSame('<p>County @coordinatorContactInfo</p>', $formState->get('countyMsg'));
  }

  public function testSubmitFormEditStateMissingTagAddsErrorAndDoesNotCallPutTurfMsg(): void {
    $this->turfMsgMock->expects($this->never())->method('putTurfMsg');
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEditStateMissingTagDoesNotChangePageFromEditState(): void {
    $this->messengerMock->method('addError')->willReturn(NULL);
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_state', $formState->get('page'));
  }

  public function testSubmitFormEditStateWithTagCallsPutTurfMsgAndSetsPageToCountySelect(): void {
    $msg = '<p>Hello @coordinatorContactInfo</p>';
    $this->turfMsgMock->expects($this->once())->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  public function testSubmitFormEditStateTagAtStartIsAccepted(): void {
    $msg = '@coordinatorContactInfo is first';
    $this->messengerMock->expects($this->never())->method('addError');
    $this->turfMsgMock->expects($this->once())->method('putTurfMsg')->with('', $msg);

    $formState = new FormState();
    $formState->set('page', 'edit_state');
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEditCountyMissingTagAddsErrorAndDoesNotCallPutTurfMsg(): void {
    $this->turfMsgMock->expects($this->never())->method('putTurfMsg');
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->setValue('body', ['value' => '<p>No tag here</p>']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEditCountyWithTagCallsPutTurfMsg(): void {
    $msg = '<p>Welcome to @coordinatorContactInfo</p>';
    $this->turfMsgMock->expects($this->once())
      ->method('putTurfMsg')
      ->with('TestCounty', $msg);

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEditCountyAdminReturnsToCountySelectAfterSave(): void {
    $msg = '<p>@coordinatorContactInfo info here</p>';
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', TRUE);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('county_select', $formState->get('page'));
  }

  public function testSubmitFormEditCountyNonAdminStaysOnEditCountyAfterSave(): void {
    $msg = '<p>@coordinatorContactInfo info here</p>';
    $this->turfMsgMock->method('putTurfMsg');

    $formState = new FormState();
    $formState->set('page', 'edit_county');
    $formState->set('county_selected', 'TestCounty');
    $formState->set('admin', FALSE);
    $formState->setValue('body', ['value' => $msg]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('edit_county', $formState->get('page'));
  }

}
