<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpCredentialsEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an NL who can't log in email themselves a one-time login link, without
 * waiting for a coordinator.  Only NLP Leaders get one; coordinators and
 * administrators manage their own logins.
 *
 * The reply is the same whether or not the email address is on file, so the
 * form can't be used to find out who is an NL.
 *
 * @noinspection PhpUnused
 */
class LoginHelpForm extends FormBase
{
  // Requests allowed from one IP address in FLOOD_WINDOW seconds.
  const string FLOOD_EVENT = 'nlpservices.login_help';
  const int FLOOD_LIMIT = 5;
  const int FLOOD_WINDOW = 3600;

  protected DrupalUser $drupalUserObj;
  protected NlpCredentialsEmail $credentialsEmailObj;
  protected FloodInterface $flood;

  public function __construct(DrupalUser $drupalUserObj, NlpCredentialsEmail $credentialsEmailObj,
                              FloodInterface $flood)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->credentialsEmailObj = $credentialsEmailObj;
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): LoginHelpForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.credentials_email'),
      $container->get('flood'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_login_help_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Enter the email address your Neighborhood Leader coordinator has for you.
        We will email you a link that logs you in.') . '</p>',
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#size' => 40,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Email me my login'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $form_state->setErrorByName('email', $this->t('Too many requests have come from this computer.
        Try again in an hour, or contact your coordinator.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $email = trim((string) $form_state->getValue('email'));
    $user = $this->drupalUserObj->getUserByEmail($email);
    $logger = Drupal::logger('nlpservices');
    if (empty($user)) {
      $logger->notice('Login help asked for an email not on file: ' . $email);
    } elseif (!$this->drupalUserObj->isLeaderOnly($user['roles'] ?? [])) {
      $logger->notice('Login help not sent, not an NLP Leader: ' . $user['userName']);
    } elseif ($this->credentialsEmailObj->sendLoginHelpEmail($user)) {
      $logger->notice('Login help sent to: ' . $user['userName']);
    }

    $this->messenger()->addStatus($this->t('If that email address belongs to a Neighborhood Leader,
      we have sent it a link to log in.  Check your spam folder if it doesn\'t arrive in a few
      minutes.'));
    $form_state->setRedirect('user.login');
  }
}
