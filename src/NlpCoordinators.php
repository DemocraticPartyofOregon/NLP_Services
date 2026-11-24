<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpCoordinators {
  
  public const string COORDINATOR_TBL = "nlp_coordinator";
  
  protected Connection $connection;
  
  public function __construct(Connection $connection) {
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
   * The coordinator is identified by the committee, hd, and precinct.
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
      nlp_debug_msg('createCoordinator', $e);
      return;
    }
  }
  
  /**
   * getCoordinators
   *
   * Get all the assigned coordinators for a committee.
   *
   * @param string $committee
   * @return array
   */
  public function getCoordinators( string $committee ): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    $query->condition('county',$committee);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCoordinators', $e);
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
      nlp_debug_msg('deleteCoordinator', $e);
      return;
    }
  }
  
  /**
   * getCoordinator
   *
   * Get the coordinator for a committee/hd/precinct.
   * The coordinator is identified by the committee, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param array $location - array with keys 'county', 'pct', 'hd'
   * @return array - the coordinator for the NL based on the committee/hd/precinct of the NL.
   */
  public function getCoordinator( array $location ): array
  {
    return $this->getAssignedCoordinator([
      'county'   => $location['county'],
      'hd'       => $location['hd'],
      'precinct' => $location['pct'],
    ]);
  }

  /**
   * getAssignedCoordinator
   *
   * Get the coordinator for a committee/hd/precinct.
   * The coordinator is identified by the committee, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param array $nls - array with keys 'county', 'hd', 'precinct'
   * @return array - the coordinator assigned to the NL's committee/hd/precinct.
   */
  public function getAssignedCoordinator( array $nls ): array
  {
    $hd = $nls['hd'];
    $precinct = $nls['precinct'];
    $committee = $nls['county'];

    $coordinatorsInCommittee = $this->getAllCoordinators($committee);
    if(empty($coordinatorsInCommittee)) {return [];}
    if(empty($coordinatorsInCommittee[$committee])) {
      return [];
    }
    $committeeCoordinator = $coordinatorsInCommittee[$committee];
    $coordinator = [];
    if(isset($committeeCoordinator['pct'][$precinct])) {
      $coordinator = $committeeCoordinator['pct'][$precinct];
    } elseif(isset($committeeCoordinator['hd'][$hd])) {
      $coordinator = $committeeCoordinator['hd'][$hd];
    } elseif(isset($committeeCoordinator['county'])) {
      $coordinator = $committeeCoordinator['county'];
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
   * @param array $committeeCoordinators
   * @return bool - returns true if the region already has a coordinator assigned.
   */
  public function duplicateScope(string $scope, string $value, array $committeeCoordinators): bool
  {
    switch ($scope) {
      case 'county':
        if(!empty($committeeCoordinators['county'])) {return TRUE;}
        break;
      case 'hd':
        if(!empty($committeeCoordinators['hd'][$value])) {return TRUE;}
        break;
      case 'precinct':
        if(!empty($committeeCoordinators['pct'][$value])) {return TRUE;}
        break;
    }
    return FALSE;
  }
  
  /**
   * getAllCoordinators
   *
   * Get all the coordinators in the database.
   * If the committee is provided, only return coordinators for that committee.
   *
   * @param string $committee - committee name.
   * @return array - array of all the known coordinators.
   */
  public function getAllCoordinators(string $committee=''): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    if(!empty($committee)) {
      $query->condition('county',$committee);
    }
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getAllCoordinators', $e);
      return [];
    }
    
    $coordinators = [];
    do {
      $coordinator = $result->fetchAssoc();
      if(empty($coordinator)) {break;}
      $coordinators[$coordinator['cIndex']] = $coordinator;
      $rowCommittee = $coordinator['county'];
      $scope = $coordinator['scope'];
      switch ($scope) {
        case 'precinct':
          $precincts = explode(',',$coordinator['precinctList']);
          foreach ($precincts as $precinct) {
            if(!isset($coordinators[$rowCommittee]['pct'][$precinct])) {
              $coordinators[$rowCommittee]['pct'][$precinct] = $coordinator;
            }
          }
          break;
        case 'hd':
          $hd = $coordinator['hd'];
          if(!isset($coordinators[$rowCommittee]['hd'][$hd])) {
            $coordinators[$rowCommittee]['hd'][$hd] = $coordinator;
          }
          break;
        case 'county':
          if(!isset($coordinators[$rowCommittee]['county'])) {
            $coordinators[$rowCommittee]['county'] = $coordinator;
          }
          break;
      }
    } while (TRUE);
    
    return $coordinators;
  }

  /**
   * getCoordinatorById
   *
   * Get the coordinator for a committee/hd/precinct.
   * The coordinator is identified by the committee, hd, and precinct.
   * The coordinator is assigned a unique index.
   *
   * @param int $mcid - the mcid of the NL.
   * @return array - the coordinator for the NL based on the committee/hd/precinct of the NL.
   */
  public function getCoordinatorById( int $mcid ): array
  {
    $query = $this->connection->select(self::COORDINATOR_TBL, 'c');
    $query->fields('c');
    $query->condition('mcid',$mcid);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCoordinatorById', $e);
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
