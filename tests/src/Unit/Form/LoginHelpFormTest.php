<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Flood\FloodInterface;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\LoginHelpForm;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpCredentialsEmail;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;

  class LoginHelpFormTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private MagicWord $magicWordMock;
    private NlpCredentialsEmail $credentialsEmailMock;
    private FloodInterface $floodMock;
    private MessengerInterface $messengerMock;
    /** @var string[] The notices logged to the nlpservices channel. */
    private array $notices = [];

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock       = $this->createMock(DrupalUser::class);
      $this->magicWordMock        = $this->createMock(MagicWord::class);
      $this->credentialsEmailMock = $this->createMock(NlpCredentialsEmail::class);
      $this->floodMock            = $this->createMock(FloodInterface::class);
      $this->messengerMock        = $this->createMock(MessengerInterface::class);

      $this->notices = [];
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerMock->method('notice')->willReturnCallback(function ($message): void {
        $this->notices[] = (string) $message;
      });
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): LoginHelpForm {
      $form = new LoginHelpForm(
        $this->drupalUserMock,
        $this->magicWordMock,
        $this->credentialsEmailMock,
        $this->floodMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    private function nlUser(array $overrides = []): array {
      return array_merge([
        'uid'       => 10,
        'userName'  => 'alice.smith',
        'email'     => 'alice@example.com',
        'mcid'      => 42,
        'firstName' => 'Alice',
        'roles'     => ['authenticated', 'nlp_leader'],
      ], $overrides);
    }

    private function submit(string $email): FormState {
      $formState = new FormState();
      $formState->setValue('email', $email);
      $form = [];
      $this->buildForm()->submitForm($form, $formState);
      return $formState;
    }

    // -------------------------------------------------------------------------
    // buildForm / getFormId
    // -------------------------------------------------------------------------

    public function testGetFormId(): void {
      $this->assertSame('nlpservices_login_help_form', $this->buildForm()->getFormId());
    }

    public function testBuildFormAsksForARequiredEmail(): void {
      $form = $this->buildForm()->buildForm([], new FormState());
      $this->assertSame('email', $form['email']['#type']);
      $this->assertTrue($form['email']['#required']);
      $this->assertArrayHasKey('submit', $form['actions']);
    }

    // -------------------------------------------------------------------------
    // validateForm
    // -------------------------------------------------------------------------

    public function testValidateFormAllowsARequestWithinTheFloodLimit(): void {
      $this->floodMock->method('isAllowed')
        ->with(LoginHelpForm::FLOOD_EVENT, LoginHelpForm::FLOOD_LIMIT, LoginHelpForm::FLOOD_WINDOW)
        ->willReturn(TRUE);
      $formState = new FormState();
      $form = [];
      $this->buildForm()->validateForm($form, $formState);
      $this->assertSame([], $formState->getErrors());
    }

    public function testValidateFormBlocksTooManyRequests(): void {
      $this->floodMock->method('isAllowed')->willReturn(FALSE);
      $formState = new FormState();
      $form = [];
      $this->buildForm()->validateForm($form, $formState);
      $this->assertArrayHasKey('email', $formState->getErrors());
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitFormEmailsAnNlWithASavedPassword(): void {
      $user = $this->nlUser();
      $this->drupalUserMock->method('getUserByEmail')->with('alice@example.com')->willReturn($user);
      $this->magicWordMock->method('getMagicWord')->with(42)->willReturn('planet7');
      $this->credentialsEmailMock->expects($this->once())->method('sendLoginHelpEmail')
        ->with($user)->willReturn(TRUE);
      $this->floodMock->expects($this->once())->method('register')
        ->with(LoginHelpForm::FLOOD_EVENT, LoginHelpForm::FLOOD_WINDOW);
      $this->messengerMock->expects($this->once())->method('addStatus');

      $formState = $this->submit(' alice@example.com ');

      $this->assertSame(['Login help sent to: alice.smith'], $this->notices);
      $this->assertSame('user.login', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormGivesTheSameReplyForAnEmailNotOnFile(): void {
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->credentialsEmailMock->expects($this->never())->method('sendLoginHelpEmail');
      $this->floodMock->expects($this->once())->method('register');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $formState = $this->submit('nobody@example.com');

      $this->assertSame(['Login help asked for an email not on file: nobody@example.com'], $this->notices);
      $this->assertSame('user.login', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormSendsNothingWithoutASavedPassword(): void {
      $this->drupalUserMock->method('getUserByEmail')->willReturn($this->nlUser());
      $this->magicWordMock->method('getMagicWord')->willReturn('');
      $this->credentialsEmailMock->expects($this->never())->method('sendLoginHelpEmail');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submit('alice@example.com');

      $this->assertSame(['Login help not sent, no NL password saved for: alice.smith'], $this->notices);
    }

    public function testSubmitFormSendsNothingToAnAccountWithoutAnMcid(): void {
      $this->drupalUserMock->method('getUserByEmail')->willReturn($this->nlUser(['mcid' => NULL]));
      $this->magicWordMock->expects($this->never())->method('getMagicWord');
      $this->credentialsEmailMock->expects($this->never())->method('sendLoginHelpEmail');

      $this->submit('alice@example.com');
    }

    public function testSubmitFormSendsNothingToAnAdministrator(): void {
      $this->drupalUserMock->method('getUserByEmail')
        ->willReturn($this->nlUser(['roles' => ['authenticated', 'nlp_admin']]));
      $this->magicWordMock->method('getMagicWord')->willReturn('planet7');
      $this->credentialsEmailMock->expects($this->never())->method('sendLoginHelpEmail');

      $this->submit('alice@example.com');
    }

    public function testSubmitFormLogsNothingSentWhenTheEmailFails(): void {
      $this->drupalUserMock->method('getUserByEmail')->willReturn($this->nlUser());
      $this->magicWordMock->method('getMagicWord')->willReturn('planet7');
      $this->credentialsEmailMock->method('sendLoginHelpEmail')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submit('alice@example.com');

      // sendLoginHelpEmail() logs its own failure.
      $this->assertSame([], $this->notices);
    }

  }

}
