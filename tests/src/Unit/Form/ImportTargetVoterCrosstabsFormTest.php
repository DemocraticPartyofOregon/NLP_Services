<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\TypedConfigManagerInterface;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Form\ImportTargetVoterCrosstabsForm;
  use Drupal\nlpservices\NlpTargetVoterCrosstabCounts;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;

  #[CoversClass(ImportTargetVoterCrosstabsForm::class)]
  #[Group('nlpservices')]
  class ImportTargetVoterCrosstabsFormTest extends UnitTestCase {

    private NlpTargetVoterCrosstabCounts $crosstabsMock;
    private ConfigFactoryInterface $configFactoryMock;
    private TypedConfigManagerInterface $typedConfigManagerMock;
    private MessengerInterface $messengerMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();

      $this->crosstabsMock          = $this->createMock(NlpTargetVoterCrosstabCounts::class);
      $this->configFactoryMock      = $this->createMock(ConfigFactoryInterface::class);
      $this->typedConfigManagerMock = $this->createMock(TypedConfigManagerInterface::class);
      $this->messengerMock          = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);

      $_FILES = [];
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
      $_FILES = [];
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

    private function buildForm(): ImportTargetVoterCrosstabsForm {
      $form = new ImportTargetVoterCrosstabsForm(
        $this->configFactoryMock,
        $this->typedConfigManagerMock,
        $this->crosstabsMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /**
     * Populates $_FILES the way ImportTargetVoterCrosstabsForm reads it directly
     * (it does not use the request stack). $files maps field name to
     * ['tmp_name' => ..., 'name' => ...].
     */
    private function setUploadedFiles(array $files): void {
      $tmpNames = [];
      $names = [];
      foreach ($files as $field => $data) {
        $tmpNames[$field] = $data['tmp_name'] ?? '';
        $names[$field] = $data['name'] ?? '';
      }
      $_FILES['files'] = ['tmp_name' => $tmpNames, 'name' => $names];
    }

    private function writeCsv(array $rows): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_tvxtab_test_');
      $fh = fopen($path, 'w');
      foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    private function writeEmptyFile(): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_tvxtab_test_');
      $this->tempFiles[] = $path;
      return $path;
    }

    /** Captures every $form_state->set(key, value) call into the given array. */
    private function captureSetCalls(FormStateInterface $formState, array &$calls): void {
      $formState->method('set')->willReturnCallback(function ($key, $value) use (&$calls) {
        $calls[$key] = $value;
      });
    }

    private function addConfigFactoryToContainer(): Config {
      $configMock = $this->createMock(Config::class);
      $configMock->method('set')->willReturnSelf();
      $configMock->method('save')->willReturnSelf();

      $this->configFactoryMock->method('getEditable')->willReturn($configMock);

      return $configMock;
    }

    private function fieldPos(): array {
      return [
        'county'        => 0,
        'cd'            => 1,
        'party'         => 2,
        'total'         => 3,
        'accepted'      => 4,
        'rejected'      => 5,
        'sent'          => 6,
        'undeliverable' => 7,
        'void'          => 8,
        'unknown'       => 9,
      ];
    }

    private function headerRows(): array {
      return [
        ['US Congressional District', 'County', 'Likely Party', 'Total People'],
        ['Accepted', 'Rejected', 'Sent', 'Undeliverable', 'Void', 'Unknown'],
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_target_voter_crosstabs_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsHintMarkup(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('markup', $result['hint']['#type']);
    }

    public function testBuildFormContainsTargetAndAllVoterFileInputs(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('file', $result['target_voter_crosstabs_file']['#type']);
      $this->assertSame('file', $result['all_voter_crosstabs_file']['#type']);
    }

    public function testBuildFormContainsSubmitButton(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('submit', $result['upload_file']['#type']);
      $this->assertSame('upload-file', $result['upload_file']['#id']);
    }

    // -------------------------------------------------------------------------
    // validateForm — no file selected
    // -------------------------------------------------------------------------

    public function testValidateFormNoFilesSelectedSetsError(): void {
      $this->setUploadedFiles([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('target_voter_crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — target file cannot be opened
    // -------------------------------------------------------------------------

    public function testValidateFormTargetFileOpenFailureSetsError(): void {
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => '/nonexistent_dir_xyz/xtab.csv', 'name' => 'xtab.csv'],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('target_voter_crosstabs_file', $this->anything());

      $form = [];
      @$this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — target file missing header rows
    // -------------------------------------------------------------------------

    public function testValidateFormTargetFileMissingFirstHeaderRowSetsError(): void {
      $path = $this->writeEmptyFile();
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => $path, 'name' => 'xtab.csv'],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('target_voter_crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    public function testValidateFormTargetFileMissingSecondHeaderRowSetsError(): void {
      $path = $this->writeCsv([['US Congressional District', 'County', 'Likely Party', 'Total People']]);
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => $path, 'name' => 'xtab.csv'],
      ]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('target_voter_crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — header decode errors
    // -------------------------------------------------------------------------

    public function testValidateFormTargetFileHeaderDecodeErrorsAddMessengerErrorsAndFormError(): void {
      $path = $this->writeCsv($this->headerRows());
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => $path, 'name' => 'xtab.csv'],
      ]);

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => [], 'err' => ['Missing column: County'], 'ok' => FALSE]);

      $this->messengerMock->expects($this->once())->method('addError')->with('Missing column: County');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('target_voter_crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — valid files store target_file / all_file on form state
    // -------------------------------------------------------------------------

    public function testValidateFormTargetFileValidStoresTargetFileOnFormState(): void {
      $path = $this->writeCsv($this->headerRows());
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => $path, 'name' => 'xtab.csv'],
      ]);

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => $this->fieldPos(), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame($path, $calls['target_file']['name']);
      $this->assertSame($this->fieldPos(), $calls['target_file']['pos']);
      $this->assertArrayNotHasKey('all_file', $calls);
    }

    public function testValidateFormAllFileValidStoresAllFileOnFormState(): void {
      $path = $this->writeCsv($this->headerRows());
      $this->setUploadedFiles([
        'all_voter_crosstabs_file' => ['tmp_name' => $path, 'name' => 'xtab.csv'],
      ]);

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => $this->fieldPos(), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame($path, $calls['all_file']['name']);
      $this->assertArrayNotHasKey('target_file', $calls);
    }

    public function testValidateFormBothFilesValidStoresBothOnFormState(): void {
      $targetPath = $this->writeCsv($this->headerRows());
      $allPath    = $this->writeCsv($this->headerRows());
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => ['tmp_name' => $targetPath, 'name' => 'target.csv'],
        'all_voter_crosstabs_file'    => ['tmp_name' => $allPath, 'name' => 'all.csv'],
      ]);

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => $this->fieldPos(), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame($targetPath, $calls['target_file']['name']);
      $this->assertSame($allPath, $calls['all_file']['name']);
    }

    // -------------------------------------------------------------------------
    // validateForm — extracts the create date from the file name
    // -------------------------------------------------------------------------

    public function testValidateFormExtractsCreateDateFromFileName(): void {
      $path = $this->writeCsv($this->headerRows());
      $this->setUploadedFiles([
        'target_voter_crosstabs_file' => [
          'tmp_name' => $path,
          'name'     => 'Crosstab Export 01_15_2024 02_30_00 PM.csv',
        ],
      ]);

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => $this->fieldPos(), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame(strtotime('01/15/2024 02:30:00 PM'), $calls['target_file']['time']);
    }

    // -------------------------------------------------------------------------
    // submitForm — helpers
    // -------------------------------------------------------------------------

    private function submitFormWithFileData(?array $targetFile, ?array $allFile): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['target_file', $targetFile],
        ['all_file',    $allFile],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    private function targetFileData(string $path, int $time = 1700000000): array {
      return ['name' => $path, 'pos' => $this->fieldPos(), 'time' => $time];
    }

    // -------------------------------------------------------------------------
    // submitForm — no file data means no import and only the parent's status
    // -------------------------------------------------------------------------

    public function testSubmitFormNoFilesSkipsImportAndOnlyAddsParentStatus(): void {
      $this->crosstabsMock->expects($this->never())->method('updateCrosstabCounts');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submitFormWithFileData(NULL, NULL);
    }

    // -------------------------------------------------------------------------
    // submitForm — target file cannot be opened
    // -------------------------------------------------------------------------

    public function testSubmitFormTargetFileOpenFailureAddsErrorAndSkipsConfigSave(): void {
      $this->configFactoryMock->expects($this->never())->method('getEditable');
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['target_file', $this->targetFileData('/nonexistent_dir_xyz/xtab.csv')],
        ['all_file',    NULL],
      ]);

      $form = [];
      @$this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — target file imports counts and saves config time
    // -------------------------------------------------------------------------

    public function testSubmitFormTargetFileImportsCountsAndSavesConfigTime(): void {
      $configMock = $this->addConfigFactoryToContainer();
      $configMock->expects($this->once())
        ->method('set')
        ->with('nlpservices-target-voter-crosstab-create-time', 1700000000)
        ->willReturnSelf();
      $configMock->expects($this->once())->method('save');

      $this->crosstabsMock->expects($this->once())
        ->method('updateCrosstabCounts')
        ->with($this->anything(), NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL)
        ->willReturn(TRUE);

      // The form's own status plus the parent ConfigFormBase status.
      $this->messengerMock->expects($this->exactly(2))->method('addStatus');

      $path = $this->writeCsv(array_merge($this->headerRows(), [
        ['Multnomah', '1', 'Democrats', '100', '90', '5', '95', '3', '1', '1'],
      ]));

      $this->submitFormWithFileData($this->targetFileData($path), NULL);
    }

    // -------------------------------------------------------------------------
    // submitForm — all-voter file imports into the ALL_VOTER_CROSSTAB_TBL
    // -------------------------------------------------------------------------

    public function testSubmitFormAllFileImportsCountsIntoAllVoterTable(): void {
      $configMock = $this->addConfigFactoryToContainer();
      $configMock->expects($this->once())
        ->method('set')
        ->with('nlpservices-all-voter-crosstab-create-time', 1700000000)
        ->willReturnSelf();

      $this->crosstabsMock->expects($this->once())
        ->method('updateCrosstabCounts')
        ->with($this->anything(), NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL)
        ->willReturn(TRUE);

      $path = $this->writeCsv(array_merge($this->headerRows(), [
        ['Multnomah', '1', 'Democrats', '100', '90', '5', '95', '3', '1', '1'],
      ]));

      $this->submitFormWithFileData(NULL, $this->targetFileData($path));
    }

    // -------------------------------------------------------------------------
    // submitForm — skips the "Total People" summary row
    // -------------------------------------------------------------------------

    public function testSubmitFormSkipsTotalPeopleRowAndDoesNotSaveConfig(): void {
      $this->configFactoryMock->expects($this->never())->method('getEditable');
      $this->crosstabsMock->expects($this->never())->method('updateCrosstabCounts');

      $path = $this->writeCsv(array_merge($this->headerRows(), [
        ['Total People', '', '', '', '', '', '', '', '', ''],
      ]));

      $this->submitFormWithFileData($this->targetFileData($path), NULL);
    }

    // -------------------------------------------------------------------------
    // submitForm — normalizes "Hood River" to "Hood_River"
    // -------------------------------------------------------------------------

    public function testSubmitFormNormalizesHoodRiverCountyName(): void {
      $this->addConfigFactoryToContainer();

      $this->crosstabsMock->expects($this->once())
        ->method('updateCrosstabCounts')
        ->with($this->callback(fn(array $c): bool => $c['county'] === 'Hood_River'), $this->anything())
        ->willReturn(TRUE);

      $path = $this->writeCsv(array_merge($this->headerRows(), [
        ['Hood River', '1', 'Democrats', '100', '90', '5', '95', '3', '1', '1'],
      ]));

      $this->submitFormWithFileData($this->targetFileData($path), NULL);
    }

    // -------------------------------------------------------------------------
    // submitForm — strips thousands separators from numeric fields
    // -------------------------------------------------------------------------

    public function testSubmitFormStripsCommasFromNumericFields(): void {
      $this->addConfigFactoryToContainer();

      $captured = NULL;
      $this->crosstabsMock->method('updateCrosstabCounts')
        ->willReturnCallback(function (array $counts) use (&$captured): bool {
          $captured = $counts;
          return TRUE;
        });

      $path = $this->writeCsv(array_merge($this->headerRows(), [
        ['Multnomah', '1', 'Democrats', '1,234', '90', '5', '95', '3', '1', '1'],
      ]));

      $this->submitFormWithFileData($this->targetFileData($path), NULL);

      $this->assertSame(1234, $captured['total']);
    }

  }

}
