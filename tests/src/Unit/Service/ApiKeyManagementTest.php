<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpEncryption;
  use Drupal\Tests\UnitTestCase;
  use Exception;

  class ApiKeyManagementTest extends UnitTestCase {

    private NlpEncryption $encryptionMock;
    private object $keyStorageMock;
    private object $entityTypeManagerMock;
    private ContainerBuilder $container;

    protected function setUp(): void {
      parent::setUp();
      $this->encryptionMock = $this->createMock(NlpEncryption::class);

      $this->keyStorageMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['load', 'create'])
        ->getMock();

      $this->entityTypeManagerMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['getStorage'])
        ->getMock();
      $this->entityTypeManagerMock->method('getStorage')->willReturn($this->keyStorageMock);

      $this->container = new ContainerBuilder();
      $this->container->set('entity_type.manager', $this->entityTypeManagerMock);
      \Drupal::setContainer($this->container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiKeyManagement {
      return new ApiKeyManagement($this->encryptionMock);
    }

    private function makeKeyEntity(string $keyValue = ''): object {
      $mock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['getKeyValue', 'getKeyProvider', 'delete', 'save'])
        ->getMock();
      $mock->method('getKeyValue')->willReturn($keyValue);
      return $mock;
    }

    private function makeKeyProvider(string $filePath): object {
      $mock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['getConfiguration'])
        ->getMock();
      $mock->method('getConfiguration')->willReturn(['file_location' => $filePath]);
      return $mock;
    }

    private function addFileSystemToContainer(): FileSystemInterface {
      $fileSystemMock = $this->createMock(FileSystemInterface::class);
      $fileSystemMock->method('realpath')->willReturn(sys_get_temp_dir());
      $this->container->set('file_system', $fileSystemMock);
      return $fileSystemMock;
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

      $this->assertSame([], $this->buildService()->getKeyValues('test_key'));
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
      $fileSystemMock = $this->createMock(FileSystemInterface::class);
      $fileSystemMock->method('realpath')->willThrowException(new Exception('FS error'));
      $this->container->set('file_system', $fileSystemMock);

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

    public function testCreateKeyWithValuesReturnsFalseOnException(): void {
      $fileSystemMock = $this->createMock(FileSystemInterface::class);
      $fileSystemMock->method('realpath')->willThrowException(new Exception('FS error'));
      $this->container->set('file_system', $fileSystemMock);

      $this->assertFalse($this->buildService()->createKeyWithValues('test_id', 'Test Label', ['API Key' => 'key']));
    }

  }

}
