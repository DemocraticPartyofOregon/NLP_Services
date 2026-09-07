<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfMapRegions
{
  const string TURF_MAP_REGIONS_TBL = 'nlp_turf_map_regions';

  protected Connection $connection;

  public function __construct( $connection )
  {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpTurfMapRegions
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * updateTurfMapRegion
   *
   * Insert or update the turf map region record for the given map region ID.
   *
   * @param array $fields - Associative array with keys: mapRegionId, mapRegionName,
   *   dateRefreshed, folderId, folderName.
   * @return void
   */
  public function updateTurfMapRegion(array $fields): void
  {
    try {
      $this->connection->merge(self::TURF_MAP_REGIONS_TBL)
        ->keys(['mapRegionId' => $fields['mapRegionId']])
        ->fields([
          'mapRegionName' => $fields['mapRegionName'],
          'dateRefreshed' => $fields['dateRefreshed'],
          'folderId' => $fields['folderId'],
          'folderName' => $fields['folderName'],
        ])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('updateTurfMapRegion', $e->getMessage());
    }
  }

}
