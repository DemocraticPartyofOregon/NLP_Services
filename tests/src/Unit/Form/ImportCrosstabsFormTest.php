<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Form\ImportCrosstabsForm;
  use Drupal\nlpservices\NlpCrosstabCounts;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\FileBag;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class ImportCrosstabsFormTest extends UnitTestCase {

    private NlpCrosstabCounts $crosstabsMock;
    private MessengerInterface $messengerMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->crosstabsMock = $this->createMock(NlpCrosstabCounts::class);
      $this->messengerMock = $this->createMock(MessengerInterface::class);

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

    private function buildForm(array $files = []): ImportCrosstabsForm {
      $filesBag = $this->createMock(FileBag::class);
      $filesBag->method('get')->with('files', [])->willReturn($files);

      $request = $this->createMock(Request::class);
      $request->files = $filesBag;

      $requestStack = $this->createMock(RequestStack::class);
      $requestStack->method('getCurrentRequest')->willReturn($request);

      $form = new ImportCrosstabsForm($this->crosstabsMock, $requestStack);
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function makeUploadedFile(string $pathname, string $originalName = 'export.csv'): UploadedFile {
      $mock = $this->createMock(UploadedFile::class);
      $mock->method('getPathname')->willReturn($pathname);
      $mock->method('getClientOriginalName')->willReturn($originalName);
      return $mock;
    }

    private function writeCsv(array $rows): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_xtab_test_');
      $fh = fopen($path, 'w');
      foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    private function writeEmptyFile(): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_xtab_test_');
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

      $configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $configFactoryMock->method('getEditable')->willReturn($configMock);

      Drupal::getContainer()->set('config.factory', $configFactoryMock);

      return $configMock;
    }

    private function fieldPos(): array {
      return ['county' => 0, 'cd' => 1, 'party' => 2, 'balRet' => 3, 'total' => 4];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_crosstabs_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsFileInputAndSubmitButton(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('file', $result['crosstabs_file']['#type']);
      $this->assertSame('submit', $result['upload_file']['#type']);
      $this->assertSame('upload-file', $result['upload_file']['#id']);
    }

    // -------------------------------------------------------------------------
    // validateForm — no file uploaded
    // -------------------------------------------------------------------------

    public function testValidateFormNoFileUploadedSetsErrorAndStillStoresCreateTime(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm([])->validateForm($form, $formState);

      $this->assertIsInt($calls['createTime']);
    }

    // -------------------------------------------------------------------------
    // validateForm — file cannot be opened
    // -------------------------------------------------------------------------

    public function testValidateFormFileOpenFailureSetsError(): void {
      $files = ['crosstabs_file' => $this->makeUploadedFile('/nonexistent_dir_xyz/xtab.csv')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('crosstabs_file', $this->anything());

      $form = [];
      @$this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — missing first header row
    // -------------------------------------------------------------------------

    public function testValidateFormEmptyFileSetsHeaderError(): void {
      $path = $this->writeEmptyFile();
      $files = ['crosstabs_file' => $this->makeUploadedFile($path)];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — missing second header row
    // -------------------------------------------------------------------------

    public function testValidateFormSingleHeaderRowSetsHeaderError(): void {
      $path = $this->writeCsv([['county', 'cd', 'party']]);
      $files = ['crosstabs_file' => $this->makeUploadedFile($path)];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — header decode errors
    // -------------------------------------------------------------------------

    public function testValidateFormHeaderDecodeErrorsAddMessengerErrorsAndFormError(): void {
      $path = $this->writeCsv([['bad', 'header'], ['also', 'bad']]);
      $files = ['crosstabs_file' => $this->makeUploadedFile($path)];

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => [], 'err' => ['Missing column: county']]);

      $this->messengerMock->expects($this->once())
        ->method('addError')
        ->with('Missing column: county');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('crosstabs_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — valid file stores crosstabs_name and field_pos
    // -------------------------------------------------------------------------

    public function testValidateFormValidFileStoresNameAndFieldPos(): void {
      $path = $this->writeCsv([
        ['county', 'cd', 'party', 'balRet', 'total'],
        ['h1', 'h2', 'h3', 'h4', 'h5'],
      ]);
      $files = ['crosstabs_file' => $this->makeUploadedFile($path)];

      $this->crosstabsMock->method('decodeCrosstabsHeader')
        ->willReturn(['pos' => $this->fieldPos(), 'err' => []]);

      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame($path, $calls['crosstabs_name']);
      $this->assertSame($this->fieldPos(), $calls['field_pos']);
    }

    // -------------------------------------------------------------------------
    // validateForm — extracts the create date from the file name
    // -------------------------------------------------------------------------

    public function testValidateFormExtractsCreateDateFromFileName(): void {
      $path = $this->writeEmptyFile();
      $files = ['crosstabs_file' => $this->makeUploadedFile($path, 'Crosstab Export 01_15_2024 02_30_00 PM.csv')];

      $formState = $this->createMock(FormStateInterface::class);
      $calls = [];
      $this->captureSetCalls($formState, $calls);

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame(strtotime('01/15/2024 02:30:00 PM'), $calls['createTime']);
    }

    // -------------------------------------------------------------------------
    // submitForm — file cannot be opened
    // -------------------------------------------------------------------------

    public function testSubmitFormFileOpenFailureAddsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['crosstabs_name', '/nonexistent_dir_xyz/xtab.csv'],
        ['field_pos',      $this->fieldPos()],
      ]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->crosstabsMock->expects($this->never())->method('updateCrosstabCounts');

      $form = [];
      @$this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — helper for building a form_state wired to a csv file
    // -------------------------------------------------------------------------

    private function submitFormWithDataRows(array $dataRows): FormStateInterface {
      $rows = array_merge([
        ['header1a', 'header1b'],
        ['header2a', 'header2b'],
      ], $dataRows);
      $path = $this->writeCsv($rows);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['crosstabs_name', $path],
        ['field_pos',      $this->fieldPos()],
        ['createTime',     1700000000],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
      return $formState;
    }

    // -------------------------------------------------------------------------
    // submitForm — aggregates ALL party totals per county/cd
    // -------------------------------------------------------------------------

    public function testSubmitFormAggregatesAllPartyTotalsPerCountyAndCd(): void {
      $this->addConfigFactoryToContainer();

      $seen = [];
      $this->crosstabsMock->method('updateCrosstabCounts')
        ->willReturnCallback(function (array $counts) use (&$seen): bool {
          $seen[$counts['party']] = $counts;
          return TRUE;
        });

      $this->submitFormWithDataRows([
        ['Multnomah', '1', 'Democrats', '100', '200'],
        ['Multnomah', '1', 'Republicans', '50', '150'],
      ]);

      $this->assertCount(3, $seen);
      $this->assertSame('100', $seen['Democrats']['regVoted']);
      $this->assertSame('50', $seen['Republicans']['regVoted']);
      $this->assertSame(150, $seen['ALL']['regVoted']);
      $this->assertSame(350, $seen['ALL']['regVoters']);
    }

    // -------------------------------------------------------------------------
    // submitForm — normalizes "Hood River" to "Hood_River"
    // -------------------------------------------------------------------------

    public function testSubmitFormNormalizesHoodRiverCountyName(): void {
      $this->addConfigFactoryToContainer();

      $this->crosstabsMock->expects($this->atLeastOnce())
        ->method('updateCrosstabCounts')
        ->with($this->callback(fn(array $c): bool => $c['county'] === 'Hood_River'));

      $this->submitFormWithDataRows([
        ['Hood River', '1', 'Democrats', '30', '60'],
      ]);
    }

    // -------------------------------------------------------------------------
    // submitForm — skips the "Total People" summary row
    // -------------------------------------------------------------------------

    public function testSubmitFormSkipsTotalPeopleRow(): void {
      $this->crosstabsMock->expects($this->never())->method('updateCrosstabCounts');
      $this->messengerMock->expects($this->never())->method('addStatus');

      $this->submitFormWithDataRows([
        ['Total People', '1', '', '', ''],
      ]);
    }

    // -------------------------------------------------------------------------
    // submitForm — strips thousands separators from numeric fields
    // -------------------------------------------------------------------------

    public function testSubmitFormStripsCommasFromNumericFields(): void {
      $this->addConfigFactoryToContainer();

      $captured = NULL;
      $this->crosstabsMock->method('updateCrosstabCounts')
        ->willReturnCallback(function (array $counts) use (&$captured): bool {
          if ($counts['party'] === 'Democrats') {
            $captured = $counts;
          }
          return TRUE;
        });

      $this->submitFormWithDataRows([
        ['Multnomah', '1', 'Democrats', '1,234', '5,678'],
      ]);

      $this->assertSame('1234', $captured['regVoted']);
      $this->assertSame('5678', $captured['regVoters']);
    }

    // -------------------------------------------------------------------------
    // submitForm — success saves the create time and adds a status message
    // -------------------------------------------------------------------------

    public function testSubmitFormSuccessSavesCreateTimeAndAddsStatus(): void {
      $configMock = $this->addConfigFactoryToContainer();
      $configMock->expects($this->once())
        ->method('set')
        ->with('nlpservices-crosstab-create-time', 1700000000)
        ->willReturnSelf();
      $configMock->expects($this->once())->method('save');

      $this->crosstabsMock->method('updateCrosstabCounts')->willReturn(TRUE);
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submitFormWithDataRows([
        ['Multnomah', '1', 'Democrats', '100', '200'],
      ]);
    }

    // -------------------------------------------------------------------------
    // submitForm — no valid rows means no config save or status message
    // -------------------------------------------------------------------------

    public function testSubmitFormNoDataRowsSkipsConfigSaveAndStatus(): void {
      $this->messengerMock->expects($this->never())->method('addStatus');
      $this->crosstabsMock->expects($this->never())->method('updateCrosstabCounts');

      $this->submitFormWithDataRows([]);
    }

  }

}
