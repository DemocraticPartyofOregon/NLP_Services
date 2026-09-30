<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;

use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpPaths;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCredentialsEmail;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\nlpservices\NlpTurfDisplay;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\ApiMapRegions;

/**
 * @noinspection PhpUnused
 */
class DeliverTurfForm extends FormBase
{
  protected NlpTurfs $turfsObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $fileSystemObj;
  protected NlpInstructions $instructionsObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpCredentialsEmail $credentialsEmailObj;
  protected NlpTurfDeliveryMessage $turfMsgObj;
  protected NlpTurfDisplay $turfDisplayObj;

  protected NlpSearchBar $searchBarObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected  NlpNls $nlsObj;
  protected ApiMapRegions $foldersObj;
  public function __construct( $turfsObj, $pathsObj, $fileSystemObj,
                              $instructionsObj, $coordinatorsObj, $credentialsEmailObj, $turfMsgObj,
                              $turfDisplayObj, $searchBarObj,
                              $nlpSessionDataObj, $nlsObj, $foldersObj,
                              $requestStack)
  {
    $this->turfsObj = $turfsObj;
    $this->pathsObj = $pathsObj;
    $this->fileSystemObj = $fileSystemObj;
    $this->instructionsObj = $instructionsObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->credentialsEmailObj = $credentialsEmailObj;
    $this->turfMsgObj = $turfMsgObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->nlsObj = $nlsObj;
    $this->foldersObj = $foldersObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeliverTurfForm
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.paths'),
      $container->get('file_system'),
      $container->get('nlpservices.instructions'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.credentials_email'),
      $container->get('nlpservices.turf_delivery_message'),
      $container->get('nlpservices.turf_display'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.api_map_regions'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_deliver_turf_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';

    $messenger = Drupal::messenger();

    if (empty($form_state->get('reenter'))) {

      $county = $this->nlpSessionDataObj->getCounty();
      if(empty($county)) {
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $instructions = $this->instructionsObj->getInstructions($county);
      if (empty($instructions['canvass']['fileName']) && empty($instructions['postcard']['fileName'])) {
        $messenger->addError(t('You need to upload the canvass or postcard instructions first.'));
        return [];
      }
      $form_state->set('county', $county);

      $hdOptions = $this->turfsObj->getTurfHD($county); // HDs with turfs.
      $turfCount = $this->turfsObj->getCountyTurfCount($county);
      if($turfCount<$this->turfsObj::TURF_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->nlpSessionDataObj->setNewDistricts($hdOptions);
    }

    $county = $form_state->get('county');

    $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
    $currentHd = $this->nlpSessionDataObj->getSavedHd();
    $hdOptions = $this->nlpSessionDataObj->getHdOptions();

    if (empty($hdOptions)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>No turf to display</p>',
      ];
      return $form;
    }

    $needle = $form_state->get('search-needle');
    if (!empty($needle)) {
      $turfArray = $this->turfsObj->getTurfs($county, 'All');
      $form_state->set('search-needle', NULL);
      $turfArray = $this->searchBarObj->searchInfoArray($needle, $turfArray);
    } else {
      $turfArray = (!empty($currentHd)) ? $this->turfsObj->getTurfs($county, $currentHd) : [];
    }
    $form_state->set('turfArray', $turfArray);

    $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);

    if (empty($turfArray)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>There is no turf to display.</p>',
      ];
      return $form;
    }

    // Create the AJAX wrapper for updating the form changes.
    $form['turf-form'] = [
      '#prefix' => '<div id="selectbox-div">',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
      '#attributes' => [
        'style' => ['background-image:none;border:0px;padding:0px;margin:0px;background-color:rgb(255,255,255);'],
      ],
    ];
    $turfTableArray = $this->turfDisplayObj->buildTurfTableArray($turfArray, 'walksheet');
    $request = $this->requestStack->getCurrentRequest();
    $sort = $request->query->get('sort', '');
    if (!empty($sort)) {
      $this->turfDisplayObj->sortTurfTable($turfTableArray, $sort, $request->query->get('order', ''));
    }
    $form['turf-form']['turf_table'] = $this->turfDisplayObj->displayTurfs($turfTableArray, 'walksheet');

    $form['footnote'] = [
      '#markup' => '<p>&#134;&#134;  This turf does not have a printable walksheet associated.  If the NL is
                    canvassing, consider doing that now.  The walksheet will be associated with the
                    selected turf.  If the NL has more than one turf, you have to send an email for each
                    turf that needs a walksheet.  It\'s better if you attached the walk sheet when you
                    synced the turf.'
    ];
    // Name of the walksheet PDF for the turf.
    $form['turf_pdf'] = [
      '#type' => 'file',
      '#title' => t('NL turf walksheet'),
      '#description' => 'Associate this PDF, XLSX or XLS file with the turf or replace an existing file.',
      '#size' => 75,
    ];
    // Allow the sender to add a paragraph to the email.
    $form['note'] = [
      '#title' => 'Additional note for the NL',
      '#type' => 'textarea',
      '#description' => 'This will add an additional paragraph to the email to be sent to the NL.',
      '#prefix' => '<p> </p>',
      '#suffix' => '<p> </p>',
    ];
    // Add a button to send the email.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Send an email to the selected NL.',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter',TRUE);
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    switch ($elementClicked) {
      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;

      case 'hd-select':
        $currentHdIndex = $form_state->getValue('hd-select');
        // The user changed the HD to display.
        $this->nlpSessionDataObj->saveHdIndex($currentHdIndex);
        return;
    }

    $values = $form_state->getValues();
    $selections = $this->turfDisplayObj->validateTurfSelection($values['contacts']);
    $form_state->set('selections', $selections);

    if (count($selections) == 0) {
      $form_state->setErrorByName('submit',
        'You must select at least one NL to send the email to.');
      return;
    }

    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $uploadedFile = $files['turf_pdf'] ?? NULL;
    if (!empty($uploadedFile)) {
      $pdfFileName = strtolower($uploadedFile->getClientOriginalName());
      $pdfFileNameParts = explode('.', $pdfFileName);
      $pdfFileNameType = end($pdfFileNameParts);
      $allowed = ['pdf', 'xls', 'xlsx'];
      if (!in_array($pdfFileNameType, $allowed)) {
        $form_state->setErrorByName('turf_pdf',
          'The walksheet must be a PDF, XLS, or XLSX type.');
      }
      if(count($selections) > 1) {
        $form_state->setErrorByName('turf_pdf',
          'You can only attach a walksheet to one NL at a time.');
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter',TRUE);
    $form_state->setRebuild();

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {

      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;

      default:
        $input = $form_state->getUserInput();
        $selections = $form_state->get('selections');

        $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
        $uploadedFile = $files['turf_pdf'] ?? NULL;

        foreach ($selections as $turfIndex => $selection) {

          $county = $form_state->get('county');

          if (!empty($uploadedFile)) {
            $pdfFileName = strtolower($uploadedFile->getClientOriginalName());
            $pdfFileNameParts = explode('.', $pdfFileName);
            $pdfFileNameType = end($pdfFileNameParts);
            // Save the PDF where we can find it.
            $pdfTempName = $uploadedFile->getRealPath();

            $turf = $this->turfsObj->getTurf($turfIndex);
            $mcid = $turf['mcid'];
            $pdfName = $pdfFileNameParts[0];
            $pdfUniqueFileName = "MCID".$mcid."_".$pdfName."_TI".$turfIndex.".".$pdfFileNameType;
            $uri = $this->pathsObj->getPath('PDF',$county).$pdfUniqueFileName;
            $this->fileSystemObj->moveUploadedFile($pdfTempName, $uri);
            $this->turfsObj->updateTurf($turfIndex, 'turfPDF', $pdfUniqueFileName);
          }

          // Get the optional note from the sender to the NL.
          $note = $form_state->getValue('note');
          $string = new FormattableMarkup($note,[]);
          $note = Html::escape($string);
          $turf = $this->updateMinivanInfo($turfIndex);
          $this->notifyUserCredentials($turf,$note);
          }
        $form_state->setUserInput($input);
        break;
    }

  }

  /**
   * notifyUserCredentials
   *
   * Notify the user of the assigned and provide the credentials to log into
   * NLP Services to access the turf and other information.
   *
   * The turfChoice array had the following keys:
   * - turfIndex - the index of the turf in the turf table.
   * - turfHd - the HD of the turf.
   * - turfPrecinct - the precinct of the turf.
   * - nlFirstName - the first name of the neighborhood leader.
   * - nlLastName - the last name of the neighborhood leader.
   * - mcid - the MCID of the neighborhood leader.
   *
   * @param array $turfChoice - array with information about the selected turf.
   * @param string $note
   * @return bool
   */
  public function notifyUserCredentials(array $turfChoice,string $note): bool
  {
    $messenger = Drupal::messenger();
    $county = $this->nlpSessionDataObj->getCounty();
    $turfPrecinct = ltrim($turfChoice['turfPrecinct'],"0");
    $region = [
      'hd'=>$turfChoice['turfHd'],
      'pct'=>$turfPrecinct,
      'county'=>$county,
    ];

    $nlCoordinator = $this->coordinatorsObj->getCoordinator($region);
    if (empty($nlCoordinator)) {
      $messenger->addError(t('A coordinator for the county must be assigned before you can send a
                      notification of a turf to @first @last', [
        '@first' => $turfChoice['nlFirstName'],
        '@last'  => $turfChoice['nlLastName'],
      ]));
      return FALSE;
    }

    $turfMsg = $this->turfMsgObj->getTurfMsg($county);

    $miniVanArray = [];
    if (!empty($turfChoice['minivanNumber'])) {
      $miniVanArray = [
        'minivanNumber' => $turfChoice['minivanNumber'],
        'minivanCreateDate' => $turfChoice['minivanCreateDate'],
      ];
    }

    $mcid = $turfChoice['mcid'];
    $result = $this->credentialsEmailObj->sendCredentialsEmail($mcid, $turfMsg, $note, $miniVanArray, $nlCoordinator, 'deliver_turf');
    if (!$result) {
      $messenger->addMessage(t('There was a problem sending your message and it was not sent.'),
        $messenger::TYPE_ERROR,TRUE);
      return FALSE;
    }

    // Update the NLs status to indicate the turf was delivered.
    $this->nlsObj->updateNlStatus($mcid,['turfDelivered' => 'Y']);
    // Now update date the turf was delivered.
    $this->turfsObj->setTurfDelivered($turfChoice['turfIndex']);
    // Done.
    $messenger->addMessage(t('Your message has been sent to @first @last', [
      '@first' => $turfChoice['nlFirstName'],
      '@last'  => $turfChoice['nlLastName'],
    ]), $messenger::TYPE_STATUS, TRUE);

    return TRUE;
  }

  /**
   * updateMinivanInfo
   *
   * Update the minivan information for the turf.  The latest minivan information
   * is retrieved from VoteBuilder, and the information is saved in the turf
   * table.  The NL will see it when they next access the turf.
   *
   * @param int $turfIndex - the index of the turf to update.
   * @return array - the updated turf information.
   */
  public function updateMinivanInfo(int $turfIndex): array
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    $county = $this->nlpSessionDataObj->getCounty();
    $turfArray[$turf['turfIndex']] = $turf;
    $this->foldersObj->updateMinivanInfo($county, $turfArray);
    $turf = $turfArray[$turfIndex];
    if(!empty($turf['minivanNumber'])) {
      $minivanNumber = $turf['minivanNumber'];
      $minivanCreateDate = $turf['minivanCreateDate'];
      $createDate = date('Y-m-d',strtotime($minivanCreateDate));
      $fields = [
        'minivanNumber' => $minivanNumber,
        'minivanCreateDate' => $createDate,
      ];
      $this->turfsObj->updateTurfFields($turfIndex, $fields);
    }
    return $turf;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * selectboxCallback
   *
   * @param $form
   * @param $unused
   * @return array
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  public function selectboxCallback($form, $unused): array
  {
    return $form['turf-form'];
  }

}
