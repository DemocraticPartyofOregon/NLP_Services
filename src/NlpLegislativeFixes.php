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
   * @param $fix
   * @return bool
   */
  public function createLegFix($fix): bool
  {
    $fields = array();
    foreach ($fix as $key => $value) {
      if (in_array($key,$this->legList)) {
        $fields[$key] = $value;
      } else {
        $fields[$key] = NULL;
      }
    }
    try {
      $this->connection->merge(self::LEG_FIX_TBL)
        ->keys(array('mcid' => $fix['mcid']))
        ->fields($fields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createLegFix', $e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * @param $county
   * @return array
   */
  public function getLegFixes($county): array
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
      nlp_debug_msg('getLegFixes', $e->getMessage());
      return [];
    }
    $fixes = [];
    do {
      $fix = $result->fetchAssoc();
      if (empty($fix)) {
        break;
      }
      $fixes[$fix['mcid']] = $fix;
    } while (TRUE);
    return $fixes;
  }
  
  /**
   * @param $mcid
   * @return bool
   */
  public function deleteLegFix($mcid): bool
  {
    try {
      $this->connection->delete(self::LEG_FIX_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('deleteLegFix', $e->getMessage());
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * @param $mcid
   * @return array
   */
  public function getLegFix($mcid): array
  {
    try {
      $query = $this->connection->select(self::LEG_FIX_TBL, 'l');
      $query->fields('l');
      $query->condition('mcid', $mcid);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getLegFix', $e->getMessage());
      return [];
    }
    $fix = $result->fetchAssoc();
    if(empty($fix)) {return [];}
    return $fix;
  }
  
}
