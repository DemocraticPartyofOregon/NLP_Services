<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpCoordinators {
  
  const string COORDINATOR_TBL = "nlp_coordinator";
  
  protected Connection $connection;
  
  public function __construct($connection) {
    $this->connection = $connection;
  }
  
  public static function create(ContainerInterface $container): NlpCoordinators
  {
    return new static(
      $container->get('database'),
    );
  }
  
  /**
   * @param $req
   * @return void
   */
  public function createCoordinator($req): void
  {
    try {
      $this->connection->insert(self::COORDINATOR_TBL)
      ->fields($req)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createCoordinator',$e->getMessage());
      return;
    }
  }
  
  /**
   * Get all the assigned coordinators for a county.
   *
   * @param $county
   * @return array
   */
  public function getCoordinators($county): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    $query->condition('county',$county);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCoordinators',$e->getMessage());
      return [];
    }
    
    $coordinators = [];
    do {
      $coordinator = $result->fetchAssoc();
      if(empty($coordinator)) {break;}
      //if(empty($coordinator['mcid'])) {continue;}
      $coordinators[$coordinator['cIndex']] = $coordinator;
    } while (TRUE);
    return $coordinators;
  }
  
  /**
   * @param $cIndex
   * @return void
   */
  public function deleteCoordinator($cIndex): void
  {
    try {
      $this->connection->delete(self::COORDINATOR_TBL)
        ->condition('cIndex', $cIndex)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteCoordinator',$e->getMessage());
      return;
    }
  }
  
  /**
   * @param $region
   * @return array - the coordinator for the NL based on the county/hd/precinct of the NL.
   */
  public function getCoordinator($region): array
  {
    $allCoordinators = $this->getAllCoordinators();
    
    if(empty($allCoordinators)) {return [];}
    $county  = $region['county'];
    // If there is a coordinator assigned to the precinct, use that person.  Else
    // chose the house district coordinator.  If there is no HD coordinator,
    // then the county coordinator.  There should be at least one of these.
    // If not, no one will be chosen.
    $precinct = $region['pct'];
    $hd = $region['hd'];
    if(empty($allCoordinators[$county])) {
      return [];  // No one in the county is a coordinator.
    }
    $countyCoordinator = $allCoordinators[$county];
    $coordinator = [];
    if(isset($countyCoordinator['pct'][$precinct])) {
      $coordinator = $countyCoordinator['pct'][$precinct];
    } elseif(isset($countyCoordinator['hd'][$hd])) {
      $coordinator = $countyCoordinator['hd'][$hd];
    } elseif (isset($countyCoordinator['county'])) {
      $coordinator = $countyCoordinator['county'];
    }
    return $coordinator;
  }
  
  public function getAssignedCoordinator($nls): array
  {
    //nlp_debug_msg('$nls',$nls);
    $hd = $nls['hd'];
    $precinct = $nls['precinct'];
    $county = $nls['county'];
    
    $coordinatorsInCounty = $this->getAllCoordinators($county);
    //nlp_debug_msg('coordinatorsInCounty',$coordinatorsInCounty);
    if(empty($coordinatorsInCounty)) {return [];}
    
    if(empty($coordinatorsInCounty[$county])) {
      return [];  // No one in the county is a coordinator.
    }
    $countyCoordinator = $coordinatorsInCounty[$county];
    //nlp_debug_msg('countyCoordinator',$countyCoordinator);
    $coordinator = [];
    //$dp = (!empty($countyCoordinator['pct'][$precinct]))?$countyCoordinator['pct'][$precinct]:'';
    //nlp_debug_msg('precinct '.$precinct,$dp);
    if(isset($countyCoordinator['pct'][$precinct])) {
      $coordinator = $countyCoordinator['pct'][$precinct];
    } elseif(isset($countyCoordinator['hd'][$hd])) {
      $coordinator = $countyCoordinator['hd'][$hd];
    } elseif (isset($countyCoordinator['county'])) {
      $coordinator = $countyCoordinator['county'];
    }
    
    return $coordinator;
  }
  /**
   * @param $scope
   * @param $value
   * @param $countyCoordinators
   * @return bool - returns true if the region already has a coordinator assigned.
   */
  public function duplicateScope($scope,$value,$countyCoordinators): bool
  {
    switch ($scope) {
      case 'county':
        if(!empty($countyCoordinators['county'])) {return TRUE;}
        break;
      case 'hd':
        if(!empty($countyCoordinators['hd'][$value])) {return TRUE;}
        break;
      case 'precinct':
        if(!empty($countyCoordinators['pct'][$value])) {return TRUE;}
        break;
    }
    return FALSE;
  }
  
  /**
   * @return array - array of all the known coordinators.
   */
  public function getAllCoordinators($county=NULL): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    if(!empty($county)) {
      $query->condition('county',$county);
    }
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getAllCoordinators',$e->getMessage());
      return [];
    }
    
    $coordinators = [];
    do {
      $coordinator = $result->fetchAssoc();
      if(empty($coordinator)) {break;}
      $coordinators[$coordinator['cIndex']] = $coordinator;
      $county = $coordinator['county'];
      $scope = $coordinator['scope'];
      switch ($scope) {
        case 'precinct':
          $precincts = explode(',',$coordinator['precinctList']);
          foreach ($precincts as $precinct) {
            if(!isset($coordinators[$county]['pct'][$precinct])) {
              $coordinators[$county]['pct'][$precinct] = $coordinator;
            }
          }
          break;
        case 'hd':
          $hd = $coordinator['hd'];
          if(!isset($coordinators[$county]['hd'][$hd])) {
            $coordinators[$county]['hd'][$hd] = $coordinator;
          }
          break;
        case 'county':
          if(!isset($coordinators[$county]['county'])) {
            $coordinators[$county]['county'] = $coordinator;
          }
          break;
      }
    } while (TRUE);
    
    return $coordinators;
  }
  
  public function getCoordinatorById($mcid): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    $query->condition('mcid',$mcid);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCoordinatorById',$e->getMessage());
      return [];
    }
    
    $coordinators = [];
    do {
      $coordinator = $result->fetchAssoc();
      if(empty($coordinator)) {break;}
      if(empty($coordinator['mcid'])) {continue;}
      $coordinators[$coordinator['cIndex']] = $coordinator;
    } while (TRUE);
    return $coordinators;
  }
  
}
