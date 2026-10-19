<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\nlpservices\Form\ImportStateEarlyVotingForm;
use Drupal\nlpservices\NlpEarlyVoting;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ImportStateEarlyVotingForm::class)]
#[Group('nlpservices')]
class ImportStateEarlyVotingFormTest extends UnitTestCase {

  private NlpEarlyVoting $earlyVotingMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->earlyVotingMock = $this->createMock(NlpEarlyVoting::class);
    $this->messengerMock   = $this->createMock(MessengerInterface::class);

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

  private function buildFormInstance(): ImportStateEarlyVotingForm {
    $form = new ImportStateEarlyVotingForm($this->earlyVotingMock);
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

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('nlpservices_import_state_early_voting_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormContainsRequiredManagedFileField(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('managed_file', $form['state_early_voting_file']['#type']);
    $this->assertTrue($form['state_early_voting_file']['#required']);
  }

  public function testBuildFormFileFieldRestrictsToCsvAndTxtExtensions(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame(['extensions' => 'csv txt'], $form['state_early_voting_file']['#upload_validators']['FileExtension']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('submit', $form['submit']['#type']);
  }

  // -------------------------------------------------------------------------
  // submitForm — no file uploaded
  // -------------------------------------------------------------------------

  public function testSubmitFormReturnsEarlyWhenUploadedFileIsEmpty(): void {
    $this->messengerMock->expects($this->never())->method('addError');

    $formState = new FormState();
    $formState->setValue('state_early_voting_file', []);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — File::load returns null
  // -------------------------------------------------------------------------

  public function testSubmitFormAddsErrorWhenFileEntityFailsToLoad(): void {
    $this->stubEntityTypeManager(NULL);
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $formState->setValue('state_early_voting_file', [42]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — save() throws an EntityStorageException
  // -------------------------------------------------------------------------

  public function testSubmitFormReturnsEarlyWithoutErrorWhenSaveThrows(): void {
    $fileMock = $this->createMock(File::class);
    $fileMock->method('save')->willThrowException(new EntityStorageException('DB error'));
    $fileMock->expects($this->never())->method('getFilename');

    $this->stubEntityTypeManager($fileMock);
    $this->messengerMock->expects($this->never())->method('addError');

    $formState = new FormState();
    $formState->setValue('state_early_voting_file', [42]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — file cannot be opened
  // -------------------------------------------------------------------------

  /**
   * The "public://" stream wrapper is not registered in a unit-test
   * environment, so fopen() on the built fileUri always returns false.
   * The form must report the error and clean up the saved file entity.
   */
  public function testSubmitFormAddsErrorAndDeletesFileWhenFileCannotBeOpened(): void {
    $fileMock = $this->createMock(File::class);
    $fileMock->method('getFilename')->willReturn('state_early_voting.csv');
    $fileMock->expects($this->once())->method('delete');

    $this->stubEntityTypeManager($fileMock);
    $this->messengerMock->expects($this->once())->method('addError');
    $this->earlyVotingMock->expects($this->never())->method('decodeStateEarlyVotingHdr');

    $formState = new FormState();
    $formState->setValue('state_early_voting_file', [42]);

    $form = [];
    @$this->buildFormInstance()->submitForm($form, $formState);
  }

}
