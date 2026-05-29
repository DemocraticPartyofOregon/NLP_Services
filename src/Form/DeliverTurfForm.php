<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Utility\Html;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpPaths;
use Drupal\nlpservices\NlpMcidRecovery;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpTurfDeliveryMessage;
use Drupal\nlpservices\NlpTurfDisplay;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpSendEmail;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\ApiMapRegions;

/**
 * @noinspection PhpUnused
 */
class DeliverTurfForm extends FormBase
{
  protected NlpTurfs $turfsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $fileSystemObj;
  protected NlpInstructions $instructionsObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpMcidRecovery $mcidRecoveryObj;
  protected NlpTurfDeliveryMessage $turfMsgObj;
  protected NlpTurfDisplay $turfDisplayObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpSendEmail $sendMailObj;
  protected NlpNls $nlsObj;
  protected ApiFolders $foldersObj;
  protected ApiMapRegions $apiMapRegionsObj;

  public function __construct(
    $turfsObj,
    $drupalUserObj,
    $pathsObj,
    $fileSystemObj,
    $instructionsObj,
    $coordinatorsObj,
    $mcidRecoveryObj,
    $turfMsgObj,
    $turfDisplayObj,
    $searchBarObj,
    $nlpConfigurationObj,
    $nlpSessionDataObj,
    $sendMailObj,
    $nlsObj,
    $foldersObj,
    $apiMapRegionsObj,
  ) {
    $this->turfsObj = $turfsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->pathsObj = $pathsObj;
    $this->fileSystemObj = $fileSystemObj;
    $this->instructionsObj = $instructionsObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->mcidRecoveryObj = $mcidRecoveryObj;
    $this->turfMsgObj = $turfMsgObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->searchBarObj = $searchBarObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->sendMailObj = $sendMailObj;
    $this->nlsObj = $nlsObj;
    $this->foldersObj = $foldersObj;
    $this->apiMapRegionsObj = $apiMapRegionsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeliverTurfForm
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.paths'),
      $container->get('file_system'),
      $container->get('nlpservices.instructions'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.mcid_recovery'),
      $container->get('nlpservices.turf_delivery_message'),
      $container->get('nlpservices.turf_display'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.send_email'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.api_folders'),
      $container->get('nlpservices.api_map_regions'),
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
    // Detect a tablesort GET request (sort/order are query params, no form POST).
    $query = $this->requestStack->getCurrentRequest()->query;
    $sort = $query->get('sort', '');
    $isSortRequest = !empty($sort);

    if (empty($form_state->get('reenter'))) {
      $county = $this->nlpSessionDataObj->getCounty();
      if ($isSortRequest) {
        // Sort GET: restore county from session so the form can rebuild in-place.
        if (!empty($county)) {
          $form_state->set('county', $county);
        }
      }
      else {
        if (empty($county)) {
          $this->messenger()->addWarning($this->t('The county is not specified. Use the "Manage County" tab to select a county.'));
          return [];
        }
        $instructions = $this->instructionsObj->getInstructions($county);
        if (empty($instructions['canvass']['fileName']) && empty($instructions['postcard']['fileName'])) {
          $this->messenger()->addError($this->t('You need to upload the canvass or postcard instructions first.'));
          return [];
        }
        $form_state->set('county', $county);

        $hdOptions = $this->turfsObj->getTurfHD($county); // HDs with turfs.
        $turfCount = $this->turfsObj->getCountyTurfCount($county);
        if ($turfCount < $this->turfsObj::TURF_ALL_MAX) {
          array_unshift($hdOptions, 'All');
        }
        $this->nlpSessionDataObj->setNewDistricts($hdOptions);
      }
    }

    $county = $form_state->get('county');
    if (empty($county)) {
      $county = $this->nlpSessionDataObj->getCounty();
      $form_state->set('county', $county);
    }

    $currentHd = $this->nlpSessionDataObj->getSavedHd();
    $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
    $hdOptions = $this->nlpSessionDataObj->getHdOptions();

    if (empty($hdOptions)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>No turf to display</p>',
      ];
      return $form;
    }

    if (!empty($sort)) {
      $order = $query->get('order', '');
      $sortField = $this->turfDisplayObj->getSortField($order);
      $sortOrder = ['sort' => $sort, 'field' => $sortField];
      $turfArray = $this->turfsObj->getTurfs($county, $currentHd, $sortOrder);
    } else {
      $needle = $form_state->get('search-needle');
      if (!empty($needle)) {
        $turfArray = $this->turfsObj->getTurfs($county, 'All');
        $form_state->set('search-needle', NULL);
        $turfArray = $this->searchBarObj->searchInfoArray($needle, $turfArray);
      } else {
        $turfArray = $this->turfsObj->getTurfs($county, $currentHd);
      }
    }

    $form_state->set('turfArray', $turfArray);

    // Create the table data for building the list of turfs.
    $turfTableArray = $this->turfDisplayObj->buildTurfTableArray($turfArray, 'walksheet');

    $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);

    // Create the AJAX wrapper for updating the form changes.
    $form['turf-form'] = [
      '#prefix' => '<div id="selectbox-div">',
      '#suffix' => '</div>',
      '#type' => 'fieldset',
      '#attributes' => [
        'style' => ['background-image:none;border:0px;padding:0px;margin:0px;background-color:rgb(255,255,255);'],
      ],
    ];
    $form['turf-form']['turf_table'] = $this->turfDisplayObj->displayTurfs($turfTableArray, 'walksheet');

    $form['footnote'] = [
      '#markup' => $this->t('<p>&#134;&#134;  This turf does not have a printable walksheet associated.  If the NL is
                    canvassing, consider doing that now.  The walksheet will be associated with the
                    selected turf.  If the NL has more than one turf, you have to send an email for each
                    turf that needs a walksheet.  It\'s better if you attached the walk sheet when you
                    synced the turf.'),
    ];
    // Name of the walksheet PDF for the turf.
    $form['turf_pdf'] = [
      '#type' => 'file',
      '#title' => $this->t('NL turf walksheet'),
      '#description' => $this->t('Associate this PDF, XLSX or XLS file with the turf or replace an existing file.'),
      '#size' => 75,
    ];
    // Allow the sender to add a paragraph to the email.
    $form['note'] = [
      '#title' => $this->t('Additional note for the NL'),
      '#type' => 'textarea',
      '#description' => $this->t('This will add an additional paragraph to the email to be sent to the NL.'),
      '#prefix' => '<p> </p>',
      '#suffix' => '<p> </p>',
    ];

    // Add a button to send the email.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send an email to the selected NL.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];

    switch ($elementClicked) {
      case 'search_submit':
        $needle = $form_state->getValue('nl-search-needle');
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
        $this->t('You must select at least one NL to send the email to.'));
      return;
    }

    $uploadedFiles = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $uploadedFile = $uploadedFiles['turf_pdf'] ?? NULL;
    if ($uploadedFile !== NULL && $uploadedFile->isValid()) {
      $pdfFileName = strtolower($uploadedFile->getClientOriginalName());
      $pdfFileNameParts = explode('.', $pdfFileName);
      $pdfFileNameType = end($pdfFileNameParts);
      $allowed = ['pdf', 'xls', 'xlsx'];
      if (!in_array($pdfFileNameType, $allowed)) {
        $form_state->setErrorByName('turf_pdf',
          $this->t('The walksheet must be a PDF, XLS, or XLSX type.'));
      }
      if (count($selections) > 1) {
        $form_state->setErrorByName('turf_pdf',
          $this->t('You can only attach a walksheet to one NL at a time.'));
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();

    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {

      case 'search_submit':
        $needle = $form_state->getValue('nl-search-needle');
        $form_state->set('search-needle', $needle);
        return;

      default:
        $selections = $form_state->get('selections');
        $county = $form_state->get('county');

        $uploadedFiles = $this->requestStack->getCurrentRequest()->files->get('files', []);
        $uploadedFile = $uploadedFiles['turf_pdf'] ?? NULL;

        foreach ($selections as $turfIndex => $selection) {

          if ($uploadedFile !== NULL && $uploadedFile->isValid()) {
            $pdfFileName = strtolower($uploadedFile->getClientOriginalName());
            $pdfFileNameParts = explode('.', $pdfFileName);
            $pdfFileNameType = end($pdfFileNameParts);
            // Save the PDF where we can find it.
            $pdfTempName = $uploadedFile->getRealPath();

            $turf = $this->turfsObj->getTurf($turfIndex);
            $mcid = $turf['mcid'];
            $pdfName = $pdfFileNameParts[0];
            $pdfUniqueFileName = "MCID".$mcid."_".$pdfName."_TI".$turfIndex.".".$pdfFileNameType;
            $uri = $this->pathsObj->getPath('PDF', $county).$pdfUniqueFileName;
            $this->fileSystemObj->moveUploadedFile($pdfTempName, $uri);
            $this->turfsObj->updateTurf($turfIndex, 'turfPDF', $pdfUniqueFileName);
          }

          // Get the optional note from the sender to the NL.
          $note = Html::escape($form_state->getValue('note'));
          $turf = $this->updateMinivanInfo($turfIndex);
          $this->notifyUserCredentials($turf, $note);
        }
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
   * @return void
   */
  private function notifyUserCredentials(array $turfChoice, string $note): void
  {
    $county = $this->nlpSessionDataObj->getCounty();
    $turfPrecinct = ltrim($turfChoice['turfPrecinct'], "0");
    $region = [
      'hd'     => $turfChoice['turfHd'],
      'pct'    => $turfPrecinct,
      'county' => $county,
    ];

    $nlCoordinator = $this->coordinatorsObj->getCoordinator($region);
    if (empty($nlCoordinator)) {
      $this->messenger()->addError($this->t('A coordinator for the county must be assigned before 
      you can send a notification of a turf to @first @last', [
        '@first' => $turfChoice['nlFirstName'],
        '@last'  => $turfChoice['nlLastName'],
      ]));
      return;
    }

    $serverUrl = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost();
    $turfMsg = $this->turfMsgObj->getTurfMsg($county);

    $coordinatorContactTemplate = '<p>@firstName @lastName<br>Phone: @phone<br>Email:
<a href="mailto:@email?subject=Request for help with turf">@email</a></p>';

    /** @noinspection HtmlUnknownTarget */
    $nlpLoginTemplate = '<p><b>Neighborhood Leader Login: </b><a href="@server_url" target="_blank">@server_url</a>
<br><b>Username:</b> @name<br><a href="@reveal_url">Click here to retrieve your password</a>
 (this link can only be used once)</p>';

    $turfArray = [$turfChoice['turfIndex'] => $turfChoice];
    $this->apiMapRegionsObj->updateMinivanInfo($county, $turfArray);

    if ($turfChoice['minivanNumber'] != $turfArray[$turfChoice['turfIndex']]['minivanNumber']) {
      $minivanNumber = $turfArray[$turfChoice['turfIndex']]['minivanNumber'];
      $minivanCreateDate = $turfArray[$turfChoice['turfIndex']]['minivanCreateDate'];
      $fields = [
        'minivanNumber'     => $minivanNumber,
        'minivanCreateDate' => $minivanCreateDate,
      ];
      $turfIndex = $turfChoice['turfIndex'];
      $this->turfsObj->updateTurfFields($turfIndex, $fields);
    }

    $minivanNumber = 0;
    $minivanCreateDate = '';
    if (!empty($turfChoice['minivanNumber'])) {
      $minivanNumber = $turfChoice['minivanNumber'];
      $minivanCreateDate = $turfChoice['minivanCreateDate'];
      $nlpLoginTemplate .= '<p><b>MiniVan Number: </b>@minivanNumber<br>MiniVan was created @minivanCreateDate</p>';
    }

    $plainNote = NULL;
    if (!empty($note)) {
      $plainNote = '<p>' . $note . '</p>';
    }

    $needles = ['@nlpLogin', '@coordinatorContactInfo', '@plainNote'];
    $replace = [$nlpLoginTemplate, $coordinatorContactTemplate, $plainNote];
    $paragraphsAdded = str_replace($needles, $replace, $turfMsg);

    $electionDate = $this->nlpConfigurationObj->getElectionDate();
    $ballotDropDate = $this->nlpConfigurationObj->getBallotDropDate();
    $electionTimeStamp = strtotime($electionDate);
    $ballotDropTimeStamp = strtotime($ballotDropDate);

    $mcid = $turfChoice['mcid'];
    $key = $this->mcidRecoveryObj->saveRecoveryKey($mcid);
    $revealUrl = $serverUrl . '/nlp-mcid-recovery?key=' . $key;

    $user = $this->drupalUserObj->getUserByMcid($mcid);
    if (empty($user)) {
      $this->logger('nlpservices')->notice('Unknown user: @mcid', ['@mcid' => $mcid]);
      $userName = 'unknown';
    } else {
      $userName = $user['userName'];
    }

    $nl = $this->nlsObj->getNlById($mcid);
    $to = $nl['email'];

    $message = $this->t($paragraphsAdded, [
      '@nickname'         => $turfChoice['nlFirstName'],
      '@county'           => $county,
      '@electionDay'      => date("F j, Y", $electionTimeStamp),
      '@ballotDropDay'    => date("F j, Y", $ballotDropTimeStamp),
      '@server_url'       => $serverUrl,
      '@name'             => $userName,
      '@reveal_url'       => $revealUrl,
      '@firstName'        => $nlCoordinator['firstName'],
      '@lastName'         => $nlCoordinator['lastName'],
      '@phone'            => $nlCoordinator['phone'],
      '@email'            => $nlCoordinator['email'],
      '@minivanNumber'    => $minivanNumber,
      '@minivanCreateDate'=> $minivanCreateDate,
    ]);

    $subject = (string) $this->t('Neighborhood Leader Materials - @county County', ['@county' => $county]);
    $reply = $nlCoordinator['email'];

    $message = (string) $message;
    $result = $this->sendMailObj->sendEmail('deliver_turf', $to, $subject, $message, $reply);
    if (!$result) {
      return;
    }

    // Update the NLs status to indicate the turf was delivered.
    $this->nlsObj->updateNlStatus($mcid, ['turfDelivered' => 'Y']);
    // Now update date the turf was delivered.
    $this->turfsObj->setTurfDelivered($turfChoice['turfIndex']);
    // Done.
    $this->messenger()->addStatus($this->t('Your message has been sent to @first @last', [
      '@first' => $turfChoice['nlFirstName'],
      '@last'  => $turfChoice['nlLastName'],
    ]));

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
  private function updateMinivanInfo(int $turfIndex): array
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    $county = $this->nlpSessionDataObj->getCounty();
    $turfArray[$turf['turfIndex']] = $turf;
    $this->apiMapRegionsObj->updateMinivanInfo($county, $turfArray);
    $turf = $turfArray[$turfIndex];
    if (!empty($turf['minivanNumber'])) {
      $minivanNumber = $turf['minivanNumber'];
      $minivanCreateDate = $turf['minivanCreateDate'];
      $createDate = date('Y-m-d', strtotime($minivanCreateDate));
      $fields = [
        'minivanNumber'     => $minivanNumber,
        'minivanCreateDate' => $createDate,
      ];
      $this->turfsObj->updateTurfFields($turfIndex, $fields);
    }
    return $turf;
  }

  /**
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
