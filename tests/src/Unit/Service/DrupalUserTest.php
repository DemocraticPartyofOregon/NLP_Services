<?php

// All stubs (nlp_debug_msg, constants) are provided by tests/bootstrap.php.
// Lookups against the user storage (getUserByName/getUserByEmail) go through
// a mocked entity_type.manager service, set up via mockUserStorageResult().

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Entity\EntityStorageInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Session\AccountProxyInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\Tests\UnitTestCase;
  use Drupal\user\UserAuthInterface;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class DrupalUserTest extends UnitTestCase {

    private MagicWord $magicWordMock;
    private RequestStack $requestStackMock;
    private object $entityTypeManagerMock;
    private object $currentUserMock;
    private object $userAuthMock;

    protected function setUp(): void {
      parent::setUp();
      $this->magicWordMock       = $this->createMock(MagicWord::class);
      $this->requestStackMock    = $this->createMock(RequestStack::class);
      $this->entityTypeManagerMock = $this->createMock(EntityTypeManagerInterface::class);
      $this->currentUserMock     = $this->createMock(AccountProxyInterface::class);
      $this->userAuthMock        = $this->createMock(UserAuthInterface::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildDrupalUser(): DrupalUser {
      return new DrupalUser(
        $this->magicWordMock,
        $this->requestStackMock,
        $this->entityTypeManagerMock,
        $this->currentUserMock,
        $this->userAuthMock,
      );
    }

    /**
     * Wires entity_type.manager->getStorage('user')->loadByProperties()
     * to return the given result, as consumed by getUserByName()/getUserByEmail().
     */
    private function mockUserStorageResult(array $result): void {
      $storageMock = $this->createMock(EntityStorageInterface::class);
      $storageMock->method('loadByProperties')->willReturn($result);
      $this->entityTypeManagerMock = $this->createMock(EntityTypeManagerInterface::class);
      $this->entityTypeManagerMock->method('getStorage')->with('user')->willReturn($storageMock);
    }

    /**
     * Returns an anonymous-class user stub whose public API matches what
     * extractUserInfo() calls on a Drupal User entity.
     */
    private function buildUserStub(array $overrides = []): object {
      $data = array_merge([
        'uid'              => 10,
        'email'            => 'alice@example.com',
        'userName'         => 'alice.smith',
        'roles'            => ['authenticated', 'nlp_leader'],
        'access'           => 1000,
        'field_mcid'       => 42,
        'field_first_name' => 'Alice',
        'field_last_name'  => 'Smith',
        'field_phone'      => '555-1234',
        'field_county'     => 'Denver',
        'field_hd'         => '06',
        'created'          => 900,
        'login'            => 800,
      ], $overrides);

      return new class($data) {
        public function __construct(private array $data) {}
        public function id(): int          { return $this->data['uid']; }
        public function getEmail(): string { return $this->data['email']; }
        public function getAccountName(): string { return $this->data['userName']; }
        public function getRoles(): array  { return $this->data['roles']; }
        public function getLastAccessedTime(): int { return $this->data['access']; }
        public function get(string $field): object {
          $obj = new \stdClass();
          $obj->value = $this->data[$field] ?? NULL;
          return $obj;
        }
      };
    }

    private function baseUserArray(): array {
      return [
        'uid'       => 10,
        'email'     => 'alice@example.com',
        'userName'  => 'alice.smith',
        'roles'     => ['authenticated', 'nlp_leader'],
        'access'    => 1000,
        'mcid'      => 42,
        'firstName' => 'Alice',
        'lastName'  => 'Smith',
        'phone'     => '555-1234',
        'county'    => 'Denver',
        'hd'        => '06',
        'created'   => 900,
        'login'     => 800,
      ];
    }

    // -------------------------------------------------------------------------
    // isNlpAdminUser
    // -------------------------------------------------------------------------

    public function testIsNlpAdminUserReturnsTrueForNlpAdminRole(): void {
      $user = array_merge($this->baseUserArray(), ['roles' => ['authenticated', 'nlp_admin']]);
      $this->assertTrue($this->buildDrupalUser()->isNlpAdminUser($user));
    }

    public function testIsNlpAdminUserReturnsTrueForAdministratorRole(): void {
      $user = array_merge($this->baseUserArray(), ['roles' => ['authenticated', 'administrator']]);
      $this->assertTrue($this->buildDrupalUser()->isNlpAdminUser($user));
    }

    public function testIsNlpAdminUserReturnsFalseForNonAdminRoles(): void {
      $user = array_merge($this->baseUserArray(), ['roles' => ['authenticated', 'nlp_leader']]);
      $this->assertFalse($this->buildDrupalUser()->isNlpAdminUser($user));
    }

    // -------------------------------------------------------------------------
    // verifyPassword
    // -------------------------------------------------------------------------

    public function testVerifyPasswordReturnsFalseOnEmptyUsername(): void {
      $this->assertFalse($this->buildDrupalUser()->verifyPassword('', 'secret5'));
    }

    public function testVerifyPasswordReturnsFalseOnEmptyPassword(): void {
      $this->assertFalse($this->buildDrupalUser()->verifyPassword('alice.smith', ''));
    }

    public function testVerifyPasswordReturnsTrueWhenAuthSucceeds(): void {
      $this->userAuthMock->method('authenticate')->willReturn(42);

      $this->assertTrue($this->buildDrupalUser()->verifyPassword('alice.smith', 'pass5'));
    }

    public function testVerifyPasswordReturnsFalseWhenAuthFails(): void {
      $this->userAuthMock->method('authenticate')->willReturn(FALSE);

      $this->assertFalse($this->buildDrupalUser()->verifyPassword('alice.smith', 'wrong5'));
    }

    // -------------------------------------------------------------------------
    // getUserByName
    // -------------------------------------------------------------------------

    public function testGetUserByNameEmptyStringReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildDrupalUser()->getUserByName(''));
    }

    public function testGetUserByNameNotFoundReturnsEmptyArray(): void {
      $this->mockUserStorageResult([]);
      $this->assertSame([], $this->buildDrupalUser()->getUserByName('nobody'));
    }

    public function testGetUserByNameFoundReturnsUserInfoArray(): void {
      $this->mockUserStorageResult([$this->buildUserStub()]);
      $result = $this->buildDrupalUser()->getUserByName('alice.smith');
      $this->assertSame(10, $result['uid']);
      $this->assertSame('alice@example.com', $result['email']);
      $this->assertSame('alice.smith', $result['userName']);
      $this->assertSame(42, $result['mcid']);
    }

    // -------------------------------------------------------------------------
    // getUserByEmail
    // -------------------------------------------------------------------------

    public function testGetUserByEmailNotFoundReturnsEmptyArray(): void {
      $this->mockUserStorageResult([]);
      $this->assertSame([], $this->buildDrupalUser()->getUserByEmail('nobody@example.com'));
    }

    public function testGetUserByEmailFoundReturnsUserInfoArray(): void {
      $this->mockUserStorageResult([$this->buildUserStub()]);
      $result = $this->buildDrupalUser()->getUserByEmail('alice@example.com');
      $this->assertSame(10, $result['uid']);
      $this->assertSame('Alice', $result['firstName']);
      $this->assertSame('Smith', $result['lastName']);
    }

    // -------------------------------------------------------------------------
    // displayCredentials
    // -------------------------------------------------------------------------

    public function testDisplayCredentialsContainsUsernameAndPassword(): void {
      $request = Request::create('https://nlp.example.org/');
      $this->requestStackMock->method('getCurrentRequest')->willReturn($request);
      $this->magicWordMock->method('getMagicWord')->with(42)->willReturn('crane7');

      $result = $this->buildDrupalUser()->displayCredentials(42, $this->baseUserArray());

      $this->assertStringContainsString('alice.smith', $result);
      $this->assertStringContainsString('crane7', $result);
    }

    public function testDisplayCredentialsContainsServerUrl(): void {
      $request = Request::create('https://nlp.example.org/');
      $this->requestStackMock->method('getCurrentRequest')->willReturn($request);
      $this->magicWordMock->method('getMagicWord')->willReturn('word5');

      $result = $this->buildDrupalUser()->displayCredentials(42, $this->baseUserArray());

      $this->assertStringContainsString('https://nlp.example.org', $result);
    }

    public function testDisplayCredentialsWithMissingMcidFieldContainsErrorIndicator(): void {
      $request = Request::create('https://nlp.example.org/');
      $this->requestStackMock->method('getCurrentRequest')->willReturn($request);
      $this->magicWordMock->method('getMagicWord')->willReturn('');

      $container = new ContainerBuilder();
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);

      // A user array without 'mcid' key triggers the "not an NLP user" branch.
      $user = ['uid' => 10, 'userName' => 'anon', 'roles' => ['authenticated']];
      $result = $this->buildDrupalUser()->displayCredentials(0, $user);

      $this->assertStringContainsString('not an NLP user', $result);
    }

    // -------------------------------------------------------------------------
    // addUser — early validation paths (no entity manager calls needed)
    // -------------------------------------------------------------------------

    public function testAddUserMissingUsernameReturnsNoUserNameStatus(): void {
      $result = $this->buildDrupalUser()->addUser([]);
      $this->assertSame('no userName', $result['status']);
    }

    public function testAddUserExistingUsernameReturnsExistsStatus(): void {
      // Storage returns a user so getUserByName returns non-empty.
      $this->mockUserStorageResult([$this->buildUserStub()]);
      $result = $this->buildDrupalUser()->addUser(['userName' => 'alice.smith']);
      $this->assertSame('exists', $result['status']);
    }

    public function testAddUserMissingEmailReturnsNoEmailStatus(): void {
      // User does not exist, so getUserByName returns [], but email is absent.
      $this->mockUserStorageResult([]);
      $result = $this->buildDrupalUser()->addUser(['userName' => 'newuser']);
      $this->assertSame('no email', $result['status']);
    }

    // -------------------------------------------------------------------------
    // createDrupalAccount
    // -------------------------------------------------------------------------

    public function testCreateDrupalAccountEmptyEmailReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildDrupalUser()->createDrupalAccount(['userName' => 'alice.smith']));
    }

    public function testCreateDrupalAccountCallsMagicWordWhenNoneProvided(): void {
      $this->magicWordMock->expects($this->once())
        ->method('createMagicWord')
        ->willReturn('crane5');

      // getUserByName check inside addUser needs a controlled stub.
      $this->mockUserStorageResult([]);

      // addUser will fail past the email check when it hits entity manager —
      // we only care that createMagicWord was called.
      try {
        $this->buildDrupalUser()->createDrupalAccount([
          'userName'  => 'alice.smith',
          'email'     => 'alice@example.com',
          'firstName' => 'Alice',
          'lastName'  => 'Smith',
          'phone'     => '555-1234',
          'county'    => 'Denver',
          'hd'        => '06',
          'mcid'      => 42,
        ]);
      } catch (\Throwable) {
        // Entity manager not wired; we already verified the mock expectation above.
      }
    }

  }

}
