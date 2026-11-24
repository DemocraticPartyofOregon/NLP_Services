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
  public function __construct(PrivateTempStoreFactory $userSession, NlpTurfs $turfObj,
                              NlpSessionData $sessionData, DrupalUser $drupalUserObj)
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
    $committee = $this->sessionDataObj->getCommittee();
    $form_state->set('county',$committee);
    $currentUser = $this->drupalUserObj->getCurrentUser();
    $mcid = $currentUser['mcid'];
    if(empty($mcid)) {
      $messenger->addError("You do not have an MCID assigned in your login record.");
      return $form;
    }
    // Check if this NL has one or more turfs.
    $turfArray = $this->turfObj->turfExists($mcid,$committee);
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
   *
   * Saves the selected turf and goes straight back to the data entry page,
   * which then shows that turf.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $turfIndex = $form_state->getValue('turf-select');
    $this->sessionDataObj->set('turfIndex',$turfIndex);
    $turfNames = $form_state->get('$turfNames');
    $selectedTurfName = $turfNames[$turfIndex];
    Drupal::messenger()->addStatus($this->t('You are now reporting results for turf: @name',
      ['@name' => $selectedTurfName]));
    $form_state->setRedirect('nlpservices.data_entry_form');
  }

}
