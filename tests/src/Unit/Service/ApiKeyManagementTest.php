<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Entity\EntityStorageInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Site\Settings;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpEncryption;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use NlpTestKeyEntityInterface;
  use NlpTestKeyProviderInterface;
  use Psr\Log\LoggerInterface;

  class ApiKeyManagementTest extends UnitTestCase {

    private NlpEncryption $encryptionMock;
    private object $keyStorageMock;
    private object $entityTypeManagerMock;
    private object $fileSystemMock;
    private object $loggerMock;
    private ContainerBuilder $container;

    protected function setUp(): void {
      parent::setUp();
      $this->encryptionMock = $this->createMock(NlpEncryption::class);

      $this->keyStorageMock = $this->createMock(EntityStorageInterface::class);

      $this->entityTypeManagerMock = $this->createMock(EntityTypeManagerInterface::class);
      $this->entityTypeManagerMock->method('getStorage')->willReturn($this->keyStorageMock);

      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);

      $this->loggerMock  = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($this->loggerMock);

      $this->container = new ContainerBuilder();
      $this->container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($this->container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      new Settings([]);
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiKeyManagement {
      return new ApiKeyManagement($this->encryptionMock, $this->entityTypeManagerMock, $this->fileSystemMock);
    }

    private function buildServiceWithTranslation(): ApiKeyManagement {
      $service = $this->buildService();
      $service->setStringTranslation($this->getStringTranslationStub());
      return $service;
    }

    private function makeKeyEntity(string $keyValue = ''): object {
      $mock = $this->createMock(NlpTestKeyEntityInterface::class);
      $mock->method('getKeyValue')->willReturn($keyValue);
      return $mock;
    }

    private function makeKeyProvider(string $filePath): object {
      $mock = $this->createMock(NlpTestKeyProviderInterface::class);
      $mock->method('getConfiguration')->willReturn(['file_location' => $filePath]);
      return $mock;
    }

    private function addFileSystemToContainer(): FileSystemInterface {
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->fileSystemMock->method('realpath')->willReturn(sys_get_temp_dir());
      return $this->fileSystemMock;
    }

    // -------------------------------------------------------------------------
    // saveApiKey
    // -------------------------------------------------------------------------

    public function testSaveApiKeyReturnsFalseWhenKeyNotFound(): void {
      $this->keyStorageMock->method('load')->willReturn(NULL);

      $this->assertFalse($this->buildService()->saveApiKey('missing_key', 'secret'));
    }

    public function testSaveApiKeyReturnsTrueOnSuccess(): void {
      $filePath = sys_get_temp_dir() . '/nlp_test_saveapikey.key';
      $keyEntity = $this->makeKeyEntity();
      $keyEntity->method('getKeyProvider')->willReturn($this->makeKeyProvider($filePath));
      $this->keyStorageMock->method('load')->willReturn($keyEntity);

      $result = $this->buildService()->saveApiKey('test_key', 'new-secret');

      $this->assertTrue($result);
      @unlink($filePath);
    }

    public function testSaveApiKeyReturnsFalseOnException(): void {
      $this->keyStorageMock->method('load')->willThrowException(new Exception('DB error'));

      $this->assertFalse($this->buildService()->saveApiKey('test_key', 'secret'));
    }

    // -------------------------------------------------------------------------
    // getKey
    // -------------------------------------------------------------------------

    public function testGetKeyReturnsEmptyStringWhenKeyNotFound(): void {
      $this->keyStorageMock->method('load')->willReturn(NULL);

      $this->assertSame('', $this->buildService()->getKey('missing_key'));
    }

    public function testGetKeyReturnsKeyValue(): void {
      $this->keyStorageMock->method('load')->willReturn($this->makeKeyEntity('my-secret-value'));

      $this->assertSame('my-secret-value', $this->buildService()->getKey('test_key'));
    }

    public function testGetKeyReturnsEmptyStringWhenKeyFileUnreadable(): void {
      $key = $this->createMock(NlpTestKeyEntityInterface::class);
      $key->method('getKeyValue')->willReturn(NULL);
      $this->keyStorageMock->method('load')->willReturn($key);

      $this->assertSame('', $this->buildService()->getKey('secret_key'));
    }

    public function testGetKeyReturnsEmptyStringOnException(): void {
      $this->keyStorageMock->method('load')->willThrowException(new Exception('DB error'));

      $this->assertSame('', $this->buildService()->getKey('test_key'));
    }

    // -------------------------------------------------------------------------
    // deleteKey
    // -------------------------------------------------------------------------

    public function testDeleteKeyReturnsFalseWhenKeyNotFound(): void {
      $this->keyStorageMock->method('load')->willReturn(NULL);

      $this->assertFalse($this->buildService()->deleteKey('missing_key'));
    }

    public function testDeleteKeyCallsDeleteAndReturnsTrueOnSuccess(): void {
      $keyEntity = $this->makeKeyEntity();
      $keyEntity->expects($this->once())->method('delete');
      $this->keyStorageMock->method('load')->willReturn($keyEntity);

      $this->assertTrue($this->buildService()->deleteKey('test_key'));
    }

    public function testDeleteKeyReturnsFalseOnException(): void {
      $this->keyStorageMock->method('load')->willThrowException(new Exception('DB error'));

      $this->assertFalse($this->buildService()->deleteKey('test_key'));
    }

    // -------------------------------------------------------------------------
    // getKeyValues
    // -------------------------------------------------------------------------

    public function testGetKeyValuesUsesStateCommitteeWhenIdIsEmpty(): void {
      $this->keyStorageMock->method('load')
        ->with('State Committee')
        ->willReturn(NULL);

      $this->assertSame([], $this->buildService()->getKeyValues(''));
    }

    public function testGetKeyValuesReturnsEmptyWhenKeyNotFound(): void {
      $this->keyStorageMock->method('load')->willReturn(NULL);
      $this->loggerMock->expects($this->once())->method('error');

      $this->assertSame([], $this->buildService()->getKeyValues('test_key'));
    }

    public function testGetKeyValuesSuppressesLogWhenLogIfMissingIsFalse(): void {
      $this->keyStorageMock->method('load')->willReturn(NULL);
      $this->loggerMock->expects($this->never())->method('error');

      $this->assertSame([], $this->buildService()->getKeyValues('State Committee', FALSE));
    }

    public function testGetKeyValuesReturnsEmptyWhenKeyValueIsEmpty(): void {
      $this->keyStorageMock->method('load')->willReturn($this->makeKeyEntity(''));

      $this->assertSame([], $this->buildService()->getKeyValues('test_key'));
    }

    public function testGetKeyValuesReturnsEmptyWhenKeyValueIsInvalidJson(): void {
      $this->keyStorageMock->method('load')->willReturn($this->makeKeyEntity('{bad json'));

      $this->assertSame([], $this->buildService()->getKeyValues('test_key'));
    }

    public function testGetKeyValuesReturnsDecryptedCredentials(): void {
      $stored = json_encode([
        'API Key'  => 'encrypted-key',
        'Url'      => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
      $this->keyStorageMock->method('load')->willReturn($this->makeKeyEntity($stored));
      $this->encryptionMock->method('encrypt_decrypt')
        ->with('decrypt', 'encrypted-key')
        ->willReturn('plaintext-key');

      $result = $this->buildService()->getKeyValues('test_key');

      $this->assertSame('plaintext-key', $result['API Key']);
      $this->assertSame('api.example.com/v4', $result['Url']);
      $this->assertSame('testapp', $result['App Name']);
    }

    public function testGetKeyValuesReturnsEmptyOnException(): void {
      $this->keyStorageMock->method('load')->willThrowException(new Exception('DB error'));

      $this->assertSame([], $this->buildService()->getKeyValues('test_key'));
    }

    // -------------------------------------------------------------------------
    // createKey
    // -------------------------------------------------------------------------

    public function testCreateKeyReturnsTrueOnSuccess(): void {
      $this->addFileSystemToContainer();
      $keyEntity = $this->makeKeyEntity();
      $keyEntity->expects($this->once())->method('save');
      $this->keyStorageMock->method('create')->willReturn($keyEntity);

      $this->assertTrue($this->buildService()->createKey('test_id', 'Test Label', 'key-value'));
    }

    public function testCreateKeyReturnsFalseOnException(): void {
      $this->fileSystemMock->method('realpath')->willThrowException(new Exception('FS error'));

      $this->assertFalse($this->buildService()->createKey('test_id', 'Test Label', 'key-value'));
    }

    // -------------------------------------------------------------------------
    // createKeyWithValues
    // -------------------------------------------------------------------------

    public function testCreateKeyWithValuesEncryptsApiKeyBeforeSaving(): void {
      $this->addFileSystemToContainer();
      $this->encryptionMock->expects($this->once())
        ->method('encrypt_decrypt')
        ->with('encrypt', 'plaintext-key')
        ->willReturn('encrypted-key');
      $keyEntity = $this->makeKeyEntity();
      $keyEntity->method('save');
      $this->keyStorageMock->method('create')->willReturn($keyEntity);

      $values = ['API Key' => 'plaintext-key', 'Url' => 'api.example.com/v4', 'App Name' => 'testapp'];
      $this->assertTrue($this->buildService()->createKeyWithValues('test_id', 'Test Label', $values));
    }

    public function testCreateKeyWithValuesSkipsEncryptionWhenApiKeyAbsent(): void {
      $this->addFileSystemToContainer();
      $this->encryptionMock->expects($this->never())->method('encrypt_decrypt');
      $keyEntity = $this->makeKeyEntity();
      $keyEntity->method('save');
      $this->keyStorageMock->method('create')->willReturn($keyEntity);

      $result = $this->buildService()->createKeyWithValues('test_id', 'Test Label', ['Url' => 'api.example.com/v4']);

      $this->assertTrue($result);
    }

    /**
     * A key file that can't be written must not leave behind a key entity,
     * which would read back empty and look like a saved key.
     */
    public function testCreateKeyWithValuesReturnsFalseWhenKeyFileCannotBeWritten(): void {
      // A regular file standing in for the private path: keys/ can't be created under it.
      $notADir = tempnam(sys_get_temp_dir(), 'nlp_notadir');
      $this->fileSystemMock->method('realpath')->willReturn($notADir);
      $this->keyStorageMock->expects($this->never())->method('create');
      $this->loggerMock->expects($this->once())->method('error');

      $result = $this->buildService()->createKeyWithValues('test_id', 'Test Label', ['Url' => 'x']);

      $this->assertFalse($result);
      @unlink($notADir);
    }

    public function testCreateKeyReturnsFalseWhenPrivatePathMissing(): void {
      $this->fileSystemMock->method('realpath')->willReturn(FALSE);
      $this->keyStorageMock->expects($this->never())->method('create');
      $this->loggerMock->expects($this->once())->method('error');

      $this->assertFalse($this->buildService()->createKey('test_id', 'Test Label', 'key-value'));
    }

    // -------------------------------------------------------------------------
    // ensurePrivateStorage
    // -------------------------------------------------------------------------

    private function makeTempDirPath(): string {
      return sys_get_temp_dir() . '/nlp_private_' . uniqid();
    }

    private function removeTempDir(string $dir): void {
      @rmdir($dir . '/keys');
      @rmdir($dir);
    }

    /**
     * Has the file system mock create directories for real, like Drupal's.
     */
    private function prepareDirectoryCreates(): void {
      $this->fileSystemMock->method('prepareDirectory')
        ->willReturnCallback(fn(string $dir) => is_dir($dir) || mkdir($dir, 0775, TRUE));
    }

    public function testEnsurePrivateStorageReportsMissingSetting(): void {
      new Settings([]);
      $this->fileSystemMock->expects($this->never())->method('prepareDirectory');

      $problem = $this->buildServiceWithTranslation()->ensurePrivateStorage();

      $this->assertNotNull($problem);
      $this->assertStringContainsString('file_private_path', (string) $problem);
    }

    public function testEnsurePrivateStorageCreatesMissingDirectories(): void {
      $dir = $this->makeTempDirPath();
      new Settings(['file_private_path' => $dir]);
      $this->prepareDirectoryCreates();
      $this->loggerMock->expects($this->exactly(2))->method('notice');

      $problem = $this->buildServiceWithTranslation()->ensurePrivateStorage();

      $this->assertNull($problem);
      $this->assertDirectoryExists($dir . '/keys');
      $this->removeTempDir($dir);
    }

    public function testEnsurePrivateStorageCreatesOnlyMissingKeysDirectory(): void {
      $dir = $this->makeTempDirPath();
      mkdir($dir);
      new Settings(['file_private_path' => $dir]);
      $this->fileSystemMock->expects($this->once())->method('prepareDirectory')
        ->with($dir . '/keys')
        ->willReturnCallback(fn(string $path) => mkdir($path));

      $this->assertNull($this->buildServiceWithTranslation()->ensurePrivateStorage());
      $this->removeTempDir($dir);
    }

    public function testEnsurePrivateStorageReportsDirectoryThatCannotBeCreated(): void {
      $dir = $this->makeTempDirPath();
      new Settings(['file_private_path' => $dir]);
      $this->fileSystemMock->method('prepareDirectory')->willReturn(FALSE);

      $problem = $this->buildServiceWithTranslation()->ensurePrivateStorage();

      $this->assertNotNull($problem);
      $this->assertStringContainsString($dir, (string) $problem);
    }

    public function testEnsurePrivateStorageLeavesExistingDirectoriesAlone(): void {
      $dir = $this->makeTempDirPath();
      mkdir($dir . '/keys', 0775, TRUE);
      new Settings(['file_private_path' => $dir]);
      $this->fileSystemMock->expects($this->never())->method('prepareDirectory');

      $this->assertNull($this->buildServiceWithTranslation()->ensurePrivateStorage());
      $this->removeTempDir($dir);
    }

    public function testCreateKeyWithValuesReturnsFalseOnException(): void {
      $this->fileSystemMock->method('realpath')->willThrowException(new Exception('FS error'));

      $this->assertFalse($this->buildService()->createKeyWithValues('test_id', 'Test Label', ['API Key' => 'key']));
    }

  }

}
