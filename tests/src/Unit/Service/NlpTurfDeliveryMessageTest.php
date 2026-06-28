<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpTurfDeliveryMessage;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpTurfDeliveryMessageTest extends UnitTestCase {

    private string $tempDir;
    private FileSystemInterface $fileSystemMock;
    private NlpPaths $pathsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->tempDir = sys_get_temp_dir() . '/nlp_turf_msg_' . uniqid() . '/';
      mkdir($this->tempDir, 0777, TRUE);
      $this->fileSystemMock = $this->createMock(FileSystemInterface::class);
      $this->pathsMock      = $this->createMock(NlpPaths::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      $this->removeDir($this->tempDir);
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function removeDir(string $dir): void {
      if (!is_dir($dir)) { return; }
      foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . $entry;
        is_dir($path) ? $this->removeDir($path . '/') : unlink($path);
      }
      rmdir(rtrim($dir, '/\\'));
    }

    private function buildService(): NlpTurfDeliveryMessage {
      return new NlpTurfDeliveryMessage($this->fileSystemMock, $this->pathsMock);
    }

    private function setupModuleContainer(string $modulePath): void {
      $moduleListMock = $this->createMock(ModuleExtensionList::class);
      $moduleListMock->method('getPath')->willReturn($modulePath);
      $container = new ContainerBuilder();
      $container->set('extension.list.module', $moduleListMock);
      \Drupal::setContainer($container);
    }

    // -------------------------------------------------------------------------
    // getTurfMsg
    // -------------------------------------------------------------------------

    public function testGetTurfMsgReturnsCountyTemplateWhenFound(): void {
      $content = '<p>County email</p>';
      file_put_contents($this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE, $content);
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);

      $result = $this->buildService()->getTurfMsg('Denver');

      $this->assertSame($content, $result);
    }

    public function testGetTurfMsgFallsBackToStateTemplateWhenCountyTemplateMissing(): void {
      $countyDir   = $this->tempDir . 'county/';
      $stateContent = '<p>State email</p>';
      file_put_contents($this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE, $stateContent);

      $this->pathsMock->method('getPath')->willReturnCallback(
        function(string $type, string $county = '') use ($countyDir): string {
          return $county ? $countyDir : $this->tempDir;
        }
      );

      $result = $this->buildService()->getTurfMsg('Denver');

      $this->assertSame($stateContent, $result);
    }

    public function testGetTurfMsgUsesDefaultTemplateWhenBothCountyAndStateAreMissing(): void {
      $moduleDir   = $this->tempDir . 'module';
      $templateDir = $moduleDir . '/src/Templates/';
      mkdir($templateDir, 0777, TRUE);
      $defaultContent = '<p>Default email</p>';
      file_put_contents($templateDir . NlpTurfDeliveryMessage::TURF_DEFAULT_TEMPLATE, $defaultContent);

      $this->pathsMock->method('getPath')->willReturn($this->tempDir . 'missing/');
      $this->setupModuleContainer($moduleDir);

      $result = $this->buildService()->getTurfMsg('Denver');

      $this->assertSame($defaultContent, $result);
    }

    public function testGetTurfMsgReturnsEmptyStringWhenAllTemplatesMissing(): void {
      $this->pathsMock->method('getPath')->willReturn($this->tempDir . 'missing/');
      $this->setupModuleContainer($this->tempDir . 'no_module');

      $result = $this->buildService()->getTurfMsg('Denver');

      $this->assertSame('', $result);
    }

    public function testGetTurfMsgWithEmptyCountyReadsStatePathDirectly(): void {
      $content = '<p>State only</p>';
      file_put_contents($this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE, $content);
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);

      $result = $this->buildService()->getTurfMsg();

      $this->assertSame($content, $result);
    }

    public function testGetTurfMsgWithEmptyCountyDoesNotFallBackToStateWhenFileMissing(): void {
      // When county is empty and the file at that path is absent, the code skips
      // the state-fallback branch and falls through to the default template.
      // Since the default template is also absent here, the result must be ''.
      $this->pathsMock->expects($this->once())
        ->method('getPath')
        ->willReturn($this->tempDir . 'missing/');
      $this->setupModuleContainer($this->tempDir . 'no_module');

      $result = $this->buildService()->getTurfMsg();

      $this->assertSame('', $result);
    }

    // -------------------------------------------------------------------------
    // putTurfMsg
    // -------------------------------------------------------------------------

    public function testPutTurfMsgWritesContentToFile(): void {
      $content = '<p>New template</p>';
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);

      $this->buildService()->putTurfMsg('Denver', $content);

      $filePath = $this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE;
      $this->assertFileExists($filePath);
      $this->assertSame($content, file_get_contents($filePath));
    }

    public function testPutTurfMsgCallsUnlinkWhenTemplateAlreadyExists(): void {
      $existingFile = $this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE;
      file_put_contents($existingFile, '<p>Old content</p>');
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);

      $this->fileSystemMock->expects($this->once())
        ->method('unlink')
        ->with($existingFile);

      $this->buildService()->putTurfMsg('Denver', '<p>New content</p>');
    }

    public function testPutTurfMsgDoesNotCallUnlinkWhenNoExistingTemplate(): void {
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);

      $this->fileSystemMock->expects($this->never())->method('unlink');

      $this->buildService()->putTurfMsg('Denver', '<p>New template</p>');
    }

    public function testPutTurfMsgOverwritesExistingFileWithNewContent(): void {
      $existingFile = $this->tempDir . NlpTurfDeliveryMessage::TURF_MSG_TEMPLATE;
      file_put_contents($existingFile, '<p>Old content</p>');
      $this->pathsMock->method('getPath')->willReturn($this->tempDir);
      $this->fileSystemMock->method('unlink')->willReturn(TRUE);

      $this->buildService()->putTurfMsg('Denver', '<p>New content</p>');

      $this->assertSame('<p>New content</p>', file_get_contents($existingFile));
    }

  }

}
