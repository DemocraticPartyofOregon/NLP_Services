<?php

// nlp_debug_msg lives in nlpservices.module; user_load_by_* are Drupal global helpers.
// Neither is autoloaded in unit tests, so we stub them here.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('user_load_by_name')) {
    function user_load_by_name(string $name): mixed {
      return \Drupal\Tests\nlpservices\Unit\Service\DrupalUserTest::$stubUserByName;
    }
  }
  if (!function_exists('user_load_by_mail')) {
    function user_load_by_mail(string $mail): mixed {
      return \Drupal\Tests\nlpservices\Unit\Service\DrupalUserTest::$stubUserByMail;
    }
  }
  if (!defined('NLP_ADMIN_ROLE_ID'))        { define('NLP_ADMIN_ROLE_ID',        'nlp_admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_ID'))  { define('NLP_COORDINATOR_ROLE_ID',  'nlp_coordinator'); }
  if (!defined('NLP_LEADER_ROLE_ID'))       { define('NLP_LEADER_ROLE_ID',       'nlp_leader'); }
  if (!defined('NLP_LEADER_ROLE_LABEL'))    { define('NLP_LEADER_ROLE_LABEL',    'NLP Leader'); }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class DrupalUserTest extends UnitTestCase {

    // Controlled by individual tests; user_load_by_* stubs read these.
    public static mixed $stubUserByName = FALSE;
    public static mixed $stubUserByMail = FALSE;

    private MagicWord $magicWordMock;
    private RequestStack $requestStackMock;

    protected function setUp(): void {
      parent::setUp();
      self::$stubUserByName = FALSE;
      self::$stubUserByMail = FALSE;
      $this->magicWordMock    = $this->createMock(MagicWord::class);
      $this->requestStackMock = $this->createMock(RequestStack::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildDrupalUser(): DrupalUser {
      return new DrupalUser($this->magicWordMock, $this->requestStackMock);
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
      $authMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['authenticate'])
        ->getMock();
      $authMock->method('authenticate')->willReturn(42);
      $container = new ContainerBuilder();
      $container->set('user.auth', $authMock);
      Drupal::setContainer($container);

      $this->assertTrue($this->buildDrupalUser()->verifyPassword('alice.smith', 'pass5'));
    }

    public function testVerifyPasswordReturnsFalseWhenAuthFails(): void {
      $authMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['authenticate'])
        ->getMock();
      $authMock->method('authenticate')->willReturn(FALSE);
      $container = new ContainerBuilder();
      $container->set('user.auth', $authMock);
      Drupal::setContainer($container);

      $this->assertFalse($this->buildDrupalUser()->verifyPassword('alice.smith', 'wrong5'));
    }

    // -------------------------------------------------------------------------
    // getUserByName
    // -------------------------------------------------------------------------

    public function testGetUserByNameEmptyStringReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildDrupalUser()->getUserByName(''));
    }

    public function testGetUserByNameNotFoundReturnsEmptyArray(): void {
      self::$stubUserByName = FALSE;
      $this->assertSame([], $this->buildDrupalUser()->getUserByName('nobody'));
    }

    public function testGetUserByNameFoundReturnsUserInfoArray(): void {
      self::$stubUserByName = $this->buildUserStub();
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
      self::$stubUserByMail = FALSE;
      $this->assertSame([], $this->buildDrupalUser()->getUserByEmail('nobody@example.com'));
    }

    public function testGetUserByEmailFoundReturnsUserInfoArray(): void {
      self::$stubUserByMail = $this->buildUserStub();
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
      $loggerMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['notice'])
        ->getMock();
      $loggerFactoryMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['get'])
        ->getMock();
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
      // Stub user_load_by_name to return a user so getUserByName returns non-empty.
      self::$stubUserByName = $this->buildUserStub();
      $result = $this->buildDrupalUser()->addUser(['userName' => 'alice.smith']);
      $this->assertSame('exists', $result['status']);
    }

    public function testAddUserMissingEmailReturnsNoEmailStatus(): void {
      // User does not exist, so getUserByName returns [], but email is absent.
      self::$stubUserByName = FALSE;
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
      self::$stubUserByName = FALSE;

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
