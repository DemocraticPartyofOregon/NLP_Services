<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiResponseCodes;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 *  used in route.*/
class MinivanResponseCodesController extends ControllerBase
{
  // MiniVAN reports a canvass with the Walk contact type.
  const string MINIVAN_CONTACT_TYPE = 'Walk';

  protected ApiResponseCodes $apiResponseCodeObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct($apiResponseCodeObj, $nlpConfigurationObj)
  {
    $this->apiResponseCodeObj = $apiResponseCodeObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): MinivanResponseCodesController
  {
    return new static(
      $container->get('nlpservices.response_codes'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * getResponseCodeList
   *
   * Combine the Walk response codes VoteBuilder knows about with the ones
   * selected for this election in ResponseCodeConfigurationForm.  The
   * permitted codes come first, then the codes NLP doesn't accept.  A
   * selected code VoteBuilder didn't return is still listed as permitted.
   *
   * @return array - rows of ['name', 'code', 'permitted'].
   */
  public function getResponseCodeList(): array
  {
    $selectedCodes = $this->nlpConfigurationObj->getContactResponses(['name' => self::MINIVAN_CONTACT_TYPE]);

    $knownCodes = [];
    $contactTypes = $this->apiResponseCodeObj->getApiContactTypes();
    if (!empty($contactTypes[self::MINIVAN_CONTACT_TYPE])) {
      $knownCodes = $this->apiResponseCodeObj->getApiResultCodes($contactTypes[self::MINIVAN_CONTACT_TYPE]);
    }

    $permitted = $notPermitted = [];
    foreach ($knownCodes as $responseName => $responseCodeId) {
      $row = ['name' => $responseName, 'code' => $responseCodeId, 'permitted' => isset($selectedCodes[$responseName])];
      if ($row['permitted']) {
        $permitted[$responseName] = $row;
      } else {
        $notPermitted[$responseName] = $row;
      }
    }
    foreach ($selectedCodes as $responseName => $responseCodeId) {
      if (!isset($permitted[$responseName])) {
        $permitted[$responseName] = ['name' => $responseName, 'code' => $responseCodeId, 'permitted' => TRUE];
      }
    }
    return array_merge(array_values($permitted), array_values($notPermitted));
  }

  /**
   * minivanResponseCodes
   *
   * Display the Walk contact responses an NL may report with MiniVAN, and
   * mark the rest as not permitted.
   *
   * @return array
   * @noinspection PhpUnused
   * used in route.
   */
  public function minivanResponseCodes(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();

    $codeList = $this->getResponseCodeList();
    if (empty($codeList)) {
      return ['#markup' => $this->t('No MiniVAN contact response codes are available.  Please contact the administrator.')];
    }

    $rows = [];
    foreach ($codeList as $code) {
      $rows[] = [
        'data' => [
          $code['name'],
          $code['code'],
          $code['permitted'] ? $this->t('Permitted') : $this->t('Not permitted'),
        ],
        'class' => [$code['permitted'] ? 'response-code-permitted' : 'response-code-not-permitted'],
      ];
    }

    $output['intro'] = [
      '#markup' => '<p>'.$this->t('When you report a canvass in MiniVAN, use only the permitted contact responses.').'</p>',
    ];
    $output['codes'] = [
      '#type' => 'table',
      '#header' => [$this->t('Contact response'), $this->t('Code'), $this->t('Status')],
      '#rows' => $rows,
      '#attributes' => ['class' => ['minivan-response-code-table']],
    ];
    return $output;
  }

}
