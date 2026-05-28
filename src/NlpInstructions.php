<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpInstructions {
  
  const string INSTRUCTIONS_TBL = "nlp_instructions";
 
  private array $instructionsList = ['county','type','fileName','originalFileName', ];
  
  protected Connection $connection;
  
  public function __construct( $connection) {
    $this->connection = $connection;
  }
  
  public static function create(ContainerInterface $container): NlpInstructions
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * createInstructions
   *
   * Creates or updates an instruction entry in the database.
   *
   * @param array $req An associative array containing the required fields:
   *                    - 'county' (string): The county associated with the instruction.
   *                    - 'type' (string): The type of the instruction.
   *                    - 'fileName' (string): The name of the file to be recorded.
   *                    - 'originalFileName' (string|null): The original name of the file, if applicable.
   * @return void
   */
  public function createInstructions( array $req ): void
  {
    $originalFileName = $req['originalFileName'] ?? null;
    try {
      $this->connection->merge(self::INSTRUCTIONS_TBL)
        ->keys([
          'county' => $req['county'],
          'type' => $req['type'],
        ])
        ->fields([
          'fileName' => $req['fileName'],
          'originalFileName' => $originalFileName,
        ])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createInstructions', $e->getMessage() );
      return;
    }
  }

  /**
   * getInstructions
   *
   * Retrieves instructions for a given county from the database.
   *
   * @param string $county The county for which to retrieve instructions.
   * @return array An associative array containing the instructions for 'canvass' and 'postcard' types, or an empty array if an error occurs.
   */
  public function getInstructions( string $county ): array
  {
    $rec = [];
    foreach ($this->instructionsList as $key) {
      $rec[$key] = '';
    }
    $instructs = [
      'canvass' => $rec,
      'postcard' => $rec,
    ];
    $query = $this->connection->select(self::INSTRUCTIONS_TBL, 'i');
    $query->fields('i');
    $query->condition('county',$county);
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getInstructions', $e->getMessage());
      return [];
    }
    while ($instruct = $result->fetchAssoc()) {
      $instructs[$instruct['type']] = $instruct;
    }
    return $instructs;
  }
  
}
