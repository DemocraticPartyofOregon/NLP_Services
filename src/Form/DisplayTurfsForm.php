<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpTurfDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\ApiMapRegions;

/**
 * @noinspection PhpUnused
 */
class DisplayTurfsForm extends FormBase
{

  protected NlpTurfs $turfsObj;
  protected NlpAwards $awardsObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpTurfDisplay $turfDisplayObj;
  protected NlpSessionData $sessionDataObj;
  protected ApiFolders $foldersObj;
  protected ApiMapRegions $apiMapRegionObj;

  public function __construct( $turfsObj, $awardsObj, $searchBarObj, $turfDisplayObj,
                               $sessionDataObj, $foldersObj, $apiMapRegionObj, $requestStack ) {
    $this->turfsObj = $turfsObj;
    $this->awardsObj = $awardsObj;
    $this->searchBarObj = $searchBarObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->foldersObj = $foldersObj;
    $this->apiMapRegionObj = $apiMapRegionObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DisplayTurfsForm
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.turf_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.api_folders'),
      $container->get('nlpservices.api_map_regions'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_display_turfs_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';

    if (empty($form_state->get('reenter'))) {
      $county = $this->sessionDataObj->getCounty();
      if(empty($county)) {
        $this->messenger()->addWarning($this->t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $form_state->set('county',$county);
      $hdOptions = $this->turfsObj->getTurfHD($county); // HDs with turfs.
      $turfCount = $this->turfsObj->getCountyTurfCount($county);
      if($turfCount<$this->turfsObj::TURF_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->sessionDataObj->setNewDistricts($hdOptions);
    }

    $county = $form_state->get('county');
    $this->sessionDataObj->getSavedHdIndex();
    $currentHd = $this->sessionDataObj->getSavedHd();

    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $turfArray = $this->turfsObj->getTurfs($county,'All');
      $form_state->set('search-needle', '');
      $turfArray = $this->searchBarObj->searchInfoArray($needle,$turfArray);
    } else {
      $turfArray = (!empty($currentHd))?$this->turfsObj->getTurfs($county,$currentHd):[];
    }

    // Update the latest minivan information.
    $this->apiMapRegionObj->updateMinivanInfo($county, $turfArray);

    // Create the table data for building the list of turfs.
    $turfTableArray = $this->turfDisplayObj->buildTurfTableArray($turfArray,'display');

    // If there is a sort request, sort the turf array.
    $query = $this->requestStack->getCurrentRequest()->query;
    $sort = $query->get('sort', '');
    if(!empty($sort)) {
      $this->turfDisplayObj->sortTurfTable($turfTableArray,$sort,$query->get('order', ''));
    }

    // Always show the HD selector when there are HDs with turfs, even if the
    // selected HD shows nothing, so the user can pick a different HD.
    $hdOptions = $this->sessionDataObj->getHdOptions();
    if(!empty($hdOptions)) {
      $form['hd-change'] = [
        '#prefix' => '<div id="selectbox-div">',
        '#suffix' => '</div>',
        '#type' => 'fieldset',
      ];
      $currentHdIndex = $this->sessionDataObj->getSavedHdIndex();
      $form['hd-change']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
    }

    if(empty($turfTableArray)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>There is no turf to display.</p>',
      ];
      return $form;
    }

    $form['hd-change']['turf-table'] = $this->turfDisplayObj->displayTurfs($turfTableArray,'display');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();  // form_state will persist

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {

      // The user changed the HD to display.
      case 'hd_submit':
        $newHdIndex = $form_state->getValue('hd-select');
        $this->sessionDataObj->saveHdIndex($newHdIndex);
        break;

      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;

    }

  }

}
