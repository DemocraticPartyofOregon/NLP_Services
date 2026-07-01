<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpDocuments;

class ManageNlsController extends ControllerBase
{
  protected NlpDocuments $documentsObj;
  protected Drupal\nlpservices\NlpSessionData $nlpSessionDataObj;
  
  public function __construct( $nlpSessionDataObj) {
    $this->nlpSessionDataObj = $nlpSessionDataObj;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ManageNlsController
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**  */
  /**
   * @return array
   * @noinspection PhpUnused
   * used in route
   */
  public function displayManageNlsLinks(): array
  {
    $county = $this->nlpSessionDataObj->getCounty();
    nlp_debug_msg('displayManageNlsLinks', "county: $county");
    $page['title'] = ['#markup' => "Manage NLs for $county County"];
    
    $page ['nlFunctions'] = [
      '#theme' => 'item_list',
      '#items' => [
        'First item',
        'Second item',
        'Third item',
        'Fourth item',
        'Fifth item',
      ],
      '#attributes' => [
        'class' => ['my-item-list'],
        'id' => 'nl-item-list',
      ],
    ];
    // Make the entire page dynamic (no render caching).
    /*
    $cacheable = new CacheableMetadata();
    $cacheable->setCacheMaxAge(0);
    $cacheable->applyTo($page);
    */
    Drupal::service("page_cache_kill_switch")->trigger();
    return $page;
  }
  
}


