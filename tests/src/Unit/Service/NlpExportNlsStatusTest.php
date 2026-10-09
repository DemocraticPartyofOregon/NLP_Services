<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpExportNlsStatus;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpExportNlsStatusTest extends UnitTestCase {

    private FileSystemInterface $fileSystemMock;
    private Connection $connectionMock;
    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpNls $nlsMock;
    private NlpTurfs $turfsMock;
    private DrupalUser $drupalUserMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->connectionMock = $this->createMock(Connection::class);
      $this->votersMock     = $this->createMock(NlpVoters::class);
      $this->reportsMock    = $this->createMock(NlpReports::class);
      $this->nlsMock        = $this->createMock(NlpNls::class);
      $this->turfsMock      = $this->createMock(NlpTurfs::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      foreach ($this->tempFiles as $file) {
        if (file_exists($file)) { unlink($file); }
      }
      $this->tempFiles = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpExportNlsStatus {
      return new NlpExportNlsStatus(
        $this->fileSystemMock,
        $this->connectionMock,
        $this->votersMock,
        $this->reportsMock,
        $this->nlsMock,
        $this->turfsMock,
        $this->drupalUserMock,
      );
    }

    private function makeTempFile(): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $this->tempFiles[] = $path;
      return $path;
    }

    private function baseNl(array $overrides = []): array {
      return array_replace([
        'mcid'            => 1,
        'county'          => 'Multnomah',
        'hd'              => 6,
        'precinct'        => '6.1',
        'nickname'        => 'Jane',
        'lastName'        => 'Smith',
        'email'           => 'jane@example.com',
        'phone'           => '503-555-1234',
        'address'         => '123 Main St',
        'resultsReported' => 0,
      ], $overrides);
    }

    private function baseStatus(array $overrides = []): array {
      return array_replace([
        'asked'   => 'yes',
        'contact' => 'canvass',
      ], $overrides);
    }

    private function baseTurf(array $overrides = []): array {
      return array_replace([
        'turfIndex' => 1,
        'turfName'  => 'Turf-01',
        'doorCount' => 0,
      ], $overrides);
    }

    /**
     * Configures mocks for a single-NL run with no turfs and no report counts.
     */
    private function setupSingleNlRun(
      array $nlOverrides     = [],
      array $statusOverrides = [],
      array $turfs           = [],
      array $countOverrides  = [],
      array $user            = [],
    ): void {
      $nl   = $this->baseNl($nlOverrides);
      $mcid = $nl['mcid'];

      $this->nlsMock->method('getCountyNls')
        ->willReturn([$mcid => $nl]);
      $this->nlsMock->method('getNlsStatus')
        ->willReturn($this->baseStatus($statusOverrides));
      $this->votersMock->method('getVoterCountByNl')->willReturn(10);
      $this->reportsMock->method('getCommitteeReportCounts')
        ->willReturn(empty($countOverrides) ? [] : [$mcid => $countOverrides]);
      $this->turfsMock->method('getTurfsById')->willReturn($turfs);
      $this->drupalUserMock->method('getLastAccessByMcid')
        ->willReturn(isset($user['access']) ? [$mcid => $user['access']] : []);
    }

    private function readCsv(string $path): array {
      return array_map(fn($line) => str_getcsv($line, escape: ''), file($path, FILE_SKIP_EMPTY_LINES));
    }

    // -------------------------------------------------------------------------
    // createNlsStatusFile — file handling
    // -------------------------------------------------------------------------

    public function testCreateNlsStatusFileReturnsFalseWhenFileCannotBeOpened(): void {
      // Suppress the fopen warning that the Symfony error handler would promote to an exception.
      set_error_handler(fn() => true);
      $result = $this->buildService()->createNlsStatusFile('/nonexistent/dir/file.csv', 'Multnomah');
      restore_error_handler();
      $this->assertFalse($result);
    }

    public function testCreateNlsStatusFileReturnsTrueOnSuccess(): void {
      $this->nlsMock->method('getCountyNls')->willReturn([]);
      $path = $this->makeTempFile();
      $this->assertTrue($this->buildService()->createNlsStatusFile($path, 'Multnomah'));
    }

    // -------------------------------------------------------------------------
    // createNlsStatusFile — header
    // -------------------------------------------------------------------------

    public function testCreateNlsStatusFileWritesHeaderAsFirstRow(): void {
      $this->nlsMock->method('getCountyNls')->willReturn([]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('mcid', $csv[0][0]);
    }

    public function testCreateNlsStatusFileHeaderContainsExpectedColumns(): void {
      $this->nlsMock->method('getCountyNls')->willReturn([]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $headerLine = file($path, FILE_SKIP_EMPTY_LINES)[0];
      foreach (['mcid', 'county', 'hd', 'email', 'Voters', 'Doors', 'Contact', 'Stamps'] as $col) {
        $this->assertStringContainsString($col, $headerLine);
      }
    }

    // -------------------------------------------------------------------------
    // createNlsStatusFile — county handling
    // -------------------------------------------------------------------------

    public function testCreateNlsStatusFileUsesGetParticipatingCountiesForAllCounties(): void {
      $this->votersMock->expects($this->once())->method('getParticipatingCounties')->willReturn([]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'all');
    }

    public function testCreateNlsStatusFileDoesNotCallGetParticipatingCountiesForSpecificCounty(): void {
      $this->votersMock->expects($this->never())->method('getParticipatingCounties');
      $this->nlsMock->method('getCountyNls')->willReturn([]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
    }

    public function testCreateNlsStatusFileSkipsCountyWhenGetCountyNlsReturnsFalsy(): void {
      $this->nlsMock->method('getCountyNls')->willReturn([]);  // empty array is falsy
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertCount(1, $csv);  // header only
    }

    // -------------------------------------------------------------------------
    // createNlsStatusFile — data rows
    // -------------------------------------------------------------------------

    public function testCreateNlsStatusFileWritesOneDataRowPerNl(): void {
      $nl1 = $this->baseNl(['mcid' => 1]);
      $nl2 = $this->baseNl(['mcid' => 2]);
      $this->nlsMock->method('getCountyNls')->willReturn([1 => $nl1, 2 => $nl2]);
      $this->nlsMock->method('getNlsStatus')->willReturn($this->baseStatus());
      $this->votersMock->method('getVoterCountByNl')->willReturn(5);
      $this->reportsMock->method('getCommitteeReportCounts')->willReturn([]);
      $this->turfsMock->method('getTurfsById')->willReturn([]);
      $this->drupalUserMock->method('getLastAccessByMcid')->willReturn([]);

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 data rows
    }

    public function testCreateNlsStatusFileLooksUpAccessTimesOnceWithoutLoadingUsers(): void {
      $nl1 = $this->baseNl(['mcid' => 1]);
      $nl2 = $this->baseNl(['mcid' => 2]);
      $this->nlsMock->method('getCountyNls')->willReturn([1 => $nl1, 2 => $nl2]);
      $this->nlsMock->method('getNlsStatus')->willReturn($this->baseStatus());
      $this->turfsMock->method('getTurfsById')->willReturn([]);
      $this->drupalUserMock->expects($this->once())->method('getLastAccessByMcid')
        ->willReturn([2 => mktime(0, 0, 0, 3, 4, 2026)]);
      $this->drupalUserMock->expects($this->never())->method('getUserByMcid');

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertStringContainsString('Never', $csv[1][9]);
      $this->assertStringContainsString('2026/3/4', $csv[2][9]);
    }

    public function testCreateNlsStatusFileAttemptsEmptyWhenNoReportCounts(): void {
      $this->setupSingleNlRun();
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('', $csv[1][11]);  // Attempts at index 11
    }

    public function testCreateNlsStatusFileUsesReportCountWhenAvailable(): void {
      $this->setupSingleNlRun([], [], [], ['attempts' => 7, 'contacts' => 4]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('7', $csv[1][11]);  // Attempts at index 11
    }

    public function testCreateNlsStatusFileDecodesHtmlEntitiesInNickname(): void {
      $this->setupSingleNlRun(['nickname' => 'O&#39;Brien']);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame("O'Brien", $csv[1][4]);  // First Name at index 4
    }

    public function testCreateNlsStatusFileDecodesHtmlEntitiesInLastName(): void {
      $this->setupSingleNlRun(['lastName' => 'Smith &amp; Jones']);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('Smith & Jones', $csv[1][5]);  // Last Name at index 5
    }

    public function testCreateNlsStatusFileFormattedEmailEmptyWhenEmailMissing(): void {
      $this->setupSingleNlRun(['email' => '']);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('', $csv[1][19]);  // Email (formatted) at index 19
    }

    public function testCreateNlsStatusFileFormattedEmailCombinesNameAndAddress(): void {
      $this->setupSingleNlRun(['nickname' => 'Jane', 'lastName' => 'Smith', 'email' => 'jane@example.com']);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('Jane Smith<jane@example.com>', $csv[1][19]);
    }

    public function testCreateNlsStatusFileAccessDateIsNeverWhenUserHasNoAccess(): void {
      $this->setupSingleNlRun([], [], [], [], ['access' => 0]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertStringContainsString('Never', $csv[1][9]);  // Last Access Date at index 9
    }

    public function testCreateNlsStatusFileAccessDateIsFormattedWhenUserHasAccess(): void {
      $timestamp = mktime(0, 0, 0, 11, 5, 2024);
      $this->setupSingleNlRun([], [], [], [], ['access' => $timestamp]);
      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertStringContainsString('2024', $csv[1][9]);
    }

    public function testCreateNlsStatusFileTurfNamesJoinedWithCommaForMultipleTurfs(): void {
      $turfs = [
        $this->baseTurf(['turfIndex' => 1, 'turfName' => 'Turf-A']),
        $this->baseTurf(['turfIndex' => 2, 'turfName' => 'Turf-B']),
      ];
      $this->setupSingleNlRun([], [], $turfs);

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('Turf-A, Turf-B', $csv[1][18]);  // Assigned Turf at index 18
    }

    public function testCreateNlsStatusFileDoorCountSumsAcrossAllTurfs(): void {
      $turfs = [
        $this->baseTurf(['turfIndex' => 1, 'turfName' => 'T1', 'doorCount' => 4]),
        $this->baseTurf(['turfIndex' => 2, 'turfName' => 'T2', 'doorCount' => 4]),
      ];
      $this->setupSingleNlRun([], [], $turfs);

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('8', $csv[1][14]);  // Doors at index 14; 2 turfs × 4 = 8
    }

    public function testCreateNlsStatusFileStampsEqualDoorsForMailContact(): void {
      $turf = $this->baseTurf(['turfIndex' => 1, 'turfName' => 'T1', 'doorCount' => 5]);
      $this->setupSingleNlRun([], ['contact' => 'mail'], [$turf]);

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame($csv[1][14], $csv[1][16]);  // Doors (14) equals Stamps (16)
    }

    public function testCreateNlsStatusFileStampsAreZeroForNonMailContact(): void {
      $turf = $this->baseTurf(['turfIndex' => 1, 'turfName' => 'T1', 'doorCount' => 5]);
      $this->setupSingleNlRun([], ['contact' => 'canvass'], [$turf]);

      $path = $this->makeTempFile();
      $this->buildService()->createNlsStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('0', $csv[1][16]);  // Stamps at index 16
    }

  }

}
