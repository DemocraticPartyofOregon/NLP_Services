<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Config\ElectionConfigurationForm;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ElectionConfigurationForm::class)]
#[Group('nlpservices')]
class ElectionConfigurationFormTest extends UnitTestCase {

  private ConfigFactoryInterface $configFactoryMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
    $this->messengerMock     = $this->createMock(MessengerInterface::class);

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

  private function buildForm(): ElectionConfigurationForm {
    $form = new ElectionConfigurationForm($this->configFactoryMock);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function stubStoredConfiguration(?array $electionConfiguration): void {
    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')
      ->with('nlpservices-election-configuration')
      ->willReturn($electionConfiguration);
    $this->configFactoryMock->method('get')
      ->with('nlpservices.configuration')
      ->willReturn($configMock);
  }

  private function validSubmittedValues(array $overrides = []): array {
    return array_merge([
      'nlp_election_cycle' => '2020-03-G',
      'nlp_cycle_name' => 'March 2020 General',
      'nlp_election_date' => '2020-03-15',
      'nlp_early_voting_start_date' => '2020-03-01',
    ], $overrides);
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('election_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormUsesEmptyDefaultsWhenNoConfigurationSaved(): void {
    $this->stubStoredConfiguration(NULL);
    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('', $form['nlp_election_cycle']['#default_value']);
    $this->assertSame('', $form['nlp_cycle_name']['#default_value']);
    $this->assertSame('', $form['nlp_election_date']['#default_value']);
    $this->assertSame('', $form['nlp_early_voting_start_date']['#default_value']);
  }

  public function testBuildFormUsesExistingConfigurationValues(): void {
    $this->stubStoredConfiguration([
      'nlp_election_cycle' => '2020-03-G',
      'nlp_cycle_name' => 'March 2020 General',
      'nlp_election_date' => '2020-03-15',
      'nlp_early_voting_start_date' => '2020-03-01',
    ]);
    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('2020-03-G', $form['nlp_election_cycle']['#default_value']);
    $this->assertSame('March 2020 General', $form['nlp_cycle_name']['#default_value']);
    $this->assertSame('2020-03-15', $form['nlp_election_date']['#default_value']);
    $this->assertSame('2020-03-01', $form['nlp_early_voting_start_date']['#default_value']);
  }

  public function testBuildFormMarksAllFieldsRequiredExceptSubmit(): void {
    $this->stubStoredConfiguration(NULL);
    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertTrue($form['nlp_election_cycle']['#required']);
    $this->assertTrue($form['nlp_cycle_name']['#required']);
    $this->assertTrue($form['nlp_election_date']['#required']);
    $this->assertTrue($form['nlp_early_voting_start_date']['#required']);
  }

  public function testBuildFormContainsBackLink(): void {
    $this->stubStoredConfiguration(NULL);
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubStoredConfiguration(NULL);
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // validateForm - election cycle
  // ---------------------------------------------------------------------------

  public function testValidateFormAddsErrorWhenCycleHasWrongNumberOfFields(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '2020-03']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleYearNotNumeric(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => 'abcd-03-G']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleYearNotFourDigits(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '202-03-G']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleYearNotGreaterThan2017(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '2017-03-G']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleMonthNotNumeric(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '2020-ab-G']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleMonthOutOfRange(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '2020-13-G']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenCycleTypeInvalid(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_cycle' => '2020-03-X']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_cycle', $formState->getErrors());
  }

  // ---------------------------------------------------------------------------
  // validateForm - dates
  // ---------------------------------------------------------------------------

  public function testValidateFormAddsErrorWhenElectionDateInvalid(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_election_date' => '03/15/2020']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_election_date', $formState->getErrors());
  }

  public function testValidateFormAllowsEmptyEarlyVotingDate(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_early_voting_start_date' => '']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenEarlyVotingDateInvalid(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues(['nlp_early_voting_start_date' => 'not-a-date']));

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('nlp_early_voting_start_date', $formState->getErrors());
  }

  public function testValidateFormPassesWithValidValues(): void {
    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues());

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormSavesConfigurationAndAddsStatusMessage(): void {
    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-election-configuration', [
        'nlp_election_cycle' => '2020-03-G',
        'nlp_election_date' => '2020-03-15',
        'nlp_early_voting_start_date' => '2020-03-01',
        'nlp_cycle_name' => 'March 2020 General',
      ])
      ->willReturnSelf();
    $editableConfigMock->expects($this->once())->method('save');

    $this->configFactoryMock->expects($this->once())
      ->method('getEditable')
      ->with('nlpservices.configuration')
      ->willReturn($editableConfigMock);

    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->setValues($this->validSubmittedValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

}
