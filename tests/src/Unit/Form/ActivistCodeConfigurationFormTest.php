<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\ApiActivistCodes;
use Drupal\nlpservices\Config\ActivistCodeConfigurationForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ActivistCodeConfigurationForm::class)]
#[Group('nlpservices')]
class ActivistCodeConfigurationFormTest extends UnitTestCase {

  private ApiActivistCodes $apiActivistCodesMock;
  private NlpConfiguration $configurationMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->apiActivistCodesMock = $this->createMock(ApiActivistCodes::class);
    $this->configurationMock    = $this->createMock(NlpConfiguration::class);
    $this->messengerMock        = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(): ActivistCodeConfigurationForm {
    $form = new ActivistCodeConfigurationForm(
      $this->apiActivistCodesMock,
      $this->configurationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function stubActivistCodes(): void {
    $this->apiActivistCodesMock->method('getApiActivistCodes')->willReturnMap([
      [0, [
        ['activistCodeId' => 11, 'name' => 'Hostile', 'type' => 'activist code'],
      ]],
      [1, [
        ['activistCodeId' => 22, 'name' => 'Dropout', 'type' => 'activist code'],
      ]],
    ]);
    $this->configurationMock->method('getActivistCode')->willReturn(0);
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('activist_code_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsActivistCodesContainer(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('activist_codes', $form);
  }

  public function testBuildFormContainsAllFourSelectFieldsets(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('nlp_hostile', $form['activist_codes']);
    $this->assertArrayHasKey('nlp_voter', $form['activist_codes']);
    $this->assertArrayHasKey('nlp_dropout', $form['activist_codes']);
    $this->assertArrayHasKey('nlp_not_registered', $form['activist_codes']);
  }

  public function testBuildFormSelectFieldUsesActivistCodeListAsOptions(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $options = $form['activist_codes']['nlp_hostile']['activist']['NLPHostileActivistCode']['#options'];
    $this->assertSame('Select an Activist Code', $options[1]);
    $this->assertSame('ID:11, name:"Hostile", type="activist code"', $options[11]);
  }

  public function testBuildFormMcListUsedForDropoutAndNotRegistered(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $dropoutOptions = $form['activist_codes']['nlp_dropout']['activist']['NLPDropoutActivistCode']['#options'];
    $this->assertSame('ID:22, name:"Dropout", type="activist code"', $dropoutOptions[22]);
  }

  public function testBuildFormShowsNotYetChosenWhenNoCurrentCodeSet(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $markup = $form['activist_codes']['nlp_hostile']['activist']['NLPHostilecurrentAC']['#markup'];
    $this->assertStringContainsString('Not yet chosen', $markup);
  }

  public function testBuildFormOmitsRemoveCheckboxWhenNoCurrentCodeSet(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayNotHasKey('NLPHostileRemoveAC', $form['activist_codes']['nlp_hostile']['activist']);
  }

  public function testBuildFormShowsRemoveCheckboxWhenCurrentCodeSet(): void {
    $this->apiActivistCodesMock->method('getApiActivistCodes')->willReturnMap([
      [0, [['activistCodeId' => 11, 'name' => 'Hostile', 'type' => 'activist code']]],
      [1, [['activistCodeId' => 22, 'name' => 'Dropout', 'type' => 'activist code']]],
    ]);
    $this->configurationMock->method('getActivistCode')->willReturnMap([
      ['NLPHostileAC', 11],
      ['NLPVoterAC', 0],
      ['NLPDropoutAC', 0],
      ['NLPNotRegisteredAC', 0],
    ]);
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('NLPHostileRemoveAC', $form['activist_codes']['nlp_hostile']['activist']);
    $this->assertSame('checkbox', $form['activist_codes']['nlp_hostile']['activist']['NLPHostileRemoveAC']['#type']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubActivistCodes();
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  private function baseSubmitValues(array $overrides = []): array {
    return array_merge([
      'NLPHostileRemoveAC' => '',
      'NLPHostileActivistCode' => '1',
      'NLPVoterRemoveAC' => '',
      'NLPVoterActivistCode' => '1',
      'NLPDropoutRemoveAC' => '',
      'NLPDropoutActivistCode' => '1',
      'NLPNotRegisteredRemoveAC' => '',
      'NLPNotRegisteredActivistCode' => '1',
    ], $overrides);
  }

  public function testSubmitFormSetsActivistCodeWhenValidSelectionMade(): void {
    $this->configurationMock->expects($this->once())
      ->method('setActivistCode')
      ->with('NLPHostileAC', 11);

    $formState = new FormState();
    $formState->setValues($this->baseSubmitValues(['NLPHostileActivistCode' => '11']));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormIgnoresSelectionOfPlaceholderOption(): void {
    $this->configurationMock->expects($this->never())->method('setActivistCode');

    $formState = new FormState();
    $formState->setValues($this->baseSubmitValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormClearsCodeWhenRemoveCheckboxChecked(): void {
    $this->configurationMock->expects($this->once())
      ->method('setActivistCode')
      ->with('NLPVoterAC', 0);

    $formState = new FormState();
    $formState->setValues($this->baseSubmitValues(['NLPVoterRemoveAC' => '1']));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormAddsStatusMessageForEachRemovedCode(): void {
    $this->messengerMock->expects($this->exactly(3))->method('addStatus');

    $formState = new FormState();
    $formState->setValues($this->baseSubmitValues([
      'NLPHostileRemoveAC' => '1',
      'NLPDropoutRemoveAC' => '1',
    ]));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormAddsFinalConfigurationSavedMessage(): void {
    $this->messengerMock->expects($this->atLeastOnce())
      ->method('addStatus')
      ->with($this->anything());

    $formState = new FormState();
    $formState->setValues($this->baseSubmitValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

}
