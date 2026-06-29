<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpExportCredentials;
  use Drupal\nlpservices\NlpFileFunctions;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpExportCredentialsTest extends UnitTestCase {

    private NlpFileFunctions $fileFunctionsMock;
    private MagicWord $magicWordMock;
    private DatabaseFunctions $dbFunctionsMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->fileFunctionsMock = $this->createMock(NlpFileFunctions::class);
      $this->magicWordMock     = $this->createMock(MagicWord::class);
      $this->dbFunctionsMock   = $this->createMock(DatabaseFunctions::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
      foreach ($this->tempFiles as $file) {
        if (file_exists($file)) { unlink($file); }
      }
      $this->tempFiles = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpExportCredentials {
      return new NlpExportCredentials(
        $this->fileFunctionsMock,
        $this->magicWordMock,
        $this->dbFunctionsMock,
      );
    }

    /**
     * Sets up a full success scenario and returns the temp file path so tests
     * can read the written CSV after the method runs.
     */
    private function setupSuccessCase(array $columns, array $mcids, array $credentialsByMcid): string {
      $tmpPath = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $fh = fopen($tmpPath, 'w');
      $this->tempFiles[] = $tmpPath;

      $this->fileFunctionsMock->method('createTempCsvFile')
        ->willReturn(['uri' => 'public://test.csv', 'fh' => $fh]);
      $this->dbFunctionsMock->method('getColumnNames')->willReturn($columns);
      $this->magicWordMock->method('getKeys')->willReturn($mcids);
      $this->magicWordMock->method('getCredentials')
        ->willReturnCallback(fn($mcid) => $credentialsByMcid[$mcid] ?? []);

      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('file_url_generator', $fileUrlMock);
      \Drupal::setContainer($container);

      return $tmpPath;
    }

    private function readCsv(string $path): array {
      return array_map(fn($line) => str_getcsv($line, escape: ''), file($path, FILE_SKIP_EMPTY_LINES));
    }

    // -------------------------------------------------------------------------
    // createNlCredentialsFile
    // -------------------------------------------------------------------------

    public function testCreateNlCredentialsFileReturnsOopsWhenTempFileCreationFails(): void {
      $this->fileFunctionsMock->method('createTempCsvFile')->willReturn(false);
      $this->assertSame('Oops!', $this->buildService()->createNlCredentialsFile());
    }

    public function testCreateNlCredentialsFileReturnsStringOnSuccess(): void {
      $this->setupSuccessCase(['mcid', 'magicWord'], [], []);
      $result = $this->buildService()->createNlCredentialsFile();
      $this->assertIsString($result);
      $this->assertNotEmpty($result);
    }

    public function testCreateNlCredentialsFileOutputContainsDownloadLink(): void {
      $this->setupSuccessCase(['mcid', 'magicWord'], [], []);
      $result = $this->buildService()->createNlCredentialsFile();
      $this->assertStringContainsString('<a href=', $result);
    }

    public function testCreateNlCredentialsFileOutputContainsFileUrl(): void {
      $this->setupSuccessCase(['mcid', 'magicWord'], [], []);
      $result = $this->buildService()->createNlCredentialsFile();
      $this->assertStringContainsString('https://example.com/file.csv', $result);
    }

    public function testCreateNlCredentialsFileWritesHeaderRowFromColumnNames(): void {
      $columns = ['mcid', 'magicWord', 'userName'];
      $path = $this->setupSuccessCase($columns, [], []);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $this->assertSame(['mcid', 'magicWord', 'userName'], $csv[0]);
    }

    public function testCreateNlCredentialsFileReplacesEncryptedColumnNameWithDecrypted(): void {
      $columns = ['mcid', 'magicWordEncrypted', 'userName'];
      $path = $this->setupSuccessCase($columns, [], []);
      $this->buildService()->createNlCredentialsFile();
      $header = $this->readCsv($path)[0];
      $this->assertContains('magicWord', $header);
      $this->assertNotContains('magicWordEncrypted', $header);
    }

    public function testCreateNlCredentialsFileSkipsEmptyCredentials(): void {
      $columns = ['mcid', 'magicWord', 'roles'];
      $path = $this->setupSuccessCase($columns, [1], [1 => []]);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(1, $csv);  // header only
    }

    public function testCreateNlCredentialsFileWritesOneDataRowPerValidCredential(): void {
      $columns = ['mcid', 'magicWord', 'roles'];
      $credentials = [
        1 => ['mcid' => 1, 'magicWord' => 'pass1', 'roles' => []],
        2 => ['mcid' => 2, 'magicWord' => 'pass2', 'roles' => []],
      ];
      $path = $this->setupSuccessCase($columns, [1, 2], $credentials);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 data rows
    }

    public function testCreateNlCredentialsFileEncodesRolesAsJson(): void {
      $columns = ['mcid', 'magicWord', 'roles'];
      $roles = ['nlp_leader', 'nlp_admin'];
      $credentials = [1 => ['mcid' => 1, 'magicWord' => 'pass1', 'roles' => $roles]];
      $path = $this->setupSuccessCase($columns, [1], $credentials);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $rolesIndex = array_search('roles', $csv[0]);
      $this->assertSame(json_encode($roles), $csv[1][$rolesIndex]);
    }

    public function testCreateNlCredentialsFileUsesEmptyStringForMissingCredentialField(): void {
      $columns = ['mcid', 'magicWord', 'unknownField', 'roles'];
      $credentials = [1 => ['mcid' => 1, 'magicWord' => 'pass1', 'roles' => []]];
      $path = $this->setupSuccessCase($columns, [1], $credentials);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $fieldIndex = array_search('unknownField', $csv[0]);
      $this->assertSame('', $csv[1][$fieldIndex]);
    }

    public function testCreateNlCredentialsFileMixedValidAndEmptyCredentialsWritesOnlyValidRows(): void {
      $columns = ['mcid', 'magicWord', 'roles'];
      $credentials = [
        1 => ['mcid' => 1, 'magicWord' => 'pass1', 'roles' => []],
        2 => [],  // skipped
        3 => ['mcid' => 3, 'magicWord' => 'pass3', 'roles' => []],
      ];
      $path = $this->setupSuccessCase($columns, [1, 2, 3], $credentials);
      $this->buildService()->createNlCredentialsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 valid rows
    }

  }

}
