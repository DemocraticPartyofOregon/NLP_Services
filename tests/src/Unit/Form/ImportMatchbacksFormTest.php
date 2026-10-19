<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Form\ImportMatchbacksForm;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\Tests\UnitTestCase;
use Drupal\file\Entity\File;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\ImportMatchbacksForm
 * @group nlpservices
 */
class ImportMatchbacksFormTest extends UnitTestCase {

  private FileSystemInterface $fileObjMock;
  private NlpMatchbacks $matchbacksMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->fileObjMock    = $this->createMock(FileSystemInterface::class);
    $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
    $this->messengerMock  = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): ImportMatchbacksForm {
    $form = new ImportMatchbacksForm(
      $this->fileObjMock,
      $this->matchbacksMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Register entity_type.manager and entity_type.repository mocks so that
   * File::load() resolves without a real Drupal entity stack.
   * $loadReturn is what storage->load() returns.
   */
  private function stubEntityTypeManager(mixed $loadReturn = NULL): void {
    $storageMock = $this->createMock(EntityStorageInterface::class);
    $storageMock->method('load')->willReturn($loadReturn);

    $etmMock = $this->createMock(EntityTypeManagerInterface::class);
    $etmMock->method('getStorage')->willReturn($storageMock);

    $etrMock = $this->createMock(EntityTypeRepositoryInterface::class);
    $etrMock->method('getEntityTypeFromClass')->willReturn('file');

    $container = Drupal::getContainer();
    $container->set('entity_type.manager', $etmMock);
    $container->set('entity_type.repository', $etrMock);
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
   */
  public function testBuildFormContainsManagedFileField(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('matchback_file', $form);
    $this->assertSame('managed_file', $form['matchback_file']['#type']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFileFieldAllowsAnyExtension(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    // An empty FileExtension validator tells Drupal to allow any extension.
    $this->assertSame([], $form['matchback_file']['#upload_validators']['FileExtension']);
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

  private function validateUpload(?string $fileName): FormState {
    $file = NULL;
    if ($fileName !== NULL) {
      $file = $this->createMock(File::class);
      $file->method('getFilename')->willReturn($fileName);
    }
    $this->stubEntityTypeManager($file);

    $formState = new FormState();
    $formState->setTriggeringElement(['#id' => 'upload-file']);
    $formState->setValue('matchback_file', [42]);
    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
    return $formState;
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormAcceptsCsvAndTxtInAnyCase(): void {
    foreach (['ballots.csv', 'ballots.txt', 'BALLOTS.CSV', 'Ballots.Txt'] as $fileName) {
      $this->assertSame([], $this->validateUpload($fileName)->getErrors(), $fileName);
    }
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRejectsOtherFileTypes(): void {
    foreach (['ballots.xlsx', 'ballots.pdf', 'ballots', 'ballots.csv.zip'] as $fileName) {
      $formState = $this->validateUpload($fileName);
      $this->assertArrayHasKey('matchback_file', $formState->getErrors(), $fileName);
    }
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRejectsFileThatCannotBeLoaded(): void {
    $this->assertArrayHasKey('matchback_file', $this->validateUpload(NULL)->getErrors());
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormRequiresAFile(): void {
    $formState = new FormState();
    $formState->setTriggeringElement(['#id' => 'upload-file']);
    $formState->setValue('matchback_file', []);
    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertArrayHasKey('matchback_file', $formState->getErrors());
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormSkipsTheFileFieldsOwnButtons(): void {
    // The managed_file Remove button submits with no file; that must not fail.
    $formState = new FormState();
    $formState->setTriggeringElement(['#id' => 'edit-matchback-file-remove-button']);
    $formState->setValue('matchback_file', []);
    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  // -------------------------------------------------------------------------
  // submitForm — empty file value returns early
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormReturnsEarlyWhenMatchbackFileIsEmptyArray(): void {
    $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
    $this->messengerMock->expects($this->never())->method('addError');

    $formState = new FormState();
    $formState->setValue('matchback_file', []);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormReturnsEarlyWhenMatchbackFileIsNull(): void {
    $this->matchbacksMock->expects($this->never())->method('decodeMatchbackHdr');
    $this->messengerMock->expects($this->never())->method('addError');

    $formState = new FormState();
    $formState->setValue('matchback_file', NULL);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — File::load returns null → fopen on empty URI fails
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   *
   * When File::load() returns null the filename stays empty, making
   * $fileUri = 'public://temp/' (an unresolvable stream in a unit-test
   * environment).  fopen() returns false so the form must add an error.
   */
  public function testSubmitFormAddsErrorWhenFileCannotBeOpened(): void {
    $this->stubEntityTypeManager(NULL);
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->setValue('matchback_file', [42]);

    $form = [];
    @$this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   *
   * Even when fopen fails, $form_state['fileUri'] must be set before the
   * early return so that any post-submit handler can inspect the intended path.
   */
  public function testSubmitFormSetsFileUriOnFormStateBeforeFopenAttempt(): void {
    $this->stubEntityTypeManager(NULL);

    $formState = new FormState();
    $formState->setValue('matchback_file', [42]);

    $form = [];
    @$this->buildFormInstance()->submitForm($form, $formState);

    // File::load returned null so filename is '' → URI must be 'public://temp/'.
    $this->assertSame('public://temp/', $formState->get('fileUri'));
  }

}
