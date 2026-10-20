<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\ImportMatchbacksForm;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\ImportMatchbacksForm
 * @group nlpservices
 */
class ImportMatchbacksFormTest extends UnitTestCase {

  private FileSystemInterface $fileObjMock;
  private NlpMatchbacks $matchbacksMock;
  private MessengerInterface $messengerMock;
  private RequestStack $requestStack;
  private string $tempFile;

  protected function setUp(): void {
    parent::setUp();

    $this->fileObjMock    = $this->createMock(FileSystemInterface::class);
    $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);
    $this->requestStack   = new RequestStack();
    $this->requestStack->push(new Request());
    $this->tempFile       = tempnam(sys_get_temp_dir(), 'nlpmb');

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
   * Put an uploaded matchback file with the given client name on the request.
   */
  private function uploadFile(string $fileName, int $error = UPLOAD_ERR_OK): void {
    $file = new UploadedFile($this->tempFile, $fileName, 'text/csv', $error, TRUE);
    $this->requestStack->getCurrentRequest()->files->set('files', ['matchback_file' => $file]);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_import_matchbacks_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsHintMarkupElement(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('hint', $form);
    $this->assertSame('markup', $form['hint']['#type']);
  }

  /**
   * @covers ::buildForm
   *
   * A managed_file field saves a file entity, which fails on a site with the
   * file_entity module, so the form must use a plain file field.
   */
  public function testBuildFormContainsPlainFileField(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('matchback_file', $form);
    $this->assertSame('file', $form['matchback_file']['#type']);
    $this->assertSame('multipart/form-data', $form['#attributes']['enctype']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsUploadSubmitButton(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('upload_file', $form);
    $this->assertSame('submit', $form['upload_file']['#type']);
  }

  // -------------------------------------------------------------------------
  // validateForm — file type
  // -------------------------------------------------------------------------

  private function validateUpload(): FormState {
    $formState = new FormState();
    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
    return $formState;
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAcceptsCsvAndTxtInAnyCase(): void {
    foreach (['ballots.csv', 'ballots.txt', 'BALLOTS.CSV', 'Ballots.Txt'] as $fileName) {
      $this->uploadFile($fileName);
      $this->assertSame([], $this->validateUpload()->getErrors(), $fileName);
    }
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRejectsOtherFileTypes(): void {
    foreach (['ballots.xlsx', 'ballots.pdf', 'ballots', 'ballots.csv.zip'] as $fileName) {
      $this->uploadFile($fileName);
      $this->assertArrayHasKey('matchback_file', $this->validateUpload()->getErrors(), $fileName);
    }
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRequiresAFile(): void {
    $this->assertArrayHasKey('matchback_file', $this->validateUpload()->getErrors());
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRejectsAFailedUpload(): void {
    $this->uploadFile('ballots.csv', UPLOAD_ERR_PARTIAL);
    $this->assertArrayHasKey('matchback_file', $this->validateUpload()->getErrors());
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormReturnsEarlyWhenNoFileWasUploaded(): void {
    $this->fileObjMock->expects($this->never())->method('moveUploadedFile');
    $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
    $this->messengerMock->expects($this->never())->method('addError');

    $form = [];
    $this->buildFormInstance()->submitForm($form, new FormState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormMovesTheUploadIntoTheTempFolder(): void {
    $this->uploadFile('NLPMatchback261019.csv');
    $this->fileObjMock->expects($this->once())->method('prepareDirectory')
      ->with('public://temp', FileSystemInterface::CREATE_DIRECTORY);
    $this->fileObjMock->expects($this->once())->method('moveUploadedFile')
      ->with($this->tempFile, 'public://temp/NLPMatchback261019.csv')
      ->willReturn(TRUE);

    $formState = new FormState();
    $form = [];
    // The public:// stream is not registered in a unit test, so the fopen
    // that follows the move fails.
    @$this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('public://temp/NLPMatchback261019.csv', $formState->get('fileUri'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDropsAnyPathInTheClientFileName(): void {
    $this->uploadFile('../../ballots.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(TRUE);

    $formState = new FormState();
    $form = [];
    @$this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('public://temp/ballots.csv', $formState->get('fileUri'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAddsErrorWhenTheUploadCannotBeMoved(): void {
    $this->uploadFile('ballots.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(FALSE);
    $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
    $this->messengerMock->expects($this->once())->method('addError');

    $form = [];
    $this->buildFormInstance()->submitForm($form, new FormState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAddsErrorWhenFileCannotBeOpened(): void {
    $this->uploadFile('ballots.csv');
    $this->fileObjMock->method('moveUploadedFile')->willReturn(TRUE);
    $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
    $this->messengerMock->expects($this->once())->method('addError');

    $form = [];
    @$this->buildFormInstance()->submitForm($form, new FormState());
  }

}
