<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\nlpservices\HtmlText;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CoordinatorEmail extends FormBase
{
  protected MailManagerInterface $mailManager;
  protected LanguageManagerInterface $languageManager;
  protected HtmlText $htmlText;
  
  public function __construct($mailManager, $languageManager, $htmlText)
  {
    $this->mailManager = $mailManager;
    $this->languageManager = $languageManager;
    $this->htmlText = $htmlText;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): CoordinatorEmail
  {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('nlpservices.html_text'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_coordinator_email_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    if (empty($form_state->get('reenter'))) {
      $form_state->set('reenter', TRUE);
    }
    $parameters = [];
    $paramNames = ['to', 'from', 'firstName', 'lastName'];
    $request = $this->requestStack->getCurrentRequest();
    foreach ($paramNames as $param) {
      $parameters[$param] = $request->query->get($param, '');
    }
    $form_state->set('parameters',$parameters);
  
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => t('Subject'),
      '#size' => 100,
      '#maxlength' => 128,
      '#required' => TRUE,
    ];
  
    $form['message'] = [
      '#title' => t('Your question or message to your NLP Coordinator.'),
      '#type' => 'textarea',
    ];
   
    $form['send'] = [
      '#type' => 'submit',
      '#value' => t('Send'),
      '#name' => 'send-email',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $form_state->setRedirect('nlpservices.data_entry_help_route');

    $subject = $form_state->getValue('subject');
    $message = $form_state->getValue('message');
    $parameters = $form_state->get('parameters');

    $firstName = Html::escape($parameters['firstName']);
    $lastName = Html::escape($parameters['lastName']);
    $from = Html::escape($parameters['from']);

    $message .= '<p><hr>This message is from: '.$firstName.'&nbsp;'.$lastName.
      '<br>'.$from.'<br>You can reply to the email, and it will be sent to the NL.</p>';

    $this->htmlText->setHtml($message);
    $plainText = $this->htmlText->getText();

    $config = Drupal::config('nlpservices.configuration');
    $emailConfiguration = $config->get('nlpservices-email-configuration');
    $notificationEmail = $emailConfiguration['notifications']['email'];

    $params['func'] = 'help_request';
    $params['subject'] = $subject;
    $params['List-Unsubscribe'] = "<mailto: ".$notificationEmail."?subject=unsubscribe>";
    $params['message'] = $message;
    $params['plainText'] = $plainText;

    $to = $parameters['to'];
    $languageCode = $this->languageManager->getDefaultLanguage()->getId();
    $reply = $parameters['from'];

    $result = $this->mailManager->mail(NLP_MODULE, 'deliver_turf', $to, $languageCode, $params, $reply);

    if (!$result['result']) {
      $messenger->addError(t('There was a problem sending your message and it was not sent.'));
      return;
    }
    $messenger->addMessage(t('You successfully sent a message to your county coordinator.'));
  }
}
