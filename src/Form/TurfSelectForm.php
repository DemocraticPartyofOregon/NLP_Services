<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\DrupalUser;

/**
 * @noinspection PhpUnused
 */
class TurfSelectForm extends FormBase
{
  protected PrivateTempStoreFactory $userSession;
  protected NlpTurfs $turfObj;
  protected NlpSessionData $sessionDataObj;
  protected DrupalUser $drupalUserObj;
  public function __construct($userSession,$turfObj,$sessionData,$drupalUserObj)
  {
    $this->userSession = $userSession;
    $this->turfObj = $turfObj;
    $this->sessionDataObj = $sessionData;
    $this->drupalUserObj = $drupalUserObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): TurfSelectForm
  {
    return new static(
      $container->get('tempstore.private'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.drupal_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_turf_select_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $messenger = Drupal::messenger();
    $county = $this->sessionDataObj->getCounty();
    $form_state->set('county',$county);
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    if(empty($mcid)) {
      $messenger->addError("You do not have an MCID assigned in your login record.");
      return $form;
    }
    // Check if this NL has one or more turfs.
    $turfArray = $this->turfObj->turfExists($mcid,$county);
    $form_state->set('turfArray', $turfArray);
    if (empty($turfArray)) {
      $messenger->addError("You do not have a turf assigned.");
      return $form;
      
    }

    // If more than one turf, ask the NL which one is wanted.
    $turfCnt = $turfArray['turfCnt'];
    if($turfCnt == 1) {
      $messenger->addStatus("You have only one turf assigned.");
      return $form;
    }
    
    $turfIndex = $this->sessionDataObj->get('turfIndex');
    $turfNames = $this->turfObj->createTurfNames($turfArray);
    $form_state->set('$turfNames',$turfNames);
    reset($turfNames);
    $firstTurfIndex = key($turfNames);
    $defaultTurfIndex = (empty($turfIndex) || empty($turfNames[$turfIndex]))?$firstTurfIndex:$turfIndex;
    $turfName = $turfNames[$defaultTurfIndex];
    $form['current-turf-name'] = [
      '#markup' => "You are currently using turf named: <b>$turfName</b>",
    ];
    
    // Give the list of turfs and let the use select one of these turfs.
    $form['turf-select'] = [
      '#type' => 'radios',
      '#multiple' => FALSE,
      '#title' => t('Choose a turf from the list'),
      '#options' => $turfNames,
      '#default_value' => $defaultTurfIndex,
      '#required' => TRUE,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Select a turf for voter contact reports'
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $turfIndex = $form_state->getValue('turf-select');
    $this->sessionDataObj->set('turfIndex',$turfIndex);
    $messenger = Drupal::messenger();
    $turfNames = $form_state->get('$turfNames');
    $selectedTurfName = $turfNames[$turfIndex];
    $messenger->addStatus("You have successfully selected turf: ".$selectedTurfName);
    $messenger->addStatus(t("Be sure to click the <b>My turf</b> tab to view your turf selection."));
  }

}
