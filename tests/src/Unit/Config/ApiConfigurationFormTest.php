<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Config;

use Drupal;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\nlpservices\ApiKeyManagement;
use Drupal\nlpservices\Config\ApiConfigurationForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpFolderSetup;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(ApiConfigurationForm::class)]
#[Group('nlpservices')]
class ApiConfigurationFormTest extends UnitTestCase {

  private NlpConfiguration $nlpConfigMock;
  private ApiKeyManagement $apiKeyMock;
  private NlpFolderSetup $folderSetupMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->nlpConfigMock   = $this->createMock(NlpConfiguration::class);
    $this->apiKeyMock      = $this->createMock(ApiKeyManagement::class);
    $this->folderSetupMock = $this->createMock(NlpFolderSetup::class);
    $this->messengerMock   = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(?UploadedFile $uploadedFile = NULL, bool $noFile = FALSE): ApiConfigurationForm {
    $form = new ApiConfigurationForm(
      $this->nlpConfigMock,
      $this->apiKeyMock,
      $this->folderSetupMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);

    $filesValue = $noFile ? NULL : ($uploadedFile !== NULL ? ['api_keys' => $uploadedFile] : []);
    $filesBag = $this->createMock(FileBag::class);
    $filesBag->method('get')->with('files')->willReturn($filesValue);

    $request = $this->createMock(Request::class);
    $request->files = $filesBag;

    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);
    $form->setRequestStack($requestStack);

    return $form;
  }

  private function makeUploadedFile(bool $valid, string $extension): UploadedFile&MockObject {
    $mock = $this->createMock(UploadedFile::class);
    $mock->method('isValid')->willReturn($valid);
    $mock->method('getClientOriginalExtension')->willReturn($extension);
    return $mock;
  }

  private function addConfigFactoryToContainer(): Config&MockObject {
    $configMock = $this->createMock(Config::class);
    $configMock->method('set')->willReturnSelf();
    $configMock->method('save')->willReturnSelf();

    $configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
    $configFactoryMock->method('getEditable')->willReturn($configMock);

    $container = Drupal::getContainer();
    $container->set('config.factory', $configFactoryMock);

    return $configMock;
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('api_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsEncryptionKeysFieldset(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('encryption_keys', $form);
    $this->assertSame('fieldset', $form['encryption_keys']['#type']);
  }

  public function testBuildFormContainsSecretKeyAndIvFields(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('secret_key', $form['encryption_keys']);
    $this->assertSame('password', $form['encryption_keys']['secret_key']['#type']);
    $this->assertArrayHasKey('secret_iv', $form['encryption_keys']);
    $this->assertSame('password', $form['encryption_keys']['secret_iv']['#type']);
  }

  public function testBuildFormContainsApiKeysFileField(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertArrayHasKey('api_keys', $form);
    $this->assertSame('file', $form['api_keys']['#type']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $form = $this->buildForm()->buildForm([], new FormState());
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  public function testBuildFormShowsRequiredNoteWhenNoKeyExists(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $form = $this->buildForm()->buildForm([], new FormState());
    $markup = $form['encryption_keys']['secret_key_note']['#markup'];
    $this->assertStringContainsString('required', $markup);
  }

  public function testBuildFormShowsReplaceNoteWhenKeyExists(): void {
    $this->apiKeyMock->method('getKey')->willReturn('existing-key');
    $form = $this->buildForm()->buildForm([], new FormState());
    $markup = $form['encryption_keys']['secret_key_note']['#markup'];
    $this->assertStringContainsString('keep the current values', $markup);
  }

  public function testBuildFormShowsStorageProblemAndDisablesSave(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('ensurePrivateStorage')
      ->willReturn(new TranslatableMarkup('The private file path is not set.'));

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertStringContainsString('The private file path is not set.', $form['storage_problem']['#markup']);
    $this->assertTrue($form['actions']['submit']['#disabled']);
  }

  public function testBuildFormEnablesSaveWhenStorageIsReady(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('ensurePrivateStorage')->willReturn(NULL);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertArrayNotHasKey('storage_problem', $form);
    $this->assertFalse($form['actions']['submit']['#disabled']);
  }

  public function testValidateFormStopsWhenStorageHasAProblem(): void {
    $this->apiKeyMock->method('ensurePrivateStorage')
      ->willReturn(new TranslatableMarkup('The private file path is not set.'));

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('ValidKey1!ValidKey1!');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('api_keys', $this->anything());

    $form = [];
    $this->buildForm()->validateForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // validateForm — key/IV pairing
  // ---------------------------------------------------------------------------

  public function testValidateFormKeyWithoutIvSetsError(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'ValidKey1!'],
      ['secret_iv',  NULL, ''],
    ]);
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with($this->logicalOr('secret_key', 'secret_iv'), $this->anything());

    $form = [];
    $this->buildForm()->validateForm($form, $formState);
  }

  public function testValidateFormIvWithoutKeySetsError(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, ''],
      ['secret_iv',  NULL, 'ValidIV1!'],
    ]);
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with($this->logicalOr('secret_key', 'secret_iv'), $this->anything());

    $form = [];
    $this->buildForm()->validateForm($form, $formState);
  }

  public function testValidateFormBothEmptyWithNoSavedKeySetsError(): void {
    $this->apiKeyMock->method('getKey')->willReturn('');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with($this->logicalOr('secret_key', 'secret_iv'), $this->anything());

    $form = [];
    $this->buildForm()->validateForm($form, $formState);
  }

  public function testValidateFormBothEmptyWithSavedKeyProceedsToFileCheck(): void {
    $this->apiKeyMock->method('getKey')->willReturn('saved-value');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('api_keys', $this->anything());

    $form = [];
    $this->buildForm(noFile: TRUE)->validateForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // validateForm — key strength
  // ---------------------------------------------------------------------------

  public function testValidateFormKeyTooShortSetsError(): void {
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'Short1!'],
      ['secret_iv',  NULL, 'Short1!'],
    ]);
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('secret_key', $this->anything());

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  public function testValidateFormKeyLacksSecurityRequirementsSetsError(): void {
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    // All lowercase: only one character category → fails hasMinimumSecurityRequirements
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'alllowercaselettersonly'],
      ['secret_iv',  NULL, 'alllowercaselettersonly'],
    ]);
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('secret_key', $this->anything());

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  public function testValidateFormIvTooShortSetsError(): void {
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'ValidLongKey1!ValidLongKey1!'],
      ['secret_iv',  NULL, 'ShortIV1'],
    ]);
    $formState->expects($this->atLeastOnce())
      ->method('setErrorByName')
      ->with('secret_iv', $this->anything());

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // validateForm — file validation
  // ---------------------------------------------------------------------------

  public function testValidateFormMissingFileSetsError(): void {
    $this->apiKeyMock->method('getKey')->willReturn('saved');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('api_keys', $this->anything());

    $form = [];
    $this->buildForm(noFile: TRUE)->validateForm($form, $formState);
  }

  public function testValidateFormInvalidFileSetsError(): void {
    $this->apiKeyMock->method('getKey')->willReturn('saved');
    $uploadedFile = $this->makeUploadedFile(FALSE, 'yml');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('api_keys', $this->anything());

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  public function testValidateFormWrongExtensionSetsError(): void {
    $this->apiKeyMock->method('getKey')->willReturn('saved');
    $uploadedFile = $this->makeUploadedFile(TRUE, 'json');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('api_keys', $this->anything());

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  public function testValidateFormValidYamlFileSetsNoError(): void {
    $this->apiKeyMock->method('getKey')->willReturn('saved');
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yaml');

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['getValue', 'setErrorByName'])
      ->getMock();
    $formState->method('getValue')->willReturn('');
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->buildForm($uploadedFile)->validateForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormCreatesNewKeyWhenNoneExists(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->apiKeyMock->expects($this->atLeastOnce())
      ->method('createKey')
      ->with('secret_key', $this->anything(), 'MyNewSecretKey1!');

    $tmpFile = $this->writeTempYaml(['State Committee' => ['API Key' => 'abc', 'Name' => 'Oregon']]);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'MyNewSecretKey1!'],
      ['secret_iv',  NULL, 'MyNewIV1!abcdefg'],
    ]);

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormDeletesAndReplacesExistingKey(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('old-value');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->apiKeyMock->expects($this->atLeastOnce())
      ->method('deleteKey')
      ->with($this->logicalOr('secret_key', 'secret_iv', 'State Committee'));

    $tmpFile = $this->writeTempYaml(['State Committee' => ['API Key' => 'abc', 'Name' => 'Oregon']]);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'NewKey1!abcdefghijklmnop'],
      ['secret_iv',  NULL, 'NewIV1!abcdefgh'],
    ]);

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  /**
   * A key entity whose file is missing or unreadable reads back empty but
   * still occupies its ID, so it must be deleted before being recreated.
   */
  public function testSubmitFormDeletesKeysThatReadBackEmpty(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn(['Benton']);
    $this->apiKeyMock->method('createKey')->willReturn(TRUE);
    $this->apiKeyMock->method('createKeyWithValues')->willReturn(TRUE);

    $deleted = [];
    $this->apiKeyMock->method('deleteKey')
      ->willReturnCallback(function (string $id) use (&$deleted) {
        $deleted[] = $id;
        return TRUE;
      });

    $tmpFile = $this->writeTempYaml(['State Committee' => ['API Key' => 'abc', 'Name' => 'Oregon']]);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'NewKey1!abcdefghijklmnop'],
      ['secret_iv',  NULL, 'NewIV1!abcdefgh'],
    ]);

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);

    foreach (['secret_key', 'secret_iv', 'State Committee', 'Benton'] as $id) {
      $this->assertContains($id, $deleted);
    }
  }

  public function testSubmitFormSavesCountyNamesFromYaml(): void {
    $configMock = $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $configMock->expects($this->atLeastOnce())
      ->method('set')
      ->with('nlpservices-county-names', ['Clackamas', 'Multnomah'])
      ->willReturnSelf();

    $data = [
      'State Committee' => ['API Key' => 'state-key', 'Name' => 'Oregon'],
      'Multnomah'       => ['API Key' => 'mult-key'],
      'Clackamas'       => ['API Key' => 'clack-key'],
    ];
    $tmpFile = $this->writeTempYaml($data);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormCallsInitializeFolders(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->folderSetupMock->expects($this->once())
      ->method('initializeFolders')
      ->with(['Multnomah']);

    $data = [
      'State Committee' => ['API Key' => 'state-key', 'Name' => 'Oregon'],
      'Multnomah'       => ['API Key' => 'mult-key'],
    ];
    $tmpFile = $this->writeTempYaml($data);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormSetsStateNameFromStateCommittee(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->nlpConfigMock->expects($this->once())
      ->method('setStateName')
      ->with('Oregon');

    $data = ['State Committee' => ['API Key' => 'state-key', 'Name' => 'Oregon']];
    $tmpFile = $this->writeTempYaml($data);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormSkipsEntriesWithoutApiKey(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->apiKeyMock->method('createKeyWithValues')
      ->with($this->logicalNot($this->identicalTo('NoKeyCounty')), $this->anything(), $this->anything());

    $data = [
      'State Committee' => ['API Key' => 'key'],
      'NoKeyCounty'     => ['SomeOtherField' => 'value'],
    ];
    $tmpFile = $this->writeTempYaml($data);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormAddsStatusMessage(): void {
    $this->addConfigFactoryToContainer();
    $this->apiKeyMock->method('getKey')->willReturn('');
    $this->apiKeyMock->method('getKeyValues')->willReturn([]);
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);

    $this->apiKeyMock->method('createKeyWithValues')->willReturn(TRUE);

    $this->messengerMock->expects($this->once())->method('addStatus');
    $this->messengerMock->expects($this->never())->method('addError');

    $tmpFile = $this->writeTempYaml(['State Committee' => ['API Key' => 'key', 'Name' => 'OR']]);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  public function testSubmitFormReportsCommitteeKeysThatFailToSave(): void {
    $this->addConfigFactoryToContainer();
    $this->nlpConfigMock->method('getCountyNames')->willReturn([]);
    $this->apiKeyMock->method('createKeyWithValues')
      ->willReturnCallback(fn(string $id) => $id !== 'Multnomah');

    $this->messengerMock->expects($this->never())->method('addStatus');
    $this->messengerMock->expects($this->once())->method('addError')
      ->with($this->callback(fn($message) => str_contains((string) $message, 'Multnomah')
        && !str_contains((string) $message, 'State Committee')));

    $data = [
      'State Committee' => ['API Key' => 'state-key', 'Name' => 'Oregon'],
      'Multnomah'       => ['API Key' => 'mult-key'],
    ];
    $tmpFile = $this->writeTempYaml($data);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturn('');

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);
  }

  /**
   * The API keys are encrypted with the secret key and IV, so a failed
   * secret save must leave the existing API keys alone.
   */
  public function testSubmitFormStopsWhenSecretKeyFailsToSave(): void {
    $this->addConfigFactoryToContainer();
    $this->nlpConfigMock->method('getCountyNames')->willReturn(['Benton']);
    $this->apiKeyMock->method('createKey')->willReturn(FALSE);
    $this->apiKeyMock->method('createKeyWithValues')->willReturn(TRUE);

    $deleted = [];
    $this->apiKeyMock->method('deleteKey')
      ->willReturnCallback(function (string $id) use (&$deleted) {
        $deleted[] = $id;
        return TRUE;
      });
    $this->folderSetupMock->expects($this->never())->method('initializeFolders');
    $this->messengerMock->expects($this->never())->method('addStatus');
    $this->messengerMock->expects($this->once())->method('addError')
      ->with($this->callback(fn($message) => str_contains((string) $message, 'secret_key')));

    $tmpFile = $this->writeTempYaml(['State Committee' => ['API Key' => 'abc', 'Name' => 'Oregon']]);
    $uploadedFile = $this->makeUploadedFile(TRUE, 'yml');
    $uploadedFile->method('getRealPath')->willReturn($tmpFile);

    $formState = $this->createMock(FormState::class);
    $formState->method('getValue')->willReturnMap([
      ['secret_key', NULL, 'NewKey1!abcdefghijklmnop'],
      ['secret_iv',  NULL, 'NewIV1!abcdefgh'],
    ]);

    $form = [];
    $this->buildForm($uploadedFile)->submitForm($form, $formState);
    unlink($tmpFile);

    $this->assertNotContains('State Committee', $deleted);
    $this->assertNotContains('Benton', $deleted);
  }

  // ---------------------------------------------------------------------------
  // Private helper
  // ---------------------------------------------------------------------------

  private function writeTempYaml(array $data): string {
    $path = sys_get_temp_dir() . '/api_config_test_' . uniqid() . '.yml';
    $lines = [];
    foreach ($data as $key => $values) {
      $lines[] = "$key:";
      foreach ($values as $field => $val) {
        $lines[] = "  $field: '$val'";
      }
    }
    file_put_contents($path, implode("\n", $lines));
    return $path;
  }

}
