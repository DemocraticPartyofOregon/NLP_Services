<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Config;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\ApiKeyVerification;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KeyValidateForm extends FormBase
{
  // The VANID offered until a site saves its own; it may not exist in every
  // committee's MyCampaign.
  const int DEFAULT_MCID = 100001693;
  // Key in nlpservices.configuration that remembers the last VANID used.
  const string MCID_CONFIG_KEY = 'nlpservices-key-validate-mcid';

  protected ApiKeyVerification $verifyApiKeysObj;

  public function __construct($verifyApiKeysObj, ConfigFactoryInterface $configFactory)
  {
    $this->verifyApiKeysObj = $verifyApiKeysObj;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): KeyValidateForm
  {
    return new static(
      $container->get('nlpservices.api_key_verification'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_key_validate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if(empty($form_state->get('renter'))) {
      $form_state->set('renter', TRUE);
      $form_state->set('page','selectMcid');
    }

    $page = $form_state->get('page');
    switch ($page) {
      case 'selectMcid':
        $mcid = $this->savedMcid();
        $form['vanid'] = [
          '#type' => 'number',
          '#title' => t('Enter a VANID for a volunteer in MyCampaign.'),
          '#default_value' => $mcid,
          '#maxlength' => 12,
          '#required' => TRUE,
        ];
        $form['useVanid'] = [
          '#type' => 'submit',
          '#value' => t('Test API keys.'),
          '#name' => 'test-keys',
        ];
        break;

      case 'keyTestResults':
        $mcid = $form_state->get('mcid');
        $results = $this->verifyApiKeysObj->apiKeyVerification($mcid);
        $form_state->set('page','selectMcid');
        $form['testResultsTitle'] = [
          '#markup' => t('<p>VANID in MyCampaign used to verify the key: <b>@mcid</b></p>', ['@mcid' => $mcid]),
        ];
        $form['testResults'] = [
          '#type' => 'table',
          '#header' => [
            ['data' => t('County'), 'field' => 'name', 'sort' => 'asc'],
            ['data' => t('MyCampaign')],
            ['data' => t('VoterFile')],
            ['data' => t('NLs')],
          ],
          '#rows' => $results,
          '#attributes' => [
            'class' => ['custom-table-class'],
          ],
        ];
        break;
    }

    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $form_state->setRebuild();
    $page = $form_state->get('page');
    switch ($page) {
      case 'selectMcid':
        $form_state->set('page','keyTestResults');
        $mcid = (int) $form_state->getValue('vanid');
        $form_state->set('mcid',$mcid);
        $this->saveMcid($mcid);
        break;
      case 'keyTestResults':
        $form_state->set('page','selectMcid');
        break;
    }
  }

  /**
   * The VANID to offer for the key test: the one saved by the last run on
   * this site, or the default if none has been saved.
   *
   * @return int
   */
  private function savedMcid(): int
  {
    $mcid = (int) $this->configFactory->get('nlpservices.configuration')
      ->get(self::MCID_CONFIG_KEY);
    return $mcid > 0 ? $mcid : self::DEFAULT_MCID;
  }

  /**
   * Remember the VANID so the next key test on this site offers it.
   *
   * @param int $mcid
   * @return void
   */
  private function saveMcid(int $mcid): void
  {
    if ($mcid <= 0 || $mcid == $this->savedMcid()) {
      return;
    }
    $this->configFactory->getEditable('nlpservices.configuration')
      ->set(self::MCID_CONFIG_KEY, $mcid)
      ->save();
  }
}
