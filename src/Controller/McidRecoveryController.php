<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpMcidRecovery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class McidRecoveryController extends ControllerBase
{
  protected NlpMcidRecovery $mcidRecoveryObj;
  protected MagicWord $magicWordObj;
  protected RequestStack $requestStack;
  protected DrupalUser $drupalUserObj;

  public function __construct($mcidRecoveryObj, $magicWordObj, $requestStack, $drupalUserObj) {
    $this->mcidRecoveryObj = $mcidRecoveryObj;
    $this->magicWordObj = $magicWordObj;
    $this->requestStack = $requestStack;
    $this->drupalUserObj = $drupalUserObj;
  }

  public static function create(ContainerInterface $container): McidRecoveryController
  {
    return new static(
      $container->get('nlpservices.mcid_recovery'),
      $container->get('nlpservices.magic_word'),
      $container->get('request_stack'),
      $container->get('nlpservices.drupal_user'),
    );
  }

  /**
   * getMagicWord
   *
   * Returns the decrypted magic word for the MCID identified by a recovery key.
   *
   * The key is consumed on success so it cannot be replayed.
   * Returns an error message for any key that is unknown, expired, or already used,
   * deliberately giving no detail to prevent enumeration.
   */
  public function getMagicWord(): Response
  {
    $key = $this->requestStack->getCurrentRequest()->query->get('key', '');
    if (empty($key)) {
      return new Response('This link is not valid.', 400);
    }

    $mcid = $this->mcidRecoveryObj->getMcid($key);
    if (empty($mcid)) {
      return new Response('This link has expired or has already been used.', 403);
    }

    // Only an active NL has a login account.  One who is no longer active
    // gets no password, even from a link sent while they were.
    if (empty($this->drupalUserObj->getUserByMcid($mcid))) {
      $this->mcidRecoveryObj->deleteKey($key);
      return new Response('This link has expired or has already been used.', 403);
    }

    $magicWord = $this->magicWordObj->getMagicWord($mcid);
    if (empty($magicWord)) {
      return new Response('No credentials were found for this account.', 404);
    }

    $this->mcidRecoveryObj->deleteKey($key);

    return new Response('Your password is: ' . $magicWord);
  }
}
