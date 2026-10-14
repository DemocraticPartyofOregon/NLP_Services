<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpCredentialsEmail;
  use Drupal\nlpservices\NlpMcidRecovery;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class NlpCredentialsEmailTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private NlpCoordinators $coordinatorsMock;
    private NlpMcidRecovery $mcidRecoveryMock;
    private NlpConfiguration $configMock;
    private NlpSendEmail $sendMailMock;
    private NlpNls $nlsMock;
    private RequestStack $requestStackMock;

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock   = $this->createMock(DrupalUser::class);
      $this->coordinatorsMock = $this->createMock(NlpCoordinators::class);
      $this->mcidRecoveryMock = $this->createMock(NlpMcidRecovery::class);
      $this->configMock       = $this->createMock(NlpConfiguration::class);
      $this->sendMailMock     = $this->createMock(NlpSendEmail::class);
      $this->nlsMock          = $this->createMock(NlpNls::class);
      $this->requestStackMock = $this->createMock(RequestStack::class);

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpCredentialsEmail {
      return new NlpCredentialsEmail(
        $this->drupalUserMock,
        $this->coordinatorsMock,
        $this->mcidRecoveryMock,
        $this->configMock,
        $this->sendMailMock,
        $this->nlsMock,
        $this->requestStackMock,
      );
    }

    private function baseNl(): array {
      return [
        'mcid'      => 42,
        'county'    => 'Denver',
        'firstName' => 'Bob',
        'lastName'  => 'Smith',
        'nickname'  => 'Bob',
        'email'     => 'bob@example.com',
        'hd'        => 6,
        'precinct'  => '6.1',
      ];
    }

    private function baseCoordinator(): array {
      return [
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'phone'     => '555-9876',
        'email'     => 'jane@example.com',
      ];
    }

    private function setupRequestMock(string $host = 'https://example.com'): void {
      $requestMock = $this->createMock(Request::class);
      $requestMock->method('getSchemeAndHttpHost')->willReturn($host);
      $this->requestStackMock->method('getCurrentRequest')->willReturn($requestMock);
    }

    private function setupStandardMocks(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->baseCoordinator());
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['userName' => 'bsmith']);
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('abc-key-123');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->sendMailMock->method('sendEmail')->willReturn(TRUE);
      $this->setupRequestMock();
    }

    // -------------------------------------------------------------------------
    // sendCredentialsEmail
    // -------------------------------------------------------------------------

    public function testSendCredentialsEmailReturnsFalseWhenNlNotFound(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $result = $this->buildService()->sendCredentialsEmail(42, 'Hello @nlpLogin');
      $this->assertFalse($result);
    }

    public function testSendCredentialsEmailReturnsTrueOnSuccess(): void {
      $this->setupStandardMocks();
      $result = $this->buildService()->sendCredentialsEmail(42, 'Hello @nlpLogin');
      $this->assertTrue($result);
    }

    public function testSendCredentialsEmailReturnsFalseWhenSendEmailFails(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->baseCoordinator());
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['userName' => 'bsmith']);
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('abc-key-123');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->sendMailMock->method('sendEmail')->willReturn(FALSE);
      $this->setupRequestMock();
      $result = $this->buildService()->sendCredentialsEmail(42, 'Hello @nlpLogin');
      $this->assertFalse($result);
    }

    public function testSendCredentialsEmailUnknownUserFallsBackToUnknownUserName(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->baseCoordinator());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('key');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->setupRequestMock();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@nlpLogin');
      $this->assertStringContainsString('unknown', $capturedMessage);
    }

    public function testSendCredentialsEmailNlpLoginPlaceholderContainsServerUrl(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@nlpLogin');
      $this->assertStringContainsString('https://example.com', $capturedMessage);
    }

    public function testSendCredentialsEmailNlpLoginPlaceholderContainsUsername(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@nlpLogin');
      $this->assertStringContainsString('bsmith', $capturedMessage);
    }

    public function testSendCredentialsEmailNlpLoginPlaceholderContainsRecoveryLink(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@nlpLogin');
      $this->assertStringContainsString('abc-key-123', $capturedMessage);
    }

    public function testSendCredentialsEmailCoordinatorContactInfoContainsCoordinatorName(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@coordinatorContactInfo');
      $this->assertStringContainsString('Jane Doe', $capturedMessage);
    }

    public function testSendCredentialsEmailNoCoordinatorLeavesContactInfoEmpty(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['userName' => 'bsmith']);
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('key');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->setupRequestMock();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@coordinatorContactInfo');
      $this->assertSame('', $capturedMessage);
    }

    public function testSendCredentialsEmailNotePlaceholderReplacedWithWrappedNote(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@plainNote', 'Please review your turf.');
      $this->assertStringContainsString('<p>Please review your turf.</p>', $capturedMessage);
    }

    public function testSendCredentialsEmailEmptyNoteProducesNoNoteParagraph(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@plainNote');
      $this->assertSame('', $capturedMessage);
    }

    public function testSendCredentialsEmailMiniVanInfoPlaceholderReplacedWhenProvided(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $miniVan = ['minivanNumber' => 7, 'minivanCreateDate' => '2024-10-20'];
      $this->buildService()->sendCredentialsEmail(42, '@miniVanInfo', '', $miniVan);
      $this->assertStringContainsString('MiniVan Number', $capturedMessage);
      $this->assertStringContainsString('7', $capturedMessage);
    }

    public function testSendCredentialsEmailEmptyMiniVanArrayProducesNoMiniVanInfo(): void {
      $this->setupStandardMocks();

      $capturedMessage = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, string $message) use (&$capturedMessage): bool {
          $capturedMessage = $message;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, '@miniVanInfo');
      $this->assertSame('', $capturedMessage);
    }

    public function testSendCredentialsEmailSubjectContainsCounty(): void {
      $this->setupStandardMocks();

      $capturedSubject = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, string $subject) use (&$capturedSubject): bool {
          $capturedSubject = $subject;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, 'Hello');
      $this->assertStringContainsString('Denver', $capturedSubject);
    }

    public function testSendCredentialsEmailSentToNlEmailAddress(): void {
      $this->setupStandardMocks();

      $capturedTo = '';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, string $to) use (&$capturedTo): bool {
          $capturedTo = $to;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, 'Hello');
      $this->assertSame('bob@example.com', $capturedTo);
    }

    public function testSendCredentialsEmailReplyToIsCoordinatorEmail(): void {
      $this->setupStandardMocks();

      $capturedReply = 'unset';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, $message, $reply) use (&$capturedReply): bool {
          $capturedReply = $reply;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, 'Hello');
      $this->assertSame('jane@example.com', $capturedReply);
    }

    public function testSendCredentialsEmailNoCoordinatorReplyToIsNull(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['userName' => 'bsmith']);
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('key');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->setupRequestMock();

      $capturedReply = 'unset';
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, $message, $reply) use (&$capturedReply): bool {
          $capturedReply = $reply;
          return TRUE;
        });

      $this->buildService()->sendCredentialsEmail(42, 'Hello');
      $this->assertNull($capturedReply);
    }


    // -------------------------------------------------------------------------
    // sendLoginHelpEmail
    // -------------------------------------------------------------------------

    private function loginHelpUser(): array {
      return ['mcid' => 42, 'userName' => 'bsmith', 'email' => 'bob@example.com', 'firstName' => 'Bob'];
    }

    public function testSendLoginHelpEmailSendsUsernameAndPasswordLinkToTheNl(): void {
      $this->setupStandardMocks();
      $sent = [];
      $this->sendMailMock->expects($this->once())->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, $message, $reply) use (&$sent): bool {
          $sent = compact('key', 'to', 'message', 'reply');
          return TRUE;
        });

      $this->assertTrue($this->buildService()->sendLoginHelpEmail($this->loginHelpUser()));

      $this->assertSame('account_reminder', $sent['key']);
      $this->assertSame('bob@example.com', $sent['to']);
      $this->assertStringContainsString('bsmith', $sent['message']);
      $this->assertStringContainsString('https://example.com/nlp-mcid-recovery?key=abc-key-123', $sent['message']);
      $this->assertStringContainsString('Jane Doe', $sent['message']);
      $this->assertSame('jane@example.com', $sent['reply']);
    }

    public function testSendLoginHelpEmailWithoutACoordinatorLeavesOutTheContact(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->coordinatorsMock->expects($this->never())->method('getAssignedCoordinator');
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('key');
      $this->setupRequestMock();
      $sent = [];
      $this->sendMailMock->method('sendEmail')
        ->willReturnCallback(function ($key, $to, $subject, $message, $reply) use (&$sent): bool {
          $sent = compact('message', 'reply');
          return TRUE;
        });

      $this->assertTrue($this->buildService()->sendLoginHelpEmail($this->loginHelpUser()));
      $this->assertStringNotContainsString('contact your coordinator', $sent['message']);
      $this->assertNull($sent['reply']);
    }

    public function testSendLoginHelpEmailReturnsFalseWithoutARecoveryKey(): void {
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('');
      $this->sendMailMock->expects($this->never())->method('sendEmail');
      $this->assertFalse($this->buildService()->sendLoginHelpEmail($this->loginHelpUser()));
    }

    public function testSendLoginHelpEmailReturnsFalseWhenSendingFails(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->baseCoordinator());
      $this->mcidRecoveryMock->method('saveRecoveryKey')->willReturn('key');
      $this->sendMailMock->method('sendEmail')->willReturn(FALSE);
      $this->setupRequestMock();
      $this->assertFalse($this->buildService()->sendLoginHelpEmail($this->loginHelpUser()));
    }

  }

}
