<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Batch\ImportStateEarlyVotingBatch;
  use Drupal\nlpservices\Form\ImportStateEarlyVotingForm;
  use Drupal\nlpservices\NlpEarlyVoting;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  #[CoversClass(ImportStateEarlyVotingForm::class)]
  #[Group('nlpservices')]
  class ImportStateEarlyVotingFormTest extends UnitTestCase {

    private NlpEarlyVoting $earlyVotingMock;
    private FileSystemInterface $fileObjMock;
    private MessengerInterface $messengerMock;
    private RequestStack $requestStack;
    private string $tempFile;

    protected function setUp(): void {
      parent::setUp();

      $this->earlyVotingMock = $this->createMock(NlpEarlyVoting::class);
      $this->fileObjMock     = $this->createMock(FileSystemInterface::class);
      $this->messengerMock   = $this->createMock(MessengerInterface::class);
      $this->requestStack    = new RequestStack();
      $this->requestStack->push(new Request());
      $this->tempFile        = tempnam(sys_get_temp_dir(), 'nlpsev');

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);

      $GLOBALS['batch_set_calls'] = [];
    }

    protected function tearDown(): void {
      parent::tearDown();
      @unlink($this->tempFile);
      Drupal::setContainer(new ContainerBuilder());
      unset($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildFormInstance(): ImportStateEarlyVotingForm {
      $form = new ImportStateEarlyVotingForm($this->earlyVotingMock, $this->fileObjMock, $this->requestStack);
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /**
     * Put an uploaded file with the given name and contents on the request.
     */
    private function uploadFile(string $fileName, string $contents = '', int $error = UPLOAD_ERR_OK): void {
      file_put_contents($this->tempFile, $contents);
      $file = new UploadedFile($this->tempFile, $fileName, 'text/csv', $error, TRUE);
      $this->requestStack->getCurrentRequest()->files->set('files', ['state_early_voting_file' => $file]);
    }

    private function validate(): FormState {
      $formState = new FormState();
      $form = [];
      $this->buildFormInstance()->validateForm($form, $formState);
      return $formState;
    }

    private function submit(): void {
      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());
    }

    private function headerOk(): void {
      $this->earlyVotingMock->method('decodeStateEarlyVotingHdr')
        ->willReturn(['ok' => TRUE, 'pos' => ['stateFileId' => 0, 'date' => 1], 'err' => []]);
    }

    // -------------------------------------------------------------------------
    // getFormId / buildForm
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_state_early_voting_form', $this->buildFormInstance()->getFormId());
    }

    /**
     * A managed_file field saves a file entity, which fails on a site with the
     * file_entity module, so the form must use a plain file field.
     */
    public function testBuildFormUsesRequiredPlainFileField(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('file', $form['state_early_voting_file']['#type']);
      $this->assertTrue($form['state_early_voting_file']['#required']);
      $this->assertSame('multipart/form-data', $form['#attributes']['enctype']);
    }

    public function testBuildFormContainsSubmitButton(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('submit', $form['submit']['#type']);
    }

    // -------------------------------------------------------------------------
    // validateForm
    // -------------------------------------------------------------------------

    public function testValidateAcceptsCsvAndTxtInAnyCase(): void {
      foreach (['ev.csv', 'ev.txt', 'EV.CSV'] as $fileName) {
        $this->uploadFile($fileName);
        $this->assertSame([], $this->validate()->getErrors(), $fileName);
      }
    }

    public function testValidateRejectsOtherFileTypes(): void {
      $this->uploadFile('ev.xlsx');
      $this->assertArrayHasKey('state_early_voting_file', $this->validate()->getErrors());
    }

    public function testValidateRequiresAFile(): void {
      $this->assertArrayHasKey('state_early_voting_file', $this->validate()->getErrors());
    }

    public function testValidateRejectsAFailedUpload(): void {
      $this->uploadFile('ev.csv', '', UPLOAD_ERR_PARTIAL);
      $this->assertArrayHasKey('state_early_voting_file', $this->validate()->getErrors());
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitDoesNothingWithNoUpload(): void {
      $this->earlyVotingMock->expects($this->never())->method('decodeStateEarlyVotingHdr');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitReportsAnEmptyFile(): void {
      $this->uploadFile('ev.csv');
      $this->messengerMock->expects($this->once())->method('addError');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();
    }

    public function testSubmitWithBadHeaderKeepsTheFileOutOfTemp(): void {
      $this->uploadFile('ev.csv', "Wrong,Columns\n");
      $this->earlyVotingMock->expects($this->once())
        ->method('decodeStateEarlyVotingHdr')
        ->with(['Wrong', 'Columns'])
        ->willReturn(['ok' => FALSE, 'pos' => [], 'err' => ['Missing Assigned Voter ID']]);
      $this->messengerMock->expects($this->once())->method('addWarning');
      $this->messengerMock->expects($this->once())->method('addError');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitStopsWhenTheMoveFails(): void {
      $this->uploadFile('ev.csv', "Assigned Voter ID,Ballot Received Date\n");
      $this->headerOk();
      $this->fileObjMock->method('moveUploadedFile')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addError');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitMovesTheFileAndStartsTheBatch(): void {
      $this->uploadFile('ev.csv', "Assigned Voter ID,Ballot Received Date\n");
      $this->headerOk();
      $this->fileObjMock->expects($this->once())
        ->method('moveUploadedFile')
        ->with($this->tempFile, 'public://temp/ev.csv')
        ->willReturn(TRUE);

      $this->submit();

      $this->assertCount(1, $GLOBALS['batch_set_calls']);
      $batch = $GLOBALS['batch_set_calls'][0];
      $this->assertSame([ImportStateEarlyVotingBatch::class, 'process'], $batch['operations'][0][0]);
      $this->assertSame([ImportStateEarlyVotingBatch::class, 'finished'], $batch['finished']);
      $this->assertSame([
        'fileUri' => 'public://temp/ev.csv',
        'fieldPos' => ['stateFileId' => 0, 'date' => 1],
      ], $batch['operations'][0][1][0]);
    }

  }

}

// Stub batch_set() in the form's namespace so unit tests do not require
// a full Drupal bootstrap.  Guarded because other test files declare the
// same namespaced stub.
namespace Drupal\nlpservices\Form {
  if (!function_exists(__NAMESPACE__ . '\batch_set')) {
    function batch_set(array $batch_definition): void {
      $GLOBALS['batch_set_calls'][] = $batch_definition;
    }
  }
}
