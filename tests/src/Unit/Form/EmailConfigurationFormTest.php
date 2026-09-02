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
use Drupal\nlpservices\Config\EmailConfigurationForm;
use Drupal\nlpservices\NlpEmailConfiguration;
use Drupal\nlpservices\NlpImap;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(EmailConfigurationForm::class)]
#[Group('nlpservices')]
class EmailConfigurationFormTest extends UnitTestCase {

  private ConfigFactoryInterface $configFactoryMock;
  private NlpImap $imapObjMock;
  private NlpEmailConfiguration $emailConfigurationObjMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->configFactoryMock        = $this->createMock(ConfigFactoryInterface::class);
    $this->imapObjMock              = $this->createMock(NlpImap::class);
    $this->emailConfigurationObjMock = $this->createMock(NlpEmailConfiguration::class);
    $this->messengerMock            = $this->createMock(MessengerInterface::class);

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

  private function buildForm(): EmailConfigurationForm {
    $form = new EmailConfigurationForm(
      $this->configFactoryMock,
      $this->imapObjMock,
      $this->emailConfigurationObjMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function defaultEmailConfiguration(array $overrides = []): array {
    return array_merge([
      'server' => '',
      'port' => '',
      'email' => '',
      'password' => '',
      'validated' => FALSE,
      'enable' => FALSE,
    ], $overrides);
  }

  private function stubEmailConfigurations(array $byType): void {
    $this->emailConfigurationObjMock->method('getEmailConfiguration')
      ->willReturnCallback(fn($type) => $byType[$type] ?? $this->defaultEmailConfiguration());
  }

  private function stubCronConfiguration(array $cronConfiguration): void {
    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')
      ->with('nlpservices-cron-configuration')
      ->willReturn($cronConfiguration);
    $this->configFactoryMock->method('get')
      ->with('nlpservices.configuration')
      ->willReturn($configMock);
  }

  /**
   * TranslatableMarkup doesn't stringify through the mocked translation
   * service, so match on the untranslated source string instead.
   */
  private function messageContaining(string $needle): \PHPUnit\Framework\Constraint\Callback {
    return $this->callback(fn($markup) => str_contains($markup->getUntranslatedString(), $needle));
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('email_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsTestInstructionsMarkup(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('good idea to test them', $form['testFirst']['#markup']);
  }

  public function testBuildFormContainsSectionHeadingsForEachEmailType(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('Notifications email', $form['notificationsPurpose']['#markup']);
    $this->assertStringContainsString('MiniVAN email', $form['minivanPurpose']['#markup']);
    $this->assertStringContainsString('matchbacks email', $form['matchbackPurpose']['#markup']);
  }

  public function testBuildFormPopulatesFieldDefaultsFromStoredConfiguration(): void {
    $this->stubEmailConfigurations([
      'notifications' => $this->defaultEmailConfiguration([
        'server' => 'imap.example.com',
        'port' => '993',
        'email' => 'notify@example.com',
      ]),
    ]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('imap.example.com', $form['notifications']['info_notifications_server']['#default_value']);
    $this->assertSame('993', $form['notifications']['info_notifications_port']['#default_value']);
    $this->assertSame('notify@example.com', $form['notifications']['info_notifications_email']['#default_value']);
  }

  public function testBuildFormShowsValidatedMarkupWhenCredentialsValidated(): void {
    $this->stubEmailConfigurations([
      'notifications' => $this->defaultEmailConfiguration(['validated' => TRUE]),
    ]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('info_notifications_validated', $form['notifications']);
  }

  public function testBuildFormOmitsValidatedMarkupWhenNotValidated(): void {
    $this->stubEmailConfigurations([
      'notifications' => $this->defaultEmailConfiguration(['validated' => FALSE]),
    ]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayNotHasKey('info_notifications_validated', $form['notifications']);
  }

  public function testBuildFormEnableCheckboxDefaultsFromCronConfiguration(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([
      'enableEmailRedirect' => TRUE,
      'enableMinivan' => FALSE,
      'enableMatchback' => TRUE,
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertTrue($form['notifications']['info_notifications_enable']['#default_value']);
    $this->assertFalse($form['minivan']['info_minivan_enable']['#default_value']);
    $this->assertTrue($form['matchback']['info_matchback_enable']['#default_value']);
  }

  public function testBuildFormContainsBackLink(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  private function notificationsValues(array $overrides = []): array {
    return array_merge([
      'info_notifications_server' => 'imap.example.com',
      'info_notifications_port' => '993',
      'info_notifications_email' => 'notify@example.com',
      'info_notifications_password' => 'secret',
      'info_notifications_enable' => TRUE,
    ], $overrides);
  }

  public function testSubmitFormAddsErrorAndSkipsAccountWhenConfigurationInvalid(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')
      ->willReturn('You must specify all the email credentials.');
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $this->emailConfigurationObjMock->expects($this->never())->method('testEmailCredentials');
    $this->emailConfigurationObjMock->expects($this->never())->method('saveEmailConfiguration');
    $this->messengerMock->expects($this->once())
      ->method('addError')
      ->with('You must specify all the email credentials.');

    $formState = new FormState();
    $formState->setValues($this->notificationsValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormSkipsAccountWithEmptyServer(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $this->emailConfigurationObjMock->expects($this->never())->method('testEmailCredentials');
    $this->emailConfigurationObjMock->expects($this->never())->method('saveEmailConfiguration');
    $this->messengerMock->expects($this->never())->method('addError');

    $formState = new FormState();
    $formState->setValues($this->notificationsValues(['info_notifications_server' => '']));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormAddsErrorWhenCredentialTestFails(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('testEmailCredentials')->willReturn([]);
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $this->emailConfigurationObjMock->expects($this->never())->method('saveEmailConfiguration');
    $this->messengerMock->expects($this->once())
      ->method('addError')
      ->with($this->messageContaining('Credential failure'));

    $formState = new FormState();
    $formState->setValues($this->notificationsValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormPreservesExistingPasswordWhenFieldLeftBlank(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('getEmailConfiguration')
      ->with('notifications')
      ->willReturn($this->defaultEmailConfiguration(['password' => 'existing-password']));
    $this->stubCronConfiguration(['enableEmailRedirect' => FALSE]);

    $this->emailConfigurationObjMock->expects($this->once())
      ->method('testEmailCredentials')
      ->with('notify@example.com', 'existing-password', 993, 'imap.example.com')
      ->willReturn(['result' => TRUE, 'message' => 'ok']);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $formState = new FormState();
    $formState->setValues($this->notificationsValues(['info_notifications_password' => '']));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormSavesValidatedConfigurationAndEnablesCronSetting(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('testEmailCredentials')
      ->willReturn(['result' => TRUE, 'message' => 'ok']);
    $this->stubCronConfiguration([
      'enableEmailRedirect' => FALSE,
      'enableMinivan' => FALSE,
      'enableMatchback' => FALSE,
    ]);

    $this->emailConfigurationObjMock->expects($this->once())
      ->method('saveEmailConfiguration')
      ->with('notifications', $this->callback(function (array $configuration): bool {
        return $configuration['validated'] === TRUE
          && !array_key_exists('enable', $configuration)
          && $configuration['server'] === 'imap.example.com';
      }));

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-cron-configuration', [
        'enableEmailRedirect' => TRUE,
        'enableMinivan' => FALSE,
        'enableMatchback' => FALSE,
      ])
      ->willReturnSelf();
    $editableConfigMock->expects($this->once())->method('save');
    $this->configFactoryMock->expects($this->once())
      ->method('getEditable')
      ->with('nlpservices.configuration')
      ->willReturn($editableConfigMock);

    $this->messengerMock->expects($this->once())
      ->method('addStatus')
      ->with($this->messageContaining('configuration options have been saved'));

    $formState = new FormState();
    $formState->setValues($this->notificationsValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormDisablesCronSettingWhenEnableCheckboxUnchecked(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('testEmailCredentials')
      ->willReturn(['result' => TRUE, 'message' => 'ok']);
    $this->stubCronConfiguration([
      'enableEmailRedirect' => TRUE,
      'enableMinivan' => FALSE,
      'enableMatchback' => FALSE,
    ]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->expects($this->once())
      ->method('set')
      ->with('nlpservices-cron-configuration', [
        'enableEmailRedirect' => FALSE,
        'enableMinivan' => FALSE,
        'enableMatchback' => FALSE,
      ])
      ->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $formState = new FormState();
    $formState->setValues($this->notificationsValues(['info_notifications_enable' => FALSE]));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

}
