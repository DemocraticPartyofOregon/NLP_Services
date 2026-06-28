<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class NlpPathsTest extends UnitTestCase {

    private FileSystemInterface $fileSystemMock;
    private ModuleExtensionList $moduleListMock;

    protected function setUp(): void {
      parent::setUp();
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->moduleListMock = $this->createMock(ModuleExtensionList::class);
      $this->moduleListMock->method('getPath')->willReturn('modules/custom/nlpservices');

      $requestStack = new RequestStack();
      $requestStack->push(Request::create('https://example.com'));

      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('request_stack', $requestStack);
      $container->set('extension.list.module', $this->moduleListMock);
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpPaths {
      return new NlpPaths($this->fileSystemMock);
    }

    public function testCreateFactoryUsesFileSystemService(): void {
      $container = new ContainerBuilder();
      $container->set('file.system', $this->fileSystemMock);

      $service = NlpPaths::create($container);

      $this->assertInstanceOf(NlpPaths::class, $service);
    }

    public function testGetPathReturnsCountyPdfPathWhenNotFull(): void {
      $result = $this->buildService()->getPath('PDF', 'Denver');
      $this->assertSame('public://nlp_files/Denver/turf_pdf/', $result);
    }

    public function testGetPathReturnsFullInstructionsPath(): void {
      $result = $this->buildService()->getPath('INST', 'Denver', true);
      $this->assertSame('https://example.com/sites/default/files/nlp_files/Denver/instructions/', $result);
    }

    public function testGetPathAllCountyReturnsRootDirectory(): void {
      $result = $this->buildService()->getPath('DOCS', 'ALL');
      $this->assertSame('public://nlp_files/', $result);
    }

    public function testGetPathDefaultDocsUsesModulePath(): void {
      $result = $this->buildService()->getPath('DEFAULT_DOCS');
      $this->assertSame('modules/custom/nlpservices/docs/', $result);
    }

    public function testCreateDirBuildsPdfDirectoryPath(): void {
      $this->fileSystemMock->expects($this->once())
        ->method('prepareDirectory')
        ->with(
          'public://nlp_files/Denver/turf_pdf',
          FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );

      $this->buildService()->createDir('PDF', 'Denver');
    }

    public function testCreateDirBuildsTempDirectoryPath(): void {
      $this->fileSystemMock->expects($this->once())
        ->method('prepareDirectory')
        ->with(
          'public://temp',
          FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );

      $this->buildService()->createDir('TEMP', '');
    }

    public function testCreateDirUnknownTypeDoesNotPrepareDirectory(): void {
      $this->fileSystemMock->expects($this->never())->method('prepareDirectory');

      $this->buildService()->createDir('UNKNOWN', 'Denver');
    }

  }

}
