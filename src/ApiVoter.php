<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;
use stdClass;

class ApiVoter
{
  
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private array $stateCommitteeKey;
  
  const array VOTER_SEARCH_FIELDS = [
    'addressLine1',
    'addressLine2',
    'city',
    'stateOrProvince',
    'zipOrPostalCode',
    'countryCode',
    'type',
  ];
  
  public function __construct($client, $nlpConfigurationObj ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->stateCommitteeKey = $this->nlpConfigurationObj->getStateKey();
  }
  
  public static function create(ContainerInterface $container): ApiVoter
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * parseAddress
   * 
   * @param $address
   * @return array - array of address fields.
   *
   * This function parses the address into its component parts.
   * It returns an array with the following keys:
   * streetNo, streetPrefix, streetName, streetSuffix, streetType, aptNo, aptType.
   * 
   * The address is expected to be in the format:
   *  "1234 NW Main St Apt 567" or "1234 NW Main St #567" or "1234 NW Main St 567"
   *  The street number is always
   *  at the beginning of the address, and the street type is always at the end.
   *  The apartment number can be at the end of the address, or it can be in
   *  the middle of the address, preceded by a space or a #.
   *  The street prefix can be at the beginning of the address, and the street suffix
   *  can be at the end of the address, but they are not always present.
   *  The street name can be one or more words, and it can contain spaces.
   */
  private function parseAddress($address): array
  {
    $addressFields = $streetParts = [];
    $prefixTypes = ['E','N','NE','NW','S','SE','SW','W'];
    /** @noinspection SpellCheckingInspection */
    $streetTypes = ['Aly','Ave','Bch','Blvd','Byp','Cir','Crst','Ct','Dr',
      'Ext','Grns','Hts','Hwy','Lk','Ln','Lndg','Loop','Park','Path','Pkwy',
      'Pl','Plz','Pt','Rd','Rdg','Sq','St','Ter','Trce','Trl','Walk','Way',
      'Brg','Ctr','Groun','Pne','Run','Vlg','Vw','Clb','Cor','Crk','Ests',
      'Expy','Flds','Flts','Frst','Fry','Gln','Grn','Hl','Hls','Holw','Isle',
      'Jct','Knl','Mdws','Mews','Ml','Mnr','Pass','Pnes','Riv','Rnch','Row',
      'Rst','Spg','Spur','Trak','Vis','Xing'];
    /** @noinspection SpellCheckingInspection */
    $aptTypes = ['#','Apt','Bldg','Bsmt','Condo','Dept','Fl','Frnt',
      'Hillsb','Lot','Lowr','Map','McMinn','Near','Ofc','Ph','Pier','Portla',
      'Rear','Rm','Side','Slip','Spc','Ste','Tigard','Trlr','Unit','Uppr',
      '& Hywy','Area','Ashlan','Attn','Baker ','Behind','Blk','Block',
      'Cut Of','Grade','Hngr','Horsec','Joseph','La Gra','Lafaye','Lbby',
      'Lithia','Lots','Madras','Medfor','Mile','Mile M','Mile P','Miles',
      'Near M','No','Off','Over','Parkda','Range','Schoon','Stop','Townsh',
      'Ms','Fairvi','Up Meh','Ship','Troutd','Sandy','Tyson','Msc','Schoen',
      'Shipst','Mcs','Lund F','Mehlin','Corrad','Kenna','Waterf','Estaca',
      'Reed C','Gresha','Studio','& Hals','Around','Mscn','Villa ','At Or ',
      'Kenna ','Christ','Albany','Brooki','Dock','Drain','Eugene','In Car',
      'Langlo','Loft','On Mai','Oregon','Rosebu','Scio','Spring','Tax',
      'Taxlot','Winche','Winsto','Box','Canby','Gervai','Linn O','Milwau',
      'Molall','Near S','Newpor','Otis','Rsc','Salem','Salem ','Sherid',
      'Stayto'];
    $fields = explode(' ', $address);
    // In the beginning, look for the street number and prefix
    $partIndex = 0;
    $addressFields['streetNo'] = NULL;
    if(is_numeric($fields[$partIndex])) {
      $addressFields['streetNo'] = $fields[$partIndex];
      $partIndex++;
    }
    $addressFields['streetPrefix'] = NULL;
    if(in_array($fields[$partIndex], $prefixTypes)) {
      $addressFields['streetPrefix'] = $fields[$partIndex];
      $partIndex++;
    }
    // At the end look for the apartment type and number.
    $partEnd = count($fields)-1;
    $addressFields['aptNo'] = $addressFields['aptType'] = NULL;
    if(($partEnd-$partIndex)>2) {
      if(in_array($fields[$partEnd], $aptTypes)) {
        $addressFields['aptType'] = $fields[$partEnd];
        $partEnd--;
      } elseif (in_array($fields[$partEnd-1], $aptTypes)) {
        $addressFields['aptType'] = $fields[$partEnd-1];
        $addressFields['aptNo'] = $fields[$partEnd];
        $partEnd = $partEnd-2;
      }
    }
    
    $addressFields['streetSuffix'] = NULL;
    if($partEnd-$partIndex>0) {
      if(in_array($fields[$partEnd], $prefixTypes)) {
        $addressFields['streetSuffix'] = $fields[$partEnd];
        $partEnd--;
      }
    }
    
    $addressFields['streetType'] = NULL;
    if($partEnd-$partIndex>0) {
      if(in_array($fields[$partEnd], $streetTypes)) {
        $addressFields['streetType'] = $fields[$partEnd];
        $partEnd--;
      }
    }
    for ($index = $partIndex; $index <= $partEnd; $index++) {
      $streetParts[] = $fields[$index];
    }
    $streetName = implode(' ', $streetParts);
    $addressFields['streetName'] = $streetName;
    return $addressFields;
  }
  
  /**
   * getApiVoter
   *
   * @param $vanid
   * @return array - array of voter information.
   *
   * This function retrieves the voter information from the API using the VANID.
   * It returns an array with the following keys:
   * vanid, firstName, lastName, nickname, dateOfBirth, age, party, sex,
   * homePhone, cellPhone, preferredPhoneType, address, votingHistory.
   * The address is an array with the following keys:
   * city, streetName, streetPrefix, streetNo, mZip, mCity, mAddress,
   * precinct, hd, county, cd.
   * The votingHistory is a string with the voting history of the voter.
   * The voting history is in the format: "G2020:@Y P2020:@N G2018:@Y P2018:@N".
   * The format is: "TypeYear:@Participation", where Type is G for General and P for Primary,
   * Year is the two-digit year, and Participation is @Y for Yes and@NforNo.
   *
   */
  public function getApiVoter($vanid): array
  {
    $committeeKey = $this->stateCommitteeKey;
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $expandOptions = '?$expand=phones,emails,addresses,codes,districts,electionRecords';
    $url = "https://$user:$apiKey|0@$apiURL/people/$vanid$expandOptions";
    try {
      $request = $this->client->get($url);
      $result = json_decode($request->getBody(), true);
    } catch (Exception $e) {
      nlp_debug_msg('getApiVoter',$e->getMessage());
      return [];
    }
    $voterAddress = $voter = [];
    $voter['vanid'] = $result['vanId'];
    $voter['firstName'] = $result['firstName'];
    $voter['lastName'] = $result['lastName'];
    $voter['nickname'] = $result['nickname'];
    $dateOfBirth = $result['dateOfBirth'];
    $birthDay = explode("T", $dateOfBirth);
    $birthDate = explode("-", $birthDay[0]);
    $birthDate['year'] = $birthDate[0];
    $birthDate['month'] = $birthDate[1];
    $birthDate['day'] = $birthDate[2];
    // get age from date or birthdate
    $birthTime = mktime(0, 0, 0, $birthDate['month'], $birthDate['day'], $birthDate['year']);
    $birthUDate = date("U", $birthTime);
    $age = (date("md", $birthUDate) > date("md")
      ? ((date("Y") - $birthDate['year']) - 1)
      : (date("Y") - $birthDate['year']));
    $voter['age'] = $age;
    $voter['party'] = $result['party'];
    $voter['sex'] = $result['sex'];
    $voterAddress['city'] = $voterAddress['streetName'] = $voterAddress['streetPrefix'] =  $voterAddress['streetNo'] = NULL;
    $voterAddress['mZip'] = $voterAddress['mCity'] = $voterAddress['mAddress'] = NULL;
    if(!empty($result['addresses'])) {
      foreach ($result['addresses'] as $address) {
        $type = $address['type'];
        switch ($type) {
          case 'Voting':
            $address1 = $address['addressLine1'];
            $addressFields = $this->parseAddress($address1);
            foreach ($addressFields as $addressKey => $addressValue) {
              $voterAddress[$addressKey] = $addressValue;
            }
            $voterAddress['city'] = $address['city'];
            $voterAddress['addressLine1'] = $address['addressLine1'];
            $voterAddress['addressLine2'] = $address['addressLine2'];
            $voterAddress['zip'] = $address['zipOrPostalCode'];
            break;
          case 'Mailing':
            $voterAddress['mAddress'] = $address['addressLine1'];
            $voterAddress['mCity'] = $address['city'];
            $voterAddress['mState'] = $address['stateOrProvince'];
            $voterAddress['mZip'] = $address['zipOrPostalCode'];
            break;
        }
      }
    }
    
    $voter['homePhone'] = $voter['cellPhone'] = $voter['preferredPhoneType'] = NULL;
    if(!empty($result['phones'])) {
      foreach ($result['phones'] as $phone) {
        $phoneType = $phone['phoneType'];
        $deviceType = $phone['deviceType'];
        $displayType = 'Skip';
        if($deviceType == 'Cell') {
          $displayType = 'Cell';
        } elseif ($phoneType == 'Personal (Home)') {
          $displayType = 'Home';
        }
        switch ($displayType) {
          case 'Home':
            if($phone['isPreferred']) {
              $voter['homePhone'] = $phone['phoneNumber'];
              $voter['homePhoneId'] = $phone['phoneId'];
              $voter['preferredPhoneType'] = 'H';
            } else {
              if(empty($voter['homePhone'])) {
                $voter['homePhone'] = $phone['phoneNumber'];
                $voter['homePhoneId'] = $phone['phoneId'];
              }
            }
            break;
          case 'Cell':
            if($phone['isPreferred']) {
              $voter['cellPhone'] = $phone['phoneNumber'];
              $voter['cellPhoneId'] = $phone['phoneId'];
              $voter['smsOptInStatus'] = $phone['smsOptInStatus'];
              $voter['preferredPhoneType'] = 'C';
            } else {
              if(empty($voter['cellPhone'])) {
                $voter['cellPhone'] = $phone['phoneNumber'];
                $voter['cellPhoneId'] = $phone['phoneId'];
                $voter['smsOptInStatus'] = $phone['smsOptInStatus'];
              }
            }
            break;
        }
      }
    }
    
    $voterAddress['precinct'] = $voterAddress['hd'] = $voterAddress['county'] = $voterAddress['cd'] = NULL;
    if(!empty($result['districts'])) {
      foreach ($result['districts']as $district) {
        $districtType = $district['name'];
        switch ($districtType) {
          case 'Congressional':
            $field = $district['districtFieldValues'][0];
            $voterAddress['cd'] = $field['name'];
            break;
          case 'County':
            $field = $district['districtFieldValues'][0];
            $voterAddress['county'] = $field['name'];
            break;
          case 'Precinct':
            $field = $district['districtFieldValues'][0];
            $voterAddress['precinct'] = $field['name'];
            break;
          case 'State House':
            $field = $district['districtFieldValues'][0];
            $voterAddress['hd'] = $field['name'];
            break;
        }
      }
    }
    $voter['address'] = $voterAddress;
    $voting = '';
    $electionArray =  $this->findVotingRecord($result['electionRecords']);
    foreach ($electionArray as $election) {
      $voting .= $election['type'].$election['year'].':'.$election['participation'].' ';
    }
    $voter['votingHistory'] = $voting;
    return $voter;
  }
  
  
  /**
   * findVotingRecord
   *
   * @param $electionRecords
   * @return array - array of the most recent voting records.
   *
   * This function finds the most recent voting records for the voter.
   * It returns an array with the following keys:
   * type, year, participation.
   * The type is G for General and P for Primary,
   * the year is the two-digit year, and participation is @Y for Yes and @N for No.
   * It looks back at the last two general elections and the last two primaries, starting with
   * the most recent.  This helps identify inconsistent voters.
   *
   * The @ is used for a search to add color to the voting history.
   */
  private function findVotingRecord($electionRecords): array
  {
    $config = Drupal::service('config.factory')->get('nlpservices.configuration');
    $electionDates = $config->get('nlpservices-election-configuration');
    $cycle = $electionDates['nlp_election_cycle'];
    list($year,$month,$type) = explode('-',$cycle);
    // For a general election in an even year, look back starting with the primary else look back starting with the
    // previous general election.
    if($year % 2 == 0){
      switch ($type) {
        case 'G':
          $general = FALSE;  // Show previous primary.
          break;
        case 'P':
          $year = $year-2;  // Show the previous general.
          $general = TRUE;
          break;
        default: // U.
          if($month>10) { // Late special election.
            $general = FALSE;  // Show this year's primary.
          } else {
            $year = $year-2;
            $general = TRUE;
          }
          break;
      }
    } else {  // Odd year.
      $general = TRUE;  // Show general election from the previous even year.
      $year--;
    }
    $twoDigitYear = substr($year,-2);
    $electionArrays = [];

    if($general) {
      $electionArrays[$year . '1'] = ['type' => 'G', 'year' => $twoDigitYear, 'participation' => '@N'];
    }
    $electionArrays[$year.'0'] = ['type'=>'P','year'=>$twoDigitYear,'participation'=>'@N'];
    $year = $year-2;
    $electionArrays[$year.'1'] = ['type'=>'G','year'=>$twoDigitYear-2,'participation'=>'@N'];
    $electionArrays[$year.'0'] = ['type'=>'P','year'=>$twoDigitYear-2,'participation'=>'@N'];
    if(!$general) {
      $year = $year-2;
      $electionArrays[$year.'1'] = ['type'=>'G','year'=>$twoDigitYear-4,'participation'=>'@N'];
    }
    
    $mostRecent = [];
    foreach ($electionRecords as $election) {
      $electionRecordType = $election['electionRecordType'];
      list($year,$electionType) = explode('-', $electionRecordType);
      $year = trim($year);
      $electionType = trim($electionType);
      if($year < 2008) {continue;}  // Too old.
      $type = substr($electionType,0,1);
      $twoDigitYear = substr($year,-2);
      if($type == "G" OR $type == "P") {
        $electionTypeCode = ($type == "G")?1:0;
        $electionIndex = $year.$electionTypeCode;
        $electionArrays[$electionIndex]['type'] = $type;
        $electionArrays[$electionIndex]['year'] = $twoDigitYear;
        $electionArrays[$electionIndex]['participation'] = (!empty($election['participation']))?'@Y':'@N';
      }
    }
    krsort($electionArrays);
    if(empty($electionArrays)) {return [];}
    $yearCount = 0;
    foreach($electionArrays as $electionIndex => $electionArray) {
      $mostRecent[$electionIndex] = $electionArray;
      if ($yearCount++ == 3) {break;}
    }
   
    return $mostRecent;
  }
  
  /**
   * getVoters
   *
   * @param $listVoters - array of VANIDs.
   * @return array - array of voter information.
   *
   * This function retrieves the voter information for a list of VANIDs from the VoterFile.
   * It returns an array with the following keys:
   * vanid, firstName, lastName, nickname, dateOfBirth, age, party
   */
  public function getVoters($listVoters): array
  {
    $state = $this->nlpConfigurationObj->getStateName();
    $voters = [];
    foreach ($listVoters as $vanid) {
  
      $voter = $this->getApiVoter($vanid);
      if(empty($voter)) {
        nlp_debug_msg('Something is wrong.  Contact NLP Support,  VANID: '.$vanid, '');
        return [];
      }
      // If the nickname field exists, and it has a value for this voter, use it.
      if(empty($voter['nickname'])) {
        $voter['nickname'] = $voter['firstName'];
      }
      
      // Remove leading zeros from HD number.
      $voter['address']['hd'] = ltrim($voter['address']['hd'],'0');
      
      // For Oregon, remove the county from the precinct name.
      if($state == 'Oregon') {
        $voterPrecinct = $voter['address']['precinct'];
        $precinctParts = explode('-', $voterPrecinct);
        if(!empty($precinctParts[1])) {
          $precinctName =  ltrim($precinctParts[1],"0");
        } else {
          $precinctName =  $precinctParts[0];
        }
      } else {
        $precinctName = str_replace(' ', '', $voter['address']['precinct']); // Remove blanks.
      }
      $voter['address']['precinct'] = $precinctName;
      
      // Protect against the space in Hood River.
      if(!empty($voter['address']['county'])) {
        $voter['address']['county'] = str_replace(' ', '_', $voter['address']['county']); // Remove blanks.
      }
      $voters[$voter['vanid']] = $voter;
    }
    
    return $voters;
  }
  
  /**
   * voterExists
   *
   * @param $vanid - The VANID of the voter to check.
   * @return bool - TRUE if the voter exists, FALSE otherwise.
   *
   * This function checks if a voter exists in the VoterFile by their VANID.
   * It returns TRUE if the voter exists, FALSE otherwise.
   * @noinspection PhpUnused
   */
  public function voterExists($vanid): bool
  {
    $apiKey = $this->stateCommitteeKey['API Key'];
    $apiURL = $this->stateCommitteeKey['Url'];
    $user = $this->stateCommitteeKey['App Name'];
    
    $url = 'https://'.$user.':'.$apiKey.'|0@'.$apiURL.'/people/'.$vanid;
    try {
      $this->client->get($url);  // Catch exception if a voter does not exist in the database.
    } catch (Exception $e) {
      $code = $e->getCode();
      if($code == 404) {return FALSE;}
      nlp_debug_msg('voterExists',$e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * @param $address
   * @return array
   */
  public function getVotersByAddressOld(string $address, string $county=''): array
  {
    if(!empty($county)) {
      $countyCommitteeKey = $this->nlpConfigurationObj->getCountyKey( $county );
      nlp_debug_msg('$countyCommitteeKey',$countyCommitteeKey);;
      $apiKey = $countyCommitteeKey['API Key'];
      $apiURL = $countyCommitteeKey['Url'];
      $user = $countyCommitteeKey['App Name'];
    } else {
      $apiKey = $this->stateCommitteeKey['API Key'];
      $apiURL = $this->stateCommitteeKey['Url'];
      $user = $this->stateCommitteeKey['App Name'];
    }
    
    $searchAddressObj = new stdClass();
    foreach ($this::VOTER_SEARCH_FIELDS as $field) {
      if(!empty($address[$field])) {
        $searchAddressObj->$field = $address[$field];
      }
    }
    //$searchAddressObj->type = 'Voting';
    $searchObj = new stdClass();
    $searchObj->vanId = 1764176;
    $searchObj->addresses = [$searchAddressObj];
    
    $dataJson = json_encode($searchObj);
    //nlp_debug_msg('$body',$dataJson);
    
    $postUrl = "https://$user:$apiKey|0@$apiURL/people/find";
    nlp_debug_msg('$postUrl',$postUrl);
    
    $headers = ['Content-Type' => 'application/json', "Accept: application/json, Expect:"];
    //nlp_debug_msg('$headers', $headers);
    try {
      $response = $this->client->post($postUrl, ['headers' => $headers, 'body' => $dataJson,]);
    } catch (Exception $e) {
      nlp_debug_msg('getVotersByAddress', $e->getMessage());
      return [];
    }
    $body = (string) $response->getBody();
    //nlp_debug_msg('$body',$body);
    //$dataObj = json_decode($body);
    return [];
  }
  
  
  public function getVotersByAddress(array $address, string $county=''): array
  {
    $committeeKey = (empty($county))?$this->stateCommitteeKey:$this->nlpConfigurationObj->getCountyKey($county);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    
    $baseUrl = "https://$user:$apiKey|0@$apiURL";
    $url = "$baseUrl/people?"
      . "streetAddress=" . urlencode($address['addressLine1'])
      . "&city=" . urlencode($address['city']);
      //. "&stateOrProvince=" . urlencode($address['stateOrProvince']);
    //nlp_debug_msg('$url',$url);;
    //$post_url = 'https://'.$user.':'.$apiKey.'|1'.'@'.$apiURL.'/people/'.$mcid.$expandOptions;
    try {
      $request = $this->client->get($url);
      $results = json_decode($request->getBody(), true);
    } catch (Exception $e) {
      $code = $e->getCode();
      if($code == 404) {return [];}
      if($code==401) {
        return ['Invalid key'];
      }
      nlp_debug_msg('nlApiCall',$e->getMessage());
      return [];
    }
    //nlp_debug_msg('$results',$results);
    if(empty($results)) {return [];}
    return $results;
  }
}
