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
use Drupal\nlpservices\Config\EmailConfigurationForm;
use Drupal\nlpservices\NlpEmailConfiguration;
use Drupal\nlpservices\NlpImap;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Constraint\Callback;

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

  /**
   * Stubs config.factory get() for both the module's cron configuration and
   * system.site.  The site email defaults to the address the notificationsValues()
   * fixture submits, so tests that aren't about the site email see no warning.
   */
  private function stubCronConfiguration(array $cronConfiguration, string $siteMail = 'notify@example.com'): void {
    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')
      ->with('nlpservices-cron-configuration')
      ->willReturn($cronConfiguration);
    $siteConfigMock = $this->createMock(ImmutableConfig::class);
    $siteConfigMock->method('get')
      ->with('mail')
      ->willReturn($siteMail);
    $this->configFactoryMock->method('get')
      ->willReturnCallback(fn(string $name) => match ($name) {
        'nlpservices.configuration' => $configMock,
        'system.site' => $siteConfigMock,
      });
  }

  /**
   * TranslatableMarkup doesn't stringify through the mocked translation
   * service, so match on the untranslated source string instead, and
   * optionally on one placeholder's substituted value.
   */
  private function messageContaining(string $needle, string $placeholder = '', string $argNeedle = ''): Callback {
    return $this->callback(function ($markup) use ($needle, $placeholder, $argNeedle) {
      if (!str_contains($markup->getUntranslatedString(), $needle)) {
        return FALSE;
      }
      if ($placeholder === '') {
        return TRUE;
      }
      $arg = (string) ($markup->getArguments()[$placeholder] ?? '');
      return str_contains($arg, $argNeedle) && !str_contains($arg, '<br>');
    });
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

  public function testBuildFormIsConstrainedTo600PixelBox(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('width:600px', $form['#attributes']['style']);
  }

  public function testBuildFormDefaultsEmptyNotificationsEmailToSiteMail(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([], 'site@example.com');
    $this->messengerMock->expects($this->never())->method('addWarning');

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('site@example.com', $form['notifications']['info_notifications_email']['#default_value']);
    // Only notifications defaults to the site email.
    $this->assertSame('', $form['minivan']['info_minivan_email']['#default_value']);
  }

  public function testBuildFormKeepsStoredNotificationsEmailAndWarnsWhenItDiffersFromSiteMail(): void {
    $this->stubEmailConfigurations([
      'notifications' => $this->defaultEmailConfiguration(['email' => 'other@example.com']),
    ]);
    $this->stubCronConfiguration([], 'site@example.com');
    $this->messengerMock->expects($this->once())
      ->method('addWarning')
      ->with($this->messageContaining('is not the same as the site email', '@email', 'other@example.com'));

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('other@example.com', $form['notifications']['info_notifications_email']['#default_value']);
  }

  public function testBuildFormTreatsSiteMailMatchAsCaseInsensitive(): void {
    $this->stubEmailConfigurations([
      'notifications' => $this->defaultEmailConfiguration(['email' => 'Site@Example.com']),
    ]);
    $this->stubCronConfiguration([], 'site@example.com');
    $this->messengerMock->expects($this->never())->method('addWarning');

    $this->buildForm()->buildForm([], new FormState());
  }

  public function testBuildFormWarnsWhenSiteMailNotSetUp(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([], '');
    $this->messengerMock->expects($this->once())
      ->method('addWarning')
      ->with($this->messageContaining('site email has not been set up'));

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('', $form['notifications']['info_notifications_email']['#default_value']);
  }

  public function testBuildFormRendersFieldHelpAsMarkupNotDescriptions(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    foreach (['notifications', 'minivan', 'matchback'] as $type) {
      foreach (['server', 'port', 'email', 'password', 'enable'] as $field) {
        $this->assertArrayNotHasKey('#description', $form[$type]['info_' . $type . '_' . $field]);
        $this->assertArrayHasKey('#markup', $form[$type]['desc_' . $type . '_' . $field]);
      }
    }
  }

  public function testBuildFormWrapsEachEmailTypeInTitledFieldset(): void {
    $this->stubEmailConfigurations([]);
    $this->stubCronConfiguration([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $expected = [
      'notifications' => 'Notifications email',
      'minivan' => 'MiniVAN email',
      'matchback' => 'matchbacks email',
    ];
    foreach ($expected as $type => $title) {
      $this->assertSame('fieldset', $form[$type]['#type']);
      $this->assertStringContainsString($title, (string) $form[$type]['#title']);
      $this->assertArrayHasKey('purpose', $form[$type]);
      $this->assertArrayHasKey('info_' . $type . '_server', $form[$type]);
    }
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

  public function testSubmitFormWarnsWhenNotificationsEmailChangedAwayFromSiteMail(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('testEmailCredentials')
      ->willReturn(['result' => TRUE, 'message' => 'ok']);
    $this->stubCronConfiguration([], 'site@example.com');

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    // The warning doesn't block the save.
    $this->emailConfigurationObjMock->expects($this->once())->method('saveEmailConfiguration');
    $this->messengerMock->expects($this->once())
      ->method('addWarning')
      ->with($this->messageContaining('is not the same as the site email', '@site', 'site@example.com'));

    $formState = new FormState();
    $formState->setValues($this->notificationsValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormDoesNotWarnWhenNotificationsEmailMatchesSiteMail(): void {
    $this->emailConfigurationObjMock->method('verifyConfiguration')->willReturn('');
    $this->emailConfigurationObjMock->method('testEmailCredentials')
      ->willReturn(['result' => TRUE, 'message' => 'ok']);
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $this->messengerMock->expects($this->never())->method('addWarning');

    $formState = new FormState();
    $formState->setValues($this->notificationsValues());

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
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
    // The real failure shape from NlpEmailConfiguration::testEmailCredentials():
    // a non-empty (truthy) array whose 'result' is FALSE.
    $this->emailConfigurationObjMock->method('testEmailCredentials')->willReturn([
      'result' => FALSE,
      'message' => 'Connection attempted failed.<br>[AUTHENTICATIONFAILED] Invalid credentials',
    ]);
    $this->stubCronConfiguration([]);

    $editableConfigMock = $this->createMock(Config::class);
    $editableConfigMock->method('set')->willReturnSelf();
    $this->configFactoryMock->method('getEditable')->willReturn($editableConfigMock);

    $this->emailConfigurationObjMock->expects($this->never())->method('saveEmailConfiguration');
    $this->messengerMock->expects($this->once())
      ->method('addError')
      ->with($this->messageContaining('Credential failure', '@message', 'Invalid credentials'));

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
