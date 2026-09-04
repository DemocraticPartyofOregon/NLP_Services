<?php

namespace Drupal\Tests\nlpservices\Unit\Config {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\nlpservices\ApiKeyVerification;
  use Drupal\nlpservices\Config\KeyValidateForm;
  use Drupal\Tests\UnitTestCase;

  class KeyValidateFormTest extends UnitTestCase {

    private ApiKeyVerification $verifyApiKeysMock;

    protected function setUp(): void {
      parent::setUp();
      $this->verifyApiKeysMock = $this->createMock(ApiKeyVerification::class);

      $container = new ContainerBuilder();
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

    private function buildForm(): KeyValidateForm {
      $form = new KeyValidateForm($this->verifyApiKeysMock);
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function freshFormState(): FormStateInterface {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('renter')->willReturn(NULL);
      return $formState;
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_key_validate_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — first entry (renter not set) initialises page to selectMcid
    // -------------------------------------------------------------------------

    public function testBuildFormFirstEntryRendersVanidField(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['renter', NULL],
        ['page',   'selectMcid'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('vanid', $result);
      $this->assertSame('number', $result['vanid']['#type']);
      $this->assertTrue($result['vanid']['#required']);
    }

    // -------------------------------------------------------------------------
    // buildForm — selectMcid page renders submit button
    // -------------------------------------------------------------------------

    public function testBuildFormSelectMcidRendersSubmitButton(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['renter', TRUE],
        ['page',   'selectMcid'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('useVanid', $result);
      $this->assertSame('submit', $result['useVanid']['#type']);
      $this->assertSame('test-keys', $result['useVanid']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — keyTestResults page calls apiKeyVerification and renders table
    // -------------------------------------------------------------------------

    public function testBuildFormKeyTestResultsCallsVerificationAndRendersTable(): void {
      $mcid = 12345;
      $rows = [['County A', 'OK', 'OK', 'OK']];

      $this->verifyApiKeysMock->expects($this->once())
        ->method('apiKeyVerification')
        ->with($mcid)
        ->willReturn($rows);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['renter', TRUE],
        ['page',   'keyTestResults'],
        ['mcid',   $mcid],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('testResults', $result);
      $this->assertSame('table', $result['testResults']['#type']);
      $this->assertSame($rows, $result['testResults']['#rows']);
    }

    // -------------------------------------------------------------------------
    // buildForm — keyTestResults page resets page to selectMcid
    // -------------------------------------------------------------------------

    public function testBuildFormKeyTestResultsResetsPageToSelectMcid(): void {
      $this->verifyApiKeysMock->method('apiKeyVerification')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['renter', TRUE],
        ['page',   'keyTestResults'],
        ['mcid',   100],
      ]);
      $formState->expects($this->once())->method('set')->with('page', 'selectMcid');

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — always includes the Go back link
    // -------------------------------------------------------------------------

    public function testBuildFormAlwaysIncludesGoBackLink(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['renter', TRUE],
        ['page',   'selectMcid'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('button_link', $result);
      $this->assertStringContainsString('Go back', $result['button_link']['#markup']);
    }

    // -------------------------------------------------------------------------
    // submitForm — selectMcid transitions to keyTestResults and stores mcid
    // -------------------------------------------------------------------------

    public function testSubmitFormSelectMcidTransitionsToKeyTestResults(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('page')->willReturn('selectMcid');
      $formState->method('getValue')->with('vanid')->willReturn('99999');
      $formState->expects($this->exactly(2))->method('set')
        ->willReturnCallback(function ($key, $value) {
          if ($key === 'page') {
            $this->assertSame('keyTestResults', $value);
          }
          if ($key === 'mcid') {
            $this->assertSame(99999, $value);
          }
        });
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — keyTestResults transitions back to selectMcid
    // -------------------------------------------------------------------------

    public function testSubmitFormKeyTestResultsTransitionsToSelectMcid(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('page')->willReturn('keyTestResults');
      $formState->expects($this->once())->method('set')->with('page', 'selectMcid');
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — always calls setRebuild
    // -------------------------------------------------------------------------

    public function testSubmitFormAlwaysCallsSetRebuild(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('page')->willReturn('selectMcid');
      $formState->method('getValue')->willReturn('0');
      $formState->method('set');
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

  }

}
