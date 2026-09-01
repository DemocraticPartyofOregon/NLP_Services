<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\DataEntryDefaultMethodForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\DataEntryDefaultMethodForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryDefaultMethodForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryDefaultMethodForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryDefaultMethodForm::class, 'submitForm')]
#[Group('nlpservices')]
class DataEntryDefaultMethodFormTest extends UnitTestCase {

  protected $sessionDataMock;
  protected $nlpConfigurationMock;
  protected $messengerMock;
  protected DataEntryDefaultMethodForm $form;

  protected function setUp(): void {
    parent::setUp();

    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    $this->messengerMock        = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->form = new DataEntryDefaultMethodForm(
      $this->sessionDataMock,
      $this->nlpConfigurationMock,
    );
    $this->form->setStringTranslation($this->getStringTranslationStub());
    $this->form->setMessenger($this->messengerMock);
  }

  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_default_method_form', $this->form->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormIncludesRequiredElements(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone']);
    $this->sessionDataMock->method('get')->willReturn(NULL);

    $form = $this->form->buildForm([], new FormState());

    $this->assertArrayHasKey('contact_title', $form);
    $this->assertArrayHasKey('contact_method', $form);
    $this->assertArrayHasKey('save', $form);
    $this->assertSame('select', $form['contact_method']['#type']);
    $this->assertSame('submit', $form['save']['#type']);
  }

  public function testBuildFormUsesSessionDefaultWhenValid(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone', 2 => 'Postcard']);
    $this->sessionDataMock->method('get')
      ->with('defaultVoterContactMethod')
      ->willReturn(['name' => 'Phone']);

    $form = $this->form->buildForm([], new FormState());

    $this->assertSame(1, $form['contact_method']['#default_value']);
  }

  public function testBuildFormFallsBackToWalkWhenNoSessionDefault(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Phone', 1 => 'Walk', 2 => 'Postcard']);
    $this->sessionDataMock->method('get')->willReturn(NULL);

    $form = $this->form->buildForm([], new FormState());

    $this->assertSame(1, $form['contact_method']['#default_value']);
  }

  public function testBuildFormFallsBackToFirstKeyWhenNoWalkAndNoSession(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([5 => 'Phone', 6 => 'Postcard']);
    $this->sessionDataMock->method('get')->willReturn(NULL);

    $form = $this->form->buildForm([], new FormState());

    $this->assertSame(5, $form['contact_method']['#default_value']);
  }

  public function testBuildFormFallsBackToWalkWhenSessionNameNotInMethods(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone']);
    $this->sessionDataMock->method('get')
      ->with('defaultVoterContactMethod')
      ->willReturn(['name' => 'Canvass']);

    $form = $this->form->buildForm([], new FormState());

    $this->assertSame(0, $form['contact_method']['#default_value']);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormSavesSelectedMethodToSession(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone', 2 => 'Postcard']);

    $this->sessionDataMock->expects($this->once())
      ->method('set')
      ->with('defaultVoterContactMethod', ['code' => 1, 'name' => 'Phone']);

    $formState = new FormState();
    $formState->setValue('contact_method', 1);

    $form = [];
    $this->form->submitForm($form, $formState);
  }

  public function testSubmitFormShowsStatusMessageWithSelectedName(): void {
    $this->nlpConfigurationMock->method('getContactMethods')
      ->willReturn([0 => 'Walk', 1 => 'Phone']);

    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->setValue('contact_method', 0);

    $form = [];
    $this->form->submitForm($form, $formState);
  }

}
