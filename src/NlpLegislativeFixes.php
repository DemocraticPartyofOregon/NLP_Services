<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpLegislativeFixes
{
  const string LEG_FIX_TBL = 'nlp_legislative_fixes';
  private array $legList = ['county','mcid','firstName','lastName','cd','hd','precinct'];
  
  protected Connection $connection;
  
  public function __construct($connection) {
    $this->connection = $connection;
  }
  
  public static function create(ContainerInterface $container): NlpLegislativeFixes
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * createLegFix
   *
   * Creates or updates a legislative fix entry in the database.
   *
   * @param array $fix An associative array containing the required fields:
   *                    - 'county' (string): The county associated with the legislative fix.
   *                    - 'mcid' (string): The unique identifier for the legislative fix.
   *                    - 'firstName' (string): The first name of the legislator.
   *                    - 'lastName' (string): The last name of the legislator.
   *                    - 'cd' (string): The congressional district associated with the legislator.
   *                    - 'hd' (string): The house district associated with the legislator.
   *                    - 'precinct' (string): The precinct associated with the legislator.
   * @return bool Returns TRUE on success, FALSE on failure.
   */
  public function createLegFix( array $fix ): bool
  {
    $fields = array_filter($fix, function ($key) {
      return in_array($key, $this->legList);
    }, ARRAY_FILTER_USE_KEY);

    try {
      $this->connection->merge(self::LEG_FIX_TBL)
        ->keys(['mcid' => $fix['mcid']])
        ->fields($fields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createLegFix', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * getLegFixes
   *
   * Retrieves all legislative fixes for a given county, ordered by house district, precinct, last name, and first name.
   *
   * @param string $county The county for which to retrieve legislative fixes.
   * @return array An associative array of legislative fixes, keyed by 'mcid'.
   */
  public function getLegFixes( string $county ): array
  {
    try {
      $query = $this->connection->select(self::LEG_FIX_TBL, 'l');
      $query->fields('l');
      $query->condition('county', $county);
      $query->orderBy('hd');
      $query->orderBy('precinct');
      $query->orderBy('lastName');
      $query->orderBy('firstName');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getLegFixes', $e);
      return [];
    }
    $fixes = [];
    while ($fix = $result->fetchAssoc()) {
      $fixes[$fix['mcid']] = $fix;
    }
    return $fixes;
  }

  /**
   * deleteLegFix
   *
   * Deletes a legislative fix entry from the database based on the provided 'mcid'.
   *
   * @param int  $mcid The unique identifier for the legislative fix to be deleted.
   * @return bool Returns TRUE on successful deletion, FALSE on failure.
   */
  public function deleteLegFix( int $mcid ): bool
  {
    try {
      $this->connection->delete(self::LEG_FIX_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteLegFix', $e);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * getLegFix
   *
   * Retrieves a single legislative fix entry from the database based on the provided 'mcid'.
   *
   * @param int $mcid The unique identifier for the legislative fix to be retrieved.
   * @return array An associative array containing the legislative fix details, or an empty array if not found or on error.
   */
  public function getLegFix( int $mcid ): array
  {
    try {
      $query = $this->connection->select(self::LEG_FIX_TBL, 'l');
      $query->fields('l');
      $query->condition('mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getLegFix', $e);
      return [];
    }
    $fix = $result->fetchAssoc();
    if(empty($fix)) {return [];}
    return $fix;
  }
  
}
