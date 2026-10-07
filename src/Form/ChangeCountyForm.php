<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an NLP admin change the county they are managing.  The change persists
 * until they log out or change it again.
 *
 * @noinspection PhpUnused
 */
class ChangeCountyForm extends FormBase {
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($sessionDataObj, $nlpConfigurationObj) {
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ChangeCountyForm|static
  {
    return new static(
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'change_county_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $county = $this->sessionDataObj->getCounty();
    $countyNames = $this->nlpConfigurationObj->getCountyNames();

    $form['current_county'] = [
      '#markup' => '<h1>' . (empty($county)
        ? $this->t('No county is selected.')
        : $this->t('Currently managing @county County', ['@county' => $county])) . '</h1>',
    ];

    $form['county_select'] = [
      '#type' => 'select',
      '#title' => $this->t('Change County'),
      '#options' => array_combine($countyNames, $countyNames),
      '#empty_option' => $this->t('Select County'),
      '#default_value' => in_array($county, $countyNames, TRUE) ? $county : '',
      '#required' => TRUE,
      '#attributes' => ['aria-describedby' => 'county-select-note'],
    ];

    // A plain paragraph rather than '#description', which the theme indents.
    $form['county_select_note'] = [
      '#markup' => '<p id="county-select-note">' . $this->t('Please select a county that you want to access.  This change persists until you logout or change it again.') . '</p>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change county'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $county = $form_state->getValue('county_select');

    $this->sessionDataObj->set('County', $county);
    $this->sessionDataObj->set('currentFolderId', 0);
    $this->sessionDataObj->set('currentHd', 0);
    $this->sessionDataObj->set('currentPct', '');

    $this->messenger()->addStatus($this->t('You are now managing @county County.', ['@county' => $county]));
    $form_state->setRedirect('nlpservices.manage_county_menu');
  }

}
