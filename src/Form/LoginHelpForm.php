<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpCredentialsEmail;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an NL who can't log in email themselves their username and a one-time
 * link to their password, without waiting for a coordinator.
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
  protected MagicWord $magicWordObj;
  protected NlpCredentialsEmail $credentialsEmailObj;
  protected FloodInterface $flood;

  public function __construct($drupalUserObj, $magicWordObj, $credentialsEmailObj, $flood)
  {
    $this->drupalUserObj = $drupalUserObj;
    $this->magicWordObj = $magicWordObj;
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
      $container->get('nlpservices.magic_word'),
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
        We will email you your username and a link to see your password.') . '</p>',
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
    } elseif (!$this->isNlWithPassword($user)) {
      $logger->notice('Login help not sent, no NL password saved for: ' . $user['userName']);
    } elseif ($this->credentialsEmailObj->sendLoginHelpEmail($user)) {
      $logger->notice('Login help sent to: ' . $user['userName']);
    }

    $this->messenger()->addStatus($this->t('If that email address belongs to a Neighborhood Leader,
      we have sent it the username and a link to the password.  Check your spam folder if it
      doesn\'t arrive in a few minutes.'));
    $form_state->setRedirect('user.login');
  }

  /**
   * isNlWithPassword
   *
   * An account gets login help when it belongs to an NL or coordinator (it has
   * an MCID) with a saved password.  Administrators manage their own logins.
   *
   * @param array $user - the Drupal user array.
   * @return bool
   */
  private function isNlWithPassword(array $user): bool
  {
    if (empty($user['mcid'])) {
      return FALSE;
    }
    if (array_intersect(['administrator', NLP_ADMIN_ROLE_ID], $user['roles'] ?? [])) {
      return FALSE;
    }
    return !empty($this->magicWordObj->getMagicWord((int) $user['mcid']));
  }
}
