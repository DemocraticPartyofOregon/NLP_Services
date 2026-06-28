<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Language\LanguageManagerInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Mail\MailManagerInterface;
  use Drupal\nlpservices\HtmlText;
  use Drupal\nlpservices\NlpImap;
  use Drupal\nlpservices\NlpReplies;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpRepliesTest extends UnitTestCase {

    private ConfigFactoryInterface $configFactoryMock;
    private NlpImap $imapMock;
    private LanguageManagerInterface $languageManagerMock;
    private MailManagerInterface $mailManagerMock;
    private HtmlText $htmlTextMock;
    private NlpSendEmail $sendEmailMock;
    private object $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $this->imapMock = $this->createMock(NlpImap::class);
      $this->languageManagerMock = $this->createMock(LanguageManagerInterface::class);
      $this->mailManagerMock = $this->createMock(MailManagerInterface::class);
      $this->htmlTextMock = $this->createMock(HtmlText::class);
      $this->sendEmailMock = $this->createMock(NlpSendEmail::class);

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

    private function buildService(): NlpReplies {
      return new NlpReplies(
        $this->configFactoryMock,
        $this->imapMock,
        $this->languageManagerMock,
        $this->mailManagerMock,
        $this->htmlTextMock,
        $this->sendEmailMock,
      );
    }

    // Build a realistic bounce message array.
    // senderData should contain 'Email: mailto:<addr>?...' so senderSearch extracts the address.
    // recipientData should contain 'Original-Recipient: rfc822;<addr>\r' or 'Final-Recipient: ...'
    private function buildMessage(string $subject, array $extraParts = []): array {
      return array_merge(['subject' => $subject], $extraParts);
    }

    private function senderPart(string $email = 'coord@example.com'): array {
      return [
        'type' => 'Text',
        'subtype' => 'plain',
        'data' => "Email: mailto:{$email}?subject=hi",
        'description' => '',
      ];
    }

    private function originalRecipientPart(string $email = 'nl@example.com'): array {
      return [
        'type' => 'Plain',
        'subtype' => 'delivery-status',
        'data' => "Original-Recipient: rfc822;{$email}\rmore data",
        'description' => '',
      ];
    }

    private function finalRecipientPart(string $email = 'nl@example.com'): array {
      return [
        'type' => 'Plain',
        'subtype' => 'delivery-status',
        'data' => "Final-Recipient: rfc822;{$email}\rmore data",
        'description' => '',
      ];
    }

    private function setupImapWithMessage(array $message, int $emailNumber = 1): void {
      $this->imapMock->method('getImapConnection')->willReturn(true);
      $this->imapMock->method('getNewNotificationReplies')->willReturn([$emailNumber => []]);
      $this->imapMock->method('getMessage')->willReturn($message);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('config.factory', $this->configFactoryMock);
      $container->set('nlpservices.imap', $this->imapMock);
      $container->set('language_manager', $this->languageManagerMock);
      $container->set('plugin.manager.mail', $this->mailManagerMock);
      $container->set('nlpservices.html_text', $this->htmlTextMock);
      $container->set('nlpservices.send_email', $this->sendEmailMock);

      $service = NlpReplies::create($container);

      $this->assertInstanceOf(NlpReplies::class, $service);
    }

    // -------------------------------------------------------------------------
    // emailForward() — early exits
    // -------------------------------------------------------------------------

    public function testEmailForwardReturnsWhenImapConnectionFails(): void {
      $this->imapMock->expects($this->once())
        ->method('getImapConnection')
        ->with('notifications')
        ->willReturn(false);
      $this->imapMock->expects($this->never())->method('getNewNotificationReplies');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();

      $this->addToAssertionCount(1);
    }

    public function testEmailForwardReturnsWhenNoNewReplies(): void {
      $this->imapMock->method('getImapConnection')->willReturn(true);
      $this->imapMock->expects($this->once())
        ->method('getNewNotificationReplies')
        ->willReturn([]);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();

      $this->addToAssertionCount(1);
    }

    public function testEmailForwardContinuesWhenMessageIsEmpty(): void {
      $this->imapMock->method('getImapConnection')->willReturn(true);
      $this->imapMock->method('getNewNotificationReplies')->willReturn([1 => []]);
      $this->imapMock->method('getMessage')->willReturn([]);
      $this->imapMock->expects($this->never())->method('setUnread');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();

      $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // emailForward() — subject matching
    // -------------------------------------------------------------------------

    public function testEmailForwardMarksUnknownSubjectUnread(): void {
      $this->setupImapWithMessage($this->buildMessage('Some random subject'));
      $this->imapMock->expects($this->once())->method('setUnread')->with(1)->willReturn(true);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();

      $this->addToAssertionCount(1);
    }

    public function testEmailForwardMarksNlpNotificationReplyUnread(): void {
      // Subject contains 'NLP Notification: ' — skips special-message search entirely,
      // leaving targetMessage null, so the message is marked unread.
      $this->setupImapWithMessage($this->buildMessage('Re: NLP Notification: please help'));
      $this->imapMock->expects($this->once())->method('setUnread')->willReturn(true);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();

      $this->addToAssertionCount(1);
    }

    public function testEmailForwardMatchesMailDeliveryFailedSubject(): void {
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart(),
        $this->originalRecipientPart(),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())->method('sendEmail');

      $this->buildService()->emailForward();
    }

    public function testEmailForwardMatchesUndeliveredMailSubject(): void {
      $message = $this->buildMessage('Undelivered Mail Returned to Sender', [
        $this->senderPart(),
        $this->originalRecipientPart(),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())->method('sendEmail');

      $this->buildService()->emailForward();
    }

    public function testEmailForwardMatchesUndeliverableSubject(): void {
      $message = $this->buildMessage('Undeliverable: your message', [
        $this->senderPart(),
        $this->originalRecipientPart(),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())->method('sendEmail');

      $this->buildService()->emailForward();
    }

    public function testEmailForwardMatchesAccountLoginReminderSubject(): void {
      $message = $this->buildMessage('account login reminder', [
        $this->senderPart(),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())->method('sendEmail');

      $this->buildService()->emailForward();
    }

    // -------------------------------------------------------------------------
    // emailForward() — sender / recipient resolution
    // -------------------------------------------------------------------------

    public function testEmailForwardSetsUnreadWhenSenderEmailNotFound(): void {
      $message = $this->buildMessage('Mail delivery failed', [
        ['type' => 'Text', 'subtype' => 'plain', 'data' => 'No email address here at all.', 'description' => ''],
      ]);
      $this->setupImapWithMessage($message);
      $this->imapMock->expects($this->once())->method('setUnread');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->buildService()->emailForward();
    }

    public function testEmailForwardSendsEmailWhenSenderFoundButNoRecipient(): void {
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart('coord@example.com'),
        // no delivery-status part → recipientSearch returns ''
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())
        ->method('sendEmail')
        ->with(
          'forward_nl_reply',
          'coord@example.com',
          $this->stringContains('NLP Notification:'),
          $this->anything(),
        );

      $this->buildService()->emailForward();
    }

    public function testEmailForwardSendsEmailWithSenderAndRecipientAddresses(): void {
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart('coord@example.com'),
        $this->originalRecipientPart('nl@example.com'),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())
        ->method('sendEmail')
        ->with(
          'forward_nl_reply',
          'coord@example.com',
          $this->stringContains('Mail delivery failed'),
          $this->anything(),
        );

      $this->buildService()->emailForward();
    }

    public function testEmailForwardExtractsFinalRecipientWhenOriginalRecipientAbsent(): void {
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart('coord@example.com'),
        $this->finalRecipientPart('nl@example.com'),
      ]);
      $this->setupImapWithMessage($message);
      $this->sendEmailMock->expects($this->once())
        ->method('sendEmail')
        ->with('forward_nl_reply', 'coord@example.com', $this->anything(), $this->anything());

      $this->buildService()->emailForward();
    }

    // -------------------------------------------------------------------------
    // emailForward() — getBodyParts paths
    // -------------------------------------------------------------------------

    public function testEmailForwardIncludesNotificationPartInBody(): void {
      // Text/plain with no description → assigned 'Notification', which is in the
      // bodyParts list for 'failed', so it should appear in the forwarded message body.
      $notificationText = 'This is the notification content.';
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart(),
        ['type' => 'Text', 'subtype' => 'plain', 'data' => $notificationText, 'description' => ''],
      ]);
      $this->setupImapWithMessage($message);

      $capturedBody = '';
      $this->sendEmailMock->method('sendEmail')
        ->willReturnCallback(function (string $key, string $to, string $subject, string $body) use (&$capturedBody): void {
          $capturedBody = $body;
        });

      $this->buildService()->emailForward();

      $this->assertStringContainsString($notificationText, $capturedBody);
    }

    public function testEmailForwardIncludesDeliveryReportPartInBody(): void {
      // Plain/delivery-status → assigned 'Delivery report', which is in bodyParts for 'failed'.
      $deliveryText = "Original-Recipient: rfc822;nl@example.com\rDelivery status details.";
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart(),
        ['type' => 'Plain', 'subtype' => 'delivery-status', 'data' => $deliveryText, 'description' => ''],
      ]);
      $this->setupImapWithMessage($message);

      $capturedBody = '';
      $this->sendEmailMock->method('sendEmail')
        ->willReturnCallback(function (string $key, string $to, string $subject, string $body) use (&$capturedBody): void {
          $capturedBody = $body;
        });

      $this->buildService()->emailForward();

      $this->assertStringContainsString('Delivery report', $capturedBody);
    }

    public function testEmailForwardIncludesOriginalEmailTextPartInBody(): void {
      // Multipart/alternative → assigned 'Original email text', which is in bodyParts
      // for 'undelivered' / 'undeliverable' but not 'failed'.
      $originalText = 'The original email that bounced.';
      $message = $this->buildMessage('Undelivered Mail Returned to Sender', [
        $this->senderPart(),
        ['type' => 'Multipart', 'subtype' => 'alternative', 'data' => $originalText, 'description' => ''],
      ]);
      $this->setupImapWithMessage($message);

      $capturedBody = '';
      $this->sendEmailMock->method('sendEmail')
        ->willReturnCallback(function (string $key, string $to, string $subject, string $body) use (&$capturedBody): void {
          $capturedBody = $body;
        });

      $this->buildService()->emailForward();

      $this->assertStringContainsString($originalText, $capturedBody);
    }

    public function testEmailForwardUsesExplicitPartDescriptionWhenPresent(): void {
      // A part that already has a description set — getBodyParts uses it directly
      // instead of inferring from type/subtype.
      $notificationText = 'Pre-labelled notification content.';
      $message = $this->buildMessage('Mail delivery failed', [
        $this->senderPart(),
        ['type' => 'Text', 'subtype' => 'plain', 'data' => $notificationText, 'description' => 'Notification'],
      ]);
      $this->setupImapWithMessage($message);

      $capturedBody = '';
      $this->sendEmailMock->method('sendEmail')
        ->willReturnCallback(function (string $key, string $to, string $subject, string $body) use (&$capturedBody): void {
          $capturedBody = $body;
        });

      $this->buildService()->emailForward();

      $this->assertStringContainsString($notificationText, $capturedBody);
    }

    // -------------------------------------------------------------------------
    // emailForward() — end-of-loop completion message
    // -------------------------------------------------------------------------

    public function testEmailForwardAddsAllEmailsProcessedMessageWhenDone(): void {
      $this->imapMock->method('getImapConnection')->willReturn(true);
      $this->imapMock->method('getNewNotificationReplies')->willReturn([]);

      $this->messengerMock->expects($this->once())
        ->method('addMessage')
        ->with('All emails processed.');

      $this->buildService()->emailForward();
    }

  }

}
