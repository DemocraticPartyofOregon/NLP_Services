<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\NlpImpersonation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns an impersonator to their own account.
 */
class ImpersonationController extends ControllerBase {

  public function __construct(
    protected NlpImpersonation $impersonationObj,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.impersonation'),
    );
  }

  /**
   * returnToAccount
   *
   * Switches back to the impersonator's account and goes back to the list of
   * NLs to impersonate.
   *
   * @return RedirectResponse
   */
  public function returnToAccount(): RedirectResponse
  {
    if (!$this->impersonationObj->switchBack()) {
      $this->messenger()->addWarning($this->t('You are not impersonating an NL.'));
      return $this->redirect('<front>');
    }
    $this->messenger()->addStatus($this->t('You are back in your own account.'));
    return $this->redirect('nlpservices.impersonate_nl_form');
  }

}
