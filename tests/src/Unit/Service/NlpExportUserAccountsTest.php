<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpExportUserAccounts;
  use Drupal\nlpservices\NlpFileFunctions;
  use Drupal\nlpservices\NlpNls;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpExportUserAccountsTest extends UnitTestCase {

    private NlpFileFunctions $fileFunctionsMock;
    private DrupalUser $drupalUserMock;
    private MagicWord $magicWordMock;
    private NlpNls $nlsMock;
    private ApiNls $apiNlsMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->fileFunctionsMock = $this->createMock(NlpFileFunctions::class);
      $this->drupalUserMock    = $this->createMock(DrupalUser::class);
      $this->magicWordMock     = $this->createMock(MagicWord::class);
      $this->nlsMock           = $this->createMock(NlpNls::class);
      $this->apiNlsMock        = $this->createMock(ApiNls::class);
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

    private function buildService(): NlpExportUserAccounts {
      return new NlpExportUserAccounts(
        $this->fileFunctionsMock,
        $this->drupalUserMock,
        $this->magicWordMock,
        $this->nlsMock,
        $this->apiNlsMock,
      );
    }

    private function baseUser(array $overrides = []): array {
      return array_replace([
        'uid'      => 1,
        'mcid'     => 42,
        'hd'       => 'HD6',
        'password' => '',
        'roles'    => [],
      ], $overrides);
    }

    /**
     * Sets up a success scenario and returns the path to the temp CSV file.
     */
    private function setupSuccessCase(array $counties, array $usersByCounty): string {
      $tmpPath = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $fh = fopen($tmpPath, 'w');
      $this->tempFiles[] = $tmpPath;

      $this->fileFunctionsMock->method('createTempCsvFile')
        ->willReturn(['uri' => 'public://test.csv', 'fh' => $fh]);
      $this->drupalUserMock->method('getCounties')->willReturn($counties);
      $this->drupalUserMock->method('getUsers')
        ->willReturnCallback(fn($county) => $usersByCounty[$county] ?? []);

      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/users.csv');
      $container = new ContainerBuilder();
      $container->set('file_url_generator', $fileUrlMock);
      \Drupal::setContainer($container);

      return $tmpPath;
    }

    private function readCsv(string $path): array {
      return array_map(fn($line) => str_getcsv($line, escape: ''), file($path, FILE_SKIP_EMPTY_LINES));
    }

    // -------------------------------------------------------------------------
    // getUserAccountsFile — file handling
    // -------------------------------------------------------------------------

    public function testGetUserAccountsFileReturnsOopsWhenTempFileCreationFails(): void {
      $this->fileFunctionsMock->method('createTempCsvFile')->willReturn(false);
      $this->assertSame('Oops!', $this->buildService()->getUserAccountsFile());
    }

    public function testGetUserAccountsFileReturnsStringOnSuccess(): void {
      $this->setupSuccessCase(['Multnomah'], ['Multnomah' => []]);
      $result = $this->buildService()->getUserAccountsFile();
      $this->assertIsString($result);
      $this->assertNotSame('Oops!', $result);
    }

    public function testGetUserAccountsFileOutputContainsDownloadLink(): void {
      $this->setupSuccessCase(['Multnomah'], ['Multnomah' => []]);
      $result = $this->buildService()->getUserAccountsFile();
      $this->assertStringContainsString('<a href=', $result);
    }

    public function testGetUserAccountsFileOutputContainsFileUrl(): void {
      $this->setupSuccessCase(['Multnomah'], ['Multnomah' => []]);
      $result = $this->buildService()->getUserAccountsFile();
      $this->assertStringContainsString('https://example.com/users.csv', $result);
    }

    // -------------------------------------------------------------------------
    // getUserAccountsFile — CSV structure
    // -------------------------------------------------------------------------

    public function testGetUserAccountsFileWritesHeaderAsFirstRow(): void {
      $user = $this->baseUser();
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->magicWordMock->method('getMagicWord')->willReturn('pw');
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $this->assertContains('uid', $csv[0]);
      $this->assertContains('password', $csv[0]);
    }

    public function testGetUserAccountsFileWritesOneDataRowPerUser(): void {
      $users = [$this->baseUser(['uid' => 1]), $this->baseUser(['uid' => 2])];
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->magicWordMock->method('getMagicWord')->willReturn('pw');
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => $users]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 data rows
    }

    public function testGetUserAccountsFileWritesUsersAcrossMultipleCounties(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->magicWordMock->method('getMagicWord')->willReturn('pw');
      $path = $this->setupSuccessCase(
        ['Multnomah', 'Washington'],
        [
          'Multnomah'  => [$this->baseUser(['uid' => 1])],
          'Washington' => [$this->baseUser(['uid' => 2])],
        ]
      );
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(3, $csv);  // header + 2 data rows
    }

    public function testGetUserAccountsFileNoUsersProducesNoDataRows(): void {
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => []]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $this->assertCount(0, $csv);  // no header when no users
    }

    // -------------------------------------------------------------------------
    // getUserAccountsFile — password handling
    // -------------------------------------------------------------------------

    public function testGetUserAccountsFileUsesGetMagicWordForUserWithMcid(): void {
      $user = $this->baseUser(['mcid' => 42]);
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->magicWordMock->method('getMagicWord')->with(42)->willReturn('secret123');
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $passwordIndex = array_search('password', $csv[0]);
      $this->assertSame('secret123', $csv[1][$passwordIndex]);
    }

    public function testGetUserAccountsFileUsesUnknownPasswordForUserWithoutMcid(): void {
      $user = $this->baseUser(['mcid' => 0]);
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $passwordIndex = array_search('password', $csv[0]);
      $this->assertSame('unknown', $csv[1][$passwordIndex]);
    }

    // -------------------------------------------------------------------------
    // getUserAccountsFile — roles formatting
    // -------------------------------------------------------------------------

    public function testGetUserAccountsFileFormatsEmptyRolesAsEmptyString(): void {
      $user = $this->baseUser(['mcid' => 0, 'roles' => []]);
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $rolesIndex = array_search('roles', $csv[0]);
      $this->assertSame('', $csv[1][$rolesIndex]);
    }

    public function testGetUserAccountsFileFormatsRolesAsColonSemicolonString(): void {
      $user = $this->baseUser(['mcid' => 0, 'roles' => ['nlp_member' => 'NLP Member']]);
      $path = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $rolesIndex = array_search('roles', $csv[0]);
      $this->assertSame('nlp_member:NLP Member;', $csv[1][$rolesIndex]);
    }

    public function testGetUserAccountsFileFormatsMultipleRolesCorrectly(): void {
      $roles = ['nlp_member' => 'NLP Member', 'nlp_admin' => 'NLP Admin'];
      $user  = $this->baseUser(['mcid' => 0, 'roles' => $roles]);
      $path  = $this->setupSuccessCase(['Multnomah'], ['Multnomah' => [$user]]);
      $this->buildService()->getUserAccountsFile();
      $csv = $this->readCsv($path);
      $rolesIndex = array_search('roles', $csv[0]);
      $this->assertSame('nlp_member:NLP Member;nlp_admin:NLP Admin;', $csv[1][$rolesIndex]);
    }

    // -------------------------------------------------------------------------
    // resolveHd — hdDrupal is 'Unknown'
    // -------------------------------------------------------------------------

    public function testResolveHdReturnsNlpHdWhenDrupalUnknownAndNlpKnown(): void {
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('Unknown', 'HD6', 1, 1);
      $this->assertSame('HD6', $result);
    }

    public function testResolveHdReturnsEmptyStringWhenBothAreUnknown(): void {
      $this->drupalUserMock->expects($this->never())->method('updateUser');
      $result = $this->buildService()->resolveHd('Unknown', 'Unknown', 1, 1);
      $this->assertSame('', $result);
    }

    public function testResolveHdCallsApiWhenDrupalUnknownAndNlpEmpty(): void {
      $this->apiNlsMock->expects($this->once())->method('getApiNls')
        ->with('', 1)->willReturn(['hd' => 'HD9']);
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('Unknown', '', 1, 1);
      $this->assertSame('HD9', $result);
    }

    public function testResolveHdReturnsUnknownWhenDrupalUnknownNlpEmptyAndApiEmpty(): void {
      $this->apiNlsMock->method('getApiNls')->willReturn([]);
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('Unknown', '', 1, 1);
      $this->assertSame('Unknown', $result);
    }

    // -------------------------------------------------------------------------
    // resolveHd — hdDrupal is non-empty
    // -------------------------------------------------------------------------

    public function testResolveHdReturnsNlpHdWhenDrupalAndNlpDiffer(): void {
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('HD6', 'HD7', 1, 1);
      $this->assertSame('HD7', $result);
    }

    public function testResolveHdReturnsEmptyStringWhenDrupalAndNlpMatch(): void {
      $this->drupalUserMock->expects($this->never())->method('updateUser');
      $result = $this->buildService()->resolveHd('HD6', 'HD6', 1, 1);
      $this->assertSame('', $result);
    }

    public function testResolveHdReturnsEmptyStringWhenNlpIsUnknown(): void {
      $this->drupalUserMock->expects($this->never())->method('updateUser');
      $result = $this->buildService()->resolveHd('HD6', 'Unknown', 1, 1);
      $this->assertSame('', $result);
    }

    public function testResolveHdReturnsEmptyStringWhenNlpIsEmpty(): void {
      $this->drupalUserMock->expects($this->never())->method('updateUser');
      $result = $this->buildService()->resolveHd('HD6', '', 1, 1);
      $this->assertSame('', $result);
    }

    // -------------------------------------------------------------------------
    // resolveHd — hdDrupal is empty
    // -------------------------------------------------------------------------

    public function testResolveHdUsesNlpHdWhenDrupalEmptyAndNlpKnown(): void {
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('', 'HD6', 1, 1);
      $this->assertSame('HD6', $result);
    }

    public function testResolveHdReturnsUnknownWhenDrupalEmptyAndNlpEmpty(): void {
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('', '', 1, 1);
      $this->assertSame('Unknown', $result);
    }

    public function testResolveHdCallsApiWhenDrupalEmptyAndNlpUnknown(): void {
      $this->apiNlsMock->expects($this->once())->method('getApiNls')
        ->with('', 1)->willReturn(['hd' => 'HD9']);
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('', 'Unknown', 1, 1);
      $this->assertSame('HD9', $result);
    }

    public function testResolveHdReturnsUnknownWhenDrupalEmptyNlpUnknownAndApiEmpty(): void {
      $this->apiNlsMock->method('getApiNls')->willReturn([]);
      $this->drupalUserMock->expects($this->once())->method('updateUser');
      $result = $this->buildService()->resolveHd('', 'Unknown', 1, 1);
      $this->assertSame('Unknown', $result);
    }

    // -------------------------------------------------------------------------
    // resolveHd — updateUser is called with correct arguments
    // -------------------------------------------------------------------------

    public function testResolveHdCallsUpdateUserWithUidAndNewHd(): void {
      $this->drupalUserMock->expects($this->once())
        ->method('updateUser')
        ->with(['uid' => 99, 'hd' => 'HD7']);
      $this->buildService()->resolveHd('HD6', 'HD7', 1, 99);
    }

  }

}
