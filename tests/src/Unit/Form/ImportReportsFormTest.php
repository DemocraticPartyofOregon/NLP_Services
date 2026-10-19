<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\nlpservices\Form\ImportReportsForm;
use Drupal\nlpservices\NlpReports;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\ImportReportsForm::class)]
#[Group('nlpservices')]
class ImportReportsFormTest extends UnitTestCase {

  private NlpReports $reportsMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock   = $this->createMock(NlpReports::class);
    $this->messengerMock = $this->createMock(MessengerInterface::class);

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

  private function buildFormInstance(): ImportReportsForm {
    $form = new ImportReportsForm($this->reportsMock);
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

  private function stubModuleExtensionList(): ModuleExtensionList {
    $moduleListMock = $this->createMock(ModuleExtensionList::class);
    $moduleListMock->method('getPath')->willReturn('modules/custom/nlpservices');
    Drupal::getContainer()->set('extension.list.module', $moduleListMock);
    return $moduleListMock;
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

  public function testBuildFormContainsManagedFileRestrictedToCsv(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame('managed_file', $form['nl_reports_file']['#type']);
    $this->assertSame(['extensions' => 'csv'], $form['nl_reports_file']['#upload_validators']['FileExtension']);
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
  // submitForm — no file uploaded
  // -------------------------------------------------------------------------

  public function testSubmitFormReturnsEarlyWhenFileValueIsEmpty(): void {
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('nl_reports_file', []);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — File::load returns null
  // -------------------------------------------------------------------------

  public function testSubmitFormReturnsEarlyWhenFileLoadReturnsNull(): void {
    $this->stubEntityTypeManager(NULL);
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('nl_reports_file', [42]);
    $formState->setValue('empty', TRUE);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — save() throws an EntityStorageException
  // -------------------------------------------------------------------------

  public function testSubmitFormReturnsEarlyWhenSaveThrows(): void {
    $fileMock = $this->createMock(File::class);
    $fileMock->method('save')->willThrowException(new EntityStorageException('DB error'));
    $fileMock->expects($this->never())->method('getFilename');

    $this->stubEntityTypeManager($fileMock);
    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('nl_reports_file', [42]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — successful upload
  // -------------------------------------------------------------------------

  public function testSubmitFormEmptyCheckboxTrueEmptiesTableBeforeImport(): void {
    $fileMock = $this->createMock(File::class);
    $fileMock->method('getFilename')->willReturn('reports.csv');
    $this->stubEntityTypeManager($fileMock);
    $this->stubModuleExtensionList();

    $this->reportsMock->expects($this->once())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('nl_reports_file', [42]);
    $formState->setValue('empty', TRUE);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEmptyCheckboxFalseSkipsEmptyingTable(): void {
    $fileMock = $this->createMock(File::class);
    $fileMock->method('getFilename')->willReturn('reports.csv');
    $this->stubEntityTypeManager($fileMock);
    $this->stubModuleExtensionList();

    $this->reportsMock->expects($this->never())->method('emptyNlTable');

    $formState = new FormState();
    $formState->setValue('nl_reports_file', [42]);
    $formState->setValue('empty', FALSE);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

}
