<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\nlpservices\DrupalUser;

/**
 * @noinspection PhpUnused
 */
class TurfDeliveryMessageForm extends FormBase
{
  // Select the key for the statewide default message. It is not a county name,
  // so it can't collide with one of the county options.
  const string STATE_OPTION = '__state__';

  protected NlpConfiguration $nlpConfiguration;
  protected NlpSessionData $sessionData;
  protected NlpTurfDeliveryMessage $turfMsg;
  protected DrupalUser $drupalUser;

  public function __construct($nlpConfiguration, $sessionData, $turfMsg, $drupalUser) {
    $this->nlpConfiguration = $nlpConfiguration;
    $this->sessionData = $sessionData;
    $this->turfMsg = $turfMsg;
    $this->drupalUser = $drupalUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): TurfDeliveryMessageForm
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.turf_delivery_message'),
      $container->get('nlpservices.drupal_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_turf_delivery_message_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (empty($form_state->get('reenter'))) {
      if($this->drupalUser->isNlpAdminUser()) {
        $form_state->set('page', 'county_select');
        $form_state->set('admin', TRUE);
      } else {
        $county = $this->sessionData->getCounty();
        $form_state->set('page', 'edit_county');
        $form_state->set('admin', FALSE);
        $form_state->set('county_selected', $county);
        $countyMsg = $this->turfMsg->getTurfMsg($county);
        $form_state->set('countyMsg', $countyMsg);
      }
    }

    $page = $form_state->get('page');
    switch ($page) {

      case 'county_select':
        $form['county_select'] = array(
          '#type' => 'select',
          '#title' => t('State/County'),
          '#options' => $this->countyOptions(),
          '#description' => t('Select either the state or a specify county.'),
        );
        $form['county_select_submit'] = array(
          '#type' => 'submit',
          '#name' => 'county-submit',
          '#value' => t('Create the email message. >>'),
        );
        break;

      case 'edit_state':
        $state = $this->nlpConfiguration->getStateName();
        $form['edit-box'] = [
          '#type' => 'fieldset',
          '#prefix' => " \n" . '<div class="edit-text">' . " \n",
          '#suffix' => " \n" . '</div>' . " \n",
        ];
        $form['edit-box']['state-name'] = [
          '#markup' => "<h1>Default email message for ".$state."</h1>",
        ];
        $form['edit-box']['help'] = $this->editHelp();
        $form['edit-box']['body'] = [
          '#type' => 'text_format',
          '#title' => $state.' Email Template',
          '#default_value' => $form_state->get('stateMsg'),
          '#format' => 'full_html',
          '#rows' => 25,
          '#description' => t('Template for the state email for turf delivery.'),
        ];
        $form['edit-box']['state_edit'] = [
          '#type' => 'submit',
          '#name' => 'state-edit',
          '#value' => t('Create or update the state email message. >>'),
        ];
        break;

      case 'edit_county':
        $county = $form_state->get('county_selected');
        $form['edit-box'] = [
          '#type' => 'fieldset',
          '#prefix' => " \n" . '<div class="edit-text">' . " \n",
          '#suffix' => " \n" . '</div>' . " \n",
        ];
        $form['edit-box']['county-name'] = [
          '#markup' => "<h1>Edit the turf-delivery message for ".$county." County.</h1>",
        ];
        $form['edit-box']['help'] = $this->editHelp();
        $countySelected = $form_state->get('county_selected');
        $form['edit-box']['body'] = [
          '#type' => 'text_format',
          '#title' => $countySelected.' County Email Template',
          '#default_value' => $form_state->get('countyMsg'),
          '#format' => 'full_html',
          '#rows' => 25,
          '#description' => t('Template for the county email for turf delivery.'),
        ];
        $form['edit-box']['county_edit'] = [
          '#type' => 'submit',
          '#name' => 'county-edit',
          '#value' => t('Create or update the '.$countySelected.' County email message. >>'),
        ];
        break;
    }
    $form['#attributes']['class'][] = 'nlp-turf-delivery-message';
    $form['#attached']['library'][] = 'nlpservices/turf-delivery-message';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();
    $messenger = Drupal::messenger();

    $page = $form_state->get('page');
    switch ($page) {

      case 'county_select':
        $countySelected = $form_state->getValue('county_select');

        if($countySelected == self::STATE_OPTION) {
          $stateMsg = $this->turfMsg->getTurfMsg();

          $form_state->set('stateMsg', $stateMsg);
          $form_state->set('page', 'edit_state');
        } else {
          $countyMsg = $this->turfMsg->getTurfMsg($countySelected);
          $form_state->set('county_selected', $countySelected);
          $form_state->set('countyMsg', $countyMsg);
          $form_state->set('page', 'edit_county');
        }
        break;

      case 'edit_state':
        $stateMsg = $form_state->getValue('body')['value'];
        if(!str_contains($stateMsg,'@coordinatorContactInfo')) {
          $messenger->addError('The @coordinatorContactInfo tag is missing.  This tag must be somewhere
          in the body of the email message. ');
          return;
        }
        $this->turfMsg->putTurfMsg('',$stateMsg);
        $form_state->set('page', 'county_select');
        break;

      case 'edit_county':
        $countySelected = $form_state->get('county_selected');
        $countyMsg = $form_state->getValue('body')['value'];
        if(!str_contains($countyMsg,'@coordinatorContactInfo')) {
          $messenger->addError('The @coordinatorContactInfo tag is missing.  This tag must be somewhere
          in the body of the email message. ');
          return;
        }
        $this->turfMsg->putTurfMsg($countySelected,$countyMsg);
        if($form_state->get('admin')) {
          $form_state->set('page', 'county_select');
        } else {
          $form_state->set('page', 'edit_county');
        }
        break;
    }
  }

  /**
   * countyOptions
   *
   * The statewide default message is followed by each county, keyed by name.
   * The configured county names no longer include a 'State' entry (the State
   * Committee key is stored separately), so the state option is added here.
   *
   * @return array
   */
  protected function countyOptions(): array
  {
    $stateName = $this->nlpConfiguration->getStateName() ?: 'State';
    $options = [self::STATE_OPTION => $stateName . ' (default for all counties)'];
    foreach ($this->nlpConfiguration->getCountyNames() as $countyName) {
      $options[$countyName] = $countyName;
    }
    return $options;
  }

  /**
   * editHelp
   *
   * Display the help for the edit form.
   *
   * @return array
   */
  function editHelp(): array
  {
    $form['county-help'] = [
      '#markup' => '<p>
The message to the NL can be any text you prefer.  In addition, you can personalize
the message by using tokens.  These tokens are changed to actual values before the email is sent.   The following
 is available:</p>',
      '#prefix' => '<div class="turf-delivery-message-help" id="turf-delivery-message-help">',
    ];
    $form['county-tokens'] = [
      '#markup' => "<p>
<b>@nickname:</b> \t\tNL's nickname, or first name if the nickname is not known.<br>
<b>@county:</b> The name of the NL's county.<br>
<b>@electionDay:</b> Date of the election.<br>
<b>@ballotDropDay:</b> First date the county elections can send out ballots.<br>
<b>@server_url:</b> The web address of NLP Services.<br>
<b>@name:</b> Username for the NL to access the turf.<br>
<b>@pass:</b> NL's password<br>
<b>@firstName:</b> First name of the coordinator.<br>
<b>@lastName:</b> Coordinator's last name.<br>
<b>@phone:</b> Coordinator's phone number.<br>
<b>@email:</b> Email address for the coordinator.<br>
<b>@plainNote:</b> Text added when the turf delivery email is sent.
</p>",
      '#prefix' => '<div class="turf-delivery-message-tokens" id="turf-delivery-message-tokens">',
      '#suffix' => '</div>',
    ];

    $form['composite-token-1'] = [
      '#markup' => '<p>
<b>@coordinatorContactInfo:</b> Composite of the Coordinator\'s contact information.<br>
<div class="turf-delivery-message-token-example" id="turf-delivery-message-token-example">
@firstName @lastName<br>
Phone: @phone<br>
Email: @email <i>(Note: the email is a hyperlink)</i><br> <br>
</div>

<b>@nlpLogin:</b> Composite of the login information for the NL.<br><br>
<div class="turf-delivery-message-token-example" id="turf-delivery-message-token-example">
Neighborhood Leader Login: @server_url <i>(Note: the server url is converted to a hyperlink)</i><br>
Username: @name<br>
Password: @pass
</div>
</p>',
      '#prefix' => '<div class="turf-delivery-message-tokens">',
      '#suffix' => '</div></div>',
    ];

    return $form;
  }

}
