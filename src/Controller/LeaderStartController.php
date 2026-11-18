<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpTurfs;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Where an NLP Leader lands after logging in, by password or by a one-time
 * login link: the data entry page if they have a turf, otherwise their
 * account page.
 */
class LeaderStartController extends ControllerBase {

  // The message Drupal shows after a one-time login link is used.  NLs keep
  // the password they were given, so it's removed rather than shown.
  const string SET_PASSWORD_MSG = 'You have used a one-time login link. You can set your new password now.';

  public function __construct(
    protected DrupalUser $drupalUserObj,
    protected NlpTurfs $turfsObj,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.turfs'),
    );
  }

  /**
   * start
   *
   * Redirects the current user to the data entry page when they are an NLP
   * Leader with a turf, otherwise to their account page.
   *
   * @return RedirectResponse
   */
  public function start(): RedirectResponse
  {
    $this->removeSetPasswordMessage();
    $user = $this->drupalUserObj->getCurrentUser();
    $reason = $this->noDataEntryReason($user);
    $name = $user['userName'] ?? '';
    if ($reason === '') {
      $this->getLogger('nlpservices')->notice('Login landing for @name: data entry.', ['@name' => $name]);
      return $this->redirect('nlpservices.data_entry_form');
    }
    $this->getLogger('nlpservices')->notice('Login landing for @name: account page, @reason.',
      ['@name' => $name, '@reason' => $reason]);
    return $this->redirect('entity.user.canonical', ['user' => $this->currentUser()->id()]);
  }

  /**
   * noDataEntryReason
   *
   * Returns why the user doesn't go to data entry, or '' if they do.
   *
   * @param array $user - the NLP user array.
   * @return string
   */
  private function noDataEntryReason(array $user): string
  {
    $roles = $user['roles'] ?? [];
    if (!$this->drupalUserObj->isLeaderOnly($roles)) {
      return 'not only an NLP Leader (roles: ' . implode(', ', $roles) . ')';
    }
    if (empty($user['mcid'])) {
      return 'no MCID on the account';
    }
    if (empty($user['county'])) {
      return 'no county on the account';
    }
    if (empty($this->turfsObj->turfExists((int) $user['mcid'], (string) $user['county']))) {
      return 'no turf for MCID ' . $user['mcid'] . ' in ' . $user['county'] . ' county';
    }
    return '';
  }

  /**
   * removeSetPasswordMessage
   *
   * Removes Drupal's invitation to set a new password, keeping any other
   * status messages.
   *
   * @return void
   */
  private function removeSetPasswordMessage(): void
  {
    $setPassword = (string) $this->t(self::SET_PASSWORD_MSG);
    $messages = $this->messenger()->messagesByType('status');
    $kept = array_filter($messages, fn($message) => (string) $message !== $setPassword);
    if (count($kept) === count($messages)) {
      return;
    }
    $this->messenger()->deleteByType('status');
    foreach ($kept as $message) {
      $this->messenger()->addStatus($message);
    }
  }

}
