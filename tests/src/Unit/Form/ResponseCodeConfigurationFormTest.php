<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\ApiResponseCodes;
use Drupal\nlpservices\Config\ResponseCodeConfigurationForm;
use Drupal\nlpservices\NlpEncryption;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ResponseCodeConfigurationForm::class)]
#[Group('nlpservices')]
class ResponseCodeConfigurationFormTest extends UnitTestCase {

  private ConfigFactoryInterface $configFactoryMock;
  private ApiResponseCodes $apiResponseCodeObjMock;
  private NlpEncryption $nlpEncryptObjMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->configFactoryMock      = $this->createMock(ConfigFactoryInterface::class);
    $this->apiResponseCodeObjMock = $this->createMock(ApiResponseCodes::class);
    $this->nlpEncryptObjMock      = $this->createMock(NlpEncryption::class);
    $this->messengerMock          = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('config.factory', $this->configFactoryMock);
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

  private function buildForm(): ResponseCodeConfigurationForm {
    $form = new ResponseCodeConfigurationForm(
      $this->configFactoryMock,
      $this->apiResponseCodeObjMock,
      $this->nlpEncryptObjMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Captures every addWarning() message, rendered with its placeholder
   * substitutions applied, into $messages, which the caller owns.
   */
  private function captureWarnings(array &$messages): void {
    $this->messengerMock->method('addWarning')
      ->willReturnCallback(function ($message) use (&$messages) {
        $messages[] = (string) $message;
      });
  }

  private function assertWarningContains(array $messages, string $needle): void {
    $this->assertTrue(
      array_reduce($messages, fn($found, $m) => $found || str_contains($m, $needle), false),
      "Expected a warning message containing \"$needle\". Got: " . implode(' | ', $messages)
    );
  }

  private function stubKnownResultCodes(array $knownResultCodes, array $expectedResultCodes = []): void {
    $this->apiResponseCodeObjMock->method('getApiKnownResultCodes')->willReturn($knownResultCodes);
    $this->apiResponseCodeObjMock->method('getApiExpectedResultCodes')->willReturn($expectedResultCodes);
  }

  private function stubCurrentResponseCodes(array $currentResponseCodes): void {
    $configMock = $this->createMock(ImmutableConfig::class);
    $configMock->method('get')
      ->with('nlpservices_canvass_response_codes')
      ->willReturn($currentResponseCodes);
    $this->configFactoryMock->method('get')
      ->with('nlpservices.configuration')
      ->willReturn($configMock);
  }

  private function defaultKnownResultCodes(): array {
    return [
      'Walk' => ['code' => 1, 'responses' => ['Deceased' => 102, 'Moved' => 103]],
      'Phone' => ['code' => 2, 'responses' => ['Left Message' => 201]],
    ];
  }

  /**
   * Known result codes that satisfy every subContext/subResponse pair
   * referenced by $phoneRcSubs, so warnOfMissingPhoneRcSubs stays silent.
   */
  private function completeKnownResultCodesForPhoneSubs(): array {
    return [
      'Walk' => ['code' => 1, 'responses' => ['Deceased' => 101, 'Hostile' => 102, 'Moved' => 103]],
      'Web Survey' => ['code' => 3, 'responses' => [
        'Mailed' => 301,
        'Not Interested' => 302,
        'No Such Address' => 303,
        'Canvassed' => 304,
        'Do Not Mail' => 305,
      ]],
    ];
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('canvass_response_code_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsBackLink(): void {
    $this->stubKnownResultCodes([]);
    $this->stubCurrentResponseCodes([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubKnownResultCodes([]);
    $this->stubCurrentResponseCodes([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['submit']['#type']);
  }

  public function testBuildFormCreatesCheckboxForEachKnownResponseCode(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());
    $this->stubCurrentResponseCodes([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('checkbox', $form['code_select']['code_display']['CK-1-102']['#type']);
    $this->assertSame('checkbox', $form['code_select']['code_display']['CK-1-103']['#type']);
    $this->assertSame('checkbox', $form['code_select']['code_display']['CK-2-201']['#type']);
  }

  public function testBuildFormMarksRecommendedResponseWithCheckmark(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes(), [
      'Walk' => ['responses' => ['Deceased' => 'Deceased']],
    ]);
    $this->stubCurrentResponseCodes([]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('&#x2714;', $form['code_select']['code_display']['TX-0-recommended']['#markup']);
    $this->assertStringNotContainsString('&#x2714;', $form['code_select']['code_display']['TX-1-recommended']['#markup']);
  }

  public function testBuildFormSetsDefaultValueForCurrentlySelectedCode(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());
    $this->stubCurrentResponseCodes([
      'Walk' => ['name' => 'Walk', 'code' => 1, 'responses' => ['Deceased' => 102]],
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame(1, $form['code_select']['code_display']['CK-1-102']['#default_value']);
  }

  public function testBuildFormOmitsDefaultValueWhenCodeNotCurrentlySelected(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());
    $this->stubCurrentResponseCodes([
      'Walk' => ['name' => 'Walk', 'code' => 1, 'responses' => ['Deceased' => 102]],
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayNotHasKey('#default_value', $form['code_select']['code_display']['CK-1-103']);
  }

  // ---------------------------------------------------------------------------
  // buildForm - warnOfMissingExpectedResultCodes
  // ---------------------------------------------------------------------------

  public function testBuildFormWarnsWhenExpectedContactTypeMissing(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes(), [
      'Web Survey' => ['responses' => ['Mailed' => 'Mailed']],
    ]);
    $this->stubCurrentResponseCodes([]);

    $messages = [];
    $this->captureWarnings($messages);

    $this->buildForm()->buildForm([], new FormState());

    $this->assertWarningContains($messages, 'Contact type not available in VoteBuilder: [Web Survey]');
  }

  public function testBuildFormWarnsWhenExpectedResponseCodeMissing(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes(), [
      'Walk' => ['responses' => ['Hostile' => 'Hostile']],
    ]);
    $this->stubCurrentResponseCodes([]);

    $messages = [];
    $this->captureWarnings($messages);

    $this->buildForm()->buildForm([], new FormState());

    $this->assertWarningContains($messages, 'Expected response code not available in VoteBuilder: [Walk - Hostile]');
  }

  public function testBuildFormDoesNotWarnWhenExpectedResponseCodeAvailable(): void {
    $this->stubKnownResultCodes($this->completeKnownResultCodesForPhoneSubs(), [
      'Walk' => ['responses' => ['Deceased' => 'Deceased']],
    ]);
    $this->stubCurrentResponseCodes([]);

    $this->messengerMock->expects($this->never())->method('addWarning');

    $this->buildForm()->buildForm([], new FormState());
  }

  // ---------------------------------------------------------------------------
  // buildForm - warnOfMissingPhoneRcSubs
  // ---------------------------------------------------------------------------

  public function testBuildFormWarnsWhenPhoneRcSubContextMissing(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());
    $this->stubCurrentResponseCodes([]);

    $messages = [];
    $this->captureWarnings($messages);

    $this->buildForm()->buildForm([], new FormState());

    $this->assertWarningContains($messages, 'Phone response substitution not available in VoteBuilder: [Phone - Left Message -> Web Survey - Mailed]');
  }

  public function testBuildFormWarnsWhenPhoneRcSubResponseMissing(): void {
    $knownResultCodes = $this->completeKnownResultCodesForPhoneSubs();
    unset($knownResultCodes['Walk']['responses']['Hostile']);
    $this->stubKnownResultCodes($knownResultCodes);
    $this->stubCurrentResponseCodes([]);

    $messages = [];
    $this->captureWarnings($messages);

    $this->buildForm()->buildForm([], new FormState());

    $this->assertWarningContains($messages, 'Phone response substitution not available in VoteBuilder: [Phone - Hostile -> Walk - Hostile]');
  }

  public function testBuildFormDoesNotWarnWhenAllPhoneRcSubsAvailable(): void {
    $this->stubKnownResultCodes($this->completeKnownResultCodesForPhoneSubs());
    $this->stubCurrentResponseCodes([]);

    $this->messengerMock->expects($this->never())->method('addWarning');

    $this->buildForm()->buildForm([], new FormState());
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  private function submitFormWithValues(array $values): array {
    $this->stubCurrentResponseCodes([]);

    $editableConfigMock = $this->createMock(Config::class);
    $setCalls = [];
    $editableConfigMock->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function ($key, $value) use (&$setCalls, $editableConfigMock) {
        $setCalls[$key] = $value;
        return $editableConfigMock;
      });
    $editableConfigMock->expects($this->exactly(2))->method('save');
    $this->configFactoryMock->method('getEditable')
      ->with('nlpservices.configuration')
      ->willReturn($editableConfigMock);

    $formState = new FormState();
    $form = $this->buildForm()->buildForm([], $formState);
    $formState->setValues($values);

    $this->buildForm()->submitForm($form, $formState);

    return $setCalls;
  }

  public function testSubmitFormSavesSelectedResponseCodes(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());

    $setCalls = $this->submitFormWithValues([
      'CK-1-102' => 1,
      'CK-1-103' => 0,
      'CK-2-201' => '',
    ]);

    $this->assertSame([
      'Walk' => ['name' => 'Walk', 'code' => '1', 'responses' => ['Deceased' => '102']],
    ], $setCalls['nlpservices_canvass_response_codes']);
  }

  public function testSubmitFormIgnoresNonCheckboxValues(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());

    $setCalls = $this->submitFormWithValues([
      'op' => 'Save the selected response codes.',
      'form_id' => 'canvass_response_code_configuration_form',
    ]);

    $this->assertSame([], $setCalls['nlpservices_canvass_response_codes']);
  }

  public function testSubmitFormBuildsAlternatePhoneCodesWhenWalkResponseSelected(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());

    $setCalls = $this->submitFormWithValues([
      'CK-1-102' => 1,
    ]);

    $expectedSub = [
      'subContextName' => 'Walk',
      'subContext' => '1',
      'subResponseName' => 'Deceased',
      'subResponse' => '102',
    ];
    $this->assertSame($expectedSub, $setCalls['nlpservices_alt_phone_response_codes']['Phone']['Deceased']);
    $this->assertSame($expectedSub, $setCalls['nlpservices_alt_phone_response_codes']['SMS Text']['Deceased']);
  }

  public function testSubmitFormOmitsAlternatePhoneCodesWhenNoMatchingResponseSelected(): void {
    $this->stubKnownResultCodes($this->defaultKnownResultCodes());

    $setCalls = $this->submitFormWithValues([
      'CK-2-201' => 1,
    ]);

    $this->assertSame([], $setCalls['nlpservices_alt_phone_response_codes']);
  }

}
