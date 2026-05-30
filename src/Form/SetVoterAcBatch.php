<?php

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * setVoterAcBatch
 *
 * For each turf, check whether each assigned voter has the NLPVoterAC
 * activist code. If not, set it. Logs a watchdog entry per turf listing
 * the VANIDs that were missing the code. Processes one turf per invocation.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function setVoterAcBatch($arg, &$context): void
{
  $turfIndexes = $arg['turfIndexes'];
  $turfCount = count($turfIndexes);

  if (empty($context['sandbox']['initialized'])) {
    $context['sandbox']['initialized'] = TRUE;
    $context['sandbox']['currentTurf'] = 0;
    $context['sandbox']['votersChecked'] = 0;
    $context['sandbox']['votersMissingAc'] = 0;
    $context['finished'] = 0;
  }

  $currentTurf = $context['sandbox']['currentTurf'];

  if ($currentTurf >= $turfCount) {
    $context['finished'] = 1;
    $context['results']['votersChecked'] = $context['sandbox']['votersChecked'];
    $context['results']['votersMissingAc'] = $context['sandbox']['votersMissingAc'];
    $context['results']['turfsProcessed'] = $turfCount;
    return;
  }

  $turfIndex = $turfIndexes[$currentTurf];

  $votersObj = Drupal::service('nlpservices.voters');
  $activistCodesObj = Drupal::service('nlpservices.activist_codes');

  $voters = $votersObj->getVotersInTurf($turfIndex);
  $missingVanids = [];

  foreach ($voters as $vanid) {
    $context['sandbox']['votersChecked']++;
    if (!$activistCodesObj->hasApiActivistCode($vanid, 'NLPVoterAC')) {
      $activistCodesObj->setApiActivistCode([
        'vanid' => $vanid,
        'codeName' => 'NLPVoterAC',
        'action' => 'Apply',
      ]);
      $missingVanids[] = $vanid;
      $context['sandbox']['votersMissingAc']++;
    }
  }

  if (!empty($missingVanids)) {
    $vanidList = implode(', ', $missingVanids);
    Drupal::logger('nlpservices')->notice(
      "NLP Voter AC was missing and has been set for turf @turfIndex - VANIDs: @vanids",
      ['@turfIndex' => $turfIndex, '@vanids' => $vanidList]
    );
  }

  $context['sandbox']['currentTurf']++;
  $context['finished'] = ($currentTurf + 1) / $turfCount;
  if($context['finished'] > 0.99) {
    $context['results']['result'] = 'success';
  }
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * setVoterAcBatchFinished
 *
 * The batch operation is finished. Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function setVoterAcBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
  $messenger->addStatus(t('Batch finished.'));
  if ($success) {
    $turfsProcessed = $results['turfsProcessed'] ?? 0;
    $votersChecked = $results['votersChecked'] ?? 0;
    $votersMissingAc = $results['votersMissingAc'] ?? 0;
    $messenger->addStatus(t("Checked @votersChecked voters across @turfsProcessed turfs. NLP Voter AC was missing and set for @votersMissingAc voters.", [
      '@votersChecked' => $votersChecked,
      '@turfsProcessed' => $turfsProcessed,
      '@votersMissingAc' => $votersMissingAc,
    ]));
  }
  else {
    $messenger->addError(t('An error occurred.'));
  }
}
