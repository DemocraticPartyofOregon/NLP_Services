<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\ImportReportsForm;
use Drupal\nlpservices\NlpReports;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ImportReportsForm::class)]
#[Group('nlpservices')]
class ImportReportsFormTest extends UnitTestCase {

  private NlpReports $reportsMock;
  private FileSystemInterface $fileObjMock;
  private MessengerInterface $messengerMock;
  private RequestStack $requestStack;
  private string $tempFile;

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock   = $this->createMock(NlpReports::class);
    $this->fileObjMock   = $this->createMock(FileSystemInterface::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);
    $this->requestStack  = new RequestStack();
    $this->requestStack->push(new Request());
    $this->tempFile      = tempnam(sys_get_temp_dir(), 'nlprp');

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    @unlink($this->tempFile);
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): ImportReportsForm {
    $form = new ImportReportsForm($this->reportsMock, $this->fileObjMock, $this->requestStack);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Put an uploaded reports file with the given client name on the request.
   */
  private function uploadFile(string $fileName, int $error = UPLOAD_ERR_OK): void {
    $file = new UploadedFile($this->tempFile, $fileName, 'text/csv', $error, TRUE);
    $this->requestStack->getCurrentRequest()->files->set('files', ['nl_reports_file' => $file]);
  }

  private function validate(): FormState {
    $formState = new FormState();
    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
    return $formState;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('nlpservices_import_reports_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormContainsEmptyCheckbox(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('checkbox', $form['empty']['#type']);
  }

  /**
   * A managed_file field saves a file entity, which fails on a site with the
   * file_entity module, so the form must use a plain file field.
   */
  public function testBuildFormUsesPlainFileField(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('file', $form['nl_reports_file']['#type']);
    $this->assertSame('multipart/form-data', $form['#attributes']['enctype']);
  }

  public function testBuildFormContainsUploadSubmitButton(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('submit', $form['upload_file']['#type']);
    $this->assertSame('upload-file', $form['upload_file']['#id']);
  }

  public function testBuildFormContainsBackButtonLink(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('button_link', $form);
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFailsWithNoUpload(): void {
    $this->assertArrayHasKey('nl_reports_file', $this->validate()->getErrors());
  }

  public function testValidateFailsOnUploadError(): void {
    $this->uploadFile('reports.csv', UPLOAD_ERR_PARTIAL);
    $this->assertArrayHasKey('nl_reports_file', $this->validate()->getErrors());
  }

  public function testValidateRejectsNonCsv(): void {
    $this->uploadFile('reports.xlsx');
    $this->assertArrayHasKey('nl_reports_file', $this->validate()->getErrors());
  }

  public function testValidateAcceptsCsvAnyCase(): void {
    $this->uploadFile('voter_contact_reports_2026-11-G_date-2026-11-01-20-59-09.CSV');
    $this->assertSame([], $this->validate()->getErrors());
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitWithNoUploadDoesNothing(): void {
    $this->fileObjMock->expects($this->never())->method('moveUploadedFile');
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $form = [];
    $this->buildFormInstance()->submitForm($form, new FormState());
  }

  public function testSubmitMovesUploadIntoPublicTemp(): void {
    $this->uploadFile('../reports.csv');
    $this->fileObjMock->expects($this->once())
      ->method('moveUploadedFile')
      ->with($this->tempFile, 'public://temp/reports.csv')
      ->willReturn(TRUE);
    $this->messengerMock->expects($this->never())->method('addError');

    $form = [];
    $this->buildFormInstance()->submitForm($form, new FormState());
  }

  public function testSubmitStopsWhenMoveFails(): void {
    $this->uploadFile('reports.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(FALSE);
    $this->messengerMock->expects($this->once())->method('addError');
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('empty', TRUE);
    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitEmptiesTableWhenChecked(): void {
    $this->uploadFile('reports.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(TRUE);
    $this->reportsMock->expects($this->once())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('empty', TRUE);
    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitKeepsTableWhenUnchecked(): void {
    $this->uploadFile('reports.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(TRUE);
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('empty', FALSE);
    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

}
