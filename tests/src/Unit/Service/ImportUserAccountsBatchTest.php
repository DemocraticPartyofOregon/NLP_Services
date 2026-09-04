<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Batch\ImportUserAccountsBatch;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('nlpservices')]
class ImportUserAccountsBatchTest extends UnitTestCase {

  private DrupalUser $drupalUserMock;
  private MagicWord $magicWordMock;
  private MessengerInterface $messengerMock;
  private array $tempFiles = [];

  protected function setUp(): void {
    parent::setUp();

    $this->drupalUserMock = $this->createMock(DrupalUser::class);
    $this->magicWordMock  = $this->createMock(MagicWord::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation',    $this->getStringTranslationStub());
    $container->set('messenger',              $this->messengerMock);
    $container->set('nlpservices.drupal_user', $this->drupalUserMock);
    $container->set('nlpservices.magic_word',  $this->magicWordMock);
    Drupal::setContainer($container);

    $this->stubUserRoles([]);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
    foreach ($this->tempFiles as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
    $this->tempFiles = [];
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /** $rolesMap maps role id => label, mimicking Drupal's user_role storage. */
  private function stubUserRoles(array $rolesMap): void {
    $roleMocks = [];
    foreach ($rolesMap as $id => $label) {
      $roleMock = $this->createMock(RoleInterface::class);
      $roleMock->method('id')->willReturn($id);
      $roleMock->method('label')->willReturn($label);
      $roleMocks[] = $roleMock;
    }

    $storageMock = $this->createMock(EntityStorageInterface::class);
    $storageMock->method('loadMultiple')->willReturn($roleMocks);

    $etmMock = $this->createMock(EntityTypeManagerInterface::class);
    $etmMock->method('getStorage')->with('user_role')->willReturn($storageMock);

    Drupal::getContainer()->set('entity_type.manager', $etmMock);
  }

  private function stubUserRolesThrowsException(): void {
    $etmMock = $this->createMock(EntityTypeManagerInterface::class);
    $etmMock->method('getStorage')->willThrowException(new PluginNotFoundException('user_role'));

    Drupal::getContainer()->set('entity_type.manager', $etmMock);
  }

  private function headerColumns(): array {
    return ['userName', 'email', 'created', 'access', 'login', 'firstName',
      'lastName', 'phone', 'county', 'hd', 'mcid', 'roles', 'password'];
  }

  private function baseRow(array $overrides = []): array {
    $defaults = [
      'userName'  => 'jdoe',
      'email'     => 'jdoe@example.com',
      'created'   => '2024-01-01',
      'access'    => '2024-06-01',
      'login'     => '2024-06-02',
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
      'phone'     => '555-1234',
      'county'    => 'Denver',
      'hd'        => '12',
      'mcid'      => '6001',
      'roles'     => '',
      'password'  => 'fixedPW',
    ];
    $row = array_merge($defaults, $overrides);
    $ordered = [];
    foreach ($this->headerColumns() as $col) {
      $ordered[] = $row[$col];
    }
    return $ordered;
  }

  private function writeCsv(array $headers, array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'nlp_users_test_');
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, escape: '');
    foreach ($rows as $row) {
      fputcsv($fh, $row, escape: '');
    }
    fclose($fh);
    $this->tempFiles[] = $path;
    return $path;
  }

  private function baseContext(): array {
    return ['sandbox' => [], 'finished' => 0, 'results' => []];
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — file cannot be opened
  // -------------------------------------------------------------------------

  public function testBatchFileOpenFailureAddsErrorAndSetsFinished(): void {
    $this->messengerMock->expects($this->once())->method('addError');
    $this->drupalUserMock->expects($this->never())->method('getUserByName');

    $context = $this->baseContext();
    @ImportUserAccountsBatch::process(['uri' => '/nonexistent_dir_xyz/users.csv'], $context);

    $this->assertTrue($context['finished']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — role-loading exception is caught and ignored
  // -------------------------------------------------------------------------

  public function testBatchContinuesWhenRoleStorageThrows(): void {
    $this->stubUserRolesThrowsException();
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(1, $context['finished']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — reads and stores the CSV header on the first call
  // -------------------------------------------------------------------------

  public function testBatchStoresFieldPosInSandboxOnFirstCall(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(array_flip($this->headerColumns()), $context['sandbox']['fieldPos']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — existing, non-administrator user is updated
  // -------------------------------------------------------------------------

  public function testBatchUpdatesExistingNonAdminUser(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')
      ->willReturn(['uid' => 55, 'roles' => ['nlp_leader']]);
    $this->drupalUserMock->expects($this->once())
      ->method('updateUser')
      ->with([
        'uid'       => 55,
        'county'    => 'Denver',
        'hd'        => '12',
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'phone'     => '555-1234',
        'mcid'      => '6001',
      ]);
    $this->drupalUserMock->expects($this->never())->method('addUser');

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  public function testBatchUpdateIncludesRolesWhenRolesArePresent(): void {
    $this->stubUserRoles(['nlp_leader' => 'NLP Leader']);
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['roles' => 'x:nlp_leader'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn(['uid' => 55, 'roles' => []]);

    $captured = NULL;
    $this->drupalUserMock->expects($this->once())
      ->method('updateUser')
      ->willReturnCallback(function (array $editUpdate) use (&$captured): array {
        $captured = $editUpdate;
        return [];
      });

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(['nlp_leader' => 'NLP Leader'], $captured['roles']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — existing administrator accounts are never touched
  // -------------------------------------------------------------------------

  public function testBatchSkipsAdministratorUsers(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')
      ->willReturn(['uid' => 1, 'roles' => ['administrator']]);
    $this->drupalUserMock->expects($this->never())->method('updateUser');
    $this->drupalUserMock->expects($this->never())->method('addUser');

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — new user creation, password handling
  // -------------------------------------------------------------------------

  public function testBatchNewUserGeneratesMagicWordWhenPasswordEmpty(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['password' => ''])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->expects($this->once())->method('createMagicWord')->willReturn('GENERATED');

    $captured = NULL;
    $this->drupalUserMock->method('addUser')
      ->willReturnCallback(function (array $account) use (&$captured): array {
        $captured = $account;
        return ['status' => 'complete'];
      });

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame('GENERATED', $captured['magicWord']);
  }

  public function testBatchNewUserGeneratesMagicWordWhenPasswordUnknown(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['password' => 'unknown'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->expects($this->once())->method('createMagicWord')->willReturn('GENERATED');
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  public function testBatchNewUserUsesProvidedPasswordWhenPresent(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['password' => 'mySecret123'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->magicWordMock->expects($this->never())->method('createMagicWord');

    $captured = NULL;
    $this->drupalUserMock->method('addUser')
      ->willReturnCallback(function (array $account) use (&$captured): array {
        $captured = $account;
        return ['status' => 'complete'];
      });

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame('mySecret123', $captured['magicWord']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — new user creation, login defaults to access
  // -------------------------------------------------------------------------

  public function testBatchNewUserDefaultsLoginToAccessWhenLoginEmpty(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['login' => '', 'access' => '2024-06-05'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);

    $captured = NULL;
    $this->drupalUserMock->method('addUser')
      ->willReturnCallback(function (array $account) use (&$captured): array {
        $captured = $account;
        return ['status' => 'complete'];
      });

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame('2024-06-05', $captured['login']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — new user creation, role parsing
  // -------------------------------------------------------------------------

  public function testBatchNewUserParsesRolesAndSkipsAuthenticatedAndUnknownRoles(): void {
    $this->stubUserRoles(['nlp_leader' => 'NLP Leader']);
    $rolesString = 'a:nlp_leader;b:authenticated;c:unknown_role;';
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['roles' => $rolesString])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);

    $captured = NULL;
    $this->drupalUserMock->method('addUser')
      ->willReturnCallback(function (array $account) use (&$captured): array {
        $captured = $account;
        return ['status' => 'complete'];
      });

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(['nlp_leader' => 'NLP Leader'], $captured['roles']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — new user creation, addUser status handling
  // -------------------------------------------------------------------------

  public function testBatchNewUserCompleteWithMcidSetsMagicWord(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['password' => 'fixedPW'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete', 'mcid' => 7001]);
    $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(7001, 'fixedPW');

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  public function testBatchNewUserCompleteWithoutMcidSkipsSetMagicWord(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);
    $this->magicWordMock->expects($this->never())->method('setMagicWord');

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  public function testBatchNewUserErrorStatusAddsStatusMessage(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow()]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')
      ->willReturn(['status' => 'error', 'firstName' => 'Jane', 'lastName' => 'Doe']);
    $this->messengerMock->expects($this->once())->method('addStatus');

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  public function testBatchNewUserExistsStatusUpdatesUserWithRoles(): void {
    $this->stubUserRoles(['nlp_leader' => 'NLP Leader']);
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(['roles' => 'x:nlp_leader'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'exists', 'uid' => 99]);
    $this->drupalUserMock->expects($this->once())
      ->method('updateUser')
      ->with(['uid' => 99, 'roles' => ['nlp_leader' => 'NLP Leader']]);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — sets results when the file finishes in one pass
  // -------------------------------------------------------------------------

  public function testBatchSetsResultsWhenFinishedInOnePass(): void {
    $path = $this->writeCsv($this->headerColumns(), [$this->baseRow(), $this->baseRow(['userName' => 'other'])]);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(1, $context['finished']);
    $this->assertSame(2, $context['results']['recordCount']);
    $this->assertSame($path, $context['results']['uri']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — pauses and saves a seek position at MAX_QUEUE_LIMIT
  // -------------------------------------------------------------------------

  public function testBatchPausesAndSavesSeekAtMaxQueueLimit(): void {
    $rows = [];
    for ($i = 0; $i < 101; $i++) {
      $rows[] = $this->baseRow(['userName' => "user$i"]);
    }
    $path = $this->writeCsv($this->headerColumns(), $rows);
    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertGreaterThan(0, $context['finished']);
    $this->assertLessThan(1, $context['finished']);
    $this->assertArrayHasKey('seek', $context['sandbox']);
    $this->assertSame(100, $context['sandbox']['recordCount']);
    $this->assertArrayNotHasKey('recordCount', $context['results']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::process — resumes from a previously saved seek position
  // -------------------------------------------------------------------------

  public function testBatchResumesFromSavedSeekPosition(): void {
    $header = $this->headerColumns();
    $rows = [$this->baseRow(['userName' => 'first']), $this->baseRow(['userName' => 'second'])];
    $path = $this->writeCsv($header, $rows);

    // Calculate the byte offset after the header + first data row.
    $fh = fopen($path, 'r');
    fgetcsv($fh, escape: ''); // header
    fgetcsv($fh, escape: ''); // first data row — already processed in a prior batch.
    $seekPos = ftell($fh);
    fclose($fh);

    $this->drupalUserMock->method('getUserByName')->willReturn([]);
    $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete']);

    $context = $this->baseContext();
    $context['sandbox']['seek']        = $seekPos;
    $context['sandbox']['recordCount'] = 1;
    $context['sandbox']['fieldPos']    = array_flip($header);

    ImportUserAccountsBatch::process(['uri' => $path], $context);

    $this->assertSame(1, $context['finished']);
    // The prior count (1) plus the resumed record (second) = 2.
    $this->assertSame(2, $context['results']['recordCount']);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::finished — success
  // -------------------------------------------------------------------------

  public function testFinishedSuccessAddsTwoStatusMessages(): void {
    $this->messengerMock->expects($this->exactly(2))->method('addStatus');
    $this->messengerMock->expects($this->never())->method('addError');

    ImportUserAccountsBatch::finished(TRUE, ['recordCount' => 5], []);
  }

  // -------------------------------------------------------------------------
  // ImportUserAccountsBatch::finished — failure
  // -------------------------------------------------------------------------

  public function testFinishedFailureAddsError(): void {
    $this->messengerMock->expects($this->once())->method('addError');
    $this->messengerMock->expects($this->never())->method('addStatus');

    ImportUserAccountsBatch::finished(FALSE, [], []);
  }

}
