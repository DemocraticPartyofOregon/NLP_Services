<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\nlpservices\HtmlText;
use Drupal\nlpservices\ApiKeyVerification;
use Symfony\Component\DependencyInjection\ContainerInterface;

class KeyValidateForm extends FormBase
{
  protected MailManagerInterface $mailManager;
  protected LanguageManagerInterface $languageManager;
  protected HtmlText $htmlText;
  protected ApiKeyVerification $verifyApiKeysObj;
  
  public function __construct($mailManager, $languageManager, $htmlText, $verifyApiKeysObj)
  {
    $this->mailManager = $mailManager;
    $this->languageManager = $languageManager;
    $this->htmlText = $htmlText;
    $this->verifyApiKeysObj = $verifyApiKeysObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): KeyValidateForm
  {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('nlpservices.html_text'),
      $container->get('nlpservices.api_key_verification'),
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
        //$mcid = 101077092;  // Mary Reed!!
        $mcid = 100001693;
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
          '#name' => 'test-keys',];
        break;
      case 'keyTestResults':
        $mcid = $form_state->get('mcid');
        $results = $this->verifyApiKeysObj->apiKeyVerification($mcid);
        $form_state->set('page','selectMcid');
        $form['testResultsTitle'] = [
          '#markup' => "<p>VANID in MyCampaign used to verify the key: <b>$mcid</b></p>",
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
        break;
      case 'keyTestResults':
        $form_state->set('page','selectMcid');
        break;
    }
  }
}
