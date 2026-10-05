<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpMcidReassignment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests moving an NL's records to the MCID that survived a MyCampaign merge.
 *
 * The reassignment is a set of UPDATEs and DELETEs across a dozen tables
 * with different keys; a mocked Connection would accept any of them, so only
 * a real database shows every row lands under the new MCID and nothing else
 * is touched. The Drupal login is mocked: it lives in a user field this test
 * doesn't need to install.
 */
#[CoversClass(NlpMcidReassignment::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class McidReassignmentTest extends KernelTestBase {

  protected static $modules = ['user', 'file', 'nlpservices'];

  const int OLD = 100000001;
  const int NEW = 100000002;
  const int OTHER = 100000003;

  private Connection $connection;
  private DrupalUser $drupalUser;
  private MagicWord $magicWord;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('nlpservices', [
      'nlp_awards', 'nlp_coordinator', 'nlp_legislative_fixes', 'nlp_magic_word',
      'nlp_mcid_recovery', 'nlp_nls', 'nlp_nls_status', 'nlp_results', 'nlp_turf',
      'nlp_turf_assignment', 'nlp_voter_turf',
    ]);
    $this->connection = $this->container->get('database');
    $this->drupalUser = $this->createMock(DrupalUser::class);
    $this->magicWord = $this->createMock(MagicWord::class);
  }

  private function reassign(): array {
    $service = new NlpMcidReassignment($this->connection, $this->drupalUser, $this->magicWord);
    return $service->reassign(self::OLD, self::NEW);
  }

  private function mcids(string $table): array {
    return array_map('intval', $this->connection->select($table, 't')
      ->fields('t', ['mcid'])
      ->orderBy('mcid')
      ->execute()
      ->fetchCol());
  }

  private function seedNl(int $mcid): void {
    $this->connection->insert('nlp_magic_word')
      ->fields(['mcid' => $mcid, 'userName' => "nl$mcid", 'vfid' => 50])->execute();
    $this->connection->insert('nlp_coordinator')
      ->fields(['mcid' => $mcid, 'county' => 'Denver', 'firstName' => 'A', 'lastName' => 'B',
        'email' => 'a@b.c', 'scope' => 'county'])->execute();
    $this->connection->insert('nlp_results')
      ->fields(['mcid' => $mcid, 'cycle' => '2026-11', 'contactDate' => '2026-10-01'])->execute();
    $turf = $this->connection->insert('nlp_turf')
      ->fields(['mcid' => $mcid, 'county' => 'Denver'])->execute();
    $this->connection->insert('nlp_turf_assignment')
      ->fields(['turfId' => $turf, 'mcid' => $mcid])->execute();
    $this->connection->insert('nlp_voter_turf')
      ->fields(['mcid' => $mcid, 'vanid' => 7, 'turfIndex' => $turf])->execute();
    $this->connection->insert('nlp_awards')
      ->fields(['mcid' => $mcid])->execute();
    $this->connection->insert('nlp_legislative_fixes')
      ->fields(['mcid' => $mcid, 'county' => 'Denver', 'hd' => 6, 'precinct' => '6.1'])->execute();
    $this->connection->insert('nlp_nls')
      ->fields(['mcid' => $mcid, 'county' => 'Denver'])->execute();
    $this->connection->insert('nlp_nls_status')
      ->fields(['mcid' => $mcid, 'county' => 'Denver', 'signedUp' => 'Y', 'notes' => "old $mcid"])->execute();
    $this->connection->insert('nlp_mcid_recovery')
      ->fields(['recovery_key' => "key$mcid", 'mcid' => $mcid, 'created' => 1])->execute();
  }

  public function testEveryTableMovesToTheNewMcid(): void {
    $this->seedNl(self::OLD);
    $this->seedNl(self::OTHER);
    $this->drupalUser->method('getUserByMcid')
      ->willReturnMap([[self::NEW, []], [self::OLD, ['uid' => 5, 'mcid' => self::OLD]]]);
    $this->drupalUser->expects($this->once())->method('updateUser')
      ->with(['uid' => 5, 'mcid' => self::NEW])
      ->willReturn(['status' => 'complete']);

    $this->assertSame(['ok' => TRUE], $this->reassign());

    foreach (array_merge(NlpMcidReassignment::SHARED_TABLES, NlpMcidReassignment::KEYED_TABLES,
      ['nlp_magic_word', 'nlp_nls_status']) as $table) {
      $this->assertSame([self::NEW, self::OTHER], $this->mcids($table), $table);
    }
    // The old MCID's recovery key is dropped; another NL's is left alone.
    $this->assertSame([self::OTHER], $this->mcids('nlp_mcid_recovery'));
  }

  public function testOldMcidRowsReplaceRowsTheDuplicateAlreadyHad(): void {
    $this->drupalUser->method('getUserByMcid')->willReturn([]);
    $this->connection->insert('nlp_legislative_fixes')
      ->fields(['mcid' => self::OLD, 'county' => 'Denver', 'hd' => 6, 'precinct' => 'old'])->execute();
    $this->connection->insert('nlp_legislative_fixes')
      ->fields(['mcid' => self::NEW, 'county' => 'Denver', 'hd' => 7, 'precinct' => 'new'])->execute();
    $this->connection->insert('nlp_nls_status')
      ->fields(['mcid' => self::OLD, 'county' => 'Denver', 'notes' => 'old'])->execute();
    $this->connection->insert('nlp_nls_status')
      ->fields(['mcid' => self::NEW, 'county' => 'Denver', 'notes' => 'new'])->execute();
    $this->connection->insert('nlp_nls_status')
      ->fields(['mcid' => self::NEW, 'county' => 'Boulder', 'notes' => 'new elsewhere'])->execute();

    $this->assertSame(['ok' => TRUE], $this->reassign());

    $fix = $this->connection->select('nlp_legislative_fixes', 'f')->fields('f')->execute()->fetchAll();
    $this->assertCount(1, $fix);
    $this->assertSame('old', $fix[0]->precinct);
    $status = $this->connection->select('nlp_nls_status', 's')
      ->fields('s', ['county', 'notes'])->condition('mcid', self::NEW)->orderBy('county')
      ->execute()->fetchAllKeyed();
    $this->assertSame(['Boulder' => 'new elsewhere', 'Denver' => 'old'], $status);
  }

  public function testRefusesWhenTheNewMcidAlreadyHasALogin(): void {
    $this->seedNl(self::OLD);
    $this->drupalUser->method('getUserByMcid')->willReturn(['uid' => 9]);
    $this->drupalUser->expects($this->never())->method('updateUser');

    $result = $this->reassign();

    $this->assertFalse($result['ok']);
    $this->assertSame([self::OLD], $this->mcids('nlp_turf'));
  }

  public function testFailedLoginUpdateRollsBackEveryTable(): void {
    $this->seedNl(self::OLD);
    $this->drupalUser->method('getUserByMcid')
      ->willReturnMap([[self::NEW, []], [self::OLD, ['uid' => 5]]]);
    $this->drupalUser->method('updateUser')->willReturn(['status' => 'error']);

    $result = $this->reassign();

    $this->assertFalse($result['ok']);
    $this->assertSame([self::OLD], $this->mcids('nlp_magic_word'));
    $this->assertSame([self::OLD], $this->mcids('nlp_turf'));
  }

}
