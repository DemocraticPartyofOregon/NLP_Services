<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpDocuments;

/**
 * @noinspection PhpUnused
 */
class DocumentsController extends ControllerBase
{
  protected NlpDocuments $documentsObj;

  public function __construct( $documentsObj) {
      $this->documentsObj = $documentsObj;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DocumentsController
  {
    return new static(
          $container->get('nlpservices.documents'),
      );
  }

/**
 * @return array
 * @noinspection PhpUnused
 * used in route
 */
  public function displayDocuments(): array
  {
      Drupal::service("page_cache_kill_switch")->trigger();
      return $this->documentsObj->buildDocumentDisplay();
  }

}