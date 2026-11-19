<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Config;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Config\MapKeyConfigurationForm;
use Drupal\nlpservices\NlpTurfMap;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class MapKeyConfigurationFormTest extends UnitTestCase {

  private NlpTurfMap $turfMapMock;
  private MessengerInterface $messengerMock;

  /** Messages shown, as "type: text". */
  private array $messages = [];

  protected function setUp(): void {
    parent::setUp();
    $this->turfMapMock = $this->createMock(NlpTurfMap::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);
    foreach (['addStatus' => 'status', 'addWarning' => 'warning', 'addError' => 'error'] as $method => $type) {
      $this->messengerMock->method($method)->willReturnCallback(function ($message) use ($type) {
        $this->messages[] = "$type: $message";
        return $this->messengerMock;
      });
    }
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  private function buildFormObject(): MapKeyConfigurationForm {
    $form = new MapKeyConfigurationForm($this->turfMapMock);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function submit(array $values): void {
    $formState = (new FormState())->setValues($values);
    $form = [];
    $this->buildFormObject()->submitForm($form, $formState);
  }

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('nlpservices_map_key_configuration_form', $this->buildFormObject()->getFormId());
  }

  public function testBuildFormShowsStoredKeysAndOffersRemoval(): void {
    $this->turfMapMock->method('mapboxTokenFingerprint')->willReturn('pk.eyJ1…x9Qw (92 characters)');
    $this->turfMapMock->method('geocodioKeyFingerprint')->willReturn('');

    $form = $this->buildFormObject()->buildForm([], new FormState());

    $this->assertStringContainsString('pk.eyJ1…x9Qw (92 characters)', (string) $form['mapbox']['status']['#markup']);
    $this->assertArrayHasKey('removeMapbox', $form['mapbox']);
    $this->assertStringContainsString('US Census', (string) $form['geocodio']['status']['#markup']);
    $this->assertArrayNotHasKey('removeGeocodio', $form['geocodio']);
    // Keys are typed in plain text fields so password managers don't fill them.
    $this->assertSame('textfield', $form['mapbox']['mapboxToken']['#type']);
    $this->assertSame('off', $form['geocodio']['geocodioKey']['#attributes']['autocomplete']);
  }

  public function testValidateRejectsKeysInTheWrongFormat(): void {
    $formState = (new FormState())->setValues(['mapboxToken' => 'MySitePassword1', 'geocodioKey' => 'not a key!']);
    $form = [];
    $this->buildFormObject()->validateForm($form, $formState);

    $this->assertArrayHasKey('mapboxToken', $formState->getErrors());
    $this->assertArrayHasKey('geocodioKey', $formState->getErrors());
  }

  public function testValidateAcceptsBlankFieldsAndRealLookingKeys(): void {
    $formState = (new FormState())->setValues([
      'mapboxToken' => ' pk.eyJ1IjoiYWJjIn0.AbC_d-9xYz ',
      'geocodioKey' => '',
    ]);
    $form = [];
    $this->buildFormObject()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testSubmitSavesNewKeysTrimmed(): void {
    $this->turfMapMock->expects($this->once())->method('saveMapboxToken')
      ->with('pk.eyJ1IjoiYWJjIn0.AbC_d-9xYz')->willReturn(TRUE);
    $this->turfMapMock->expects($this->once())->method('saveGeocodioKey')
      ->with('abcdef0123456789abcdef0123456789')->willReturn(TRUE);

    $this->submit(['mapboxToken' => ' pk.eyJ1IjoiYWJjIn0.AbC_d-9xYz ', 'geocodioKey' => 'abcdef0123456789abcdef0123456789']);

    $this->assertCount(2, array_filter($this->messages, fn($m) => str_starts_with($m, 'status: ')));
  }

  public function testSubmitWithBlankFieldsChangesNothing(): void {
    $this->turfMapMock->expects($this->never())->method('saveMapboxToken');
    $this->turfMapMock->expects($this->never())->method('saveGeocodioKey');
    $this->turfMapMock->expects($this->never())->method('deleteMapboxToken');
    $this->turfMapMock->expects($this->never())->method('deleteGeocodioKey');

    $this->submit(['mapboxToken' => '', 'geocodioKey' => '']);

    $this->assertSame([], $this->messages);
  }

  public function testSubmitRemoveWinsOverANewValue(): void {
    $this->turfMapMock->expects($this->once())->method('deleteGeocodioKey')->willReturn(TRUE);
    $this->turfMapMock->expects($this->never())->method('saveGeocodioKey');

    $this->submit(['geocodioKey' => 'abcdef0123456789abcdef0123456789', 'removeGeocodio' => 1]);

    $this->assertSame(['status: The Geocodio key was removed.'], $this->messages);
  }

  public function testSubmitReportsAKeyThatCouldNotBeSaved(): void {
    $this->turfMapMock->method('saveMapboxToken')->willReturn(FALSE);

    $this->submit(['mapboxToken' => 'pk.eyJ1IjoiYWJjIn0.AbC_d-9xYz']);

    $this->assertStringStartsWith('error: The Mapbox token could not be saved.', $this->messages[0]);
  }

  public function testTestKeysReportsEachResult(): void {
    $this->turfMapMock->method('hasMapboxToken')->willReturn(TRUE);
    $this->turfMapMock->method('verifyMapboxToken')->willReturn('');
    $this->turfMapMock->method('hasGeocodioKey')->willReturn(TRUE);
    $this->turfMapMock->method('verifyGeocodioKey')->willReturn('Geocodio refused the key (status 403): Invalid API key');

    $form = [];
    $this->buildFormObject()->testKeysSubmit($form, new FormState());

    $this->assertSame([
      'status: The Mapbox token works.',
      'error: The Geocodio key did not work: Geocodio refused the key (status 403): Invalid API key',
    ], $this->messages);
  }

  public function testTestKeysWithNothingStored(): void {
    $this->turfMapMock->method('hasMapboxToken')->willReturn(FALSE);
    $this->turfMapMock->method('hasGeocodioKey')->willReturn(FALSE);
    $this->turfMapMock->expects($this->never())->method('verifyMapboxToken');
    $this->turfMapMock->expects($this->never())->method('verifyGeocodioKey');

    $form = [];
    $this->buildFormObject()->testKeysSubmit($form, new FormState());

    $this->assertSame([
      'warning: No Mapbox token is stored, so walk sheets have no map.',
      'status: No Geocodio key is stored; the US Census geocoder is used.',
    ], $this->messages);
  }

}
