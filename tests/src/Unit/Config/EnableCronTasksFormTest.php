<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Config;

use Drupal;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\nlpservices\Config\EnableCronTasksForm;
use Drupal\nlpservices\NlpCronRunner;
use Drupal\nlpservices\NlpEmailConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(EnableCronTasksForm::class)]
#[Group('nlpservices')]
class EnableCronTasksFormTest extends UnitTestCase {

  private ConfigFactoryInterface $configFactoryMock;
  private NlpEmailConfiguration $emailConfigurationObjMock;
  private MessengerInterface $messengerMock;
  private StateInterface $stateMock;

  protected function setUp(): void {
    parent::setUp();
    $this->configFactoryMock         = $this->createMock(ConfigFactoryInterface::class);
    $this->emailConfigurationObjMock = $this->createMock(NlpEmailConfiguration::class);
    $this->messengerMock             = $this->createMock(MessengerInterface::class);
    $this->stateMock                 = $this->createMock(StateInterface::class);

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

  private function buildForm(): EnableCronTasksForm {
    $form = new EnableCronTasksForm(
      $this->configFactoryMock,
      $this->emailConfigurationObjMock,
      $this->stateMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function stubCronConfiguration(?array $cronConfiguration): void {
    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')
      ->with('nlpservices-cron-configuration')
      ->willReturn($cronConfiguration);
    $this->configFactoryMock->method('get')
      ->with('nlpservices.configuration')
      ->willReturn($configMock);
  }

  private function stubCredentialsValidated(array $validatedByType): void {
    $this->emailConfigurationObjMock->method('emailCredentialsValidated')
      ->willReturnCallback(fn($type) => $validatedByType[$type] ?? FALSE);
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('enable_cron_tasks_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormShowsCheckboxesForValidatedEmailAccounts(): void {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([
      'minivan' => TRUE,
      'matchback' => TRUE,
      'notifications' => TRUE,
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('checkbox', $form['enableMinivan']['#type']);
    $this->assertSame('checkbox', $form['enableMatchback']['#type']);
    $this->assertSame('checkbox', $form['enableEmailRedirect']['#type']);
  }

  // ---------------------------------------------------------------------------
  // buildForm — cron job check
  // ---------------------------------------------------------------------------

  private function cronJobCheckMarkup(?int $lastRun): string {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([]);
    $this->stateMock->method('get')
      ->with(NlpCronRunner::CRON_JOB_LAST_RUN)
      ->willReturn($lastRun);

    $form = $this->buildForm()->buildForm([], new FormState());
    return (string) $form['cronJobCheck']['#markup'];
  }

  public function testCronJobCheckCautionsWhenNoRunSeen(): void {
    $markup = $this->cronJobCheckMarkup(NULL);

    $this->assertStringContainsString('messages--warning', $markup);
    $this->assertStringContainsString('no run of the cron job has been seen', $markup);
  }

  public function testCronJobCheckCautionsWhenLastRunIsLate(): void {
    $markup = $this->cronJobCheckMarkup(time() - NlpCronRunner::CRON_JOB_LATE_SECS - 60);

    $this->assertStringContainsString('messages--warning', $markup);
    $this->assertStringContainsString('It should run every 15 minutes', $markup);
  }

  public function testCronJobCheckShowsLastRunWhenRecent(): void {
    $lastRun = time() - 300;
    $markup = $this->cronJobCheckMarkup($lastRun);

    $this->assertStringContainsString('messages--status', $markup);
    $this->assertStringContainsString(date('M j, Y g:i a', $lastRun), $markup);
  }

  public function testBuildFormShowsUnconfiguredMarkupWhenCredentialsNotValidated(): void {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertArrayNotHasKey('#type', $form['enableMinivan']);
    $this->assertStringContainsString('minivan email processing have not yet been configured', $form['enableMinivan']['#markup']);

    $this->assertArrayNotHasKey('#type', $form['enableMatchback']);
    $this->assertStringContainsString('matchback email processing have not yet been configured', $form['enableMatchback']['#markup']);

    $this->assertArrayNotHasKey('#type', $form['enableEmailRedirect']);
    $this->assertStringContainsString('redirection of emails to notifications have not yet been configured', $form['enableEmailRedirect']['#markup']);
  }

  public function testBuildFormAlwaysShowsTurfAndActivistCodeCheckboxes(): void {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('checkbox', $form['enableTurfResync']['#type']);
    $this->assertSame('checkbox', $form['enableVoterAcCheck']['#type']);
    $this->assertSame('checkbox', $form['enableTurfAcCheck']['#type']);
  }

  public function testBuildFormPopulatesDefaultValuesFromStoredCronConfiguration(): void {
    $this->stubCronConfiguration([
      'enableMinivan' => 1,
      'enableMatchback' => 0,
      'enableEmailRedirect' => 1,
      'enableTurfResync' => 0,
      'enableVoterAcCheck' => 1,
      'enableTurfAcCheck' => 0,
    ]);
    $this->stubCredentialsValidated([
      'minivan' => TRUE,
      'matchback' => TRUE,
      'notifications' => TRUE,
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame(1, $form['enableMinivan']['#default_value']);
    $this->assertSame(0, $form['enableMatchback']['#default_value']);
    $this->assertSame(1, $form['enableEmailRedirect']['#default_value']);
    $this->assertSame(0, $form['enableTurfResync']['#default_value']);
    $this->assertSame(1, $form['enableVoterAcCheck']['#default_value']);
    $this->assertSame(0, $form['enableTurfAcCheck']['#default_value']);
  }

  public function testBuildFormDefaultsToZeroWhenNoCronConfigurationStored(): void {
    $this->stubCronConfiguration(NULL);
    $this->stubCredentialsValidated([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame(0, $form['enableTurfResync']['#default_value']);
    $this->assertSame(0, $form['enableVoterAcCheck']['#default_value']);
    $this->assertSame(0, $form['enableTurfAcCheck']['#default_value']);
  }

  public function testBuildFormContainsBackLink(): void {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubCronConfiguration([]);
    $this->stubCredentialsValidated([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormSavesSubmittedCheckboxValues(): void {
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-cron-configuration', [
        'enableMinivan' => 1,
        'enableMatchback' => 0,
        'enableEmailRedirect' => 1,
        'enableTurfResync' => 0,
        'enableVoterAcCheck' => 1,
        'enableTurfAcCheck' => 0,
      ])
      ->willReturnSelf();
    $editableConfigMock->expects($this->once())->method('save');

    $this->configFactoryMock->expects($this->once())
      ->method('getEditable')
      ->with('nlpservices.configuration')
      ->willReturn($editableConfigMock);

    $this->messengerMock->expects($this->once())
      ->method('addStatus');

    $formState = new FormState();
    $formState->setValues([
      'enableMinivan' => 1,
      'enableMatchback' => 0,
      'enableEmailRedirect' => 1,
      'enableTurfResync' => 0,
      'enableVoterAcCheck' => 1,
      'enableTurfAcCheck' => 0,
    ]);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormDefaultsUnsubmittedCheckboxesToZero(): void {
    $this->stubCronConfiguration([
      'enableMinivan' => 1,
      'enableMatchback' => 1,
      'enableEmailRedirect' => 1,
      'enableTurfResync' => 1,
      'enableVoterAcCheck' => 1,
      'enableTurfAcCheck' => 1,
    ]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-cron-configuration', [
        'enableMinivan' => 0,
        'enableMatchback' => 0,
        'enableEmailRedirect' => 0,
        'enableTurfResync' => 0,
        'enableVoterAcCheck' => 0,
        'enableTurfAcCheck' => 0,
      ])
      ->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $formState = new FormState();
    $formState->setValues([]);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormHandlesMissingStoredCronConfiguration(): void {
    $this->stubCronConfiguration(NULL);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-cron-configuration', [
        'enableMinivan' => 1,
        'enableMatchback' => 0,
        'enableEmailRedirect' => 0,
        'enableTurfResync' => 0,
        'enableVoterAcCheck' => 0,
        'enableTurfAcCheck' => 0,
      ])
      ->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $formState = new FormState();
    $formState->setValues(['enableMinivan' => 1]);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

}
