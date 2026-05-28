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
   * getCounty
   *
   * Returns the county for the current user.  But an admin can change the county they
   * are working in.   This permits the admin to support any county.
   *
   * @return string
   */
  public function getCounty(): string
  {
    $county = (string) $this->get('County');
    $currentUser = $this->drupalUser->getCurrentUser();
    $admin = $this->drupalUser->isNlpAdminUser();
    if(empty($county)) {
      $county = empty($currentUser['county'])?'':$currentUser['county'];
      $this->set('County',$county);
    } elseif(!$admin AND $county!=$currentUser['county']) { // Fix a county change.
      $this->set('County',$currentUser['county']);
    }
    return $county;
  }
  
  /**
   * setVoterContactMethod
   *
   * Sets the contact method for a voter.
   *
   * @param $method - An array with the contact method data.
   *   Example: ['vanid' => '123456', 'name' => 'Phone', 'code' => 1]
   */
  public function setVoterContactMethod($method): void
  {
    $voterContactMethods = $this->get('voterContactMethods');
    $voterContactMethods[$method['vanid']] = $method;
    $this->set('voterContactMethods', $voterContactMethods);
  }
  
  /**
   * getVoterContactMethod
   *
   * Returns the voter contact method for a voter.
   *
   * @param int $vanid - The Voter Access Number (VANID) of the voter.
   * @return array - An array with the contact method data.
   *   Example: ['vanid' => '123456', 'name' => 'Phone', 'code' => 1]
   */
  public function getVoterContactMethod(int $vanid): array
  {
    $voterContactMethods = $this->get('voterContactMethods');
    // A contact method is selected for this voter.
    if(!empty($voterContactMethods[$vanid])) {
      return $voterContactMethods[$vanid];
    }
    if(empty($voterContactMethod)) {
      $voterContactMethod = [
        'vanid' => $vanid,
        'name' => 'Walk',
        'code' => 2,
      ];
      
    }
    return $voterContactMethod;
  }
  
  /**
   * setNewDistricts
   *
   * This method sets the new districts for the session.  It remembers the session and
   * restores the hd and precinct focus for the coordinator.  It helps reduce keystrokes for
   * managing NLs and turfs.  The indexes for hd and precinct are saved in the session and
   * record the focus of the last session.
   *
   * The method saves the district information in the temp store.  The key is 'selectedDistricts'.
   * The value is:
   *     'selectedHdIndex' => 0, // The index of the selected house district, nonzero if not the first time.
   *     'selectedPrecinctIndex' => 0, // The index of the selected precinct, nonzero if not the first time.
   *     'hdList' => $hdList, // Associative array of hd values and hd names.
   *     'pctList' => $pctList, // Associative array of precinct names for each hd.
   *
   * @param $hdList - An array of house district names.
   * @param array $pctList - An array of precinct names for each house district.
   * @return void
   */
  public function setNewDistricts($hdList,array $pctList=[]):void
  {
    $savedDistricts = $this->get('selectedDistricts');
    if(!empty($savedDistricts['hdList'])) {
      $selectedHdIndex = $savedDistricts['selectedHdIndex'];
      $savedHd = (!empty($savedDistricts['hdList'][$selectedHdIndex]))?$savedDistricts['hdList'][$selectedHdIndex]:0;
      $newHdIndex = array_search($savedHd,$hdList);
      if($selectedHdIndex !== FALSE) {
        $savedDistricts['selectedHdIndex'] = $newHdIndex;
        $savedDistricts['hdList'] = $hdList;
        if(!empty($pctList)) {
          $savedDistricts['selectedPrecinctIndex'] = 0;
          $savedDistricts['pctList'] = $pctList;
        }
        $this->set('selectedDistricts',$savedDistricts);
        return;
      }
      
    }
    $savedDistricts = [
      'selectedHdIndex' => 0,
      'selectedPrecinctIndex' => 0,
      'hdList' => $hdList,
      'pctList' => $pctList,
    ];
    $this->set('selectedDistricts',$savedDistricts);
  }
  
  /**`
   * getHdOptions
   *
   * Returns the list of house districts saved in the session.
   *
   * @return array - An associative array of house district names.
   */
  public function getHdOptions():array
  {
    $savedDistricts = $this->get('selectedDistricts');
    return empty($savedDistricts['hdList'])?[]:$savedDistricts['hdList'];
  }
  
  /**
   * saveHdIndex
   *
   * Saves the index of the selected house district in the session.
   *
   * @param $hdIndex
   * @return void
   */
  public function saveHdIndex($hdIndex): void {
    $districts = $this->get('selectedDistricts');
    $districts['selectedHdIndex'] = $hdIndex;
    $this->set('selectedDistricts',$districts);
  }
  
  /**
   * getSavedHdIndex
   *
   * Returns the index of the selected house district saved in the session.
   * This is used to restore the focus of the house district when the user.
   *
   * @return int
   */
  public function getSavedHdIndex(): int
  {
    $hdIndexFromQuery = $this->requestStack->getCurrentRequest()->query->get('hd_index');
    if($hdIndexFromQuery !== NULL) {
      $this->saveHdIndex((int)$hdIndexFromQuery);
    }
    $districts = $this->sessionDataObj->get('selectedDistricts');
    return empty($districts['selectedHdIndex']) ? 0 : $districts['selectedHdIndex'];
  }
  
  /**
   * getPrecinctList
   *
   * Returns the list of precincts saved in the session for the selected house district.
   *
   * @return array - An associative array of precinct names for the selected house district.
   */
  public function getPrecinctList():array
  {
    $savedDistricts = $this->sessionDataObj->get('selectedDistricts');
    return empty($savedDistricts['pctList'])? []:$savedDistricts['pctList'];
  }
  
  /**
   * getSavedPrecinctIndex
   *
   * Returns the index of the selected precinct saved in the session.
   * This is used to restore the focus of the precinct when the user.
   *
   * @return int
   */
  public function getSavedPrecinctIndex(): int
  {
    $districts = $this->sessionDataObj->get('selectedDistricts');
    return empty($districts['selectedPrecinctIndex'])?0:$districts['selectedPrecinctIndex'];
  }
  
  /**
   * savePrecinctIndex
   *
   * Saves the index of the selected precinct in the session.
   * This is used to restore the focus of the precinct when the user.
   *
   * @param $pctIndex - The index of the selected precinct.
   * @return void
   */
  public function savePrecinctIndex($pctIndex): void {
    $districts = $this->sessionDataObj->get('selectedDistricts');
    $districts['selectedPrecinctIndex'] = $pctIndex;
    $this->set('selectedDistricts',$districts);
  }
  
  /**
   * getSavedHd
   *
   * Returns the saved house district for the current user.
   * This is used to restore the focus of the house district when the user.
   *
   * @return string - The name of the saved house district.
   */
  public function getSavedHd():string
  {
    $savedDistricts = $this->sessionDataObj->get('selectedDistricts');
    if(!empty($savedDistricts['hdList'])) {
      return (!empty($savedDistricts['hdList'][$savedDistricts['selectedHdIndex']]))?
        $savedDistricts['hdList'][$savedDistricts['selectedHdIndex']]:'';
    }
    return '';
  }

  /**
   * getCurrentDistricts
   *
   * Returns an array of the focus for the HD and precinct for the current session.
   * It is used to restore the focus of the HD and precinct when the coordinator works with turfs.
   *
   * The county changes for an admin who can change counties for support purposes.
   *
   * @return array
   */
  public function getCurrentDistricts(): array
  {
    $districts = $this->get('currentDistricts');
    $county = $this->getCounty();
    if(empty($districts) || (!empty($districts['county']) && $districts['county'] != $county)) {
      $districts = ['county' => $county, 'hdNew' => TRUE, ];
    }
    return $districts;
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
   *
   * @return void
   */
  public function set(mixed $key, mixed $value): void {
    try {
      $this->sessionDataObj->set($key,$value);
    } catch (Drupal\Core\TempStore\TempStoreException $e) {
      nlp_debug_msg('set', $e->getMessage());
    }
  }
  
  /**
   * Gets a value from the session storage.
   * If the key does not exist, it returns NULL.
   *
   * @param mixed $key - The key identifying the value to be retrieved.
   *
   * @return mixed - The value associated with the key, or NULL if not found.
   */
  public function get(mixed $key): mixed
  {
    return $this->sessionDataObj->get($key);
  }
}