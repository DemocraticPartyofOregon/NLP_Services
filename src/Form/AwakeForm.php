<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpTurfMap;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test form for temporary experiments.
 *
 * Current experiment: map a turf.  Geocode its doors with the US Census
 * geocoder, get a Mapbox static map with an unnumbered marker per door, and
 * put the map in a PDF.  See NlpTurfMap.
 */
class AwakeForm extends FormBase {

  protected NlpTurfMap $turfMapObj;

  public function __construct(NlpTurfMap $turfMapObj) {
    $this->turfMapObj = $turfMapObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AwakeForm
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
    return 'nlpservices_awake_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['turfIndex'] = [
      '#type' => 'number',
      '#title' => $this->t('Turf index'),
      '#min' => 1,
      '#default_value' => $form_state->getValue('turfIndex'),
    ];
    $fingerprint = $this->turfMapObj->mapboxTokenFingerprint();
    // A plain text field with autofill off: as a password field, the
    // browser's password manager filled in the site password, and the next
    // submit saved it over the token.
    $form['mapboxToken'] = [
      '#type' => 'textfield',
      '#title' => $fingerprint !== '' ? $this->t('Replace the Mapbox token (optional)') : $this->t('Mapbox access token'),
      '#maxlength' => 512,
      '#attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
      '#description' => $fingerprint !== ''
        ? $this->t('Stored token: %fingerprint. Stored in the Key module as %id.', ['%fingerprint' => $fingerprint, '%id' => NlpTurfMap::MAPBOX_KEY_ID])
        : $this->t('Starts with "pk.". Stored in the Key module as %id.', ['%id' => NlpTurfMap::MAPBOX_KEY_ID]),
    ];
    $form['geocoder'] = [
      '#type' => 'radios',
      '#title' => $this->t('Geocoder'),
      '#options' => NlpTurfMap::GEOCODERS,
      '#default_value' => $form_state->getValue('geocoder') ?? 'census',
      '#description' => $this->t('Red pins are on the building, purple pins are on the nearest building the geocoder knows, and orange pins are estimated along the street.'),
    ];
    $geocodioFingerprint = $this->turfMapObj->geocodioKeyFingerprint();
    $form['geocodioKey'] = [
      '#type' => 'textfield',
      '#title' => $geocodioFingerprint !== '' ? $this->t('Replace the Geocodio API key (optional)') : $this->t('Geocodio API key'),
      '#maxlength' => 128,
      '#attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
      '#description' => $geocodioFingerprint !== ''
        ? $this->t('Stored key: %fingerprint. Stored in the Key module as %id.', ['%fingerprint' => $geocodioFingerprint, '%id' => NlpTurfMap::GEOCODIO_KEY_ID])
        : $this->t('From dash.geocod.io. Stored in the Key module as %id.', ['%id' => NlpTurfMap::GEOCODIO_KEY_ID]),
      '#states' => ['visible' => [':input[name="geocoder"]' => ['value' => 'geocodio']]],
    ];
    $form['style'] = [
      '#type' => 'select',
      '#title' => $this->t('Map style'),
      '#options' => NlpTurfMap::STYLES + ['custom' => $this->t('Custom (Mapbox Studio)')],
      '#default_value' => $form_state->getValue('style') ?? NlpTurfMap::DEFAULT_STYLE,
    ];
    $form['customStyle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom style'),
      '#description' => $this->t('From Mapbox Studio, as username/styleId.'),
      '#default_value' => $form_state->getValue('customStyle'),
      '#states' => ['visible' => [':input[name="style"]' => ['value' => 'custom']]],
    ];
    $form['minZoom'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum zoom (optional)'),
      '#description' => $this->t('Blank fits the map to the doors. House outlines show from about 16; doors that don\'t fit at this zoom fall off the edge.'),
      '#min' => 10,
      '#max' => 20,
      '#step' => 0.5,
      '#default_value' => $form_state->getValue('minZoom'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Build the turf map'),
    ];

    $form['vanCheck'] = [
      '#type' => 'details',
      '#title' => $this->t('Check VAN for coordinates'),
      '#open' => TRUE,
      '#weight' => 50,
    ];
    $form['vanCheck']['vanid'] = [
      '#type' => 'number',
      '#title' => $this->t('VANID (optional)'),
      '#description' => $this->t('Blank uses the first voter in the turf above.'),
      '#min' => 1,
      '#default_value' => $form_state->getValue('vanid'),
    ];
    $form['vanCheck']['checkVan'] = [
      '#type' => 'submit',
      '#name' => 'checkVan',
      '#value' => $this->t('Show the VAN address'),
      '#submit' => ['::checkVanSubmit'],
    ];
    $vanReport = $form_state->get('vanReport');
    if (!empty($vanReport)) {
      $form['vanCheck']['result'] = $this->vanReportDisplay($vanReport);
    }

    $report = $form_state->get('mapReport');
    if (!empty($report)) {
      $form['report'] = $this->reportDisplay($report);
    }
    return $form;
  }

  /**
   * checkVanSubmit
   *
   * Submit handler for "Show the VAN address".
   */
  public function checkVanSubmit(array &$form, FormStateInterface $form_state): void
  {
    $vanid = (int) $form_state->getValue('vanid');
    $turfIndex = (int) $form_state->getValue('turfIndex');
    if ($vanid === 0 && $turfIndex > 0) {
      $vanid = $this->turfMapObj->firstTurfVanid($turfIndex);
    }
    if ($vanid === 0) {
      $this->messenger()->addError($this->t('Enter a VANID, or a turf index with voters.'));
      $form_state->setRebuild();
      return;
    }
    $vanReport = $this->turfMapObj->fetchVanAddresses($vanid);
    if (!empty($vanReport['error'])) {
      $this->messenger()->addError($vanReport['error']);
    }
    $form_state->set('vanReport', $vanReport);
    $form_state->setRebuild();
  }

  /**
   * vanReportDisplay
   *
   * @param array $vanReport - from NlpTurfMap::fetchVanAddresses().
   * @return array - render array.
   */
  protected function vanReportDisplay(array $vanReport): array
  {
    if (!isset($vanReport['addresses'])) {
      return [];
    }
    $rows = [];
    foreach ($vanReport['addresses'] as $address) {
      $lat = $address['geoLocation']['lat'] ?? NULL;
      $lon = $address['geoLocation']['lon'] ?? NULL;
      if (is_numeric($lat) && is_numeric($lon)) {
        $lat = (float) $lat;
        $lon = (float) $lon;
        $osmUrl = "https://www.openstreetmap.org/?mlat=$lat&mlon=$lon#map=19/$lat/$lon";
        $location = ['data' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => "$lat, $lon",
          '#attributes' => ['href' => $osmUrl, 'target' => '_blank'],
        ]];
      }
      else {
        $location = $this->t('none');
      }
      $rows[] = [
        $address['type'] ?? '',
        $address['addressLine1'] ?? '',
        $address['city'] ?? '',
        $address['zipOrPostalCode'] ?? '',
        $location,
      ];
    }
    return [
      'summary' => [
        '#markup' => '<p>' . $this->t('VANID @vanid: @count address(es).', ['@vanid' => $vanReport['vanid'], '@count' => count($rows)]) . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Type'), $this->t('Address'), $this->t('City'), $this->t('Zip'), $this->t('Coordinates (opens the map)')],
        '#rows' => $rows,
        '#empty' => $this->t('VAN returned no addresses.'),
      ],
      'raw' => [
        '#type' => 'details',
        '#title' => $this->t('Everything VAN returned for the addresses'),
        'json' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => htmlspecialchars(json_encode($vanReport['addresses'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'checkVan') {
      return;
    }
    if ((int) $form_state->getValue('turfIndex') < 1) {
      $form_state->setErrorByName('turfIndex', $this->t('Enter a turf index.'));
    }
    if (trim((string) $form_state->getValue('mapboxToken')) === '' && !$this->turfMapObj->hasMapboxToken()) {
      $form_state->setErrorByName('mapboxToken', $this->t('Enter a Mapbox access token.'));
    }
    $token = trim((string) $form_state->getValue('mapboxToken'));
    if ($token !== '' && !NlpTurfMap::isMapboxTokenFormat($token)) {
      $form_state->setErrorByName('mapboxToken',
        $this->t('That is not a Mapbox token. It should look like pk.eyJ1Ijoi….xxxx, with no spaces or quotes.'));
    }
    $geocodioKey = trim((string) $form_state->getValue('geocodioKey'));
    if ($geocodioKey !== '' && !NlpTurfMap::isGeocodioKeyFormat($geocodioKey)) {
      $form_state->setErrorByName('geocodioKey', $this->t('That is not a Geocodio API key. It should be letters and digits only.'));
    }
    if ($form_state->getValue('geocoder') === 'geocodio' && $geocodioKey === '' && !$this->turfMapObj->hasGeocodioKey()) {
      $form_state->setErrorByName('geocodioKey', $this->t('Enter a Geocodio API key.'));
    }
    if ($form_state->getValue('style') === 'custom'
      && !NlpTurfMap::isStyleFormat(trim((string) $form_state->getValue('customStyle')))) {
      $form_state->setErrorByName('customStyle', $this->t('Enter the custom style as username/styleId.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $token = trim((string) $form_state->getValue('mapboxToken'));
    if ($token !== '' && !$this->turfMapObj->saveMapboxToken($token)) {
      $this->messenger()->addError($this->t('The Mapbox token could not be saved.'));
      return;
    }
    $geocodioKey = trim((string) $form_state->getValue('geocodioKey'));
    if ($geocodioKey !== '' && !$this->turfMapObj->saveGeocodioKey($geocodioKey)) {
      $this->messenger()->addError($this->t('The Geocodio API key could not be saved.'));
      return;
    }
    $geocoder = $form_state->getValue('geocoder');
    $geocoder = isset(NlpTurfMap::GEOCODERS[$geocoder]) ? $geocoder : 'census';
    $style = $form_state->getValue('style');
    if ($style === 'custom') {
      $style = trim((string) $form_state->getValue('customStyle'));
    }
    elseif (!isset(NlpTurfMap::STYLES[$style])) {
      $style = NlpTurfMap::DEFAULT_STYLE;
    }
    $minZoom = $form_state->getValue('minZoom');
    $minZoom = ($minZoom === '' || $minZoom === NULL) ? NULL : (float) $minZoom;
    $report = $this->turfMapObj->buildTurfMap((int) $form_state->getValue('turfIndex'), $style, $minZoom, $geocoder);
    if (!empty($report['error'])) {
      $this->messenger()->addError($report['error']);
    }
    $form_state->set('mapReport', $report);
    $form_state->setRebuild();
  }

  /**
   * reportDisplay
   *
   * @param array $report - from NlpTurfMap::buildTurfMap().
   * @return array - render array.
   */
  protected function reportDisplay(array $report): array
  {
    $display = ['#type' => 'container', '#weight' => 100];
    if (empty($report['turf'])) {
      return $display;
    }
    $turf = $report['turf'];
    $nl = trim(($turf['nlFirstName'] ?? '') . ' ' . ($turf['nlLastName'] ?? ''));
    $lines = [
      $this->t('Turf: @name (@county), NL: @nl', ['@name' => $turf['turfName'] ?? '', '@county' => $turf['county'] ?? '', '@nl' => $nl]),
      $this->t('Voters: @voters, doors: @doors, matched: @matched, not matched: @unmatched', [
        '@voters' => $report['voterCount'],
        '@doors' => count($report['doors']),
        '@matched' => count($report['matched']),
        '@unmatched' => count($report['unmatched']),
      ]),
    ];
    if (isset($report['placedCounts'])) {
      $counts = $report['placedCounts'];
      $lines[] = $this->t('Geocoder: @geocoder. On the building (red): @building, nearest building (purple): @nearest, along the street (orange): @street', [
        '@geocoder' => NlpTurfMap::GEOCODERS[$report['geocoder']] ?? $report['geocoder'],
        '@building' => $counts[NlpTurfMap::PLACED_BUILDING] ?? 0,
        '@nearest' => $counts[NlpTurfMap::PLACED_NEAREST] ?? 0,
        '@street' => $counts[NlpTurfMap::PLACED_STREET] ?? 0,
      ]);
    }
    if (isset($report['outliers'])) {
      $lines[] = $this->t('Far from the rest of the turf, left off the map: @count', ['@count' => count($report['outliers'])]);
    }
    if (isset($report['zoom'])) {
      $lines[] = $this->t('Style: @style, zoom: @zoom', ['@style' => $report['style'], '@zoom' => $report['zoom']]);
    }
    if (!empty($report['censusCheckError'])) {
      $lines[] = $this->t('The Census double-check failed, so Geocodio results were used as they came: @error', ['@error' => $report['censusCheckError']]);
    }
    foreach (['geocoder' => 'Geocoding', 'censusCheck' => 'Census double-check', 'mapbox' => 'Mapbox map', 'pdf' => 'PDF'] as $step => $label) {
      if (isset($report['timings'][$step])) {
        $lines[] = $this->t('@label: @seconds s', ['@label' => $label, '@seconds' => number_format($report['timings'][$step], 2)]);
      }
    }
    $display['summary'] = ['#theme' => 'item_list', '#items' => $lines];

    if (!empty($report['pdfUrl'])) {
      $display['pdf'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        'link' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $this->t('Open the PDF'),
          '#attributes' => ['href' => $report['pdfUrl'], 'target' => '_blank'],
        ],
      ];
    }
    if (!empty($report['mapUrl'])) {
      $display['map'] = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => ['src' => $report['mapUrl'], 'style' => 'max-width:100%;border:1px solid #ccc', 'alt' => 'Turf map'],
      ];
    }

    if (!empty($report['checks'])) {
      $rows = [];
      foreach ($report['checks'] as $door) {
        $rows[] = [$door['street'], $door['city'], $door['zip'], $door['reasons'], $door['outcome']];
      }
      $display['checks'] = [
        '#type' => 'details',
        '#title' => $this->t('Double-checked with Census (@count)', ['@count' => count($rows)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Street'), $this->t('City'), $this->t('Zip'), $this->t('Why'), $this->t('Result')],
          '#rows' => $rows,
        ],
      ];
    }
    if (!empty($report['outliers'])) {
      $rows = [];
      foreach ($report['outliers'] as $door) {
        $rows[] = [$door['street'], $door['matchedAddress'], $door['matchType'], NlpTurfMap::distanceText($door['meters']), $door['lat'], $door['lon']];
      }
      $display['outliers'] = [
        '#type' => 'details',
        '#title' => $this->t('Far from the rest of the turf, left off the map (@count)', ['@count' => count($rows)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Street'), $this->t('Matched as'), $this->t('Accuracy'), $this->t('From the middle of the turf'), $this->t('Lat'), $this->t('Lon')],
          '#rows' => $rows,
        ],
      ];
    }
    if (!empty($report['unmatched'])) {
      $rows = [];
      foreach ($report['unmatched'] as $door) {
        $rows[] = [$door['street'], $door['city'], $door['zip'], $door['voters'], $door['status']];
      }
      $display['unmatched'] = [
        '#type' => 'details',
        '#title' => $this->t('Addresses Census did not match (@count)', ['@count' => count($rows)]),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Street'), $this->t('City'), $this->t('Zip'), $this->t('Voters'), $this->t('Status')],
          '#rows' => $rows,
        ],
      ];
    }
    if (!empty($report['matched'])) {
      $rows = [];
      foreach ($report['matched'] as $door) {
        $rows[] = [$door['street'], $door['source'] ?? 'Census', $door['matchedAddress'], $door['matchType'], $door['lat'], $door['lon']];
      }
      $display['matched'] = [
        '#type' => 'details',
        '#title' => $this->t('Matched addresses (@count)', ['@count' => count($rows)]),
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Street'), $this->t('Source'), $this->t('Matched as'), $this->t('Accuracy'), $this->t('Lat'), $this->t('Lon')],
          '#rows' => $rows,
        ],
      ];
    }
    return $display;
  }

}
