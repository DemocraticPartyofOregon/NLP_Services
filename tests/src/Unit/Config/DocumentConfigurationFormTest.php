<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Config;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Config\DocumentConfigurationForm;
use Drupal\nlpservices\NlpDocuments;
use Drupal\nlpservices\NlpPaths;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(DocumentConfigurationForm::class)]
#[Group('nlpservices')]
class DocumentConfigurationFormTest extends UnitTestCase {

  private NlpDocuments $documentsObjMock;
  private NlpPaths $pathsObjMock;
  private FileSystemInterface $filesystemMock;
  private MessengerInterface $messengerMock;

  /** @var string[] Absolute directories created by tests, removed in tearDown(). */
  private array $tempDirs = [];

  protected function setUp(): void {
    parent::setUp();

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir());
    }

    $this->documentsObjMock = $this->createMock(NlpDocuments::class);
    $this->pathsObjMock     = $this->createMock(NlpPaths::class);
    $this->filesystemMock   = $this->createMock(FileSystemInterface::class);
    $this->messengerMock    = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());

    foreach ($this->tempDirs as $dir) {
      foreach (glob($dir . '*') ?: [] as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
      if (is_dir($dir)) {
        rmdir($dir);
      }
    }
    $this->tempDirs = [];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(): DocumentConfigurationForm {
    $form = new DocumentConfigurationForm(
      $this->documentsObjMock,
      $this->pathsObjMock,
      $this->filesystemMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Creates a real, empty directory under DRUPAL_ROOT and returns the
   * relative path (as NlpPaths::getPath('DEFAULT_DOCS') would).
   */
  private function makeSourceDir(): string {
    $absolute = DRUPAL_ROOT . '/sites/default/files/nlp_test_docs_' . uniqid() . '/';
    mkdir($absolute, 0777, TRUE);
    $this->tempDirs[] = $absolute;
    return substr($absolute, strlen(DRUPAL_ROOT) + 1);
  }

  private function stubPaths(string $defaultDocsRelative, string $docsDest = 'public://nlp_files/docs/'): void {
    $this->pathsObjMock->method('getPath')->willReturnMap([
      ['DEFAULT_DOCS', $defaultDocsRelative],
      ['DOCS', $docsDest],
    ]);
  }

  /**
   * TranslatableMarkup doesn't stringify through the mocked translation
   * service, so match on the untranslated source string instead.
   */
  private function messageContaining(string $needle): \PHPUnit\Framework\Constraint\Callback {
    return $this->callback(fn($markup) => str_contains($markup->getUntranslatedString(), $needle));
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('document_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsInstructionsMarkup(): void {
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('Save Configuration', $form['counties_names']['#markup']);
  }

  public function testBuildFormContainsBackLink(): void {
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormWarnsWhenNoDefaultDocumentsMatch(): void {
    $relative = $this->makeSourceDir();
    $this->stubPaths($relative);

    $this->messengerMock->expects($this->once())
      ->method('addWarning')
      ->with($this->messageContaining('Default documents are missing'));
    $this->messengerMock->expects($this->once())
      ->method('addStatus')
      ->with($this->messageContaining('configuration options have been saved'));

    $this->filesystemMock->expects($this->never())->method('prepareDirectory');
    $this->filesystemMock->expects($this->never())->method('copy');
    $this->documentsObjMock->expects($this->never())->method('createDocument');

    $form = [];
    $this->buildForm()->submitForm($form, new FormState());
  }

  public function testSubmitFormCopiesMatchingDefaultDocumentAndSavesRecord(): void {
    $relative = $this->makeSourceDir();
    $absolute = DRUPAL_ROOT . '/' . $relative;
    file_put_contents($absolute . 'InstructionsCanvass_test.docx', 'doc-contents');
    file_put_contents($absolute . 'InstructionsCanvass_test.pdf', 'pdf-contents');

    $destUri = 'public://nlp_files/docs/';
    $this->stubPaths($relative, $destUri);

    $this->filesystemMock->expects($this->once())
      ->method('prepareDirectory')
      ->with($destUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $copiedPaths = [];
    $this->filesystemMock->expects($this->exactly(2))
      ->method('copy')
      ->willReturnCallback(function ($source, $destination) use (&$copiedPaths, $destUri) {
        $copiedPaths[] = [$source, $destination];
        $this->assertStringStartsWith($destUri, $destination);
        return $destination;
      });

    $this->documentsObjMock->expects($this->once())
      ->method('createDocument')
      ->with($this->callback(function (array $doc): bool {
        return $doc['name'] === 'nlInstruct'
          && $doc['weight'] === 10
          && $doc['description'] === 'Neighborhood Leader Instructions - canvass'
          && $doc['docFileName'] === 'InstructionsCanvass_test.docx'
          && $doc['pdfFileName'] === 'InstructionsCanvass_test.pdf'
          && $doc['createDate'] === date('Y-m-d');
      }));

    $this->messengerMock->expects($this->never())->method('addWarning');
    $this->messengerMock->expects($this->once())
      ->method('addStatus')
      ->with($this->messageContaining('configuration options have been saved'));

    $form = [];
    $this->buildForm()->submitForm($form, new FormState());

    $this->assertCount(2, $copiedPaths);
  }

  public function testSubmitFormSkipsNonMatchingDocumentsInSourceDirectory(): void {
    $relative = $this->makeSourceDir();
    $absolute = DRUPAL_ROOT . '/' . $relative;
    file_put_contents($absolute . 'RandomUnrelatedFile.docx', 'doc-contents');
    file_put_contents($absolute . 'notes.txt', 'not a docx');

    $this->stubPaths($relative);

    $this->messengerMock->expects($this->once())
      ->method('addWarning')
      ->with($this->messageContaining('Default documents are missing'));
    $this->filesystemMock->expects($this->never())->method('copy');
    $this->documentsObjMock->expects($this->never())->method('createDocument');

    $form = [];
    $this->buildForm()->submitForm($form, new FormState());
  }

}
