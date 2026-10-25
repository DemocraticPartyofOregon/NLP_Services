<?php /** @noinspection JSUnresolvedReference */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpTurfDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\NlpTurfStatus;

/**
 * @noinspection PhpUnused
 */
class DeleteTurfForm extends FormBase
{

  protected NlpTurfs $turfs;

  protected NlpAwards $awardsObj;
  protected NlpSearchBar $searchBarObj;
  protected NlpTurfDisplay $turfDisplayObj;
  protected NlpSessionData $nlpSessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiFolders $foldersObj;
  protected NlpTurfStatus $turfStatusObj;


  public function __construct(   $turfs, $awardsObj, $searchBarObj, $turfDisplayObj,
                                 $nlpSessionDataObj, $nlpConfigurationObj, $foldersObj,
                                 $turfStatusObj) {
    $this->turfs = $turfs;
    $this->awardsObj = $awardsObj;
    $this->searchBarObj = $searchBarObj;
    $this->turfDisplayObj = $turfDisplayObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->foldersObj = $foldersObj;
    $this->turfStatusObj = $turfStatusObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DeleteTurfForm
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.turf_display'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.folders'),
      $container->get('nlpservices.turf_status'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_delete_turf_form';
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
      $county = $this->nlpSessionDataObj->getCounty();
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [];
      }
      $form_state->set('county',$county);
      $hdOptions = $this->turfs->getTurfHD($county); // HDs with turfs.
      $turfCount = $this->turfs->getCountyTurfCount($county);
      if($turfCount<$this->turfs::TURF_ALL_MAX ) {
        array_unshift($hdOptions , 'All');
      }
      $this->nlpSessionDataObj->setNewDistricts($hdOptions);
    }
    
    $county = $form_state->get('county');

    $this->nlpSessionDataObj->getSavedHdIndex();
    $currentHd = $this->nlpSessionDataObj->getSavedHd();
    $needle = $form_state->get('search-needle');
    if(!empty($needle)) {
      $turfArray = $this->turfs->getTurfs($county,'All');
      $form_state->set('search-needle', NULL);
      $turfArray = $this->searchBarObj->searchInfoArray($needle,$turfArray);
    } else {
      $turfArray = (!empty($currentHd))?$this->turfs->getTurfs($county,$currentHd):[];
    }

    $form_state->set('turfArray',$turfArray);
    // Record how the turf list was built so a stale selection can be diagnosed.
    $form_state->set('turfListInfo', [
      'builtAt' => time(),
      'hd' => $currentHd,
      'needle' => $needle ?? '',
    ]);

    // Always show the HD selector when there are HDs with turfs, even if the
    // selected HD shows nothing, so the user can pick a different HD.
    $hdOptions = $this->nlpSessionDataObj->getHdOptions();
    if(!empty($hdOptions)) {
      $form['hd-change'] = [
        '#prefix' => '<div id="selectbox-div">',
        '#suffix' => '</div>',
        '#type' => 'fieldset',
      ];
      $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
      $form['hd-change']['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex);
    }

    $turfCount = count($turfArray);
    if(empty($turfCount)) {
      $form['turf-table']['turf-form'] = [
        '#markup' => '<p>There is no turf to display.</p>',
      ];
      return $form;
    }

    $turfRows = $this->turfDisplayObj->buildTurfTableArray($turfArray);
    $form['hd-change']['turf-table'] = $this->turfDisplayObj->displayTurfs($turfRows);
    
    // add a button to delete the selected turf or turfs.
    $form['submit'] = [
      '#type' => 'submit',
      '#name' => 'delete_submit',
      '#value' => 'Delete Selected Turf(s) >>',
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
        // A search doesn't delete anything, so no turf needs to be selected.
        return;

      case 'hd-select':
        $newHdIndex = $form_state->getValue('hd-select');
        // The user changed the HD to display.
        $this->nlpSessionDataObj->saveHdIndex($newHdIndex);
        return;
    }
    $values = $form_state->getValues();
    $selections = $this->turfDisplayObj->validateTurfSelection($values['contacts']);
    $form_state->set('selections', $selections);
    
    if (count($selections) == 0) {
      $form_state->setErrorByName('submit', 'You must select at least one turf to delete.');
      return;
    }

    $turfArray = $form_state->get('turfArray') ?? [];
    $missing = array_diff(array_keys($selections), array_keys($turfArray));
    if (!empty($missing)) {
      $missingDbTurfs = [];
      foreach ($missing as $turfIndex) {
        $missingDbTurfs[$turfIndex] = $this->turfs->getTurf((int) $turfIndex);
      }
      $this->logMissingTurfs($form, $form_state, $missingDbTurfs, $selections, $turfArray);
      if (empty(array_filter($missingDbTurfs))) {
        // None of the missing turfs are in the database.  Usually the page was submitted twice and the
        // first submit deleted them, so say so rather than report that the list changed.
        Drupal::messenger()->addWarning(t('The selected turf(s) @turfIndexes had already been deleted.',
          ['@turfIndexes' => implode(', ', $missing)]));
        $form_state->set('selections', array_diff_key($selections, $missingDbTurfs));
        return;
      }
      // Reloading the page would resubmit the same selection, so send the user back through the menu.
      $form_state->setErrorByName('submit', t('The list of turfs has changed since this page was loaded. Open Delete Turf again from the menu and select the turf(s) again.'));
    }

  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $form_state->set('reenter', TRUE);
    $form_state->setRebuild();  // form_state will persist
    
    $triggeringElement = $form_state->getTriggeringElement();
    $elementClicked = $triggeringElement['#name'];
    switch ($elementClicked) {
      
      case 'search_submit':
        $needle = $form_state->getValue('search-needle');
        $form_state->set('search-needle', $needle);
        return;
      
      default:
        $county = $form_state->get('county');
        // From the list of turfs in the list, find the ones to be deleted.
        $turfArray = $form_state->get('turfArray');
        $selections = $form_state->get('selections');
        
        foreach ($selections as $turfIndex => $selection) {
          
            $turfChoice = $turfArray[$turfIndex] ?? NULL;
            if (empty($turfChoice)) {
              continue;
            }
            // The turf list may be stale, so confirm the turf still exists and belongs to the same NL.
            $currentTurf = $this->turfs->getTurf((int) $turfIndex);
            if (empty($currentTurf) || (int) $currentTurf['mcid'] !== (int) $turfChoice['mcid']) {
              $messenger->addWarning(t('Turf @turfName was not deleted because it has changed since this page was loaded.', ['@turfName' => $turfChoice['turfName']]));
              Drupal::logger('nlpservices')->notice('Delete turf: turf index @turfIndex skipped, @reason.', [
                '@turfIndex' => $turfIndex,
                '@reason' => empty($currentTurf) ? 'it no longer exists' : 'its NL changed from ' . $turfChoice['mcid'] . ' to ' . $currentTurf['mcid'],
              ]);
              continue;
            }
            $nickname = $turfChoice['nickname'];
            $lastName = $turfChoice['nlLastName'];
            $turfName = $turfChoice['turfName'];
            $mcid = $turfChoice['mcid'];

            $folderName = $turfChoice['folderName'] ?? '';
            if (!$this->turfStatusObj->turfStatusCheck($folderName, $turfName)) {
              $messenger->addWarning(t('Turf @turfName cannot be deleted yet — activist codes are still being processed. Please try again later.', ['@turfName' => $turfName]));
              continue;
            }

            $folderId   = (int) ($turfChoice['folderId'] ?? 0);
            $turfId     = (int) ($turfChoice['listId'] ?? 0);
            $voterCount = (int) ($turfChoice['voterCount'] ?? 0);

            $turf['mcid'] = $mcid;
            $turf['county'] = $county;
            $turf['turfIndex'] = $turfIndex;



            $status = $this->turfs->deleteTurf($turf);
            if(!$status) {
              $messenger->addError(t('Turf remove failed'));
              return;
            }

            $expirationSeconds = ($voterCount < 60) ? NlpTurfStatus::DELETED_EXPIRY_SMALL_SECS : NlpTurfStatus::DELETED_EXPIRY_LARGE_SECS;
            $now = time();
            $this->turfStatusObj->createTurfStatus([
              'folderName'     => $folderName,
              'folderId'       => $folderId,
              'turfName'       => $turfName,
              'turfId'         => $turfId,
              'createTime'     => date('c', $now),
              'expirationTime' => date('c', $now + $expirationSeconds),
              'status'         => NlpTurfStatus::TURF_DELETED,
            ]);

            $this->awardsObj->nlParticipation($mcid,'Yes');
            
            // successful!
            $status_msg = $turfChoice['turfIndex']." ".$turfChoice['syncDate']." "."$nickname, $lastName, $turfName successfully deleted";
            $messenger->addStatus(t($status_msg));
          
        }
        
        break;
    }
    
  }
  
  /**
   * logMissingTurfs
   *
   * Logs the details needed to diagnose a selected turf that isn't in the turf list saved with the form.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   * @param array $missingDbTurfs - Database records of the turfs selected but not in the turf list,
   *   keyed by turf index.  An empty record means the turf isn't in the database.
   * @param array $selections - All selected turfs, keyed by turf index.
   * @param array $turfArray - The turf list saved with the form.
   * @return void
   */
  private function logMissingTurfs(array $form, FormStateInterface $form_state, array $missingDbTurfs,
                                   array $selections, array $turfArray): void
  {
    $turfListInfo = $form_state->get('turfListInfo') ?? [];
    $builtAt = $turfListInfo['builtAt'] ?? NULL;
    $inDatabase = [];
    foreach ($missingDbTurfs as $turfIndex => $dbTurf) {
      $inDatabase[] = empty($dbTurf) ? "$turfIndex: not in database"
        : "$turfIndex: county=" . $dbTurf['county'] . ' hd=' . $dbTurf['turfHd'] . ' mcid=' . $dbTurf['mcid'];
    }
    Drupal::logger('nlpservices')->notice(
      'Delete turf: turf index(es) @missing not found in the displayed turf list. Selected: @selected. '
      . 'Turf list: @count turfs, county=@county, hd=@hd, search=@needle, built @age seconds earlier. '
      . 'Form build @buildId, from form cache: @cached. Database: @inDatabase.',
      [
        '@missing' => implode(', ', array_keys($missingDbTurfs)),
        '@selected' => implode(', ', array_keys($selections)),
        '@count' => count($turfArray),
        '@county' => $form_state->get('county') ?? '',
        '@hd' => $turfListInfo['hd'] ?? 'unknown',
        '@needle' => ($turfListInfo['needle'] ?? '') === '' ? 'none' : $turfListInfo['needle'],
        '@age' => isset($builtAt) ? time() - $builtAt : 'unknown',
        '@buildId' => $form['#build_id'] ?? 'unknown',
        '@cached' => $form_state->isCached() ? 'yes' : 'no',
        '@inDatabase' => implode('; ', $inDatabase),
      ]
    );
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
  function selectboxCallback($form, $unused): array
  {
    return $form['hd-change'];
  }
  
}
