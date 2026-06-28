<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Language\LanguageInterface;
  use Drupal\Core\Language\LanguageManagerInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Mail\MailManagerInterface;
  use Drupal\nlpservices\HtmlText;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpSendEmailTest extends UnitTestCase {

    private MailManagerInterface $mailManagerMock;
    private HtmlText $htmlTextMock;
    private LanguageManagerInterface $languageManagerMock;
    private NlpConfiguration $nlpConfigurationMock;
    private object $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->mailManagerMock = $this->createMock(MailManagerInterface::class);
      $this->htmlTextMock = $this->createMock(HtmlText::class);
      $this->languageManagerMock = $this->createMock(LanguageManagerInterface::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);

      $languageMock = $this->createMock(LanguageInterface::class);
      $languageMock->method('getId')->willReturn('en');
      $this->languageManagerMock->method('getDefaultLanguage')->willReturn($languageMock);

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $this->messengerMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['addMessage'])
        ->getMock();

      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      $container->set('messenger', $this->messengerMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpSendEmail {
      return new NlpSendEmail(
        $this->mailManagerMock,
        $this->htmlTextMock,
        $this->languageManagerMock,
        $this->nlpConfigurationMock,
      );
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateReturnsInstance(): void {
      $container = new ContainerBuilder();
      $container->set('plugin.manager.mail', $this->mailManagerMock);
      $container->set('nlpservices.html_text', $this->htmlTextMock);
      $container->set('language_manager', $this->languageManagerMock);
      $container->set('nlpservices.configuration', $this->nlpConfigurationMock);

      $service = NlpSendEmail::create($container);

      $this->assertInstanceOf(NlpSendEmail::class, $service);
    }

    // -------------------------------------------------------------------------
    // sendEmail()
    // -------------------------------------------------------------------------

    public function testSendEmailReturnsTrueOnSuccess(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('plain text');
      $this->mailManagerMock->method('mail')->willReturn(['result' => TRUE]);

      $result = $this->buildService()->sendEmail('test_key', 'to@example.com', 'Subject', '<p>Body</p>');

      $this->assertTrue($result);
    }

    public function testSendEmailReturnsFalseOnFailure(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('plain text');
      $this->mailManagerMock->method('mail')->willReturn(['result' => FALSE]);

      $result = $this->buildService()->sendEmail('test_key', 'to@example.com', 'Subject', '<p>Body</p>');

      $this->assertFalse($result);
    }

    public function testSendEmailCallsAddMessageOnFailure(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('plain text');
      $this->mailManagerMock->method('mail')->willReturn(['result' => FALSE]);
      $this->messengerMock->expects($this->once())->method('addMessage');

      $this->buildService()->sendEmail('test_key', 'to@example.com', 'Subject', '<p>Body</p>');
    }

    public function testSendEmailDoesNotCallAddMessageOnSuccess(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('plain text');
      $this->mailManagerMock->method('mail')->willReturn(['result' => TRUE]);
      $this->messengerMock->expects($this->never())->method('addMessage');

      $this->buildService()->sendEmail('test_key', 'to@example.com', 'Subject', '<p>Body</p>');
    }

    public function testSendEmailPassesSubjectAndMessageInParams(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('plain text');
      $this->mailManagerMock->expects($this->once())
        ->method('mail')
        ->with(
          'nlpservices',
          'my_key',
          'to@example.com',
          'en',
          $this->callback(function (array $params): bool {
            return $params['subject'] === 'Hello'
              && $params['message'] === '<p>Hello</p>'
              && $params['plainText'] === 'plain text'
              && $params['from'] === 'from@example.com';
          }),
          NULL,
        )
        ->willReturn(['result' => TRUE]);

      $this->buildService()->sendEmail('my_key', 'to@example.com', 'Hello', '<p>Hello</p>');
    }

    public function testSendEmailSetsListUnsubscribeHeaderWithFromAddress(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('notify@org.com');
      $this->htmlTextMock->method('getText')->willReturn('');
      $this->mailManagerMock->expects($this->once())
        ->method('mail')
        ->with(
          $this->anything(),
          $this->anything(),
          $this->anything(),
          $this->anything(),
          $this->callback(fn(array $p): bool =>
            str_contains($p['headers']['List-Unsubscribe'], 'notify@org.com')
          ),
          $this->anything(),
        )
        ->willReturn(['result' => TRUE]);

      $this->buildService()->sendEmail('key', 'to@example.com', 'Sub', 'Msg');
    }

    public function testSendEmailForwardsReplyToWhenProvided(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('');
      $this->mailManagerMock->expects($this->once())
        ->method('mail')
        ->with(
          $this->anything(), $this->anything(), $this->anything(), $this->anything(),
          $this->anything(),
          'reply@example.com',
        )
        ->willReturn(['result' => TRUE]);

      $this->buildService()->sendEmail('key', 'to@example.com', 'Sub', 'Msg', 'reply@example.com');
    }

    public function testSendEmailPassesNullReplyWhenNotProvided(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('');
      $this->mailManagerMock->expects($this->once())
        ->method('mail')
        ->with(
          $this->anything(), $this->anything(), $this->anything(), $this->anything(),
          $this->anything(),
          NULL,
        )
        ->willReturn(['result' => TRUE]);

      $this->buildService()->sendEmail('key', 'to@example.com', 'Sub', 'Msg');
    }

    public function testSendEmailCallsSetHtmlBeforeGetText(): void {
      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->mailManagerMock->method('mail')->willReturn(['result' => TRUE]);

      $this->htmlTextMock->expects($this->once())->method('setHtml')->with('<b>Hi</b>');
      $this->htmlTextMock->expects($this->once())->method('getText')->willReturn('Hi');

      $this->buildService()->sendEmail('key', 'to@example.com', 'Sub', '<b>Hi</b>');
    }

    public function testSendEmailUsesDefaultLanguageCode(): void {
      $languageMock = $this->createMock(LanguageInterface::class);
      $languageMock->method('getId')->willReturn('fr');
      $languageManagerMock = $this->createMock(LanguageManagerInterface::class);
      $languageManagerMock->method('getDefaultLanguage')->willReturn($languageMock);

      $this->nlpConfigurationMock->method('getNotificationEmail')->willReturn('from@example.com');
      $this->htmlTextMock->method('getText')->willReturn('');
      $this->mailManagerMock->expects($this->once())
        ->method('mail')
        ->with($this->anything(), $this->anything(), $this->anything(), 'fr', $this->anything(), $this->anything())
        ->willReturn(['result' => TRUE]);

      (new NlpSendEmail(
        $this->mailManagerMock,
        $this->htmlTextMock,
        $languageManagerMock,
        $this->nlpConfigurationMock,
      ))->sendEmail('key', 'to@example.com', 'Sub', 'Msg');
    }

  }

}
