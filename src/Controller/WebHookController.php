<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

//use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\ApiExportJobs;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WebHookController extends ControllerBase
{
  protected ApiExportJobs $exportJobsObj;
  
  public function __construct($exportJobsObj) {
    $this->exportJobsObj = $exportJobsObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): WebHookController
  {
    return new static(
      $container->get('nlpservices.export_jobs'),
    );
  }
  
  /**
   * webhook_callback
   *
   * This function is called when a GET is received for the URL 'nlp-webhook-callback'.  It
   * signals that an export job has completed.  The URL contains the eventId to identify the
   * export job.
   *
   * @return array
   */
  public function webhook_callback(): array
  {
    $eventId = $_GET['eventId'];
    //$nice = 'webhook_callback: eventId=' . $eventId;
    //Drupal::logger('nlpservices')->notice($nice);
    $eventId = htmlentities($eventId, ENT_QUOTES);
    $this->exportJobsObj->endExportJob($eventId);
    return [
      '#markup' => 'ok',
    ];
  }
}
