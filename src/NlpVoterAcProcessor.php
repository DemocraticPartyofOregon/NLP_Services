<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpVoterAcProcessor {

  /**
   * The turfs queued at a time for the nightly check.  The cron time budget
   * sets how many are checked in a night, and the next night goes on from
   * the last turf checked, so all turfs are checked over several nights.
   * When a pass of all turfs finishes, the next pass waits for the next day.
   */
  const int TURFS_PER_SLICE = 100;

  /**
   * State of the nightly check: keys cursor (the last turf index queued),
   * passStart, passEnd (when the last pass finished, 0 while a pass is under
   * way), turfs, voters and missing (counts for the current pass).
   */
  const string CHECK_STATE = 'nlpservices.voter_ac_check';

  /**
   * How far back a missing voter's NLPVoterAC history is logged.
   */
  const int HISTORY_SECS = 30 * 86400;

  protected ApiActivistCodes $activistCodesObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpFileFunctions $fileFunctionsObj;
  protected NlpNls $nlsObj;
  protected NlpSendEmail $sendEmailObj;
  protected NlpTurfs $turfsObj;
  protected NlpVoters $votersObj;

  public function __construct(
    $activistCodesObj,
    $coordinatorsObj,
    $nlpConfigurationObj,
    $fileFunctionsObj,
    $nlsObj,
    $sendEmailObj,
    $turfsObj,
    $votersObj,
  ) {
    $this->activistCodesObj    = $activistCodesObj;
    $this->coordinatorsObj     = $coordinatorsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->fileFunctionsObj    = $fileFunctionsObj;
    $this->nlsObj              = $nlsObj;
    $this->sendEmailObj        = $sendEmailObj;
    $this->turfsObj            = $turfsObj;
    $this->votersObj           = $votersObj;
  }

  public static function create(ContainerInterface $container): NlpVoterAcProcessor
  {
    return new static(
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.file_functions'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.send_email'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
    );
  }

  /**
   * getCoordinatorName
   *
   * Gets the name of the coordinator for a turf.
   *
   * @param array $turf
   * @return string
   */
  private function getCoordinatorName(array $turf): string
  {
    $mcid = $turf['mcid'] ?? 0;
    if (empty($mcid)) {
      return '';
    }
    $nl = $this->nlsObj->getNlById($mcid);
    if (empty($nl)) {
      return '';
    }
    $coordinator = $this->coordinatorsObj->getAssignedCoordinator($nl);
    if (empty($coordinator)) {
      return '';
    }
    return trim(($coordinator['firstName'] ?? '') . ' ' . ($coordinator['lastName'] ?? ''));
  }

  /**
   * processTurf
   *
   * Processes a single turf by checking for missing activist codes and applying them.
   * 1. Get all voters in the turf.
   * 2. Check if any voters are missing the activist code.
   * 3. If any voters are missing the activist code, apply the activist code.
   *
   * @param int $turfIndex
   * @return void
   */
  public function processTurf(int $turfIndex): void
  {
    $voters = array_values($this->votersObj->getVotersInTurf($turfIndex));
    if (empty($voters)) {
      return;
    }
    $missingVanids = $this->activistCodesObj->hasApiActivistCodeBatch($voters, 'NLPVoterAC');
    $this->countCheck(count($voters), count($missingVanids));
    if (!empty($missingVanids)) {
      $turf            = $this->turfsObj->getTurf($turfIndex);
      $turfName        = $turf['turfName'] ?? (string) $turfIndex;
      $county          = $turf['county'] ?? '';
      $coordinatorName = $this->getCoordinatorName($turf);

      // The history of each missing voter, to look for a pattern.
      $historyText = '';
      $history = Drupal::hasService('nlpservices.ac_history') ? Drupal::service('nlpservices.ac_history') : NULL;
      if ($history instanceof NlpAcHistory) {
        $historyText = ' History: ' . $history->describe($missingVanids, time() - self::HISTORY_SECS);
        $history->record($missingVanids, NlpAcHistory::MISSING, "voter AC check of $turfName (turfIndex $turfIndex)");
      }
      Drupal::logger('nlpservices')->notice(
        'Missing NLPVoterAC: county=@county, turf=@turf, coordinator=@coordinator, vanids=@vanids.@history',
        [
          '@county'      => $county,
          '@turf'        => $turfName,
          '@coordinator' => $coordinatorName,
          '@vanids'      => implode(', ', $missingVanids),
          '@history'     => $historyText,
        ]
      );

      $adminEmail = $this->nlpConfigurationObj->getNotificationEmail();
      $subject    = t('Missing NLPVoterAC activist codes detected');
      $message    = t(
        'Missing NLPVoterAC: county=@county, turf=@turf, coordinator=@coordinator, vanids=@vanids',
        [
          '@county'      => $county,
          '@turf'        => $turfName,
          '@coordinator' => $coordinatorName,
          '@vanids'      => implode(', ', $missingVanids),
        ]
      );
      $this->sendEmailObj->sendEmail('nlp_admin_notice', $adminEmail, $subject, $message);

      $this->activistCodesObj->setApiActivistCodeBatch($missingVanids, 'NLPVoterAC', 'Apply', "voter AC check of $turfName");
    }
  }

  /**
   * queueNextTurfs
   *
   * Queues the next turfs for the nightly check, after the last turf queued.
   * After the last turf, the counts for the pass are logged and nothing more
   * is queued that day.  The next pass starts from the first turf on a later
   * day, so each turf is checked once a pass and the pass is logged once.
   *
   * @return int - the count of turfs queued.
   */
  public function queueNextTurfs(): int
  {
    $check = $this->getCheckState();
    if (!empty($check['passEnd'])) {
      if (date('Y-m-d', $check['passEnd']) === date('Y-m-d')) {
        return 0;
      }
      $check = ['cursor' => 0, 'passStart' => 0, 'passEnd' => 0, 'turfs' => 0, 'voters' => 0, 'missing' => 0];
    }
    $turfIndexes = $this->turfsObj->getTurfIndexesAfter($check['cursor'], self::TURFS_PER_SLICE);
    if (empty($turfIndexes) && $check['cursor'] > 0) {
      Drupal::logger('nlpservices')->notice(
        'Voter AC check finished a pass of @turfs turfs with @voters voters, started @start. '
        . '@missing voter(s) were missing NLPVoterAC.',
        [
          '@turfs'   => $check['turfs'],
          '@voters'  => $check['voters'],
          '@missing' => $check['missing'],
          '@start'   => date('Y-m-d', $check['passStart'] ?: time()),
        ]
      );
      $check['passEnd'] = time();
      Drupal::state()->set(self::CHECK_STATE, $check);
      return 0;
    }
    if (empty($turfIndexes)) {
      return 0;
    }
    if (empty($check['passStart'])) {
      $check['passStart'] = time();
    }
    $queue = Drupal::queue('nlpservices_voter_ac_queue');
    foreach ($turfIndexes as $turfIndex) {
      $queue->createItem(['turfIndex' => $turfIndex]);
    }
    $check['cursor'] = end($turfIndexes);
    Drupal::state()->set(self::CHECK_STATE, $check);
    return count($turfIndexes);
  }

  /**
   * The state of the nightly check, with defaults for a first pass.
   */
  private function getCheckState(): array
  {
    return Drupal::state()->get(self::CHECK_STATE, []) + [
      'cursor' => 0, 'passStart' => 0, 'passEnd' => 0, 'turfs' => 0, 'voters' => 0, 'missing' => 0,
    ];
  }

  /**
   * Adds a checked turf to the counts for the current pass of the nightly check.
   */
  private function countCheck(int $voters, int $missing): void
  {
    if (!Drupal::hasService('state')) {
      return;
    }
    $check = $this->getCheckState();
    $check['turfs']++;
    $check['voters'] += $voters;
    $check['missing'] += $missing;
    Drupal::state()->set(self::CHECK_STATE, $check);
  }

  /**
   * processBatch
   *
   * Processes a batch of turfs by iterating through a list of voter data, applying specified logic,
   * and updating the batch context for tracking progress and results.
   *
   * @param array $arg An associative array containing inputs for the batch process, including:
   *                   - 'turfIndexes': An array of turf indexes to process.
   *                   - 'stopAtLimit': (optional) A boolean indicating whether to stop processing when a specific limit is reached.
   *                   - 'reportOnly': (optional) A boolean that, when true, skips applying codes and only accumulates data for reporting.
   * @param array &$context A reference to an array used for storing the batch processing state, sandbox data, and accumulated results.
   *                        The array should include or be updated with:
   *                        - 'sandbox':
   *                           - 'initialized': A flag indicating whether the sandbox has been initialized.
   *                           - 'currentTurf': The index of the current turf being processed.
   *                        - 'results':
   *                           - 'votersChecked': The total number of voters processed.
   *                           - 'votersMissingAc': The total number of voters missing the activist code.
   *                           - 'turfsProcessed': The number of turfs processed.
   *                           - 'missingAcRecords': An array of records detailing missing activist code information.
   *                           - 'reportOnly': A flag indicating whether the batch was run in reporting-only mode.
   *                        - 'finished': A flag or progress indicator signaling whether the processing is complete.
   *
   * @return void This method does not return a value. All results and state updates are stored in the $context.
   */
  public function processBatch(array $arg, array &$context): void
  {
    $turfIndexes = array_values($arg['turfIndexes']);
    $turfCount   = count($turfIndexes);
    $stopAtLimit = $arg['stopAtLimit'] ?? FALSE;
    $reportOnly  = $arg['reportOnly'] ?? FALSE;

    if (empty($context['sandbox']['initialized'])) {
      $context['sandbox']['initialized']  = TRUE;
      $context['sandbox']['currentTurf']  = 0;
    }

    if (!isset($context['results']['votersChecked'])) {
      $context['results']['votersChecked']    = 0;
      $context['results']['votersMissingAc']  = 0;
      $context['results']['turfsProcessed']   = 0;
      $context['results']['missingAcRecords'] = [];
      $context['results']['reportOnly']       = $reportOnly;
    }

    $currentTurf = $context['sandbox']['currentTurf'];

    if ($currentTurf >= $turfCount) {
      $context['finished'] = 1;
      return;
    }

    $turfIndex       = $turfIndexes[$currentTurf];
    $turf            = $this->turfsObj->getTurf($turfIndex);
    $turfName        = $turf['turfName'] ?? (string) $turfIndex;
    $county          = $turf['county'] ?? '';
    $coordinatorName = $this->getCoordinatorName($turf);

    $voters = array_values($this->votersObj->getVotersInTurf($turfIndex));
    $context['results']['votersChecked'] += count($voters);

    $missingVanids = $this->activistCodesObj->hasApiActivistCodeBatch($voters, 'NLPVoterAC');

    $toApply = [];
    foreach ($missingVanids as $vanid) {
      if ($stopAtLimit && $context['results']['votersMissingAc'] >= 100) {
        $context['finished'] = 1;
        return;
      }
      $context['results']['missingAcRecords'][] = [
        'vanid'       => $vanid,
        'turfName'    => $turfName,
        'county'      => $county,
        'coordinator' => $coordinatorName,
      ];
      $context['results']['votersMissingAc']++;
      if (!$reportOnly) {
        $toApply[] = $vanid;
      }
    }

    if (!empty($toApply)) {
      $this->activistCodesObj->setApiActivistCodeBatch($toApply, 'NLPVoterAC', 'Apply', "voter AC check of $turfName");
    }
    $context['results']['turfsProcessed']++;

    $context['sandbox']['currentTurf']++;
    $context['finished'] = $context['sandbox']['currentTurf'] / $turfCount;
  }

}
