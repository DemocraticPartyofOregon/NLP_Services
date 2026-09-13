<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\NlpMcidRecovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests NlpMcidRecovery's database-backed expiry/replay behavior against a
 * real DB. This is a security-sensitive account-recovery flow where the
 * boundary conditions (exact expiry cutoff, delete-after-use) are exactly
 * what a mocked Connection can't verify - a mock only proves the code called
 * ->condition('created', $cutoff, '<'), never that the real query actually
 * filters the row it should.
 */
#[CoversClass(NlpMcidRecovery::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class NlpMcidRecoveryTest extends KernelTestBase {

  protected static $modules = ['user', 'file', 'nlpservices'];

  private NlpMcidRecovery $recovery;
  private Connection $connection;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('nlpservices', ['nlp_mcid_recovery']);
    $this->recovery = $this->container->get('nlpservices.mcid_recovery');
    $this->connection = $this->container->get('database');
  }

  public function testSaveAndGetMcidRoundTrip(): void {
    $key = $this->recovery->saveRecoveryKey(12345);

    $this->assertNotSame('', $key);
    $this->assertSame(64, strlen($key), 'Key should be a hex-encoded 32-byte value.');
    $this->assertSame(12345, $this->recovery->getMcid($key));
  }

  public function testGetMcidReturnsZeroForUnknownKey(): void {
    $this->assertSame(0, $this->recovery->getMcid('nonexistent-key'));
  }

  public function testGetMcidReturnsZeroForExpiredKey(): void {
    $key = 'expired-key-0000000000000000000000000000000000000000000000000';
    $expiredCreated = \Drupal::time()->getRequestTime() - NlpMcidRecovery::LIFETIME_SECONDS - 60;
    $this->connection->insert('nlp_mcid_recovery')
      ->fields(['recovery_key' => $key, 'mcid' => 999, 'created' => $expiredCreated])
      ->execute();

    $this->assertSame(0, $this->recovery->getMcid($key));
  }

  public function testDeleteKeyPreventsReplay(): void {
    $key = $this->recovery->saveRecoveryKey(555);
    $this->assertSame(555, $this->recovery->getMcid($key));

    $this->recovery->deleteKey($key);

    $this->assertSame(0, $this->recovery->getMcid($key));
  }

  public function testPurgeExpiredRemovesOnlyExpiredRows(): void {
    $now = \Drupal::time()->getRequestTime();
    $freshKey = 'fresh-key-00000000000000000000000000000000000000000000000000';
    $expiredKey = 'stale-key-00000000000000000000000000000000000000000000000000';
    $this->connection->insert('nlp_mcid_recovery')
      ->fields(['recovery_key' => $freshKey, 'mcid' => 1, 'created' => $now])
      ->execute();
    $this->connection->insert('nlp_mcid_recovery')
      ->fields(['recovery_key' => $expiredKey, 'mcid' => 2, 'created' => $now - NlpMcidRecovery::LIFETIME_SECONDS - 60])
      ->execute();

    $this->recovery->purgeExpired();

    $remaining = $this->connection->select('nlp_mcid_recovery', 'r')
      ->fields('r', ['recovery_key'])
      ->execute()
      ->fetchCol();
    $this->assertSame([$freshKey], $remaining);
  }

}
