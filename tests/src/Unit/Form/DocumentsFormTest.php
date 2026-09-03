<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\DocumentsForm;
  use Drupal\nlpservices\NlpDocuments;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\FileBag;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class DocumentsFormTest extends UnitTestCase {

    private NlpDocuments $documentsMock;
    private DrupalUser $drupalUserMock;
    private NlpPaths $pathsMock;
    private FileSystemInterface $fileSystemMock;

    protected function setUp(): void {
      parent::setUp();
      $this->documentsMock  = $this->createMock(NlpDocuments::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->pathsMock      = $this->createMock(NlpPaths::class);
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(array $files = []): DocumentsForm {
      $filesBag = $this->createMock(FileBag::class);
      $filesBag->method('get')->with('files', [])->willReturn($files);

      $request = $this->createMock(Request::class);
      $request->files = $filesBag;

      $requestStack = $this->createMock(RequestStack::class);
      $requestStack->method('getCurrentRequest')->willReturn($request);

      $form = new DocumentsForm(
        $this->documentsMock,
        $this->drupalUserMock,
        $this->pathsMock,
        $this->fileSystemMock,
        $requestStack,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function makeUploadedFile(string $originalName, string $realPath = '/tmp/phpABCDEF'): UploadedFile {
      $mock = $this->createMock(UploadedFile::class);
      $mock->method('getClientOriginalName')->willReturn($originalName);
      $mock->method('getRealPath')->willReturn($realPath);
      return $mock;
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_documents_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — admin user sees the upload fieldset
    // -------------------------------------------------------------------------

    public function testBuildFormAdminUserShowsUploadFieldset(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
      $this->documentsMock->method('displayList')->willReturn(['nlInstruct' => 'NL Instructions']);

      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertArrayHasKey('new_documents', $result);
      $this->assertSame(['nlInstruct' => 'NL Instructions'], $result['new_documents']['document_type']['#options']);
      $this->assertSame('file', $result['new_documents']['new_docx']['#type']);
      $this->assertSame('file', $result['new_documents']['new_pdf']['#type']);
      $this->assertSame('document_submit', $result['new_documents']['submit']['#name']);
      $this->assertSame('multipart/form-data', $result['#attributes']['enctype']);
    }

    // -------------------------------------------------------------------------
    // buildForm — non-admin user gets an unmodified form
    // -------------------------------------------------------------------------

    public function testBuildFormNonAdminUserLeavesFormUnchanged(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->documentsMock->expects($this->never())->method('displayList');

      $form = [];
      $result = $this->buildForm()->buildForm($form, $this->createMock(FormStateInterface::class));
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // validateForm — editable document extension
    // -------------------------------------------------------------------------

    public function testValidateFormBadEditableExtensionSetsError(): void {
      $files = [
        'new_docx' => $this->makeUploadedFile('instructions.txt'),
        'new_pdf'  => $this->makeUploadedFile('instructions.pdf'),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('new_docx', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    public function testValidateFormDocxExtensionSetsNoEditableError(): void {
      $files = [
        'new_docx' => $this->makeUploadedFile('instructions.docx'),
        'new_pdf'  => $this->makeUploadedFile('instructions.pdf'),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    public function testValidateFormDocExtensionSetsNoEditableError(): void {
      $files = [
        'new_docx' => $this->makeUploadedFile('instructions.doc'),
        'new_pdf'  => $this->makeUploadedFile('instructions.pdf'),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — PDF extension
    // -------------------------------------------------------------------------

    public function testValidateFormBadPdfExtensionSetsError(): void {
      $files = [
        'new_docx' => $this->makeUploadedFile('instructions.docx'),
        'new_pdf'  => $this->makeUploadedFile('instructions.docx'),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('new_pdf', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — missing files → both errors set
    // -------------------------------------------------------------------------

    public function testValidateFormMissingFilesSetsBothErrors(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->exactly(2))
        ->method('setErrorByName')
        ->with($this->logicalOr(
          $this->equalTo('new_docx'),
          $this->equalTo('new_pdf'),
        ), $this->anything());

      $form = [];
      $this->buildForm([])->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitFormReplacesExistingDocumentsAndCreatesRecord(): void {
      $files = [
        'new_docx' => $this->makeUploadedFile('newDoc.docx', '/tmp/phpDOCX'),
        'new_pdf'  => $this->makeUploadedFile('newDoc.pdf', '/tmp/phpPDF'),
      ];

      $this->documentsMock->method('getDocuments')->willReturn([
        'nlInstruct' => ['docFileName' => 'oldDoc.docx', 'pdfFileName' => 'oldDoc.pdf'],
      ]);
      $this->pathsMock->method('getPath')->with('DOCS')->willReturn('/var/files/docs/');

      $this->fileSystemMock->expects($this->exactly(2))
        ->method('unlink')
        ->with($this->logicalOr(
          $this->equalTo('/var/files/docs/oldDoc.docx'),
          $this->equalTo('/var/files/docs/oldDoc.pdf'),
        ));

      $this->fileSystemMock->expects($this->exactly(2))
        ->method('moveUploadedFile')
        ->with($this->logicalOr(
          $this->equalTo('/tmp/phpDOCX'),
          $this->equalTo('/tmp/phpPDF'),
        ), $this->logicalOr(
          $this->equalTo('/var/files/docs/newDoc.docx'),
          $this->equalTo('/var/files/docs/newDoc.pdf'),
        ));

      $this->documentsMock->expects($this->once())
        ->method('createDocument')
        ->with($this->callback(function (array $req): bool {
          return $req['name'] === 'nlInstruct'
            && $req['docFileName'] === 'newDoc.docx'
            && $req['pdfFileName'] === 'newDoc.pdf'
            && $req['missing'] === FALSE;
        }));

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->with('document_type')->willReturn('nlInstruct');

      $form = [];
      $this->buildForm($files)->submitForm($form, $formState);
    }

  }

}
