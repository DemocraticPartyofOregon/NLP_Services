<?php

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpImpersonation;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(NlpImpersonation::class)]
class NlpImpersonationTest extends UnitTestCase
{

  private Session $session;
  private AccountProxyInterface $currentUser;
  private DrupalUser $drupalUser;
  private UserStorageInterface $userStorage;
  /** @var UserInterface[] keyed by uid */
  private array $accounts = [];
  /** @var array[] [uid, timestamp] for each last-access update. */
  private array $accessUpdates = [];
  /** @var string[] */
  private array $notices = [];

  protected function setUp(): void
  {
    parent::setUp();
    $this->session = new Session(new MockArraySessionStorage());
    $this->session->set('uid', 10);

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentUser->method('getAccountName')->willReturn('current');

    $this->drupalUser = $this->createMock(DrupalUser::class);
    $this->drupalUser->method('isLeaderOnly')->willReturnCallback(
      fn(array $roles) => in_array('nlp_leader', $roles, TRUE)
        && !array_intersect(['nlp_coordinator', 'nlp_admin', 'administrator'], $roles));
    $this->drupalUser->method('isNlpAdminUser')->willReturnCallback(
      fn(array $user) => (bool) array_intersect(['nlp_admin', 'administrator'], $user['roles']));

    $this->accounts = [10 => $this->account(10, 'coord', 500), 20 => $this->account(20, 'nl', 1000)];
    $this->accessUpdates = [];
    $this->userStorage = $this->createMock(UserStorageInterface::class);
    $this->userStorage->method('load')->willReturnCallback(fn($uid) => $this->accounts[$uid] ?? NULL);
    $this->userStorage->method('updateLastAccessTimestamp')->willReturnCallback(
      function ($account, $timestamp): void { $this->accessUpdates[] = [(int) $account->id(), $timestamp]; });

    $this->notices = [];
  }

  private function account(int $uid, string $name, int $access, bool $active = TRUE): UserInterface
  {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getAccountName')->willReturn($name);
    $account->method('getLastAccessedTime')->willReturn($access);
    $account->method('isActive')->willReturn($active);
    return $account;
  }

  private function service(): NlpImpersonation
  {
    $request = new Request();
    $request->setSession($this->session);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($this->userStorage);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('notice')->willReturnCallback(function ($message, array $context = []): void {
      $this->notices[] = strtr((string) $message, $context);
    });
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new NlpImpersonation($requestStack, $this->currentUser, $entityTypeManager, $this->drupalUser,
      $loggerFactory);
  }

  private function coordinator(array $overrides = []): array
  {
    return array_merge(['uid' => 10, 'roles' => ['authenticated', 'nlp_coordinator', 'nlp_leader'],
      'county' => 'Lane'], $overrides);
  }

  private function nl(array $overrides = []): array
  {
    return array_merge(['uid' => 20, 'roles' => ['authenticated', 'nlp_leader'], 'county' => 'Lane',
      'mcid' => 777], $overrides);
  }

  // impersonationRefusal

  public function testACoordinatorMayImpersonateAnNlInTheirCounty(): void
  {
    $this->assertSame('', $this->service()->impersonationRefusal($this->coordinator(), $this->nl()));
  }

  public function testACoordinatorMayNotImpersonateAnNlInAnotherCounty(): void
  {
    $refusal = $this->service()->impersonationRefusal($this->coordinator(), $this->nl(['county' => 'Linn']));
    $this->assertStringContainsString('own county', $refusal);
  }

  public function testACoordinatorWithNoCountyMayNotImpersonate(): void
  {
    $refusal = $this->service()->impersonationRefusal($this->coordinator(['county' => NULL]),
      $this->nl(['county' => NULL]));
    $this->assertStringContainsString('own county', $refusal);
  }

  public function testAnAdminMayImpersonateAnNlInAnyCounty(): void
  {
    $admin = ['uid' => 10, 'roles' => ['authenticated', 'nlp_admin'], 'county' => 'Lane'];
    $this->assertSame('', $this->service()->impersonationRefusal($admin, $this->nl(['county' => 'Linn'])));
  }

  public function testAnotherCoordinatorCannotBeImpersonated(): void
  {
    $target = $this->nl(['roles' => ['authenticated', 'nlp_leader', 'nlp_coordinator']]);
    $this->assertStringContainsString('not also a coordinator',
      $this->service()->impersonationRefusal($this->coordinator(), $target));
  }

  public function testAnAdminCannotBeImpersonated(): void
  {
    $admin = ['uid' => 10, 'roles' => ['authenticated', 'nlp_admin'], 'county' => 'Lane'];
    $target = $this->nl(['roles' => ['authenticated', 'nlp_leader', 'administrator']]);
    $this->assertNotSame('', $this->service()->impersonationRefusal($admin, $target));
  }

  public function testAnNlCannotImpersonateAnotherNl(): void
  {
    $leader = ['uid' => 30, 'roles' => ['authenticated', 'nlp_leader'], 'county' => 'Lane'];
    $this->assertStringContainsString('Only an NLP Coordinator',
      $this->service()->impersonationRefusal($leader, $this->nl()));
  }

  public function testNobodyCanImpersonateThemselves(): void
  {
    $this->assertStringContainsString('yourself',
      $this->service()->impersonationRefusal($this->nl(), $this->nl()));
  }

  public function testAMissingAccountCannotBeImpersonated(): void
  {
    $this->assertNotSame('', $this->service()->impersonationRefusal($this->coordinator(), []));
  }

  // switchTo / switchBack

  public function testSwitchToMovesTheSessionToTheNlAndRemembersTheImpersonator(): void
  {
    $this->drupalUser->method('getCurrentUser')->willReturn($this->coordinator());
    $this->drupalUser->method('getUserByUid')->with(20)->willReturn($this->nl());
    $this->currentUser->expects($this->once())->method('setAccount')->with($this->accounts[20]);

    $service = $this->service();
    $this->assertSame('', $service->switchTo(20));

    $this->assertSame(20, $this->session->get('uid'));
    $this->assertTrue($service->isImpersonating());
    $this->assertSame(['impersonatorUid' => 10, 'targetUid' => 20, 'targetAccess' => 1000],
      $this->session->get(NlpImpersonation::SESSION_KEY));
    $this->assertStringContainsString('started impersonating NL nl', $this->notices[0]);
  }

  public function testSwitchToARefusedNlLeavesTheSessionAlone(): void
  {
    $this->drupalUser->method('getCurrentUser')->willReturn($this->coordinator());
    $this->drupalUser->method('getUserByUid')->willReturn($this->nl(['county' => 'Linn']));
    $this->currentUser->expects($this->never())->method('setAccount');

    $service = $this->service();
    $this->assertStringContainsString('own county', $service->switchTo(20));
    $this->assertSame(10, $this->session->get('uid'));
    $this->assertFalse($service->isImpersonating());
  }

  public function testSwitchToABlockedNlIsRefused(): void
  {
    $this->accounts[20] = $this->account(20, 'nl', 1000, FALSE);
    $this->drupalUser->method('getCurrentUser')->willReturn($this->coordinator());
    $this->drupalUser->method('getUserByUid')->willReturn($this->nl());

    $this->assertStringContainsString('blocked', $this->service()->switchTo(20));
    $this->assertSame(10, $this->session->get('uid'));
  }

  public function testSwitchToWhileImpersonatingIsRefused(): void
  {
    $this->session->set(NlpImpersonation::SESSION_KEY, ['impersonatorUid' => 10, 'targetUid' => 20]);
    $this->assertStringContainsString('Return to your own account', $this->service()->switchTo(20));
  }

  public function testSwitchBackRestoresTheImpersonatorAndTheNlsLastAccess(): void
  {
    $this->session->set('uid', 20);
    $this->session->set(NlpImpersonation::SESSION_KEY,
      ['impersonatorUid' => 10, 'targetUid' => 20, 'targetAccess' => 1000]);
    $this->currentUser->expects($this->once())->method('setAccount')->with($this->accounts[10]);

    $service = $this->service();
    $this->assertTrue($service->switchBack());

    $this->assertSame(10, $this->session->get('uid'));
    $this->assertFalse($service->isImpersonating());
    $this->assertSame([[20, 1000]], $this->accessUpdates);
  }

  public function testSwitchBackWithoutImpersonatingDoesNothing(): void
  {
    $this->currentUser->expects($this->never())->method('setAccount');
    $this->assertFalse($this->service()->switchBack());
    $this->assertSame(10, $this->session->get('uid'));
  }

  public function testSwitchBackToABlockedImpersonatorIsRefused(): void
  {
    $this->accounts[10] = $this->account(10, 'coord', 500, FALSE);
    $this->session->set('uid', 20);
    $this->session->set(NlpImpersonation::SESSION_KEY, ['impersonatorUid' => 10, 'targetUid' => 20]);

    $this->assertFalse($this->service()->switchBack());
    $this->assertSame(20, $this->session->get('uid'));
  }

  // endOnLogout

  public function testLogoutWhileImpersonatingRestoresTheNlsLastAccess(): void
  {
    $this->session->set(NlpImpersonation::SESSION_KEY,
      ['impersonatorUid' => 10, 'targetUid' => 20, 'targetAccess' => 1000]);

    $service = $this->service();
    $service->endOnLogout();

    $this->assertSame([[20, 1000]], $this->accessUpdates);
    $this->assertFalse($service->isImpersonating());
  }

  public function testLogoutWithoutImpersonatingChangesNothing(): void
  {
    $this->service()->endOnLogout();
    $this->assertSame([], $this->accessUpdates);
  }

  // buildBanner

  public function testNoBannerWhenNotImpersonating(): void
  {
    $build = $this->service()->buildBanner();
    $this->assertArrayNotHasKey('#markup', $build);
    $this->assertSame(0, $build['#cache']['max-age']);
  }

}
