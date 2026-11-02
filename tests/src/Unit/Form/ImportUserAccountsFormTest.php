<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Batch\ImportUserAccountsBatch;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\ImportUserAccountsForm;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\Group;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  #[CoversClass(ImportUserAccountsForm::class)]
  #[Group('nlpservices')]
  class ImportUserAccountsFormTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private FileSystemInterface $fileObjMock;
    private MessengerInterface $messengerMock;
    private RequestStack $requestStack;
    private string $tempFile;

    protected function setUp(): void {
      parent::setUp();

      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->fileObjMock    = $this->createMock(FileSystemInterface::class);
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->requestStack   = new RequestStack();
      $this->requestStack->push(new Request());
      $this->tempFile       = tempnam(sys_get_temp_dir(), 'nlpua');

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

    private function buildFormInstance(): ImportUserAccountsForm {
      $form = new ImportUserAccountsForm($this->drupalUserMock, $this->fileObjMock, $this->requestStack);
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /**
     * Put an uploaded user accounts file with the given name on the request.
     */
    private function uploadFile(string $fileName, int $error = UPLOAD_ERR_OK): void {
      $file = new UploadedFile($this->tempFile, $fileName, 'text/csv', $error, TRUE);
      $this->requestStack->getCurrentRequest()->files->set('files', ['user_file' => $file]);
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

    // -------------------------------------------------------------------------
    // getFormId / buildForm
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_user_accounts_form', $this->buildFormInstance()->getFormId());
    }

    /**
     * A managed_file field saves a file entity, which fails on a site with the
     * file_entity module, so the form must use a plain file field.
     */
    public function testBuildFormUsesPlainFileField(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('file', $form['user_file']['#type']);
      $this->assertSame('multipart/form-data', $form['#attributes']['enctype']);
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
    // validateForm
    // -------------------------------------------------------------------------

    public function testValidateAcceptsCsvInAnyCase(): void {
      foreach (['accounts.csv', 'ACCOUNTS.CSV'] as $fileName) {
        $this->uploadFile($fileName);
        $this->assertSame([], $this->validate()->getErrors(), $fileName);
      }
    }

    public function testValidateRejectsOtherFileTypes(): void {
      $this->uploadFile('accounts.txt');
      $this->assertArrayHasKey('user_file', $this->validate()->getErrors());
    }

    public function testValidateRequiresAFile(): void {
      $this->assertArrayHasKey('user_file', $this->validate()->getErrors());
    }

    public function testValidateRejectsAFailedUpload(): void {
      $this->uploadFile('accounts.csv', UPLOAD_ERR_PARTIAL);
      $this->assertArrayHasKey('user_file', $this->validate()->getErrors());
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitDoesNothingWithNoUpload(): void {
      $this->fileObjMock->expects($this->never())->method('moveUploadedFile');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitStopsWhenTheMoveFails(): void {
      $this->uploadFile('accounts.csv');
      $this->fileObjMock->method('moveUploadedFile')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addError');

      $this->submit();

      $this->assertSame([], $GLOBALS['batch_set_calls']);
    }

    public function testSubmitMovesTheFileAndStartsTheBatch(): void {
      $this->uploadFile('accounts.csv');
      $this->fileObjMock->expects($this->once())
        ->method('moveUploadedFile')
        ->with($this->tempFile, 'public://temp/accounts.csv')
        ->willReturn(TRUE);

      $this->submit();

      $this->assertCount(1, $GLOBALS['batch_set_calls']);
      $batch = $GLOBALS['batch_set_calls'][0];
      $this->assertSame([ImportUserAccountsBatch::class, 'process'], $batch['operations'][0][0]);
      $this->assertSame([ImportUserAccountsBatch::class, 'finished'], $batch['finished']);
      $this->assertSame('public://temp/accounts.csv', $batch['operations'][0][1][0]['uri']);
    }

  }

}

// Stub batch_set() in the form's namespace so unit tests do not require
// a full Drupal bootstrap.  Guarded because other test files (e.g.
// ImportMinivanFormTest) declare the same namespaced stub, and PHP fatals
// on redeclaring a function when both files load in the same test run.
namespace Drupal\nlpservices\Form {
  if (!function_exists(__NAMESPACE__ . '\batch_set')) {
    function batch_set(array $batch_definition): void {
      $GLOBALS['batch_set_calls'][] = $batch_definition;
    }
  }
}
