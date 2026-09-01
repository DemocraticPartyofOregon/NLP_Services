<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\Form\UploadInstructionsForm;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpPaths;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\UploadInstructionsForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\UploadInstructionsForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\UploadInstructionsForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\UploadInstructionsForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\UploadInstructionsForm::class, 'submitForm')]
#[Group('nlpservices')]
class UploadInstructionsFormTest extends UnitTestCase {

  private NlpInstructions $instructionsMock;
  private NlpPaths $pathsMock;
  private FileSystemInterface $fileSystemMock;
  private MessengerInterface $messengerMock;
  private PrivateTempStoreFactory $tempStoreFactoryMock;
  private PrivateTempStore $tempStoreMock;

  protected function setUp(): void {
    parent::setUp();

    $this->instructionsMock   = $this->createMock(NlpInstructions::class);
    $this->pathsMock          = $this->createMock(NlpPaths::class);
    $this->fileSystemMock     = $this->createMock(FileSystemInterface::class);
    $this->messengerMock      = $this->createMock(MessengerInterface::class);

    $this->tempStoreMock = $this->createMock(PrivateTempStore::class);
    $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStoreFactoryMock->method('get')
      ->with('nlpservices.session_data')
      ->willReturn($this->tempStoreMock);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('tempstore.private', $this->tempStoreFactoryMock);
    Drupal::setContainer($container);

    $_FILES = [];
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
    $_FILES = [];
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): UploadInstructionsForm {
    $form = new UploadInstructionsForm(
      $this->instructionsMock,
      $this->pathsMock,
      $this->fileSystemMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function stubCounty(string $county): void {
    $this->tempStoreMock->method('get')
      ->with('County')
      ->willReturn($county);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame(
      'nlpservices_upload_instructions_form',
      $this->buildFormInstance()->getFormId()
    );
  }

  // -------------------------------------------------------------------------
  // buildForm — no county
  // -------------------------------------------------------------------------

  public function testBuildFormNoCountyAddsWarning(): void {
    $this->stubCounty('');
    $this->messengerMock->expects($this->once())->method('addWarning');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormNoCountyReturnsEmptyArray(): void {
    $this->stubCounty('');

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame([], $form);
  }

  // -------------------------------------------------------------------------
  // buildForm — county present
  // -------------------------------------------------------------------------

  public function testBuildFormStoresCountyInFormState(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county'));
  }

  public function testBuildFormCallsGetInstructionsWithCounty(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->expects($this->once())
      ->method('getInstructions')
      ->with('TestCounty')
      ->willReturn([]);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormStoresCurrentInstructionsInFormState(): void {
    $current = ['canvass' => ['originalFileName' => 'canvass.pdf'], 'postcard' => []];
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn($current);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame($current, $formState->get('current'));
  }

  public function testBuildFormContainsInstructionFieldset(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('instruction_form', $form);
    $this->assertSame('fieldset', $form['instruction_form']['#type']);
  }

  public function testBuildFormContainsCanvassFileInput(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('canvass', $form['instruction_form']);
    $this->assertSame('file', $form['instruction_form']['canvass']['#type']);
  }

  public function testBuildFormContainsCardFileInput(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('card', $form['instruction_form']);
    $this->assertSame('file', $form['instruction_form']['card']['#type']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('submit', $form);
    $this->assertSame('submit', $form['submit']['#type']);
  }

  public function testBuildFormAttachesLibraries(): void {
    $this->stubCounty('TestCounty');
    $this->instructionsMock->method('getInstructions')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertContains('nlpservices/form-helper', $form['#attached']['library']);
    $this->assertContains('nlpservices/info-boxes', $form['#attached']['library']);
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFormCanvassNonPdfSetsError(): void {
    $_FILES['files']['name']['canvass'] = 'instructions.docx';
    $_FILES['files']['name']['card']    = '';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('canvass', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormCanvassPdfExtensionSetsNoError(): void {
    $_FILES['files']['name']['canvass'] = 'instructions.pdf';
    $_FILES['files']['name']['card']    = '';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormCanvassTooLongSetsError(): void {
    $_FILES['files']['name']['canvass'] = str_repeat('a', 161) . '.pdf';
    $_FILES['files']['name']['card']    = '';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('canvass', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEmptyCanvassSkipsCanvassValidation(): void {
    $_FILES['files']['name']['canvass'] = '';
    $_FILES['files']['name']['card']    = '';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormCardNonPdfSetsError(): void {
    $_FILES['files']['name']['canvass'] = '';
    $_FILES['files']['name']['card']    = 'postcard.docx';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('card', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormCardPdfExtensionSetsNoError(): void {
    $_FILES['files']['name']['canvass'] = '';
    $_FILES['files']['name']['card']    = 'postcard.pdf';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormCardTooLongSetsError(): void {
    $_FILES['files']['name']['canvass'] = '';
    $_FILES['files']['name']['card']    = str_repeat('b', 161) . '.pdf';

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('card', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormCanvassFileMoveUploadedFileIsCalled(): void {
    $_FILES['files']['name']['canvass']     = 'canvass.pdf';
    $_FILES['files']['tmp_name']['canvass'] = '/tmp/phpABCDEF';
    $_FILES['files']['name']['card']        = '';

    $this->pathsMock->method('getPath')
      ->with('INST', 'TestCounty')
      ->willReturn('/var/files/inst/');

    $this->fileSystemMock->expects($this->once())
      ->method('moveUploadedFile')
      ->with('/tmp/phpABCDEF', $this->stringContains('/var/files/inst/'));

    $this->instructionsMock->method('createInstructions');

    $formState = new FormState();
    $formState->set('county', 'TestCounty');
    $formState->set('current', ['canvass' => [], 'postcard' => []]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormCanvassFileCallsCreateInstructions(): void {
    $_FILES['files']['name']['canvass']     = 'canvass.pdf';
    $_FILES['files']['tmp_name']['canvass'] = '/tmp/phpABCDEF';
    $_FILES['files']['name']['card']        = '';

    $this->pathsMock->method('getPath')->willReturn('/var/files/inst/');
    $this->fileSystemMock->method('moveUploadedFile');

    $this->instructionsMock->expects($this->once())
      ->method('createInstructions')
      ->with($this->callback(function (array $req): bool {
        return $req['county'] === 'TestCounty'
          && $req['type'] === 'canvass'
          && $req['originalFileName'] === 'canvass.pdf'
          && str_ends_with($req['fileName'], '.pdf');
      }));

    $formState = new FormState();
    $formState->set('county', 'TestCounty');
    $formState->set('current', ['canvass' => [], 'postcard' => []]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormCardFileCallsCreateInstructions(): void {
    $_FILES['files']['name']['canvass']  = '';
    $_FILES['files']['name']['card']     = 'postcard.pdf';
    $_FILES['files']['tmp_name']['card'] = '/tmp/phpXYZ';

    $this->pathsMock->method('getPath')->willReturn('/var/files/inst/');
    $this->fileSystemMock->method('moveUploadedFile');

    $this->instructionsMock->expects($this->once())
      ->method('createInstructions')
      ->with($this->callback(function (array $req): bool {
        return $req['county'] === 'TestCounty'
          && $req['type'] === 'postcard'
          && $req['originalFileName'] === 'postcard.pdf';
      }));

    $formState = new FormState();
    $formState->set('county', 'TestCounty');
    $formState->set('current', ['canvass' => [], 'postcard' => []]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormNoFilesSkipsBothUploads(): void {
    $_FILES['files']['name']['canvass'] = '';
    $_FILES['files']['name']['card']    = '';

    $this->fileSystemMock->expects($this->never())->method('moveUploadedFile');
    $this->instructionsMock->expects($this->never())->method('createInstructions');

    $formState = new FormState();
    $formState->set('county', 'TestCounty');
    $formState->set('current', ['canvass' => [], 'postcard' => []]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormGeneratesTimestampedFilename(): void {
    $_FILES['files']['name']['canvass']     = 'canvass.pdf';
    $_FILES['files']['tmp_name']['canvass'] = '/tmp/phpABCDEF';
    $_FILES['files']['name']['card']        = '';

    $this->pathsMock->method('getPath')->willReturn('/path/');
    $this->fileSystemMock->method('moveUploadedFile');

    $this->instructionsMock->expects($this->once())
      ->method('createInstructions')
      ->with($this->callback(function (array $req): bool {
        return (bool) preg_match('/^canvass_instructions_\d+\.pdf$/', $req['fileName']);
      }));

    $formState = new FormState();
    $formState->set('county', 'TestCounty');
    $formState->set('current', ['canvass' => [], 'postcard' => []]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

}
