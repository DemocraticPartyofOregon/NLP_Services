<?php

// nlp_debug_msg lives in nlpservices.module, which is not autoloaded in unit tests.
// Bracketed namespace syntax is required to declare a global stub alongside a named namespace.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!defined('NLP_COORDINATOR_ROLE_ID')) {
    define('NLP_COORDINATOR_ROLE_ID', 'nlp_coordinator');
  }
}

namespace Drupal\Tests\nlpservices\Unit {

  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\ImmutableConfig;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpEncryption;
  use Drupal\Tests\UnitTestCase;

  class MagicWordTest extends UnitTestCase {

    private ConfigFactoryInterface $configFactoryMock;
    private Connection $connectionMock;
    private NlpEncryption $encryptMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $this->connectionMock    = $this->createMock(Connection::class);
      $this->encryptMock       = $this->createMock(NlpEncryption::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildMagicWord(): MagicWord {
      return new MagicWord(
        $this->configFactoryMock,
        $this->connectionMock,
        $this->encryptMock,
      );
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('key')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    private function setupConfigWithWords(array $words): void {
      $configMock = $this->createMock(ImmutableConfig::class);
      $configMock->method('get')->with('nlpservices-magic-words')->willReturn($words);
      $this->configFactoryMock->method('get')
        ->with('nlpservices.configuration')
        ->willReturn($configMock);
    }

    private function baseRawCredentials(): array {
      return [
        'mcid'               => 42,
        'userName'           => 'alice.smith',
        'email'              => 'alice@example.com',
        'county'             => 'Denver',
        'hd'                 => 6,
        'magicWordEncrypted' => 'ENC:secret7',
        'roles'              => '{"nlp_leader":"NLP Leader"}',
        'access'             => 0,
        'created'            => 0,
        'vfid'               => 0,
      ];
    }

    // -------------------------------------------------------------------------
    // createNewCredentials — pure, no dependencies
    // -------------------------------------------------------------------------

    public function testCreateNewCredentialsReturnsCorrectIdentifiers(): void {
      $result = $this->buildMagicWord()->createNewCredentials(42, 'alice.smith', 'alice@example.com');
      $this->assertSame(42, $result['mcid']);
      $this->assertSame('alice.smith', $result['userName']);
      $this->assertSame('alice@example.com', $result['email']);
    }

    public function testCreateNewCredentialsHasExpectedDefaults(): void {
      $result = $this->buildMagicWord()->createNewCredentials(1, 'u', 'u@x.com');
      $this->assertSame('', $result['county']);
      $this->assertSame(0, $result['hd']);
      $this->assertSame('', $result['roles']);
      $this->assertSame(0, $result['access']);
      $this->assertSame(0, $result['created']);
      $this->assertSame(0, $result['vfid']);
    }

    // -------------------------------------------------------------------------
    // createMagicWord
    // -------------------------------------------------------------------------

    public function testCreateMagicWordEndsWithDigitTwoToNine(): void {
      $this->setupConfigWithWords(['apple', 'beach', 'crane']);
      $result = $this->buildMagicWord()->createMagicWord();
      $this->assertMatchesRegularExpression('/[2-9]$/', $result);
    }

    public function testCreateMagicWordWordPartIsFromConfigList(): void {
      $words = ['apple', 'beach', 'crane'];
      $this->setupConfigWithWords($words);
      $result = $this->buildMagicWord()->createMagicWord();
      $this->assertContains(substr($result, 0, -1), $words);
    }

    // -------------------------------------------------------------------------
    // setMagicWord
    // -------------------------------------------------------------------------

    public function testSetMagicWordEncryptsBeforeSaving(): void {
      $this->encryptMock->expects($this->once())
        ->method('encrypt_decrypt')
        ->with('encrypt', 'secret7')
        ->willReturn('ENC:secret7');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->buildMagicWord()->setMagicWord(42, 'secret7');
    }

    public function testSetMagicWordReturnsTrueOnSuccess(): void {
      $this->encryptMock->method('encrypt_decrypt')->willReturn('ENC');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->assertTrue($this->buildMagicWord()->setMagicWord(42, 'word5'));
    }

    public function testSetMagicWordReturnsFalseOnException(): void {
      $this->encryptMock->method('encrypt_decrypt')->willReturn('ENC');
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildMagicWord()->setMagicWord(42, 'word5'));
    }

    // -------------------------------------------------------------------------
    // getMagicWord
    // -------------------------------------------------------------------------

    public function testGetMagicWordNotFoundReturnsEmptyString(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame('', $this->buildMagicWord()->getMagicWord(42));
    }

    public function testGetMagicWordFoundDecryptsAndReturns(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['magicWordEncrypted' => 'ENC:secret7']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->encryptMock->method('encrypt_decrypt')->with('decrypt', 'ENC:secret7')->willReturn('secret7');
      $this->assertSame('secret7', $this->buildMagicWord()->getMagicWord(42));
    }

    public function testGetMagicWordEmptyEncryptedReturnsEmptyString(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['magicWordEncrypted' => '']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame('', $this->buildMagicWord()->getMagicWord(42));
    }

    public function testGetMagicWordExceptionReturnsEmptyString(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame('', $this->buildMagicWord()->getMagicWord(42));
    }

    // -------------------------------------------------------------------------
    // getCredentials
    // -------------------------------------------------------------------------

    public function testGetCredentialsNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildMagicWord()->getCredentials(42));
    }

    public function testGetCredentialsDecryptedTrueDecryptsMagicWordAndRemovesEncrypted(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->encryptMock->method('encrypt_decrypt')->with('decrypt', 'ENC:secret7')->willReturn('secret7');
      $result = $this->buildMagicWord()->getCredentials(42, TRUE);
      $this->assertSame('secret7', $result['magicWord']);
      $this->assertArrayNotHasKey('magicWordEncrypted', $result);
    }

    public function testGetCredentialsDecryptedTrueEmptyEncryptedStoresEmptyMagicWord(): void {
      $raw = array_merge($this->baseRawCredentials(), ['magicWordEncrypted' => '']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($raw);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildMagicWord()->getCredentials(42, TRUE);
      $this->assertSame('', $result['magicWord']);
    }

    public function testGetCredentialsDecryptedFalseKeepsMagicWordEncrypted(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildMagicWord()->getCredentials(42, FALSE);
      $this->assertArrayHasKey('magicWordEncrypted', $result);
      $this->assertArrayNotHasKey('magicWord', $result);
    }

    public function testGetCredentialsAlwaysDecodesRolesJson(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->encryptMock->method('encrypt_decrypt')->willReturn('');
      $result = $this->buildMagicWord()->getCredentials(42, FALSE);
      $this->assertSame(['nlp_leader' => 'NLP Leader'], $result['roles']);
    }

    public function testGetCredentialsExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildMagicWord()->getCredentials(42));
    }

    // -------------------------------------------------------------------------
    // getCredentialsByEmail
    // -------------------------------------------------------------------------

    public function testGetCredentialsByEmailNotFoundReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildMagicWord()->getCredentialsByEmail('x@x.com'));
    }

    public function testGetCredentialsByEmailRemovesEncryptedFieldAndDecodesRoles(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildMagicWord()->getCredentialsByEmail('alice@example.com');
      $this->assertArrayNotHasKey('magicWordEncrypted', $result);
      $this->assertSame(['nlp_leader' => 'NLP Leader'], $result['roles']);
    }

    // -------------------------------------------------------------------------
    // getCredentialsByUsername
    // -------------------------------------------------------------------------

    public function testGetCredentialsByUsernameEmptyUsernameSkipsDatabase(): void {
      $this->connectionMock->expects($this->never())->method('select');
      $this->assertSame([], $this->buildMagicWord()->getCredentialsByUsername(''));
    }

    public function testGetCredentialsByUsernameFoundReturnsCleanCredentials(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildMagicWord()->getCredentialsByUsername('alice.smith');
      $this->assertArrayNotHasKey('magicWordEncrypted', $result);
      $this->assertSame(['nlp_leader' => 'NLP Leader'], $result['roles']);
    }

    // -------------------------------------------------------------------------
    // createCredentials
    // -------------------------------------------------------------------------

    public function testCreateCredentialsNonEmptyMagicWordIsEncrypted(): void {
      $this->encryptMock->expects($this->once())
        ->method('encrypt_decrypt')
        ->with('encrypt', 'secret7')
        ->willReturn('ENC:secret7');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->buildMagicWord()->createCredentials(['mcid' => 42, 'magicWord' => 'secret7']);
    }

    public function testCreateCredentialsEmptyMagicWordSkipsEncryption(): void {
      $this->encryptMock->expects($this->never())->method('encrypt_decrypt');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->buildMagicWord()->createCredentials(['mcid' => 42, 'magicWord' => '']);
    }

    public function testCreateCredentialsRolesArrayIsJsonEncoded(): void {
      $roles = ['nlp_admin' => 'NLP Admin'];
      $captured = NULL;
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$captured, $mergeMock): Merge {
          $captured = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildMagicWord()->createCredentials(['mcid' => 42, 'roles' => $roles]);
      $this->assertSame(json_encode($roles), $captured['roles']);
    }

    public function testCreateCredentialsReturnsFalseOnException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildMagicWord()->createCredentials(['mcid' => 42]));
    }

    public function testCreateCredentialsReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->assertTrue($this->buildMagicWord()->createCredentials(['mcid' => 42]));
    }

    // -------------------------------------------------------------------------
    // updateCredentials
    // -------------------------------------------------------------------------

    public function testUpdateCredentialsMagicWordIsEncryptedBeforeStore(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $this->encryptMock->expects($this->once())
        ->method('encrypt_decrypt')
        ->with('encrypt', 'newpass5')
        ->willReturn('ENC:newpass5');
      $this->buildMagicWord()->updateCredentials(['mcid' => 42, 'magicWord' => 'newpass5']);
    }

    public function testUpdateCredentialsMergesIncomingFieldsOverCurrent(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($this->baseRawCredentials());
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());
      $stored = NULL;
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('fields')->willReturnCallback(
        function (array $fields) use (&$stored, $mergeMock): Merge {
          $stored = $fields;
          return $mergeMock;
        }
      );
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildMagicWord()->updateCredentials(['mcid' => 42, 'email' => 'new@example.com']);
      $this->assertSame('alice.smith', $stored['userName']);  // preserved from current.
      $this->assertSame('new@example.com', $stored['email']); // overwritten by incoming.
    }

    // -------------------------------------------------------------------------
    // emailIsUnique
    // -------------------------------------------------------------------------

    public function testEmailIsUniqueReturnsTrueWhenNotInDatabase(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertTrue($this->buildMagicWord()->emailIsUnique('new@example.com'));
    }

    public function testEmailIsUniqueReturnsFalseWhenInDatabase(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(['email' => 'taken@example.com']);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertFalse($this->buildMagicWord()->emailIsUnique('taken@example.com'));
    }

    public function testEmailIsUniqueReturnsFalseOnException(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertFalse($this->buildMagicWord()->emailIsUnique('x@x.com'));
    }

    // -------------------------------------------------------------------------
    // uniqueUserName
    // -------------------------------------------------------------------------

    public function testUniqueUserNameFirstAttemptUsesFirstNameDotLastName(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false); // unique
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'nickName' => '', 'middleName' => ''];
      $tried = [];
      $this->assertSame('alice.smith', $this->buildMagicWord()->uniqueUserName($nl, $tried));
    }

    public function testUniqueUserNameFirstAttemptUsesNickNameWhenPresent(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'nickName' => 'Ali', 'middleName' => ''];
      $tried = [];
      $this->assertSame('ali.smith', $this->buildMagicWord()->uniqueUserName($nl, $tried));
    }

    public function testUniqueUserNameStripsHyphensApostrophesAndSpaces(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => "O'Brien-Jones", 'nickName' => '', 'middleName' => ''];
      $tried = [];
      $this->assertSame('alice.obrienjones', $this->buildMagicWord()->uniqueUserName($nl, $tried));
    }

    public function testUniqueUserNameFallsBackToInitialsPlusLastNameWhenFirstTaken(): void {
      $stmtTaken = $this->createMock(StatementInterface::class);
      $stmtTaken->method('fetchAssoc')->willReturn(['userName' => 'alice.smith', 'mcid' => 99]);
      $stmtFree = $this->createMock(StatementInterface::class);
      $stmtFree->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturnOnConsecutiveCalls(
        $this->buildSelectMock($stmtTaken),
        $this->buildSelectMock($stmtFree),
      );
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'nickName' => '', 'middleName' => 'B'];
      $tried = [];
      $this->assertSame('absmith', $this->buildMagicWord()->uniqueUserName($nl, $tried));
    }

    public function testUniqueUserNameExhaustedAttemptsReturnsEmptyString(): void {
      // Pre-populating namesTried with 10 entries puts tryCount=10 on the first
      // loop iteration, hitting the >9 guard before any DB call.
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'nickName' => '', 'middleName' => ''];
      $tried = array_fill(0, 10, 'placeholder');
      $this->connectionMock->expects($this->never())->method('select');
      $this->assertSame('', $this->buildMagicWord()->uniqueUserName($nl, $tried));
    }

    // -------------------------------------------------------------------------
    // removeCoordinatorRole
    // -------------------------------------------------------------------------

    public function testRemoveCoordinatorRoleRemovesItWhenPresent(): void {
      $raw = array_merge($this->baseRawCredentials(), [
        'roles' => json_encode(['nlp_coordinator' => 'NLP Coordinator', 'nlp_leader' => 'NLP Leader']),
      ]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($raw);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildMagicWord()->removeCoordinatorRole(42);
    }

    public function testRemoveCoordinatorRoleIsNoopWhenCoordinatorAbsent(): void {
      $raw = array_merge($this->baseRawCredentials(), [
        'roles' => json_encode(['nlp_leader' => 'NLP Leader']),
      ]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($raw);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->expects($this->never())->method('merge');
      $this->buildMagicWord()->removeCoordinatorRole(42);
    }

  }

}
