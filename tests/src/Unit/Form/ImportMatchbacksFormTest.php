<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Batch\ImportMatchbacksBatch;
  use Drupal\nlpservices\Form\ImportMatchbacksForm;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  #[CoversClass(ImportMatchbacksForm::class)]
  #[Group('nlpservices')]
  class ImportMatchbacksFormTest extends UnitTestCase {

    private FileSystemInterface $fileObjMock;
    private NlpMatchbacks $matchbacksMock;
    private MessengerInterface $messengerMock;
    private Config $configMock;
    private RequestStack $requestStack;
    private string $tempFile;

    protected function setUp(): void {
      parent::setUp();

      $this->fileObjMock    = $this->createMock(FileSystemInterface::class);
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->configMock     = $this->createMock(Config::class);
      $this->requestStack   = new RequestStack();
      $this->requestStack->push(new Request());
      $this->tempFile       = tempnam(sys_get_temp_dir(), 'nlpmb');

      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('getEditable')->willReturn($this->configMock);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('config.factory', $configFactory);
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

    private function buildFormInstance(): ImportMatchbacksForm {
      $form = new ImportMatchbacksForm(
        $this->fileObjMock,
        $this->matchbacksMock,
        $this->requestStack,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /**
     * Put an uploaded matchback file with the given name and contents on the
     * request.
     */
    private function uploadFile(string $fileName, string $contents = '', int $error = UPLOAD_ERR_OK): void {
      file_put_contents($this->tempFile, $contents);
      $file = new UploadedFile($this->tempFile, $fileName, 'text/csv', $error, TRUE);
      $this->requestStack->getCurrentRequest()->files->set('files', ['matchback_file' => $file]);
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
      $this->matchbacksMock->method('decodeMatchbackHdr')
        ->willReturn(['ok' => TRUE, 'pos' => ['vanid' => 0, 'date' => 1], 'err' => []]);
    }

    // -------------------------------------------------------------------------
    // getFormId / buildForm
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedString(): void {
      $this->assertSame('nlpservices_import_matchbacks_form', $this->buildFormInstance()->getFormId());
    }

    public function testBuildFormContainsHintMarkupElement(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('markup', $form['hint']['#type']);
    }

    /**
     * A managed_file field saves a file entity, which fails on a site with the
     * file_entity module, so the form must use a plain file field.
     */
    public function testBuildFormUsesPlainFileField(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('file', $form['matchback_file']['#type']);
      $this->assertSame('multipart/form-data', $form['#attributes']['enctype']);
    }

    public function testBuildFormContainsUploadSubmitButton(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('submit', $form['upload_file']['#type']);
      $this->assertSame('upload-file', $form['upload_file']['#id']);
    }

    // -------------------------------------------------------------------------
    // validateForm
    // -------------------------------------------------------------------------

    public function testValidateFormAcceptsCsvAndTxtInAnyCase(): void {
      foreach (['ballots.csv', 'ballots.txt', 'BALLOTS.CSV', 'Ballots.Txt'] as $fileName) {
        $this->uploadFile($fileName);
        $this->assertSame([], $this->validate()->getErrors(), $fileName);
      }
    }

    public function testValidateFormRejectsOtherFileTypes(): void {
      foreach (['ballots.xlsx', 'ballots.pdf', 'ballots', 'ballots.csv.zip'] as $fileName) {
        $this->uploadFile($fileName);
        $this->assertArrayHasKey('matchback_file', $this->validate()->getErrors(), $fileName);
      }
    }

    public function testValidateFormRequiresAFile(): void {
      $this->assertArrayHasKey('matchback_file', $this->validate()->getErrors());
    }

    public function testValidateFormRejectsAFailedUpload(): void {
      $this->uploadFile('ballots.csv', '', UPLOAD_ERR_PARTIAL);
      $this->assertArrayHasKey('matchback_file', $this->validate()->getErrors());
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitFormDoesNothingWithNoUpload(): void {
      $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitFormReportsAnEmptyFile(): void {
      $this->uploadFile('ballots.csv');
      $this->messengerMock->expects($this->once())->method('addError');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();
    }

    public function testSubmitFormDecodesTheCsvHeader(): void {
      $this->uploadFile('ballots.csv', " VANID ,Ballot Received\n123,11/01/2026\n");
      $this->matchbacksMock->expects($this->once())
        ->method('decodeMatchbackHdr')
        ->with(['VANID', 'Ballot Received'])
        ->willReturn(['ok' => FALSE, 'pos' => [], 'err' => []]);

      $this->submit();
    }

    public function testSubmitFormDecodesTheTabDelimitedHeader(): void {
      $this->uploadFile('ballots.txt', "VANID\tBallot Received\r\n123\t11/01/2026\n");
      $this->matchbacksMock->expects($this->once())
        ->method('decodeMatchbackHdr')
        ->with(['VANID', 'Ballot Received'])
        ->willReturn(['ok' => FALSE, 'pos' => [], 'err' => []]);

      $this->submit();
    }

    public function testSubmitFormWithBadHeaderKeepsTheFileOutOfTemp(): void {
      $this->uploadFile('ballots.csv', "Wrong,Columns\n");
      $this->matchbacksMock->method('decodeMatchbackHdr')
        ->willReturn(['ok' => FALSE, 'pos' => [], 'err' => ['Missing VANID']]);
      $this->messengerMock->expects($this->once())->method('addWarning')->with('Missing VANID');
      $this->messengerMock->expects($this->once())->method('addError');
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');
      $this->configMock->expects($this->never())->method('save');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitFormStopsWhenTheMoveFails(): void {
      $this->uploadFile('ballots.csv', "VANID,Ballot Received\n");
      $this->headerOk();
      $this->fileObjMock->method('moveUploadedFile')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addError');
      $this->configMock->expects($this->never())->method('save');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitFormMovesTheFileAndStartsTheBatch(): void {
      $this->uploadFile('ballots.txt', "VANID\tBallot Received\n");
      $this->headerOk();
      $this->fileObjMock->expects($this->once())
        ->method('moveUploadedFile')
        ->with($this->tempFile, 'public://temp/ballots.txt')
        ->willReturn(TRUE);
      $this->configMock->expects($this->once())->method('set')
        ->with('nlpservices-last-matchback-time', $this->greaterThan(0));
      $this->configMock->expects($this->once())->method('save');

      $this->submit();

      $this->assertCount(1, $GLOBALS['batch_set_calls']);
      $batch = $GLOBALS['batch_set_calls'][0];
      $this->assertSame([ImportMatchbacksBatch::class, 'process'], $batch['operations'][0][0]);
      $this->assertSame([
        'fileUri' => 'public://temp/ballots.txt',
        'fileType' => 'txt',
        'fieldPos' => ['vanid' => 0, 'date' => 1],
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
