<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpTurfMap;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stores the keys for the walk sheet map: a Mapbox access token, which draws
 * the map, and an optional Geocodio API key, which places each door on its
 * building.  Without Geocodio, doors are placed by the free US Census
 * geocoder, along the street.  Without Mapbox, walk sheets have no map.
 */
class MapKeyConfigurationForm extends FormBase
{
  protected NlpTurfMap $turfMapObj;

  public function __construct(NlpTurfMap $turfMapObj)
  {
    $this->turfMapObj = $turfMapObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): MapKeyConfigurationForm
  {
    return new static(
      $container->get('nlpservices.turf_map'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_map_key_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Walk sheets include a map with a pin at each door. Mapbox draws the map; without a Mapbox token, walk sheets have no map. Geocodio finds where each door is and places most pins on the building; without a Geocodio key, the free US Census geocoder places the pins along the street.') . '</p>',
    ];

    $mapbox = $this->turfMapObj->mapboxTokenFingerprint();
    $form['mapbox'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mapbox (draws the map)'),
    ];
    $form['mapbox']['status'] = [
      '#markup' => '<p>' . ($mapbox !== ''
        ? $this->t('Stored token: %fingerprint', ['%fingerprint' => $mapbox])
        : $this->t('No token is stored, so walk sheets have no map.')) . '</p>',
    ];
    // Plain text fields with autofill off: as password fields, the browser
    // fills in the site password and the next save stores it as the key.
    $form['mapbox']['mapboxToken'] = [
      '#type' => 'textfield',
      '#title' => $mapbox !== '' ? $this->t('Replace the token') : $this->t('Access token'),
      '#description' => $this->t('From account.mapbox.com, the default public token (it starts with "pk.").'),
      '#maxlength' => 512,
      '#attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
    ];
    if ($mapbox !== '') {
      $form['mapbox']['removeMapbox'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the token (walk sheets will have no map)'),
      ];
    }

    $geocodio = $this->turfMapObj->geocodioKeyFingerprint();
    $form['geocodio'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Geocodio (places the pins on the buildings; optional)'),
    ];
    $form['geocodio']['status'] = [
      '#markup' => '<p>' . ($geocodio !== ''
        ? $this->t('Stored key: %fingerprint', ['%fingerprint' => $geocodio])
        : $this->t('No key is stored, so the US Census geocoder places the pins along the street.')) . '</p>',
    ];
    $form['geocodio']['geocodioKey'] = [
      '#type' => 'textfield',
      '#title' => $geocodio !== '' ? $this->t('Replace the key') : $this->t('API key'),
      '#description' => $this->t('From dash.geocod.io, under API Keys.'),
      '#maxlength' => 128,
      '#attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
    ];
    if ($geocodio !== '') {
      $form['geocodio']['removeGeocodio'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the key (use the US Census geocoder)'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#name' => 'save',
      '#value' => $this->t('Save'),
    ];
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#name' => 'test',
      '#value' => $this->t('Test the stored keys'),
      '#submit' => ['::testKeysSubmit'],
      '#limit_validation_errors' => [],
    ];
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">' . $this->t('Go back') . '</a>',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $mapboxToken = trim((string) $form_state->getValue('mapboxToken'));
    if ($mapboxToken !== '' && !NlpTurfMap::isMapboxTokenFormat($mapboxToken)) {
      $form_state->setErrorByName('mapboxToken',
        $this->t('That is not a Mapbox token. It should look like pk.eyJ1Ijoi….xxxx, with no spaces or quotes.'));
    }
    $geocodioKey = trim((string) $form_state->getValue('geocodioKey'));
    if ($geocodioKey !== '' && !NlpTurfMap::isGeocodioKeyFormat($geocodioKey)) {
      $form_state->setErrorByName('geocodioKey',
        $this->t('That is not a Geocodio API key. It should be letters and digits only.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $this->saveKey('Mapbox token', trim((string) $form_state->getValue('mapboxToken')),
      (bool) $form_state->getValue('removeMapbox'),
      fn($value) => $this->turfMapObj->saveMapboxToken($value),
      fn() => $this->turfMapObj->deleteMapboxToken());
    $this->saveKey('Geocodio key', trim((string) $form_state->getValue('geocodioKey')),
      (bool) $form_state->getValue('removeGeocodio'),
      fn($value) => $this->turfMapObj->saveGeocodioKey($value),
      fn() => $this->turfMapObj->deleteGeocodioKey());
  }

  /**
   * testKeysSubmit
   *
   * Submit handler for "Test the stored keys".
   */
  public function testKeysSubmit(array &$form, FormStateInterface $form_state): void
  {
    if ($this->turfMapObj->hasMapboxToken()) {
      $this->reportTest('Mapbox token', $this->turfMapObj->verifyMapboxToken());
    }
    else {
      $this->messenger()->addWarning($this->t('No Mapbox token is stored, so walk sheets have no map.'));
    }
    if ($this->turfMapObj->hasGeocodioKey()) {
      $this->reportTest('Geocodio key', $this->turfMapObj->verifyGeocodioKey());
    }
    else {
      $this->messenger()->addStatus($this->t('No Geocodio key is stored; the US Census geocoder is used.'));
    }
  }

  /**
   * saveKey
   *
   * Removes, replaces, or leaves one key, and says what happened.
   *
   * @param string $label
   * @param string $newValue - '' leaves the stored key alone.
   * @param bool $remove
   * @param callable $save - takes the new value, returns bool.
   * @param callable $delete - returns bool.
   */
  protected function saveKey(string $label, string $newValue, bool $remove, callable $save, callable $delete): void
  {
    if ($remove) {
      if ($delete()) {
        $this->messenger()->addStatus($this->t('The @label was removed.', ['@label' => $label]));
      }
      else {
        $this->messenger()->addError($this->t('The @label could not be removed.', ['@label' => $label]));
      }
      return;
    }
    if ($newValue === '') {
      return;
    }
    if ($save($newValue)) {
      $this->messenger()->addStatus($this->t('The @label was saved. Use "Test the stored keys" to check it.', ['@label' => $label]));
    }
    else {
      $this->messenger()->addError($this->t('The @label could not be saved. Check that the private file path is set up (see NlpReadMe.md).', ['@label' => $label]));
    }
  }

  /**
   * reportTest
   *
   * @param string $label
   * @param string $error - '' if the key works.
   */
  protected function reportTest(string $label, string $error): void
  {
    if ($error === '') {
      $this->messenger()->addStatus($this->t('The @label works.', ['@label' => $label]));
    }
    else {
      $this->messenger()->addError($this->t('The @label did not work: @error', ['@label' => $label, '@error' => $error]));
    }
  }

}
