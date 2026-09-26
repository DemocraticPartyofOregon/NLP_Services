<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the queries the turf sync uses to find and clear what a failed sync
 * left behind.  Both are built from conditions a mocked Connection accepts
 * whatever their meaning - a DELETE with a NOT IN subquery, and an OR of an
 * AND group - so only a real database shows they select the right rows.
 */
#[CoversClass(NlpVoters::class)]
#[CoversClass(NlpTurfs::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class TurfSyncCleanupTest extends KernelTestBase {

  protected static $modules = ['user', 'file', 'nlpservices'];

  private NlpVoters $voters;
  private NlpTurfs $turfs;
  private Connection $connection;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('nlpservices', ['nlp_turf', 'nlp_voter_turf', 'nlp_voter_address']);
    $this->voters = $this->container->get('nlpservices.voters');
    $this->turfs = $this->container->get('nlpservices.turfs');
    $this->connection = $this->container->get('database');
  }

  private function addTurf(array $fields): int {
    return (int) $this->connection->insert('nlp_turf')
      ->fields($fields + ['county' => 'Denver'])
      ->execute();
  }

  private function addTurfVoter(int $vanid, int $turfIndex): void {
    $this->connection->insert('nlp_voter_turf')
      ->fields(['vanid' => $vanid, 'turfIndex' => $turfIndex, 'mcid' => 42, 'county' => 'Denver'])
      ->execute();
    $this->connection->insert('nlp_voter_address')
      ->fields(['vanid' => $vanid, 'turfIndex' => $turfIndex, 'county' => 'Denver'])
      ->execute();
  }

  private function rows(string $table): array {
    return $this->connection->select($table, 't')
      ->fields('t', ['vanid', 'turfIndex'])
      ->orderBy('vanid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function testDeleteOrphanTurfVotersRemovesOnlyRowsForMissingTurfs(): void {
    $liveTurf = $this->addTurf(['turfName' => 'Live']);
    $goneTurf = $liveTurf + 100;
    $this->addTurfVoter(101, $liveTurf);   // Assigned to a real turf: kept.
    $this->addTurfVoter(102, $goneTurf);   // Orphan in the sync: removed.
    $this->addTurfVoter(103, $goneTurf);   // Orphan, but not in the sync: kept.

    $this->voters->deleteOrphanTurfVoters([101, 102]);

    $expected = [
      ['vanid' => '101', 'turfIndex' => (string) $liveTurf],
      ['vanid' => '103', 'turfIndex' => (string) $goneTurf],
    ];
    $this->assertEquals($expected, $this->rows('nlp_voter_turf'));
    $this->assertEquals($expected, $this->rows('nlp_voter_address'));
  }

  public function testDeleteOrphanTurfVotersWithNoTurfsAtAll(): void {
    $this->addTurfVoter(101, 7);

    $this->voters->deleteOrphanTurfVoters([101]);

    $this->assertSame([], $this->rows('nlp_voter_turf'));
    $this->assertSame([], $this->rows('nlp_voter_address'));
  }

  public function testGetTurfsForSyncMatchesByListIdOrFolderAndName(): void {
    $byListId = $this->addTurf(['listId' => 10, 'folderId' => 9, 'turfName' => 'Renamed']);
    $byName   = $this->addTurf(['listId' => 11, 'folderId' => 5, 'turfName' => 'Elm Street']);
    $this->addTurf(['listId' => 12, 'folderId' => 6, 'turfName' => 'Elm Street']);  // Other folder.
    $this->addTurf(['listId' => 13, 'folderId' => 5, 'turfName' => 'Oak Ave']);     // Other turf.

    $found = $this->turfs->getTurfsForSync(5, 10, 'Elm Street');

    $this->assertEqualsCanonicalizing([$byListId, $byName], array_keys($found));
  }

  public function testIncompleteTurfIsRecognizedFromTheDatabaseRecord(): void {
    // What the old sync left when it failed: the record createTurf() wrote, and nothing more.
    $residue = $this->addTurf(['listId' => 10, 'folderId' => 5, 'turfName' => 'Elm Street', 'mcid' => 42]);
    $complete = $this->addTurf(['listId' => 20, 'folderId' => 5, 'turfName' => 'Oak Ave', 'mcid' => 42,
      'nlFirstName' => 'Alice', 'nlLastName' => 'Smith', 'turfHd' => 6]);

    $this->assertTrue($this->turfs->isIncompleteTurf($this->turfs->getTurf($residue)));
    $this->assertFalse($this->turfs->isIncompleteTurf($this->turfs->getTurf($complete)));
  }

}
