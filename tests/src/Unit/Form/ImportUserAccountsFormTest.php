<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Entity\EntityStorageException;
  use Drupal\Core\Entity\EntityStorageInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Entity\EntityTypeRepositoryInterface;
  use Drupal\Core\Form\FormState;
  use Drupal\file\Entity\File;
  use Drupal\nlpservices\Batch\ImportUserAccountsBatch;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\ImportUserAccountsForm;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;

  #[CoversClass(ImportUserAccountsForm::class)]
  #[Group('nlpservices')]
  class ImportUserAccountsFormTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;

    protected function setUp(): void {
      parent::setUp();

      $this->drupalUserMock = $this->createMock(DrupalUser::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);

      $GLOBALS['batch_set_calls'] = [];
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
      unset($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildFormInstance(): ImportUserAccountsForm {
      $form = new ImportUserAccountsForm($this->drupalUserMock);
      $form->setStringTranslation($this->getStringTranslationStub());
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
      $this->assertSame('nlpservices_import_user_accounts_form', $this->buildFormInstance()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsManagedFileRestrictedToCsv(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('managed_file', $form['user_file']['#type']);
      $this->assertSame(['extensions' => 'csv'], $form['user_file']['#upload_validators']['FileExtension']);
    }

    public function testBuildFormContainsImportSubmitButton(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('submit', $form['import_file']['#type']);
      $this->assertSame('import-file', $form['import_file']['#id']);
    }

    public function testBuildFormContainsBackButtonLink(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertArrayHasKey('button_link', $form);
    }

    // -------------------------------------------------------------------------
    // submitForm — no file uploaded
    // -------------------------------------------------------------------------

    public function testSubmitFormReturnsEarlyWhenUserFileIsEmpty(): void {
      $formState = new FormState();
      $formState->setValue('user_file', []);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $this->assertEmpty($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // submitForm — File::load returns null
    // -------------------------------------------------------------------------

    public function testSubmitFormReturnsEarlyWhenFileLoadReturnsNull(): void {
      $this->stubEntityTypeManager(NULL);

      $formState = new FormState();
      $formState->setValue('user_file', [42]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $this->assertEmpty($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // submitForm — save() throws an EntityStorageException
    // -------------------------------------------------------------------------

    public function testSubmitFormReturnsEarlyWhenSaveThrows(): void {
      $fileMock = $this->createMock(File::class);
      $fileMock->method('save')->willThrowException(new EntityStorageException('DB error'));
      $fileMock->expects($this->never())->method('getFilename');

      $this->stubEntityTypeManager($fileMock);

      $formState = new FormState();
      $formState->setValue('user_file', [42]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $this->assertEmpty($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // submitForm — successful upload sets the batch
    // -------------------------------------------------------------------------

    public function testSubmitFormSuccessSetsOneBatch(): void {
      $fileMock = $this->createMock(File::class);
      $fileMock->method('getFilename')->willReturn('accounts.csv');
      $fileMock->expects($this->once())->method('save');

      $this->stubEntityTypeManager($fileMock);

      $formState = new FormState();
      $formState->setValue('user_file', [42]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $this->assertCount(1, $GLOBALS['batch_set_calls']);
    }

    public function testSubmitFormSuccessBatchUsesUploadedFileUri(): void {
      $fileMock = $this->createMock(File::class);
      $fileMock->method('getFilename')->willReturn('accounts.csv');

      $this->stubEntityTypeManager($fileMock);

      $formState = new FormState();
      $formState->setValue('user_file', [42]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $args = $GLOBALS['batch_set_calls'][0]['operations'][0][1][0];
      $this->assertSame('public://temp/accounts.csv', $args['uri']);
    }

    public function testSubmitFormSuccessBatchReferencesImportUserAccountsBatchClass(): void {
      $fileMock = $this->createMock(File::class);
      $fileMock->method('getFilename')->willReturn('accounts.csv');

      $this->stubEntityTypeManager($fileMock);

      $formState = new FormState();
      $formState->setValue('user_file', [42]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, $formState);

      $batch = $GLOBALS['batch_set_calls'][0];
      $this->assertSame([ImportUserAccountsBatch::class, 'process'], $batch['operations'][0][0]);
      $this->assertSame([ImportUserAccountsBatch::class, 'finished'], $batch['finished']);
    }

  }

}

// Stub batch_set() in the form's namespace so unit tests do not require
// a full Drupal bootstrap. Guarded because other test files (e.g.
// ImportMinivanFormTest) declare the same namespaced stub, and PHP fatals
// on redeclaring a function when both files load in the same test run.
namespace Drupal\nlpservices\Form {
  if (!function_exists(__NAMESPACE__ . '\batch_set')) {
    function batch_set(array $batch_definition): void {
      $GLOBALS['batch_set_calls'][] = $batch_definition;
    }
  }
}
