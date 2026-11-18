<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\NlpReports;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests NlpReports::updateNlName(), which fills in the NL's name on old
 * reports for the data entry page's historical contacts.  It updates only
 * the reports missing a name, through an OR of NULL and empty-string
 * conditions that a mocked Connection accepts whatever it means, so only a
 * real database shows it picks the right rows.
 */
#[CoversClass(NlpReports::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class ReportNlNameTest extends KernelTestBase {

  protected static $modules = ['user', 'file', 'nlpservices'];

  private NlpReports $reports;
  private Connection $connection;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('nlpservices', ['nlp_results']);
    $this->reports = $this->container->get('nlpservices.reports');
    $this->connection = $this->container->get('database');
  }

  private function addReport(int $mcid, ?string $nickname, ?string $lastName): int {
    return (int) $this->connection->insert('nlp_results')
      ->fields([
        'vanid' => 500,
        'cycle' => '2026-11',
        'mcid' => $mcid,
        'nickname' => $nickname,
        'lastName' => $lastName,
        'contactDate' => '2026-10-01',
        'type' => 'Comment',
      ])
      ->execute();
  }

  private function nlNameOn(int $reportIndex): array {
    return $this->connection->select('nlp_results', 'r')
      ->fields('r', ['nickname', 'lastName'])
      ->condition('reportIndex', $reportIndex)
      ->execute()
      ->fetchAssoc();
  }

  public function testUpdateNlNameFillsOnlyThisNlsReportsMissingAName(): void {
    $noNames     = $this->addReport(7, NULL, NULL);
    $emptyFirst  = $this->addReport(7, '', 'Doe');
    $noLast      = $this->addReport(7, 'Jane', NULL);
    $complete    = $this->addReport(7, 'Janie', 'Doe-Smith');
    $otherNl     = $this->addReport(8, NULL, NULL);

    $this->reports->updateNlName(7, 'Jane', 'Doe');

    $jane = ['nickname' => 'Jane', 'lastName' => 'Doe'];
    $this->assertSame($jane, $this->nlNameOn($noNames));
    $this->assertSame($jane, $this->nlNameOn($emptyFirst));
    $this->assertSame($jane, $this->nlNameOn($noLast));
    // Already had both names: left as it was.
    $this->assertSame(['nickname' => 'Janie', 'lastName' => 'Doe-Smith'], $this->nlNameOn($complete));
    // A different NL's report isn't touched.
    $this->assertSame(['nickname' => NULL, 'lastName' => NULL], $this->nlNameOn($otherNl));
  }

  public function testMarkNlNameNotFoundMarksOnlyThisNlsReportsMissingAName(): void {
    $noLast   = $this->addReport(7, 'Jane', NULL);
    $complete = $this->addReport(7, 'Jane', 'Doe');
    $otherNl  = $this->addReport(8, NULL, NULL);

    $this->reports->markNlNameNotFound(7);

    $this->assertSame('1', $this->notFoundMark($noLast));
    $this->assertNull($this->notFoundMark($complete));
    $this->assertNull($this->notFoundMark($otherNl));
  }

  private function notFoundMark(int $reportIndex): ?string {
    return $this->connection->select('nlp_results', 'r')
      ->fields('r', ['nlNameNotFound'])
      ->condition('reportIndex', $reportIndex)
      ->execute()
      ->fetchField();
  }

  public function testUpdateNlNameCutsNamesToTheColumnLength(): void {
    $report = $this->addReport(7, NULL, NULL);

    $this->reports->updateNlName(7, str_repeat('a', 70), 'Doe');

    $this->assertSame(str_repeat('a', 64), $this->nlNameOn($report)['nickname']);
  }

}
