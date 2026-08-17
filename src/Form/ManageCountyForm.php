<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\TempStoreException;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ManageCountyForm extends FormBase {
  protected PrivateTempStoreFactory $privateTempstoreObj;
  protected DrupalUser $drupalUserObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($privateTempstoreObj, $drupalUserObj, $sessionDataObj, $nlpConfigurationObj) {
    $this->privateTempstoreObj = $privateTempstoreObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ManageCountyForm|static
  {
    return new static(
      $container->get('tempstore.private'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'manage_county_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $countyNames = $this->nlpConfigurationObj->getCountyNames();
    $county = $this->sessionDataObj->getCounty();
    if(empty($county)) {
      $this->messenger()->addWarning($this->t('The county is not specified. Please set the County field in your user login record.'));
    }
    $form_state->set('county',$county);

    // A user with the administrator role can change the committee.
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $userRoles = $currentUser['roles'];
    $isAdmin = in_array('administrator',$userRoles);

    $form['known_county'] = [
      '#prefix' => '<div id="county-wrapper">',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
    ];


    $form['known_county']['county-name'] = [
      '#markup' => '<h1>' . htmlspecialchars($county, ENT_QUOTES, 'UTF-8') . ' County</h1>',
    ];

    if($isAdmin) {

      $form['known_county']['count_change_note'] = [
        '#markup' => $this->t('As a site administrator, you can change the county to be managed.'),
      ];
      array_unshift($countyNames, "Select County");
      $form_state->set('countyNames',$countyNames);
      $form['known_county']['county_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Change Committee'),
        '#description' => $this->t('Please select a committee that you want
        to access.  This change persists until you logout or change it again.'),
        '#options' => $countyNames,
        '#ajax' => [
          'callback' => '::countySelectedCallback',
          'wrapper' => 'county-wrapper',
        ],
      ];
    }

    $output = "<h1>Set up the site for your county</h1>";

    $output .= '
        <ul>
          <li><a href="/nlpservices-upload_instructions">Upload the PDF of the instructions for NLs.</a></li>
          <li><a href="/nlpservices-coordinators">Identify the coordinators who provide help to NLs.</a></li>
          <li><a href="/nlpservices-remove-coordinator-role">Remove a coordinator role.</a></li>
          <li><a href="/nlpservices-turf-delivery-message">Edit the body of the email to deliver a turf to an NL.</a></li>
          <li><a href="/nlpservices-document-list">Download the manuals for managing NLP.</a></li>'
        ;
    $stateName = $this->nlpConfigurationObj->getStateName();
    if(!empty($stateName) && $stateName == 'Mississippi') {
      $output .= '<li><a href="/nlpservices-import-county-early-voting">Upload early voting PDF.</a></li>';
    }

    $output .= '</ul>';

    $output .= "<h1>Correct move status</h1>";

    $output .= "<p>An NL can mark a voter as moved, and this status is reported to VoteBuilder.  Unfortunately, there is
    no API function to correct an erroneous report of moved.   The NL has to ask for help.  First, use the function
    here to remove the status from NLP Services.   Then use VoteBuilder to restore the address in the VoterFile.</p>";

    $output .= '
      <ul>
          <li><a href="/nlp-cancel-moved-report">Cancel an incorrect report that a voter moved.</a></li>
      </ul>';

    $form['known_county']['links'] = [
      '#markup' => $output,
    ];

    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();

    $selectedCountyIndex = $values['county_select'];
    $countyNames = $form_state->get('countyNames');
    $county = $countyNames[$selectedCountyIndex];

    $store = $this->privateTempstoreObj->get('nlpservices.session_data');

    try {
      $store->set('County', $county);
      $store->set('currentFolderId', 0);
      $store->set('currentHd', 0);
      $store->set('currentPct', '');
    } catch (TempStoreException $e) {
      nlp_debug_msg('Temp store save error', $e->getMessage());
      $form_state->setErrorByName('county_select', $this->t('Temp store save error'));
    }

  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {

  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * countySelectedCallback
   *
   * AJAX call back for the selection of the HD
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  public function countySelectedCallback($form, $unused): array
  {
    return $form['known_county'];
  }

}
