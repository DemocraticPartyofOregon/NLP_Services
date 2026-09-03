<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\Form\ImportTableForm;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\FileBag;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  #[CoversClass(ImportTableForm::class)]
  #[Group('nlpservices')]
  class ImportTableFormTest extends UnitTestCase {

    private DatabaseFunctions $databaseFunctionsMock;
    private NlpAwards $nlpAwardsMock;
    private NlpNls $nlpNlsMock;
    private NlpTurfs $turfsMock;
    private NlpTurfAssignments $turfAssignmentsMock;
    private MessengerInterface $messengerMock;
    private array $tempFiles = [];

    protected function setUp(): void {
      parent::setUp();
      $this->databaseFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->nlpAwardsMock         = $this->createMock(NlpAwards::class);
      $this->nlpNlsMock            = $this->createMock(NlpNls::class);
      $this->turfsMock             = $this->createMock(NlpTurfs::class);
      $this->turfAssignmentsMock   = $this->createMock(NlpTurfAssignments::class);
      $this->messengerMock         = $this->createMock(MessengerInterface::class);

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

    private function buildForm(array $files = []): ImportTableForm {
      $filesBag = $this->createMock(FileBag::class);
      $filesBag->method('get')->with('files', [])->willReturn($files);

      $request = $this->createMock(Request::class);
      $request->files = $filesBag;

      $requestStack = $this->createMock(RequestStack::class);
      $requestStack->method('getCurrentRequest')->willReturn($request);

      $form = new ImportTableForm(
        $this->databaseFunctionsMock,
        $this->nlpAwardsMock,
        $this->nlpNlsMock,
        $this->turfsMock,
        $this->turfAssignmentsMock,
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
      $path = tempnam(sys_get_temp_dir(), 'nlp_table_test_');
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
      $path = tempnam(sys_get_temp_dir(), 'nlp_table_test_');
      $this->tempFiles[] = $path;
      return $path;
    }

    private function awardsHeaderColumns(): array {
      return ['mcid', 'vfid', 'electionCount', 'participation'];
    }

    private function turfHeaderColumns(): array {
      return ['turfId', 'vfid', 'dateAssigned'];
    }

    private function submitFormWithRows(
      string $tableName,
      array $headerColumns,
      array $rows,
      mixed $merge = FALSE,
    ): void {
      $path = $this->writeCsv($headerColumns, $rows);
      $fieldPos = array_flip($headerColumns);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['tableName', $tableName],
        ['tempName',  $path],
        ['fieldPos',  $fieldPos],
      ]);
      $formState->method('getValue')->willReturnMap([
        ['merge', $merge],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_table_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormForAwardsContainsMergeCheckboxFileFieldAndSubmit(): void {
      $result = $this->buildForm()->buildForm([], new FormState(), 'nlp_awards');

      $this->assertSame('checkbox', $result['merge']['#type']);
      $this->assertSame('file', $result['table_file']['#type']);
      $this->assertSame('submit', $result['upload_file']['#type']);
    }

    public function testBuildFormForTurfAssignmentDoesNotContainMergeCheckbox(): void {
      $result = $this->buildForm()->buildForm([], new FormState(), 'nlp_turf_assignment');

      $this->assertArrayNotHasKey('merge', $result);
      $this->assertSame('file', $result['table_file']['#type']);
      $this->assertSame('submit', $result['upload_file']['#type']);
    }

    public function testBuildFormContainsBackButtonLink(): void {
      $result = $this->buildForm()->buildForm([], new FormState(), 'nlp_awards');

      $this->assertArrayHasKey('button_link', $result);
    }

    public function testBuildFormSetsTableNameOnFormState(): void {
      $formState = new FormState();
      $this->buildForm()->buildForm([], $formState, 'nlp_turf_assignment');

      $this->assertSame('nlp_turf_assignment', $formState->get('tableName'));
    }

    // -------------------------------------------------------------------------
    // validateForm — no file uploaded
    // -------------------------------------------------------------------------

    public function testValidateFormNoFileUploadedSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('tableName')->willReturn('nlp_awards');
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
      $files = ['table_file' => $this->makeUploadedFile('/nonexistent_dir_xyz/table.csv')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('tableName')->willReturn('nlp_awards');
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
      $formState->method('get')->with('tableName')->willReturn('nlp_awards');
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('table_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — valid header stores tempName and fieldPos
    // -------------------------------------------------------------------------

    public function testValidateFormAwardsValidHeaderStoresTempNameAndFieldPos(): void {
      $path = $this->writeCsv($this->awardsHeaderColumns(), []);
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $this->nlpAwardsMock->method('decodeAwardHeader')
        ->with($this->awardsHeaderColumns())
        ->willReturn(['pos' => array_flip($this->awardsHeaderColumns()), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('tableName')->willReturn('nlp_awards');
      $formState->expects($this->never())->method('setErrorByName');
      $calls = [];
      $formState->method('set')->willReturnCallback(function ($key, $value) use (&$calls) {
        $calls[$key] = $value;
      });

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame($path, $calls['tempName']);
      $this->assertSame(array_flip($this->awardsHeaderColumns()), $calls['fieldPos']);
    }

    public function testValidateFormTurfAssignmentValidHeaderStoresTempNameAndFieldPos(): void {
      $path = $this->writeCsv($this->turfHeaderColumns(), []);
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $this->turfAssignmentsMock->method('decodeTurfAssignmentHeader')
        ->with($this->turfHeaderColumns())
        ->willReturn(['pos' => array_flip($this->turfHeaderColumns()), 'err' => [], 'ok' => TRUE]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('tableName')->willReturn('nlp_turf_assignment');
      $formState->expects($this->never())->method('setErrorByName');
      $calls = [];
      $formState->method('set')->willReturnCallback(function ($key, $value) use (&$calls) {
        $calls[$key] = $value;
      });

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);

      $this->assertSame($path, $calls['tempName']);
      $this->assertSame(array_flip($this->turfHeaderColumns()), $calls['fieldPos']);
    }

    // -------------------------------------------------------------------------
    // validateForm — header errors are reported and block submission
    // -------------------------------------------------------------------------

    public function testValidateFormHeaderErrorsAddMessengerErrorsAndSetsFormError(): void {
      $path = $this->writeCsv(['wrongColumn'], []);
      $files = ['table_file' => $this->makeUploadedFile($path)];

      $this->nlpAwardsMock->method('decodeAwardHeader')
        ->willReturn(['pos' => [], 'err' => ['The awards column "mcid" is missing.'], 'ok' => FALSE]);

      $this->messengerMock->expects($this->once())->method('addError')
        ->with('The awards column "mcid" is missing.');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('tableName')->willReturn('nlp_awards');
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('table_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — file cannot be opened
    // -------------------------------------------------------------------------

    public function testSubmitFormFileOpenFailureAddsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['tableName', 'nlp_awards'],
        ['tempName',  '/nonexistent_dir_xyz/table.csv'],
        ['fieldPos',  array_flip($this->awardsHeaderColumns())],
      ]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->databaseFunctionsMock->expects($this->never())->method('mergeRecord');

      $form = [];
      @$this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — awards, no merge
    // -------------------------------------------------------------------------

    public function testSubmitFormAwardsWithoutMergeUpdatesRecordAndAddsStatus(): void {
      $row = ['5001', '12', '', ''];

      $this->databaseFunctionsMock->expects($this->once())
        ->method('mergeRecord')
        ->with(
          'nlp_awards',
          ['mcid' => '5001'],
          $this->callback(fn(array $fields): bool => $fields['vfid'] === '12' && $fields['electionCount'] === 0),
        )
        ->willReturn(TRUE);

      $this->messengerMock->expects($this->once())->method('addStatus');
      $this->messengerMock->expects($this->never())->method('addError');

      $this->submitFormWithRows('nlp_awards', $this->awardsHeaderColumns(), [$row], FALSE);
    }

    public function testSubmitFormAwardsDefaultsMissingVfidAndElectionCountToZero(): void {
      $row = ['5001', '', '', ''];

      $this->databaseFunctionsMock->expects($this->once())
        ->method('mergeRecord')
        ->with(
          $this->anything(),
          $this->anything(),
          $this->callback(fn(array $fields): bool => $fields['vfid'] === 0 && $fields['electionCount'] === 0),
        )
        ->willReturn(TRUE);

      $this->submitFormWithRows('nlp_awards', $this->awardsHeaderColumns(), [$row], FALSE);
    }

    // -------------------------------------------------------------------------
    // submitForm — awards, merge when the row's election keys differ from
    // the existing record's election keys
    // -------------------------------------------------------------------------

    /**
     * When the row's participation keys differ from the existing record's
     * keys, ImportTableForm merges the existing record's election years with
     * the row's election years, all forced to TRUE.
     */
    public function testSubmitFormAwardsMergeWithDifferingKeysMergesElections(): void {
      $row = ['5001', '12', '', json_encode(['2021' => TRUE, '2022' => TRUE])];

      $this->nlpAwardsMock->method('getAward')
        ->willReturn(['participation' => ['2020' => TRUE]]);

      $expectedElections = ['2020' => TRUE, '2021' => TRUE, '2022' => TRUE];

      $this->databaseFunctionsMock->expects($this->once())
        ->method('mergeRecord')
        ->with(
          'nlp_awards',
          ['mcid' => '5001'],
          $this->callback(function (array $fields) use ($expectedElections): bool {
            return $fields['electionCount'] === 3
              && $fields['participation'] === json_encode($expectedElections);
          }),
        )
        ->willReturn(TRUE);

      $this->submitFormWithRows('nlp_awards', $this->awardsHeaderColumns(), [$row], TRUE);
    }

    // -------------------------------------------------------------------------
    // submitForm — awards, merge with unchanged participation skips update
    // -------------------------------------------------------------------------

    public function testSubmitFormAwardsMergeWithUnchangedParticipationSkipsUpdate(): void {
      $row = ['5001', '12', '', json_encode(['2020' => TRUE])];

      $this->nlpAwardsMock->method('getAward')
        ->willReturn(['participation' => ['2020' => TRUE]]);

      $this->databaseFunctionsMock->expects($this->never())->method('mergeRecord');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $this->submitFormWithRows('nlp_awards', $this->awardsHeaderColumns(), [$row], TRUE);
    }

    // -------------------------------------------------------------------------
    // submitForm — turf assignment
    // -------------------------------------------------------------------------

    public function testSubmitFormTurfAssignmentUpdatesRecordWithKeyedByTurfId(): void {
      $row = ['777', '12', '2024-01-15'];

      $this->databaseFunctionsMock->expects($this->once())
        ->method('mergeRecord')
        ->with(
          'nlp_turf_assignment',
          ['turfId' => '777'],
          $this->callback(fn(array $fields): bool => $fields['dateAssigned'] === '2024-01-15' && $fields['vfid'] === '12'),
        )
        ->willReturn(TRUE);

      $this->submitFormWithRows('nlp_turf_assignment', $this->turfHeaderColumns(), [$row]);
    }

    public function testSubmitFormTurfAssignmentRemovesEmptyDateAssigned(): void {
      $row = ['777', '12', ''];

      $this->databaseFunctionsMock->expects($this->once())
        ->method('mergeRecord')
        ->with(
          $this->anything(),
          $this->anything(),
          $this->callback(fn(array $fields): bool => !array_key_exists('dateAssigned', $fields)),
        )
        ->willReturn(TRUE);

      $this->submitFormWithRows('nlp_turf_assignment', $this->turfHeaderColumns(), [$row]);
    }

    // -------------------------------------------------------------------------
    // submitForm — mergeRecord failure stops processing
    // -------------------------------------------------------------------------

    public function testSubmitFormStopsAndAddsErrorWhenMergeRecordFails(): void {
      $rows = [['5001', '12', '', ''], ['5002', '13', '', '']];

      $this->databaseFunctionsMock->expects($this->once())->method('mergeRecord')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addError');
      $this->messengerMock->expects($this->never())->method('addStatus');

      $this->submitFormWithRows('nlp_awards', $this->awardsHeaderColumns(), $rows, FALSE);
    }

  }

}
