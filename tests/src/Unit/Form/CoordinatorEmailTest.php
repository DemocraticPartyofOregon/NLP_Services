<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\CoordinatorEmail;
use Drupal\nlpservices\HtmlText;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\CoordinatorEmail
 * @group nlpservices
 */
class CoordinatorEmailTest extends UnitTestCase {

  private MailManagerInterface $mailManagerMock;
  private LanguageManagerInterface $languageManagerMock;
  private HtmlText $htmlTextMock;
  private MessengerInterface $messengerMock;
  private RequestStack $requestStackMock;

  protected function setUp(): void {
    parent::setUp();

    $this->mailManagerMock     = $this->createMock(MailManagerInterface::class);
    $this->languageManagerMock = $this->createMock(LanguageManagerInterface::class);
    $this->htmlTextMock        = $this->createMock(HtmlText::class);
    $this->messengerMock       = $this->createMock(MessengerInterface::class);
    $this->requestStackMock    = $this->createMock(RequestStack::class);

    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')->with('nlpservices-email-configuration')->willReturn([
      'notifications' => ['email' => 'coordinator@example.com'],
    ]);

    $configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
    $configFactoryMock->method('get')->with('nlpservices.configuration')->willReturn($configMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('config.factory', $configFactoryMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildForm(): CoordinatorEmail {
    $form = new CoordinatorEmail(
      $this->mailManagerMock,
      $this->languageManagerMock,
      $this->htmlTextMock,
      $this->requestStackMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function makeRequest(array $params = []): Request {
    $defaults = [
      'to'        => 'coord@example.com',
      'from'      => 'nl@example.com',
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
    ];
    return Request::create('/', 'GET', array_merge($defaults, $params));
  }

  private function configureLanguageMock(): void {
    $languageMock = $this->createMock(LanguageInterface::class);
    $languageMock->method('getId')->willReturn('en');
    $this->languageManagerMock->method('getDefaultLanguage')->willReturn($languageMock);
  }

  private function submitFormWithDefaults(CoordinatorEmail $form): void {
    $formState = new FormState();
    $formState->set('parameters', [
      'to'        => 'coord@example.com',
      'from'      => 'nl@example.com',
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
    ]);
    $formState->setValue('subject', 'Test Subject');
    $formState->setValue('message', 'Hello coordinator.');

    $formRef = [];
    $form->submitForm($formRef, $formState);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_coordinator_email_form', $this->buildForm()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsSubjectMessageAndSendElements(): void {
    $this->requestStackMock->method('getCurrentRequest')->willReturn($this->makeRequest());

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertArrayHasKey('subject', $form);
    $this->assertArrayHasKey('message', $form);
    $this->assertArrayHasKey('send', $form);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormStoresQueryParametersInFormState(): void {
    $this->requestStackMock->method('getCurrentRequest')->willReturn(
      $this->makeRequest(['to' => 'coord@test.com', 'from' => 'nl@test.com', 'firstName' => 'Alice', 'lastName' => 'Smith'])
    );

    $formState = new FormState();
    $this->buildForm()->buildForm([], $formState);

    $params = $formState->get('parameters');
    $this->assertSame('coord@test.com', $params['to']);
    $this->assertSame('nl@test.com', $params['from']);
    $this->assertSame('Alice', $params['firstName']);
    $this->assertSame('Smith', $params['lastName']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSetsReenterFlagWhenNotAlreadySet(): void {
    $this->requestStackMock->method('getCurrentRequest')->willReturn($this->makeRequest());

    $formState = new FormState();
    $this->assertNull($formState->get('reenter'));

    $this->buildForm()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormUsesEmptyStringWhenQueryParamMissing(): void {
    $this->requestStackMock->method('getCurrentRequest')
      ->willReturn(Request::create('/'));

    $formState = new FormState();
    $this->buildForm()->buildForm([], $formState);

    $params = $formState->get('parameters');
    $this->assertSame('', $params['to']);
    $this->assertSame('', $params['from']);
    $this->assertSame('', $params['firstName']);
    $this->assertSame('', $params['lastName']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSendsMailAndAddsSuccessMessage(): void {
    $this->configureLanguageMock();
    $this->htmlTextMock->method('getText')->willReturn('plain text');
    $this->mailManagerMock->expects($this->once())->method('mail')->willReturn(['result' => TRUE]);
    $this->messengerMock->expects($this->once())->method('addMessage');

    $this->submitFormWithDefaults($this->buildForm());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAddsErrorMessageWhenMailFails(): void {
    $this->configureLanguageMock();
    $this->htmlTextMock->method('getText')->willReturn('plain text');
    $this->mailManagerMock->method('mail')->willReturn(['result' => FALSE]);
    $this->messengerMock->expects($this->once())->method('addError');
    $this->messengerMock->expects($this->never())->method('addMessage');

    $this->submitFormWithDefaults($this->buildForm());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPassesMessageWithSenderSignatureToHtmlText(): void {
    $this->configureLanguageMock();
    $this->mailManagerMock->method('mail')->willReturn(['result' => TRUE]);

    $this->htmlTextMock->expects($this->once())
      ->method('setHtml')
      ->with($this->logicalAnd(
        $this->stringContains('Jane'),
        $this->stringContains('Doe'),
        $this->stringContains('nl@example.com'),
      ));

    $this->submitFormWithDefaults($this->buildForm());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPassesCorrectRecipientAndReplyToMail(): void {
    $this->configureLanguageMock();
    $this->htmlTextMock->method('getText')->willReturn('plain text');

    $this->mailManagerMock->expects($this->once())
      ->method('mail')
      ->with(
        $this->anything(),
        $this->anything(),
        'coord@example.com',
        'en',
        $this->anything(),
        'nl@example.com',
      )
      ->willReturn(['result' => TRUE]);

    $this->submitFormWithDefaults($this->buildForm());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSetsRedirectToHelpRoute(): void {
    $this->configureLanguageMock();
    $this->htmlTextMock->method('getText')->willReturn('plain text');
    $this->mailManagerMock->method('mail')->willReturn(['result' => TRUE]);

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRedirect'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('nlpservices.data_entry_help_route');
    $formState->set('parameters', [
      'to'        => 'coord@example.com',
      'from'      => 'nl@example.com',
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
    ]);
    $formState->setValue('subject', 'Test Subject');
    $formState->setValue('message', 'Body text.');

    $formRef = [];
    $this->buildForm()->submitForm($formRef, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSetsRedirectEvenWhenMailFails(): void {
    $this->configureLanguageMock();
    $this->htmlTextMock->method('getText')->willReturn('plain text');
    $this->mailManagerMock->method('mail')->willReturn(['result' => FALSE]);

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRedirect'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setRedirect')
      ->with('nlpservices.data_entry_help_route');
    $formState->set('parameters', [
      'to'        => 'coord@example.com',
      'from'      => 'nl@example.com',
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
    ]);
    $formState->setValue('subject', 'Test Subject');
    $formState->setValue('message', 'Body text.');

    $formRef = [];
    $this->buildForm()->submitForm($formRef, $formState);
  }

}
