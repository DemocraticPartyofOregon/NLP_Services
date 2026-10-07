<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpSessionData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @noinspection PhpUnused */
class CountyManagementController extends ControllerBase {

  protected DrupalUser $drupalUserObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($drupalUserObj, $sessionDataObj, $nlpConfigurationObj) {
    $this->drupalUserObj = $drupalUserObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): CountyManagementController
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * Displays the menu of functions to set up and manage the county.
   *
   * @return array - a render array containing the manage county template.
   * @noinspection PhpUnused
   */
  public function manage_county(): array
  {
    $county = $this->sessionDataObj->getCounty();
    if(empty($county)) {
      $this->messenger()->addWarning($this->t('The county is not specified. Please set the County field in your user login record.'));
    }

    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $template_path = $modulePath . "/src/Templates/manageCounty.html.twig";
    $template = file_get_contents($template_path);
    $variables = [
      'module' => 'nlpservices',
      'county' => $county,
      'isAdmin' => $this->drupalUserObj->isNlpAdminUser(),
      'stateName' => $this->nlpConfigurationObj->getStateName(),
    ];
    return
      [
        'description' => [
          '#type' => 'inline_template',
          '#template' => $template,
          '#context' => $variables,
          '#attached' => ['library' => ['nlpservices/menu-page']],
          // The county is per-session, so the page can't be cached across users.
          '#cache' => ['max-age' => 0],
        ],
      ];
  }

}
