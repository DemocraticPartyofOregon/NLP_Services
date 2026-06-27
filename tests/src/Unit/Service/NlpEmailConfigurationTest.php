<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\nlpservices\NlpEmailConfiguration;
  use Drupal\nlpservices\NlpImap;
  use Drupal\Tests\UnitTestCase;

  class NlpEmailConfigurationTest extends UnitTestCase {

    private ConfigFactoryInterface $configMock;
    private Config $iConfigMock;
    private NlpImap $imapMock;

    protected function setUp(): void {
      parent::setUp();
      $this->imapMock    = $this->createMock(NlpImap::class);
      $this->iConfigMock = $this->createMock(Config::class);
      $this->configMock  = $this->createMock(ConfigFactoryInterface::class);
      $this->configMock->method('getEditable')->willReturn($this->iConfigMock);
      $this->iConfigMock->method('set')->willReturnSelf();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(?array $storedConfig = null): NlpEmailConfiguration {
      $this->iConfigMock->method('get')->willReturn($storedConfig);
      return new NlpEmailConfiguration($this->configMock, $this->imapMock);
    }

    private function fullCredentials(array $overrides = []): array {
      return array_replace([
        'email'     => 'user@example.com',
        'password'  => 'secret',
        'port'      => 993,
        'server'    => 'imap.example.com',
        'validated' => TRUE,
        'enable'    => TRUE,
      ], $overrides);
    }

    // -------------------------------------------------------------------------
    // createCredentials
    // -------------------------------------------------------------------------

    public function testCreateCredentialsReturnsExpectedKeys(): void {
      $result = $this->buildService()->createCredentials();
      $this->assertArrayHasKey('email', $result);
      $this->assertArrayHasKey('password', $result);
      $this->assertArrayHasKey('port', $result);
      $this->assertArrayHasKey('server', $result);
      $this->assertArrayHasKey('validated', $result);
      $this->assertArrayHasKey('enable', $result);
    }

    public function testCreateCredentialsReturnsEmptyCredentialFields(): void {
      $result = $this->buildService()->createCredentials();
      $this->assertSame('', $result['email']);
      $this->assertSame('', $result['password']);
      $this->assertSame('', $result['port']);
      $this->assertSame('', $result['server']);
    }

    public function testCreateCredentialsReturnsFalseForValidatedAndEnable(): void {
      $result = $this->buildService()->createCredentials();
      $this->assertFalse($result['validated']);
      $this->assertFalse($result['enable']);
    }

    // -------------------------------------------------------------------------
    // getEmailConfiguration
    // -------------------------------------------------------------------------

    public function testGetEmailConfigurationReturnsStoredConfigForKnownType(): void {
      $stored = ['notifications' => $this->fullCredentials()];
      $result = $this->buildService($stored)->getEmailConfiguration('notifications');
      $this->assertSame('user@example.com', $result['email']);
    }

    public function testGetEmailConfigurationReturnsDefaultCredentialsForUnknownType(): void {
      $result = $this->buildService()->getEmailConfiguration('notifications');
      $this->assertSame('', $result['email']);
      $this->assertFalse($result['validated']);
    }

    public function testGetEmailConfigurationReturnsDefaultCredentialsWhenStoredConfigEmpty(): void {
      $result = $this->buildService([])->getEmailConfiguration('notifications');
      $this->assertFalse($result['validated']);
    }

    public function testGetEmailConfigurationReturnsCorrectTypeWhenMultipleTypesStored(): void {
      $stored = [
        'notifications' => $this->fullCredentials(['email' => 'notify@example.com']),
        'minivan'       => $this->fullCredentials(['email' => 'minivan@example.com']),
      ];
      $service = $this->buildService($stored);
      $this->assertSame('notify@example.com', $service->getEmailConfiguration('notifications')['email']);
      $this->assertSame('minivan@example.com', $service->getEmailConfiguration('minivan')['email']);
    }

    // -------------------------------------------------------------------------
    // saveEmailConfiguration
    // -------------------------------------------------------------------------

    public function testSaveEmailConfigurationCallsSetThenSave(): void {
      $this->iConfigMock->expects($this->once())->method('set')
        ->with('nlpservices-email-configuration', $this->anything())
        ->willReturnSelf();
      $this->iConfigMock->expects($this->once())->method('save');
      $this->buildService()->saveEmailConfiguration('notifications', $this->fullCredentials());
    }

    public function testSaveEmailConfigurationPersistsConfigUnderCorrectType(): void {
      $savedValue = null;
      $this->iConfigMock->method('set')
        ->willReturnCallback(function($key, $value) use (&$savedValue) {
          $savedValue = $value;
          return $this->iConfigMock;
        });
      $credentials = $this->fullCredentials();
      $this->buildService()->saveEmailConfiguration('notifications', $credentials);
      $this->assertSame($credentials, $savedValue['notifications']);
    }

    public function testSaveEmailConfigurationPreservesExistingTypesWhenAddingNew(): void {
      $existingConfig = ['minivan' => $this->fullCredentials(['email' => 'minivan@example.com'])];
      $savedValue = null;
      $this->iConfigMock->method('set')
        ->willReturnCallback(function($key, $value) use (&$savedValue) {
          $savedValue = $value;
          return $this->iConfigMock;
        });
      $service = $this->buildService($existingConfig);
      $service->saveEmailConfiguration('notifications', $this->fullCredentials());
      $this->assertArrayHasKey('minivan', $savedValue);
      $this->assertArrayHasKey('notifications', $savedValue);
    }

    // -------------------------------------------------------------------------
    // verifyConfiguration
    // -------------------------------------------------------------------------

    public function testVerifyConfigurationAllEmptyIsValid(): void {
      $this->assertSame('', $this->buildService()->verifyConfiguration([]));
    }

    public function testVerifyConfigurationServerWithAllCredentialsIsValid(): void {
      $config = ['server' => 'imap.example.com', 'email' => 'u@example.com', 'password' => 'pw', 'port' => 993];
      $this->assertSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationServerWithMissingEmailIsInvalid(): void {
      $config = ['server' => 'imap.example.com', 'password' => 'pw', 'port' => 993];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationServerWithMissingPasswordIsInvalid(): void {
      $config = ['server' => 'imap.example.com', 'email' => 'u@example.com', 'port' => 993];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationServerWithMissingPortIsInvalid(): void {
      $config = ['server' => 'imap.example.com', 'email' => 'u@example.com', 'password' => 'pw'];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationNoServerButEmailSetIsInvalid(): void {
      $config = ['email' => 'u@example.com'];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationNoServerButPasswordSetIsInvalid(): void {
      $config = ['password' => 'pw'];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationNoServerButPortSetIsInvalid(): void {
      $config = ['port' => 993];
      $this->assertNotSame('', $this->buildService()->verifyConfiguration($config));
    }

    public function testVerifyConfigurationReturnsStringOnError(): void {
      $config = ['server' => 'imap.example.com'];
      $result = $this->buildService()->verifyConfiguration($config);
      $this->assertIsString($result);
      $this->assertNotEmpty($result);
    }

    // -------------------------------------------------------------------------
    // testEmailCredentials
    // -------------------------------------------------------------------------

    public function testTestEmailCredentialsSuccessReturnsResultTrue(): void {
      $this->imapMock->method('testImapConnection')->willReturn(['result' => 'success']);
      $result = $this->buildService()->testEmailCredentials('u@example.com', 'pw', 993, 'imap.example.com');
      $this->assertTrue($result['result']);
    }

    public function testTestEmailCredentialsSuccessReturnsSuccessMessage(): void {
      $this->imapMock->method('testImapConnection')->willReturn(['result' => 'success']);
      $result = $this->buildService()->testEmailCredentials('u@example.com', 'pw', 993, 'imap.example.com');
      $this->assertStringContainsString('successful', $result['message']);
    }

    public function testTestEmailCredentialsFailureReturnsResultFalse(): void {
      $this->imapMock->method('testImapConnection')->willReturn(['result' => 'failure', 'message' => 'timeout']);
      $result = $this->buildService()->testEmailCredentials('u@example.com', 'pw', 993, 'imap.example.com');
      $this->assertFalse($result['result']);
    }

    public function testTestEmailCredentialsFailureIncludesImapMessageInResponse(): void {
      $this->imapMock->method('testImapConnection')->willReturn(['result' => 'failure', 'message' => 'timeout']);
      $result = $this->buildService()->testEmailCredentials('u@example.com', 'pw', 993, 'imap.example.com');
      $this->assertStringContainsString('timeout', $result['message']);
    }

    public function testTestEmailCredentialsPassesCorrectInfoToImap(): void {
      $this->imapMock->expects($this->once())
        ->method('testImapConnection')
        ->with([
          'email'    => 'u@example.com',
          'password' => 'pw',
          'port'     => 993,
          'server'   => 'imap.example.com',
        ])
        ->willReturn(['result' => 'success']);
      $this->buildService()->testEmailCredentials('u@example.com', 'pw', 993, 'imap.example.com');
    }

    // -------------------------------------------------------------------------
    // emailCredentialsValidated
    // -------------------------------------------------------------------------

    public function testEmailCredentialsValidatedReturnsTrueWhenValidated(): void {
      $stored = ['notifications' => $this->fullCredentials(['validated' => TRUE])];
      $this->assertTrue($this->buildService($stored)->emailCredentialsValidated('notifications'));
    }

    public function testEmailCredentialsValidatedReturnsFalseWhenNotValidated(): void {
      $stored = ['notifications' => $this->fullCredentials(['validated' => FALSE])];
      $this->assertFalse($this->buildService($stored)->emailCredentialsValidated('notifications'));
    }

    public function testEmailCredentialsValidatedReturnsFalseForUnknownType(): void {
      $this->assertFalse($this->buildService()->emailCredentialsValidated('notifications'));
    }

  }

}
