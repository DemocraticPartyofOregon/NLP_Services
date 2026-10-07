<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\ChangeCountyForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\ChangeCountyForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\ChangeCountyForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\ChangeCountyForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\ChangeCountyForm::class, 'submitForm')]
#[Group('nlpservices')]
class ChangeCountyFormTest extends UnitTestCase {

  private NlpSessionData $sessionDataMock;
  private NlpConfiguration $nlpConfigurationMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    $this->messengerMock        = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
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

  private function buildFormInstance(): ChangeCountyForm {
    $form = new ChangeCountyForm(
      $this->sessionDataMock,
      $this->nlpConfigurationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('change_county_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormOptionsAreKeyedByCountyName(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('Hinds');
    $this->nlpConfigurationMock->method('getCountyNames')->willReturn(['Hinds', 'Madison']);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame(['Hinds' => 'Hinds', 'Madison' => 'Madison'], $form['county_select']['#options']);
    $this->assertTrue($form['county_select']['#required']);
    $this->assertArrayHasKey('submit', $form);
  }

  public function testBuildFormDefaultsToCurrentCounty(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('Madison');
    $this->nlpConfigurationMock->method('getCountyNames')->willReturn(['Hinds', 'Madison']);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('Madison', $form['county_select']['#default_value']);
    $this->assertStringContainsString('Madison County', (string) $form['current_county']['#markup']);
  }

  public function testBuildFormUnknownCountyHasNoDefault(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->nlpConfigurationMock->method('getCountyNames')->willReturn(['Hinds', 'Madison']);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('', $form['county_select']['#default_value']);
    $this->assertStringContainsString('No county is selected', (string) $form['current_county']['#markup']);
  }

  public function testBuildFormHandlesNoConfiguredCounties(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->nlpConfigurationMock->method('getCountyNames')->willReturn([]);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $form['county_select']['#options']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormStoresCountyAndResetsTurfContext(): void {
    $stored = [];
    $this->sessionDataMock->method('set')
      ->willReturnCallback(function ($key, $value) use (&$stored) { $stored[$key] = $value; });
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->setValue('county_select', 'Madison');
    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame(
      ['County' => 'Madison', 'currentFolderId' => 0, 'currentHd' => 0, 'currentPct' => ''],
      $stored
    );
  }

  public function testSubmitFormRedirectsToManageCounty(): void {
    $formState = new FormState();
    $formState->setValue('county_select', 'Madison');
    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('nlpservices.manage_county_menu', $formState->getRedirect()->getRouteName());
  }

}
