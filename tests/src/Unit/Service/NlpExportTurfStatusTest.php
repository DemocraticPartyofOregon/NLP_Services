<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\File\FileSystemInterface;
  use Drupal\nlpservices\NlpExportTurfStatus;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpExportTurfStatusTest extends UnitTestCase {

    private FileSystemInterface $fileSystemMock;
    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpNls $nlsMock;
    private NlpTurfs $turfsMock;
    private NlpMatchbacks $matchbacksMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->votersMock     = $this->createMock(NlpVoters::class);
      $this->reportsMock    = $this->createMock(NlpReports::class);
      $this->nlsMock        = $this->createMock(NlpNls::class);
      $this->turfsMock      = $this->createMock(NlpTurfs::class);
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
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

    private function buildService(): NlpExportTurfStatus {
      return new NlpExportTurfStatus(
        $this->fileSystemMock,
        $this->votersMock,
        $this->reportsMock,
        $this->nlsMock,
        $this->turfsMock,
        $this->matchbacksMock,
      );
    }

    private function makeTempFile(): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $this->tempFiles[] = $path;
      return $path;
    }

    private function baseTurfRecord(array $overrides = []): array {
      return array_replace([
        'turfIndex'     => 1,
        'mcid'          => 42,
        'turfName'      => 'Multnomah-001',
        'folderName'    => 'Folder-01',
        'mapRegionName' => 'Region-01',
        'turfCd'        => 1,
        'county'        => 'Multnomah',
        'turfHd'        => 6,
        'turfPrecinct'  => '6.1',
        'doorCount'     => 10,
        'syncDate'      => '2024-01-01',
      ], $overrides);
    }

    private function baseNl(array $overrides = []): array {
      return array_replace(['firstName' => 'Jane', 'lastName' => 'Smith'], $overrides);
    }

    private function baseStatus(array $overrides = []): array {
      return array_replace(['contact' => 'canvass'], $overrides);
    }

    /**
     * Configures mocks for a single-turf run.
     *
     * @param array $voterIds       vanids returned by getVotersInTurf
     * @param array $matchedVanids  vanids that have a matchback
     * @param array $surveyVanids   vanids with a survey response
     * @param array $attemptVanids  vanids with a contact attempt
     */
    private function setupSingleTurfRun(
      array $voterIds        = [101],
      array $matchedVanids   = [],
      array $surveyVanids    = [],
      array $attemptVanids   = [],
      array $turfOverrides   = [],
      array $nlOverrides     = [],
      array $statusOverrides = [],
    ): void {
      $this->turfsMock->method('getCountyTurfs')
        ->willReturn([$this->baseTurfRecord($turfOverrides)]);
      $this->votersMock->method('getVotersInTurf')->willReturn($voterIds);
      $this->matchbacksMock->method('matchbackExists')
        ->willReturnCallback(fn($vanid) => in_array($vanid, $matchedVanids));
      $this->reportsMock->method('contactAttempt')
        ->willReturnCallback(fn($vanid) => [
          'survey'  => in_array($vanid, $surveyVanids),
          'attempt' => in_array($vanid, $attemptVanids),
        ]);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl($nlOverrides));
      $this->nlsMock->method('getNlsStatus')->willReturn($this->baseStatus($statusOverrides));
    }

    private function readCsv(string $path): array {
      return array_map(fn($line) => str_getcsv($line, escape: ''), file($path, FILE_SKIP_EMPTY_LINES));
    }

    // -------------------------------------------------------------------------
    // createTurfStatusFile — file handling
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusFileReturnsFalseWhenFileCannotBeOpened(): void {
      set_error_handler(fn() => true);
      $result = $this->buildService()->createTurfStatusFile('/nonexistent/dir/file.csv', 'Multnomah');
      restore_error_handler();
      $this->assertFalse($result);
    }

    public function testCreateTurfStatusFileReturnsFalseWhenCountyHasNoTurfs(): void {
      $this->turfsMock->method('getCountyTurfs')->willReturn([]);
      $path = $this->makeTempFile();
      $this->assertFalse($this->buildService()->createTurfStatusFile($path, 'Multnomah'));
    }

    public function testCreateTurfStatusFileReturnsTrueOnSuccess(): void {
      $this->setupSingleTurfRun();
      $path = $this->makeTempFile();
      $this->assertTrue($this->buildService()->createTurfStatusFile($path, 'Multnomah'));
    }

    // -------------------------------------------------------------------------
    // createTurfStatusFile — header
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusFileWritesHeaderAsFirstRow(): void {
      $this->setupSingleTurfRun();
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('Turf Name', $csv[0][0]);
    }

    public function testCreateTurfStatusFileHeaderContainsExpectedColumns(): void {
      $this->setupSingleTurfRun();
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $header = $this->readCsv($path)[0];
      foreach (['MCID', 'Voters in turf', 'Voted', 'Contact', 'Doors', 'Stamps', 'Contact attempts'] as $col) {
        $this->assertContains($col, $header);
      }
    }

    // -------------------------------------------------------------------------
    // createTurfStatusFile — turf skipping
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusFileSkipsTurfWithNoVoters(): void {
      $this->turfsMock->method('getCountyTurfs')->willReturn([$this->baseTurfRecord()]);
      $this->votersMock->method('getVotersInTurf')->willReturn([]);

      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertCount(1, $csv);  // header only
    }

    public function testCreateTurfStatusFileWritesOneDataRowPerTurfWithVoters(): void {
      $this->turfsMock->method('getCountyTurfs')
        ->willReturn([$this->baseTurfRecord(['turfIndex' => 1]), $this->baseTurfRecord(['turfIndex' => 2])]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101]);
      $this->matchbacksMock->method('matchbackExists')->willReturn(false);
      $this->reportsMock->method('contactAttempt')->willReturn(['survey' => false, 'attempt' => false]);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->nlsMock->method('getNlsStatus')->willReturn($this->baseStatus());

      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 data rows
    }

    // -------------------------------------------------------------------------
    // createTurfStatusFile — voter counts and percentages
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusFileVoterCountMatchesVotersInTurf(): void {
      $this->setupSingleTurfRun([101, 102, 103]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('3', $csv[1][13]);  // Voters in turf at index 13
    }

    public function testCreateTurfStatusFileVotedCountMatchesMatchbackHits(): void {
      $this->setupSingleTurfRun([101, 102, 103], matchedVanids: [101, 103]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('2', $csv[1][14]);  // Voted at index 14
    }

    public function testCreateTurfStatusFilePercentVotedCalculatedCorrectly(): void {
      // 1 voted of 4 voters = 25%
      $this->setupSingleTurfRun([101, 102, 103, 104], matchedVanids: [101]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('25%', $csv[1][15]);  // Percent voted at index 15
    }

    public function testCreateTurfStatusFileSurveyResponseCountCorrect(): void {
      $this->setupSingleTurfRun([101, 102, 103], surveyVanids: [101, 102]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('2', $csv[1][16]);  // Pledged to Vote at index 16
    }

    public function testCreateTurfStatusFileSurveyResponsesVotedCountCorrect(): void {
      // 101 has survey response AND voted; 102 has survey but no matchback
      $this->setupSingleTurfRun([101, 102, 103], matchedVanids: [101], surveyVanids: [101, 102]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('1', $csv[1][17]);  // Pledged and voted at index 17
    }

    public function testCreateTurfStatusFilePercentPledgedAndVotedIsZeroWhenNoSurveyResponses(): void {
      $this->setupSingleTurfRun([101, 102]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('0%', $csv[1][18]);  // Percent pledged and voted at index 18
    }

    public function testCreateTurfStatusFileContactAttemptsCountCorrect(): void {
      $this->setupSingleTurfRun([101, 102, 103], attemptVanids: [101, 102]);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('2', $csv[1][19]);  // Contact attempts at index 19
    }

    // -------------------------------------------------------------------------
    // createTurfStatusFile — NL info and contact method
    // -------------------------------------------------------------------------

    public function testCreateTurfStatusFilePopulatesNlFirstAndLastName(): void {
      $this->setupSingleTurfRun(nlOverrides: ['firstName' => 'Alice', 'lastName' => 'Wong']);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('Alice', $csv[1][8]);  // First Name at index 8
      $this->assertSame('Wong',  $csv[1][9]);  // Last Name at index 9
    }

    public function testCreateTurfStatusFileFallsBackToEmptyStringForMissingNlName(): void {
      $this->turfsMock->method('getCountyTurfs')->willReturn([$this->baseTurfRecord()]);
      $this->votersMock->method('getVotersInTurf')->willReturn([101]);
      $this->matchbacksMock->method('matchbackExists')->willReturn(false);
      $this->reportsMock->method('contactAttempt')->willReturn(['survey' => false, 'attempt' => false]);
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->nlsMock->method('getNlsStatus')->willReturn($this->baseStatus());

      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('', $csv[1][8]);
      $this->assertSame('', $csv[1][9]);
    }

    public function testCreateTurfStatusFileStampsEqualDoorCountForMailContact(): void {
      $this->setupSingleTurfRun(turfOverrides: ['doorCount' => 8], statusOverrides: ['contact' => 'mail']);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('8', $csv[1][12]);  // Stamps at index 12
    }

    public function testCreateTurfStatusFileStampsAreZeroForNonMailContact(): void {
      $this->setupSingleTurfRun(turfOverrides: ['doorCount' => 8], statusOverrides: ['contact' => 'canvass']);
      $path = $this->makeTempFile();
      $this->buildService()->createTurfStatusFile($path, 'Multnomah');
      $csv = $this->readCsv($path);
      $this->assertSame('0', $csv[1][12]);  // Stamps at index 12
    }

  }

}
