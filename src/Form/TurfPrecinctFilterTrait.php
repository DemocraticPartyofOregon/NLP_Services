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
   * the session.  When the HD is 0 (all HDs), the options are every precinct in the
   * county with turfs; otherwise only the precincts in that HD.  The first
   * option is always 'All'.
   *
   * @param NlpTurfs $turfsObj
   * @param NlpSessionData $sessionDataObj
   * @param string $county
   * @param int $currentHd - The selected HD, or 0 for all HDs.
   * @return array - [$pctOptions, $currentPctIndex]
   */
  protected function buildPrecinctOptions(NlpTurfs $turfsObj, NlpSessionData $sessionDataObj,
                                          string $county, int $currentHd): array
  {
    $pctNames = array_filter($turfsObj->getTurfPrecinctNames($county, $currentHd), 'strlen');
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
   * when 'All' precincts are selected.
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
    return array_filter($turfArray, fn($turf) => (string)($turf['turfPrecinct'] ?? '') === $currentPct);
  }

  /**
   * getHdTurfs
   *
   * Returns the turfs in the selected HD.  HD 0 means all HDs, but those turfs
   * are loaded only when 'All' is one of the HD options, which it is only when
   * the county has fewer than TURF_ALL_MAX turfs.
   *
   * @param NlpTurfs $turfsObj
   * @param NlpSessionData $sessionDataObj
   * @param string $county
   * @param int $currentHd - The selected HD, or 0 for all HDs.
   * @param bool $includeRemovedNls - Include turfs whose NL is not in the NLs table.
   * @return array - Turf records keyed by turfIndex.
   */
  protected function getHdTurfs(NlpTurfs $turfsObj, NlpSessionData $sessionDataObj, string $county,
                                int $currentHd, bool $includeRemovedNls = FALSE): array
  {
    if (empty($currentHd) && !in_array('All', $sessionDataObj->getHdOptions(), TRUE)) {
      return [];
    }
    return $turfsObj->getTurfs($county, $currentHd, $includeRemovedNls);
  }

}
