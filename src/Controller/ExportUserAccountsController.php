<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpExportUserAccounts;
use Drupal\nlpservices\NlpExportCredentials;
use Drupal\nlpservices\DrupalUser;

/**
 * @noinspection PhpUnused
 *  used in route.
 */
class ExportUserAccountsController extends ControllerBase
{
  protected NlpExportUserAccounts $exportUserAccountsObj;
  protected NlpExportCredentials $exportCredentialsObj;
  protected DrupalUser $drupalUserObj;

  public function __construct( $exportUserAccountsObj, $exportCredentialsObj, $drupalUserObj) {
    $this->exportUserAccountsObj = $exportUserAccountsObj;
    $this->exportCredentialsObj = $exportCredentialsObj;
    $this->drupalUserObj = $drupalUserObj;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ExportUserAccountsController
  {
    return new static(
      $container->get('nlpservices.export_user_accounts'),
      $container->get('nlpservices.export_credentials'),
      $container->get('nlpservices.drupal_user'),
    );
  }
  
  /**
   * @noinspection PhpUnused
   * used in route.
   */
  public function createUserAccountsFile(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $display['email_status'] = [
      '#markup' => $this->exportUserAccountsObj->getUserAccountsFile(),
    ];
    $display['button_link'] = [
      '#markup' => '<p></p><a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a></p>',
    ];
    return $display;
    
  }
  
  /**
   * @noinspection PhpUnused
   * used in route.
   */
  public function createNlCredentialsFile(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $display['email_status'] = [
      '#markup' => $this->exportCredentialsObj->createNlCredentialsFile(),
    ];
    $display['button_link'] = [
      '#markup' => '<p></p><a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a></p>',
    ];
    return $display;
    
  }
  
  /**
   * @noinspection PhpUnused
   * used in route.
   */
  public function purgeNlCredentials(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $this->drupalUserObj->purgeNlUsers();
    $display['email_status'] = [
      '#markup' => "<p>The login accounts for NLs have been removed.</p>",
    ];
    $display['button_link'] = [
      '#markup' => '<p></p><a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a></p>',
    ];
    return $display;
  }
  
}
