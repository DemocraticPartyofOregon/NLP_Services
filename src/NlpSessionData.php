<?php

namespace Drupal\nlpservices;

use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\PrivateTempStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal;

class NlpSessionData
{
  protected PrivateTempStoreFactory $tempStoreObj;
  protected DrupalUser $drupalUser;
  protected PrivateTempStore $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected RequestStack $requestStack;

  public function __construct( $tempStoreObj, $drupalUser, $nlpConfigurationObj, $requestStack ) {
    $this->tempStoreObj = $tempStoreObj;
    $this->drupalUser = $drupalUser;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->requestStack = $requestStack;

    $this->sessionDataObj = $this->tempStoreObj->get('nlpservices.session_data');
  }

  public static function create(ContainerInterface $container): NlpSessionData
  {
    return new static(
      $container->get('tempstore.private'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.configuration'),
      $container->get('request_stack'),
    );
  }

  /**
   * Returns the county for the current user.  An admin can change the county they
   * are working in, which permits them to support any county.
   *
   * @return string
   */
  public function getCounty(): string
  {
    $county = (string) $this->get('County');
    $currentUser = $this->drupalUser->getCurrentUser();
    $admin = $this->drupalUser->isNlpAdminUser();
    if(empty($county)) {
      $county = empty($currentUser['county']) ? '' : $currentUser['county'];
      $this->set('County', $county);
    } elseif(!$admin && $county != $currentUser['county']) {
      $county = $currentUser['county'];
      $this->set('County', $county);
    }
    return $county;
  }

  /**
   * Sets the contact method for a voter.
   *
   * @param array $method - An array with the contact method data.
   *   Example: ['vanid' => '123456', 'name' => 'Phone', 'code' => 1]
   */
  public function setVoterContactMethod(array $method): void
  {
    $voterContactMethods = $this->get('voterContactMethods');
    $voterContactMethods[$method['vanid']] = $method;
    $this->set('voterContactMethods', $voterContactMethods);
  }

  /**
   * Returns the voter contact method for a voter.  Defaults to Walk if none is set.
   *
   * @param int $vanid - The Voter Access Number (VANID) of the voter.
   * @return array - An array with the contact method data.
   *   Example: ['vanid' => '123456', 'name' => 'Phone', 'code' => 1]
   */
  public function getVoterContactMethod(int $vanid): array
  {
    $voterContactMethods = $this->get('voterContactMethods');
    if(!empty($voterContactMethods[$vanid])) {
      return $voterContactMethods[$vanid];
    }
    return ['vanid' => $vanid, 'name' => 'Walk', 'code' => 2];
  }

  /**
   * Sets the new districts for the session.  It remembers the session and
   * restores the hd and precinct focus for the coordinator.  It helps reduce
   * keystrokes for managing NLs and turfs.  The indexes for hd and precinct are
   * saved in the session and record the focus of the last session.
   *
   * The method saves the district information in the temp store.  The key is 'selectedDistricts'.
   * The value is:
   *     'selectedHdIndex' => 0, // The index of the selected house district, nonzero if not the first time.
   *     'selectedPrecinctIndex' => 0, // The index of the selected precinct, nonzero if not the first time.
   *     'districtList' => $districtList, // Associative array of hd values and hd names.
   *     'pctList' => $pctList, // Associative array of precinct names for each hd.
   *
   * @param array $districtList - An array of house district names.
   * @param array $pctList - An array of precinct names for each house district.
   * @return void
   */
  public function setNewDistricts(array $districtList, array $pctList=[]): void
  {
    $savedDistricts = $this->get('selectedDistricts');
    if(!empty($savedDistricts['districtList'])) {
      $selectedHdIndex = $savedDistricts['selectedHdIndex'];
      $savedHd = (!empty($savedDistricts['districtList'][$selectedHdIndex])) ? $savedDistricts['districtList'][$selectedHdIndex] : 0;
      $newHdIndex = array_search($savedHd, $districtList);
      if($newHdIndex !== FALSE) {
        $savedDistricts['selectedHdIndex'] = $newHdIndex;
        $savedDistricts['districtList'] = $districtList;
        if(!empty($pctList)) {
          $savedPctIndex = $savedDistricts['selectedPrecinctIndex'] ?? 0;
          $savedPct = (!empty($savedDistricts['pctList'][$savedPctIndex])) ? $savedDistricts['pctList'][$savedPctIndex] : '';
          $savedDistricts['pctList'] = $pctList;
          if(!empty($savedPct)) {
            $newPctIndex = array_search($savedPct, $pctList);
            $savedDistricts['selectedPrecinctIndex'] = ($newPctIndex !== FALSE) ? $newPctIndex : 0;
          } else {
            $savedDistricts['selectedPrecinctIndex'] = 0;
          }
        }
        $this->set('selectedDistricts', $savedDistricts);
        return;
      }
    }
    $savedDistricts = [
      'selectedHdIndex' => 0,
      'selectedPrecinctIndex' => 0,
      'districtList' => $districtList,
      'pctList' => $pctList,
    ];
    $this->set('selectedDistricts', $savedDistricts);
  }

  /**
   * Returns the list of house districts saved in the session.
   *
   * @return array - An associative array of house district names.
   */
  public function getHdOptions(): array
  {
    $savedDistricts = $this->get('selectedDistricts');
    return empty($savedDistricts['districtList']) ? [] : $savedDistricts['districtList'];
  }

  /**
   * Saves the index of the selected house district in the session.
   *
   * @param int $hdIndex
   * @return void
   */
  public function saveHdIndex(int $hdIndex): void
  {
    $districts = $this->get('selectedDistricts');
    $districts['selectedHdIndex'] = $hdIndex;
    $this->set('selectedDistricts', $districts);
  }

  /**
   * Returns the index of the selected house district saved in the session.
   * If a hd_index query parameter is present in the current request, it is
   * saved to session first, so URL-driven HD selection takes effect immediately.
   *
   * @return int
   */
  public function getSavedHdIndex(): int
  {
    $hdIndexFromQuery = $this->requestStack->getCurrentRequest()->query->get('hd_index');
    if($hdIndexFromQuery !== NULL) {
      $this->saveHdIndex((int)$hdIndexFromQuery);
    }
    $districts = $this->get('selectedDistricts');
    return empty($districts['selectedHdIndex']) ? 0 : $districts['selectedHdIndex'];
  }

  /**
   * Returns the list of precincts saved in the session for the selected house district.
   *
   * @return array - An associative array of precinct names for the selected house district.
   */
  public function getPrecinctList(): array
  {
    $savedDistricts = $this->get('selectedDistricts');
    return empty($savedDistricts['pctList']) ? [] : $savedDistricts['pctList'];
  }

  /**
   * Returns the index of the selected precinct saved in the session.
   * If a pct_index query parameter is present in the current request, it is
   * saved to session first, so URL-driven precinct selection takes effect immediately.
   *
   * @return int
   */
  public function getSavedPrecinctIndex(): int
  {
    $request = $this->requestStack->getCurrentRequest();
    $pctIndexFromQuery = $request?->query->get('pct_index');
    if ($pctIndexFromQuery !== NULL) {
      $this->savePrecinctIndex((int)$pctIndexFromQuery);
    }
    $districts = $this->get('selectedDistricts');
    return empty($districts['selectedPrecinctIndex']) ? 0 : $districts['selectedPrecinctIndex'];
  }

  public function getSavedPct(): string
  {
    $savedDistricts = $this->get('selectedDistricts');
    if (!empty($savedDistricts['pctList'])) {
      $pctIndex = $savedDistricts['selectedPrecinctIndex'] ?? 0;
      $pct = $savedDistricts['pctList'][$pctIndex] ?? 'All';
      return ($pct === 'All') ? '' : $pct;
    }
    return '';
  }

  /**
   * Saves the index of the selected precinct in the session.
   *
   * @param int $pctIndex - The index of the selected precinct.
   * @return void
   */
  public function savePrecinctIndex(int $pctIndex): void
  {
    $districts = $this->get('selectedDistricts');
    $districts['selectedPrecinctIndex'] = $pctIndex;
    $this->set('selectedDistricts', $districts);
  }

  /**
   * Returns the saved house district value for the current user.
   *
   * @return string - The name of the saved house district.
   */
  public function getSavedHd(): string
  {
    $savedDistricts = $this->get('selectedDistricts');
    if(!empty($savedDistricts['districtList'])) {
      return (!empty($savedDistricts['districtList'][$savedDistricts['selectedHdIndex']])) ?
        $savedDistricts['districtList'][$savedDistricts['selectedHdIndex']] : '';
    }
    return '';
  }

  /**
   * Sets a value in the session storage.
   * Known Keys:
   * - turfIndex - The index of the current turf being worked on.
   * - voterContactMethods - see setVoterContactMethod above
   * - folderId - The ID of the current folder being worked on.
   * - mapRegionId - The ID of the current map region being worked on.
   * - helpSeen - A boolean indicating if the help was displayed for the first
   *              access to a turf.  It is cleared by the expiration of the temp data.
   *
   * @param mixed $key - The key identifying the value to be set.
   * @param mixed $value - The value to be stored in the session.
   * @return void
   */
  public function set(mixed $key, mixed $value): void
  {
    try {
      $this->sessionDataObj->set($key, $value);
    } catch (Drupal\Core\TempStore\TempStoreException $e) {
      nlp_debug_msg('set', $e);
    }
  }

  /**
   * Gets a value from the session storage.
   * If the key does not exist, it returns NULL.
   *
   * @param mixed $key - The key identifying the value to be retrieved.
   * @return mixed - The value associated with the key, or NULL if not found.
   */
  public function get(mixed $key): mixed
  {
    return $this->sessionDataObj->get($key);
  }
}
