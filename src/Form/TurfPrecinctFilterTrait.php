<?php

namespace Drupal\nlpservices\Form;

use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfs;

/**
 * Narrows a turf list to a single precinct, for the turf forms that already
 * narrow by HD (DeleteTurfForm, DeliverTurfForm, DisplayTurfsForm).
 */
trait TurfPrecinctFilterTrait
{

  /**
   * buildPrecinctOptions
   *
   * Builds the precinct select options for the selected HD and saves them in
   * the session.  When the HD is 'All', the options are every precinct in the
   * county with turfs; otherwise only the precincts in that HD.  The first
   * option is always 'All'.
   *
   * @param NlpTurfs $turfsObj
   * @param NlpSessionData $sessionDataObj
   * @param string $county
   * @param string $currentHd - The selected HD, 'All', or empty.
   * @return array - [$pctOptions, $currentPctIndex]
   */
  protected function buildPrecinctOptions(NlpTurfs $turfsObj, NlpSessionData $sessionDataObj,
                                          string $county, string $currentHd): array
  {
    $hdValue = ($currentHd === 'All' || empty($currentHd)) ? 0 : (int)$currentHd;
    $pctNames = array_filter($turfsObj->getTurfPrecinctNames($county, $hdValue), 'strlen');
    $pctOptions = array_merge(['All'], array_values($pctNames));
    $sessionDataObj->setNewDistricts($sessionDataObj->getHdOptions(), $pctOptions);
    $currentPctIndex = $sessionDataObj->getSavedPrecinctIndex();
    // A stale index (e.g., the precinct no longer has turfs) falls back to 'All'.
    if (!isset($pctOptions[$currentPctIndex])) {
      $sessionDataObj->savePrecinctIndex(0);
      $currentPctIndex = 0;
    }
    return [$pctOptions, $currentPctIndex];
  }

  /**
   * filterTurfsByPrecinct
   *
   * Keeps only the turfs in the selected precinct.  Returns the list unchanged
   * when 'All' precincts is selected.
   *
   * @param array $turfArray - Turf records keyed by turfIndex.
   * @param NlpSessionData $sessionDataObj
   * @return array
   */
  protected function filterTurfsByPrecinct(array $turfArray, NlpSessionData $sessionDataObj): array
  {
    $currentPct = $sessionDataObj->getSavedPct();
    if ($currentPct === '') {
      return $turfArray;
    }
    return array_filter($turfArray, fn($turf) => (string)($turf['turfPrecinct'] ?? '') === (string)$currentPct);
  }

}
