<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\ImportCredentialsForm;
  use Drupal\nlpservices\MagicWord;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\FileBag;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class ImportCredentialsFormTest extends UnitTestCase {

    private FileSystemInterface $fileSystemMock;
    private Connection $connectionMock;
    private DrupalUser $drupalUserMock;
    private MagicWord $magicWordMock;
    private ApiNls $apiNlsMock;
    private MessengerInterface $messengerMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->connectionMock = $this->createMock(Connection::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->magicWordMock  = $this->createMock(MagicWord::class);
      $this->apiNlsMock     = $this->createMock(ApiNls::class);
      $this->messengerMock  = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
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

    private function buildForm(array $files = []): ImportCredentialsForm {
      $filesBag = $this->createMock(FileBag::class);
      $filesBag->method('get')->with('files', [])->willReturn($files);

      $request = $this->createMock(Request::class);
      $request->files = $filesBag;

      $requestStack = $this->createMock(RequestStack::class);
      $requestStack->method('getCurrentRequest')->willReturn($request);

      $form = new ImportCredentialsForm(
        $this->fileSystemMock,
        $this->connectionMock,
        $this->drupalUserMock,
        $this->magicWordMock,
        $this->apiNlsMock,
        $requestStack,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    private function makeUploadedFile(string $pathname): UploadedFile {
      $mock = $this->createMock(UploadedFile::class);
      $mock->method('getPathname')->willReturn($pathname);
      return $mock;
    }

    private function writeCsv(array $headers, array $rows): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_cred_test_');
      $fh = fopen($path, 'w');
      fputcsv($fh, $headers, escape: '');
      foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    private function writeEmptyFile(): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_cred_test_');
      $this->tempFiles[] = $path;
      return $path;
    }

    /** Captures every $form_state->set(key, value) call into the given array. */
    private function captureSetCalls(FormStateInterface $formState, array &$calls): void {
      $formState->method('set')->willReturnCallback(function ($key, $value) use (&$calls) {
        $calls[$key] = $value;
      });
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_credentials_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsOperationVerifyFileAndSubmit(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('radios', $result['operation']['#type']);
      $this->assertSame('import', $result['operation']['#default_value']);
      $this->assertArrayHasKey('import', $result['operation']['#options']);
      $this->assertArrayHasKey('restore', $result['operation']['#options']);
      $this->assertSame('checkbox', $result['verify']['#type']);
      $this->assertSame('file', $result['table_file']['#type']);
      $this->assertSame('submit', $result['upload_file']['#type']);
    }

    // -------------------------------------------------------------------------
    // validateForm — no file uploaded
    // -------------------------------------------------------------------------

    public function testValidateFormNoFileUploadedSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('table_file', $this->anything());

      $form = [];
      $this->buildForm([])->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — file cannot be opened
    // -------------------------------------------------------------------------

    public function testValidateFormFileOpenFailureSetsError(): void {
      $files = ['table_file' => $this->makeUploadedFile('/nonexistent_dir_xyz/creds.csv')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('table_file', $this->anything());

      $form = [];
      @$this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — empty file has no header
    // -------------------------------------------------------------------------

    public function testValidateFormEmptyFileSetsHeaderError(): void {
      $path = $this->writeEmptyFile();
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('table_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — valid file stores tempName and fieldPos
    // -------------------------------------------------------------------------

    public function testValidateFormValidFileStoresTempNameAndFieldPos(): void {
      $path = $this->writeCsv(['mcid', 'userName'], []);
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame($path, $calls['tempName']);
      $this->assertSame(['mcid' => 0, 'userName' => 1], $calls['fieldPos']);
    }

    public function testValidateFormTrimsWhitespaceFromHeaderNames(): void {
      $path = $this->writeCsv([' mcid ', ' userName '], []);
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame(['mcid' => 0, 'userName' => 1], $calls['fieldPos']);
    }

    // -------------------------------------------------------------------------
    // Helpers for submitForm
    // -------------------------------------------------------------------------

    private function headerColumns(): array {
      return ['mcid', 'userName', 'magicWord', 'hd', 'created', 'access', 'roles', 'county'];
    }

    private function baseRow(array $overrides = []): array {
      $defaults = [
        'mcid'      => '5001',
        'userName'  => 'jdoe',
        'magicWord' => 'secretWord',
        'hd'        => '12',
        'created'   => '2024-01-01',
        'access'    => '2024-01-02',
        'roles'     => '',
        'county'    => 'Denver',
      ];
      $row = array_merge($defaults, $overrides);
      $ordered = [];
      foreach ($this->headerColumns() as $col) {
        $ordered[] = $row[$col];
      }
      return $ordered;
    }

    private function submitFormWithRows(array $rows, string $operation = 'import', bool $verify = FALSE): void {
      $path = $this->writeCsv($this->headerColumns(), $rows);
      $fieldPos = array_flip($this->headerColumns());

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['operation', $operation],
        ['verify',    $verify],
      ]);
      $formState->method('get')->willReturnMap([
        ['tempName', $path],
        ['fieldPos', $fieldPos],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — file cannot be opened
    // -------------------------------------------------------------------------

    public function testSubmitFormFileOpenFailureAddsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['operation', 'import'],
        ['verify',    FALSE],
      ]);
      $formState->method('get')->willReturnMap([
        ['tempName', '/nonexistent_dir_xyz/creds.csv'],
        ['fieldPos', array_flip($this->headerColumns())],
      ]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->magicWordMock->expects($this->never())->method('updateCredentials');

      $form = [];
      @$this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — import: successful record
    // -------------------------------------------------------------------------

    public function testSubmitFormImportUpdatesCredentialsWithParsedRow(): void {
      $this->magicWordMock->expects($this->once())
        ->method('updateCredentials')
        ->with($this->callback(function (array $c): bool {
          return $c['mcid'] === '5001'
            && $c['userName'] === 'jdoe'
            && $c['magicWord'] === 'secretWord'
            && $c['hd'] === '12'
            && $c['created'] === '2024-01-01'
            && $c['access'] === '2024-01-02'
            && $c['roles'] === NULL;
        }));
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submitFormWithRows([$this->baseRow()]);
    }

    public function testSubmitFormImportSkipsRowWithEmptyUserName(): void {
      $this->magicWordMock->expects($this->never())->method('updateCredentials');

      $this->submitFormWithRows([$this->baseRow(['userName' => ''])]);
    }

    public function testSubmitFormImportDefaultsMissingMagicWordToUnknown(): void {
      $this->magicWordMock->expects($this->once())
        ->method('updateCredentials')
        ->with($this->callback(fn(array $c): bool => $c['magicWord'] === 'Unknown'));

      $this->submitFormWithRows([$this->baseRow(['magicWord' => ''])]);
    }

    public function testSubmitFormImportNonNumericHdBecomesNull(): void {
      $this->magicWordMock->expects($this->once())
        ->method('updateCredentials')
        ->with($this->callback(fn(array $c): bool => $c['hd'] === NULL));

      $this->submitFormWithRows([$this->baseRow(['hd' => 'N/A'])]);
    }

    public function testSubmitFormImportDecodesValidJsonRoles(): void {
      $this->magicWordMock->expects($this->once())
        ->method('updateCredentials')
        ->with($this->callback(fn(array $c): bool => $c['roles'] === ['nlp_leader' => TRUE]));

      $this->submitFormWithRows([$this->baseRow(['roles' => '{"nlp_leader":true}'])]);
    }

    public function testSubmitFormImportInvalidJsonRolesBecomesNull(): void {
      $this->magicWordMock->expects($this->once())
        ->method('updateCredentials')
        ->with($this->callback(fn(array $c): bool => $c['roles'] === NULL));

      $this->submitFormWithRows([$this->baseRow(['roles' => 'not-json'])]);
    }

    public function testSubmitFormImportAddsSuccessStatusMessage(): void {
      $this->messengerMock->expects($this->once())->method('addStatus');
      $this->submitFormWithRows([$this->baseRow()]);
    }

    // -------------------------------------------------------------------------
    // submitForm — verify checkbox
    // -------------------------------------------------------------------------

    public function testSubmitFormVerifyTrueSkipsRowWhenNlDoesNotExist(): void {
      $this->apiNlsMock->expects($this->once())
        ->method('nlExists')
        ->with('Denver', 5001)
        ->willReturn(FALSE);
      $this->magicWordMock->expects($this->never())->method('updateCredentials');

      $this->submitFormWithRows([$this->baseRow()], 'import', TRUE);
    }

    public function testSubmitFormVerifyTrueProcessesRowWhenNlExists(): void {
      $this->apiNlsMock->method('nlExists')->willReturn(TRUE);
      $this->magicWordMock->expects($this->once())->method('updateCredentials');

      $this->submitFormWithRows([$this->baseRow()], 'import', TRUE);
    }

    // -------------------------------------------------------------------------
    // submitForm — restore operation
    // -------------------------------------------------------------------------

    public function testSubmitFormRestoreEmptyPasswordSkipsLookup(): void {
      $this->magicWordMock->expects($this->never())->method('getCredentials');
      $this->messengerMock->expects($this->once())
        ->method('addStatus');

      $this->submitFormWithRows([$this->baseRow(['magicWord' => ''])], 'restore');
    }

    public function testSubmitFormRestoreNoExistingCredentialsSkipsRestore(): void {
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->expects($this->never())->method('setMagicWord');

      $this->submitFormWithRows([$this->baseRow()], 'restore');
    }

    public function testSubmitFormRestoreExistingPasswordSkipsRestore(): void {
      $this->magicWordMock->method('getCredentials')->willReturn(['mcid' => 5001]);
      $this->magicWordMock->method('getMagicWord')->willReturn('alreadySet');
      $this->magicWordMock->expects($this->never())->method('setMagicWord');

      $this->submitFormWithRows([$this->baseRow()], 'restore');
    }

    public function testSubmitFormRestoreDrupalUserPasswordMismatchSkipsRestore(): void {
      $this->magicWordMock->method('getCredentials')->willReturn(['mcid' => 5001]);
      $this->magicWordMock->method('getMagicWord')->willReturn('');
      $this->drupalUserMock->method('getUserByName')->willReturn(['uid' => 9]);
      $this->drupalUserMock->method('verifyPassword')->willReturn(FALSE);
      $this->magicWordMock->expects($this->never())->method('setMagicWord');

      $this->submitFormWithRows([$this->baseRow()], 'restore');
    }

    public function testSubmitFormRestoreDrupalUserPasswordMatchRestores(): void {
      $this->magicWordMock->method('getCredentials')->willReturn(['mcid' => 5001]);
      $this->magicWordMock->method('getMagicWord')->willReturn('');
      $this->drupalUserMock->method('getUserByName')->willReturn(['uid' => 9]);
      $this->drupalUserMock->method('verifyPassword')->willReturn(TRUE);
      $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(5001, 'secretWord');

      $capturedMessage = NULL;
      $this->messengerMock->expects($this->once())
        ->method('addStatus')
        ->willReturnCallback(function ($message) use (&$capturedMessage): void {
          $capturedMessage = (string) $message;
        });

      $this->submitFormWithRows([$this->baseRow()], 'restore');

      $this->assertStringContainsString('1', $capturedMessage);
    }

    public function testSubmitFormRestoreNoDrupalAccountRestoresWithoutVerifying(): void {
      $this->magicWordMock->method('getCredentials')->willReturn(['mcid' => 5001]);
      $this->magicWordMock->method('getMagicWord')->willReturn('');
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->drupalUserMock->expects($this->never())->method('verifyPassword');
      $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(5001, 'secretWord');

      $this->submitFormWithRows([$this->baseRow()], 'restore');
    }

  }

}
