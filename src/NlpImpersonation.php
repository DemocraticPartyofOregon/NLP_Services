<?php

namespace Drupal\nlpservices;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Lets an NLP Coordinator or NLP Admin act as an NL without the NL's password.
 *
 * The session is switched to the NL's account, so the impersonator sees the NL's
 * turfs and data entry pages exactly as the NL does.  The impersonator's uid is
 * kept in the session so they can return to their own account without logging
 * in again.  Logging out while impersonating ends the session as usual.
 *
 * A coordinator may impersonate only the NLs of their own county; an admin may
 * impersonate any NL.  Only accounts that are just NLP Leaders can be
 * impersonated, so nobody gains a coordinator's or admin's access this way.
 *
 * Drupal records the time of each page view as the account's last access.  The
 * NL's last access is put back when the impersonator returns or logs out, so a
 * coordinator's visit doesn't make an inactive NL look active.
 */
class NlpImpersonation implements TrustedCallbackInterface
{

  // The session key holding ['impersonatorUid' => int, 'targetUid' => int, 'targetAccess' => int].
  public const string SESSION_KEY = 'nlpservices_impersonation';

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DrupalUser $drupalUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array
  {
    return ['buildBanner'];
  }

  /**
   * isImpersonating
   *
   * @return bool - TRUE when the current session is acting as an NL.
   */
  public function isImpersonating(): bool
  {
    return !empty($this->getSession()?->get(self::SESSION_KEY));
  }

  /**
   * impersonationRefusal
   *
   * Returns why the impersonator may not impersonate the target, or '' if they
   * may.
   *
   * @param array $impersonator - the NLP user array of the impersonator.
   * @param array $target - the NLP user array of the NL.
   * @return string
   */
  public function impersonationRefusal(array $impersonator, array $target): string
  {
    if (empty($target['uid'])) {
      return 'That account no longer exists.';
    }
    if ((int) $target['uid'] === (int) ($impersonator['uid'] ?? 0)) {
      return 'You cannot impersonate yourself.';
    }
    if (!$this->drupalUser->isLeaderOnly($target['roles'] ?? [])) {
      return 'Only an NL who is not also a coordinator or admin can be impersonated.';
    }
    if ($this->drupalUser->isNlpAdminUser($impersonator)) {
      return '';
    }
    if (!in_array(NLP_COORDINATOR_ROLE_ID, $impersonator['roles'] ?? [], TRUE)) {
      return 'Only an NLP Coordinator or NLP Admin can impersonate an NL.';
    }
    if (empty($impersonator['county']) || $impersonator['county'] !== ($target['county'] ?? '')) {
      return 'You can only impersonate NLs in your own county.';
    }
    return '';
  }

  /**
   * switchTo
   *
   * Switches the session to the NL's account.
   *
   * @param int $targetUid
   * @return string - why it was refused, or '' when the session was switched.
   */
  public function switchTo(int $targetUid): string
  {
    $session = $this->getSession();
    if ($session === NULL) {
      return 'There is no session to switch.';
    }
    if ($this->isImpersonating()) {
      return 'Return to your own account before impersonating another NL.';
    }
    $impersonator = $this->drupalUser->getCurrentUser();
    $target = $this->drupalUser->getUserByUid($targetUid);
    $refusal = $this->impersonationRefusal($impersonator, $target);
    if ($refusal !== '') {
      return $refusal;
    }
    $account = $this->getUserStorage()?->load($targetUid);
    if (!$account instanceof UserInterface) {
      return 'That account no longer exists.';
    }
    if (!$account->isActive()) {
      return 'That account is blocked.';
    }

    $impersonatorName = $this->currentUser->getAccountName();
    $this->switchSession($session, $account);
    $session->set(self::SESSION_KEY, [
      'impersonatorUid' => (int) $impersonator['uid'],
      'targetUid' => $targetUid,
      'targetAccess' => (int) $account->getLastAccessedTime(),
    ]);
    $this->loggerFactory->get('nlpservices')->notice('@impersonator started impersonating NL @nl (MCID @mcid).', [
      '@impersonator' => $impersonatorName,
      '@nl' => $account->getAccountName(),
      '@mcid' => $target['mcid'] ?? '',
    ]);
    return '';
  }

  /**
   * switchBack
   *
   * Returns the session to the impersonator's own account.
   *
   * @return bool - FALSE when the session wasn't impersonating or the
   *   impersonator's account is gone (the session is then left as it was).
   */
  public function switchBack(): bool
  {
    $session = $this->getSession();
    $impersonation = $session?->get(self::SESSION_KEY);
    if (empty($impersonation['impersonatorUid'])) {
      return FALSE;
    }
    $account = $this->getUserStorage()?->load($impersonation['impersonatorUid']);
    if (!$account instanceof UserInterface || !$account->isActive()) {
      return FALSE;
    }
    $nlName = $this->currentUser->getAccountName();
    $this->restoreTargetAccess($impersonation);
    $session->remove(self::SESSION_KEY);
    $this->switchSession($session, $account);
    $this->loggerFactory->get('nlpservices')->notice('@impersonator stopped impersonating NL @nl.', [
      '@impersonator' => $account->getAccountName(),
      '@nl' => $nlName,
    ]);
    return TRUE;
  }

  /**
   * endOnLogout
   *
   * Called when the session logs out.  Puts back the NL's last access time if
   * the session was impersonating.
   *
   * @return void
   */
  public function endOnLogout(): void
  {
    $impersonation = $this->getSession()?->get(self::SESSION_KEY);
    if (empty($impersonation)) {
      return;
    }
    $this->restoreTargetAccess($impersonation);
    $this->getSession()->remove(self::SESSION_KEY);
    $this->loggerFactory->get('nlpservices')->notice('Impersonation of NL @nl ended by logout.',
      ['@nl' => $this->currentUser->getAccountName()]);
  }

  /**
   * buildBanner
   *
   * A lazy builder for the notice at the top of every page while impersonating,
   * with the link back to the impersonator's own account.  It's a lazy builder
   * so the page can still be cached; the notice is filled in on each request.
   *
   * @return array
   */
  public function buildBanner(): array
  {
    $build = ['#cache' => ['max-age' => 0]];
    if (!$this->isImpersonating()) {
      return $build;
    }
    $name = Html::escape($this->currentUser->getDisplayName());
    $returnUrl = Url::fromRoute('nlpservices.impersonate_return')->toString();
    $build['#markup'] = '<div class="nlp-impersonation-banner" role="status">'
      . 'You are viewing NLP Services as <strong>' . $name . '</strong>. '
      . '<a href="' . $returnUrl . '">Return to my account</a></div>';
    $build['#attached']['library'][] = 'nlpservices/impersonation-banner';
    return $build;
  }

  /**
   * switchSession
   *
   * Moves the session to a different account, as Drupal does at login, but
   * without the login hooks, so the account's last login time isn't changed.
   *
   * @param SessionInterface $session
   * @param UserInterface $account
   * @return void
   */
  private function switchSession(SessionInterface $session, UserInterface $account): void
  {
    $session->migrate();
    $session->set('uid', $account->id());
    $this->currentUser->setAccount($account);
  }

  /**
   * restoreTargetAccess
   *
   * Puts back the NL's last access time from before the impersonation.
   *
   * @param array $impersonation - the session's impersonation record.
   * @return void
   */
  private function restoreTargetAccess(array $impersonation): void
  {
    if (empty($impersonation['targetUid'])) {
      return;
    }
    $storage = $this->getUserStorage();
    $account = $storage?->load($impersonation['targetUid']);
    if (!$account instanceof UserInterface) {
      return;
    }
    $storage->updateLastAccessTimestamp($account, (int) ($impersonation['targetAccess'] ?? 0));
  }

  private function getSession(): ?SessionInterface
  {
    $request = $this->requestStack->getCurrentRequest();
    return ($request !== NULL && $request->hasSession()) ? $request->getSession() : NULL;
  }

  private function getUserStorage(): ?UserStorageInterface
  {
    try {
      $storage = $this->entityTypeManager->getStorage('user');
    } catch (Exception $e) {
      nlp_debug_msg('NlpImpersonation', $e);
      return NULL;
    }
    return $storage instanceof UserStorageInterface ? $storage : NULL;
  }

}
