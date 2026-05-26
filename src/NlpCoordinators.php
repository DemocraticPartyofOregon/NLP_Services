<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpCoordinators {
  
  public const string COORDINATOR_TBL = "nlp_coordinator";
  
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
   * createCoordinator
   *
   * Create a coordinator in the database.
   * The coordinator is identified by the county, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param array $req
   * @return void
   */
  public function createCoordinator(array $req): void
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
   * getCoordinators
   *
   * Get all the assigned coordinators for a county.
   *
   * @param string $county
   * @return array
   */
  public function getCoordinators( string $county ): array
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
      $coordinators[$coordinator['cIndex']] = $coordinator;
    } while (TRUE);
    return $coordinators;
  }
  
  /**
   * deleteCoordinator
   *
   * Delete a coordinator from the database.
   * The coordinator is identified by the unique index.
   *
   * @param int $cIndex
   * @return void
   */
  public function deleteCoordinator( int $cIndex ): void
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
   * getCoordinator
   *
   * Get the coordinator for a county/hd/precinct.
   * The coordinator is identified by the county, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param array $region - array with keys 'county', 'pct', 'hd'
   * @return array - the coordinator for the NL based on the county/hd/precinct of the NL.
   */
  public function getCoordinator( array $region ): array
  {
    return $this->getAssignedCoordinator([
      'county'   => $region['county'],
      'hd'       => $region['hd'],
      'precinct' => $region['pct'],
    ]);
  }

  /**
   * getAssignedCoordinator
   *
   * Get the coordinator for a county/hd/precinct.
   * The coordinator is identified by the county, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param array $nls - array with keys 'county', 'hd', 'precinct'
   * @return array - the coordinator assigned to the NL's county/hd/precinct.
   */
  public function getAssignedCoordinator( array $nls ): array
  {
    $hd = $nls['hd'];
    $precinct = $nls['precinct'];
    $county = $nls['county'];

    $coordinatorsInCounty = $this->getAllCoordinators($county);
    if(empty($coordinatorsInCounty)) {return [];}
    if(empty($coordinatorsInCounty[$county])) {
      return [];
    }
    $countyCoordinator = $coordinatorsInCounty[$county];
    $coordinator = [];
    if(isset($countyCoordinator['pct'][$precinct])) {
      $coordinator = $countyCoordinator['pct'][$precinct];
    } elseif(isset($countyCoordinator['hd'][$hd])) {
      $coordinator = $countyCoordinator['hd'][$hd];
    } elseif(isset($countyCoordinator['county'])) {
      $coordinator = $countyCoordinator['county'];
    }
    return $coordinator;
  }

  /**
   * duplicateScope
   *
   * Check to see if the region already has a coordinator assigned.
   * If so, return TRUE.
   * If not, return FALSE.
   *
   * @param string $scope
   * @param string $value
   * @param array $countyCoordinators
   * @return bool - returns true if the region already has a coordinator assigned.
   */
  public function duplicateScope(string $scope, string $value, array $countyCoordinators): bool
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
   * getAllCoordinators
   *
   * Get all the coordinators in the database.
   * If the county is provided, only return coordinators for that county.
   *
   * @param string $county - county name.
   * @return array - array of all the known coordinators.
   */
  public function getAllCoordinators(string $county=''): array
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
      $rowCounty = $coordinator['county'];
      $scope = $coordinator['scope'];
      switch ($scope) {
        case 'precinct':
          $precincts = explode(',',$coordinator['precinctList']);
          foreach ($precincts as $precinct) {
            if(!isset($coordinators[$rowCounty]['pct'][$precinct])) {
              $coordinators[$rowCounty]['pct'][$precinct] = $coordinator;
            }
          }
          break;
        case 'hd':
          $hd = $coordinator['hd'];
          if(!isset($coordinators[$rowCounty]['hd'][$hd])) {
            $coordinators[$rowCounty]['hd'][$hd] = $coordinator;
          }
          break;
        case 'county':
          if(!isset($coordinators[$rowCounty]['county'])) {
            $coordinators[$rowCounty]['county'] = $coordinator;
          }
          break;
      }
    } while (TRUE);
    
    return $coordinators;
  }

  /**
   * getCoordinatorById
   *
   * Get the coordinator for a county/hd/precinct.
   * The coordinator is identified by the county, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param int $mcid - the mcid of the NL.
   * @return array - the coordinator for the NL based on the county/hd/precinct of the NL.
   */
  public function getCoordinatorById( int $mcid ): array
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
