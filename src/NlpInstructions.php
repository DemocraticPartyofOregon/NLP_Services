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
   * @param $req
   * @return void
   */
  public function createInstructions($req): void
  {
    $originalFileName = (!empty($req['originalFileName']))?$req['originalFileName']:NULL;
    try {
      $this->connection->merge(self::INSTRUCTIONS_TBL)
        ->keys(array(
          'county' => $req['county'],
          'type' => $req['type'],))
        ->fields(array(
          'fileName' => $req['fileName'],
          'originalFileName' => $originalFileName,
          ))
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createInstructions', $e->getMessage() );
      return;
    }
  }
  
  /**
   * @param $county
   * @return array[]
   */
  public function getInstructions($county): array
  {
    $rec = [];
    foreach ($this->instructionsList as $key) {
      $rec[$key] = '';
    }
    $instructs = [
      'canvass'=>$rec,
      'postcard'=>$rec,
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
    do {
      $instruct = $result->fetchAssoc();
      if(empty($instruct)) {break;}
      $instructs[$instruct['type']] = $instruct;
    } while (TRUE);
    return $instructs;
  }
  
}
