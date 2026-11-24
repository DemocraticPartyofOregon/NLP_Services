<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
//use Drupal\Core\Entity\EntityStorageException;
//use Drupal\Core\File\FileSystemInterface;
use Drupal\nlpservices\AwakeSalutation;
use Drupal\nlpservices\ApiExportJobs;
use Drupal\nlpservices\NlpAwards;
//use Drupal\user\Entity\User;
//use Exception;
//use Symfony\Component\HttpFoundation;
//use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AwakeController extends ControllerBase {
  
  //const TURF_SYNC_BATCH_SIZE = 3;
  protected AwakeSalutation $salutation;
  protected ApiExportJobs $exportJobs;
  protected NlpAwards $awardObj;
  
  
  public function __construct(AwakeSalutation $salutation, ApiExportJobs $exportJobs, NlpAwards $awardObj) {
    $this->salutation = $salutation;
    $this->exportJobs = $exportJobs;
    $this->awardObj = $awardObj;
    
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): AwakeController
  {
    return new static(
      $container->get('nlpservices.awake'),
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.awards'),
    
    );
  }
  
  public function awake(): array
  {
    //$messenger = Drupal::messenger();
    
    //$turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    $resyncTurfObj = Drupal::getContainer()->get('nlpservices.resync_turf');
    
    $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    $committeesWithTurfs = $turfsObj->getCommitteesWithTurfs();
    nlp_debug_msg('$committeesWithTurfs',$committeesWithTurfs);
    
    $committee = "Jefferson";
    
    $chunks = $resyncTurfObj->turfsToResyncCheck($committee);
    nlp_debug_msg('$chunks',$chunks);
    
    $output = $this->salutation->getSalutation();
    Drupal::service("page_cache_kill_switch")->trigger();
    return [
      //'#markup' => $page,
      '#markup' => $output,
      
      //'#markup' => $this->salutation->getSalutation(),
    ];
    
  }
  
}
