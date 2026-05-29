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

  /**
   * getTurfMapRegion
   *
   * Fetches the turf map region associated with the given map region ID.
   *
   * @param int $mapRegionId - The ID of the map region to retrieve.
   * @return array - Associative array containing the turf map region data,
   *   or an empty array if no record is found or an error occurs.
   */
  public function getTurfMapRegion(int $mapRegionId): array
  {
    try {
      $query = $this->connection->select(self::TURF_MAP_REGIONS_TBL, 'i');
      $query->fields('i');
      $query->condition('mapRegionId', $mapRegionId);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfMapRegion', $e->getMessage());
      return [];
    }

    $turfRecord = $result->fetchAssoc();
    if(empty($turfRecord)) { return []; }
    return $turfRecord;
  }

}
