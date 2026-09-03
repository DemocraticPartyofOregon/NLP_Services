<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\File\FileExists;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\StringTranslation\TranslationInterface;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpFolderSetup;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\Tests\UnitTestCase;

  class NlpFolderSetupTest extends UnitTestCase {

    private NlpConfiguration $configurationMock;
    private NlpPaths $pathsMock;
    private FileSystemInterface $fileSystemMock;
    private MessengerInterface $messengerMock;
    private ModuleExtensionList $moduleListMock;
    private TranslationInterface $translationMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->pathsMock         = $this->createMock(NlpPaths::class);
      $this->fileSystemMock    = $this->createMock(FileSystemInterface::class);
      $this->messengerMock     = $this->createMock(MessengerInterface::class);
      $this->moduleListMock    = $this->createMock(ModuleExtensionList::class);
      $this->translationMock   = $this->createMock(TranslationInterface::class);

      // The module's own repo files (src/Config/magic_words.yml, img/*) are
      // used as fixtures, so getPath() points at the real module root.
      $this->moduleListMock->method('getPath')->willReturn($this->modulePath());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function modulePath(): string {
      return dirname(__DIR__, 4);
    }

    private function buildFolderSetup(): NlpFolderSetup {
      return new NlpFolderSetup(
        $this->configurationMock,
        $this->pathsMock,
        $this->fileSystemMock,
        $this->messengerMock,
        $this->moduleListMock,
        $this->translationMock,
      );
    }

    // -------------------------------------------------------------------------
    // initializeFolders
    // -------------------------------------------------------------------------

    public function testInitializeFoldersCreatesBaseDirsWithNoCounties(): void {
      $calls = [];
      $this->pathsMock->method('createDir')
        ->willReturnCallback(function (string $type, string $county) use (&$calls): void {
          $calls[] = [$type, $county];
        });

      $this->buildFolderSetup()->initializeFolders([]);

      $this->assertSame([
        ['TEMP', ''],
        ['NLP', ''],
        ['TURF', ''],
      ], $calls);
    }

    public function testInitializeFoldersCreatesCountyAndSubfoldersForEachCounty(): void {
      $calls = [];
      $this->pathsMock->method('createDir')
        ->willReturnCallback(function (string $type, string $county) use (&$calls): void {
          $calls[] = [$type, $county];
        });

      $this->buildFolderSetup()->initializeFolders(['Denver', 'Boulder']);

      $this->assertSame([
        ['TEMP', ''],
        ['NLP', ''],
        ['TURF', ''],
        ['COUNTY', 'Denver'],
        ['INST', 'Denver'],
        ['PDF', 'Denver'],
        ['TURF', 'Denver'],
        ['COUNTY', 'Boulder'],
        ['INST', 'Boulder'],
        ['PDF', 'Boulder'],
        ['TURF', 'Boulder'],
      ], $calls);
    }

    public function testInitializeFoldersSavesMagicWordsToConfig(): void {
      $captured = NULL;
      $this->configurationMock->expects($this->once())
        ->method('set')
        ->with('nlpservices-magic-words', $this->callback(function ($value) use (&$captured) {
          $captured = $value;
          return TRUE;
        }));

      $this->buildFolderSetup()->initializeFolders([]);

      $this->assertIsArray($captured);
      $this->assertContains('abacus', $captured);
    }

    public function testInitializeFoldersAddsStatusMessage(): void {
      $this->messengerMock->expects($this->once())->method('addStatus');
      $this->messengerMock->expects($this->never())->method('addWarning');

      $this->buildFolderSetup()->initializeFolders([]);
    }

    public function testInitializeFoldersCopiesLogoAndFaviconToPublicDestination(): void {
      $copiedTo = [];
      $this->fileSystemMock->method('copy')
        ->willReturnCallback(function (string $source, string $destination, FileExists $fileExists) use (&$copiedTo) {
          $copiedTo[] = [$source, $destination, $fileExists];
          return $destination;
        });

      $this->buildFolderSetup()->initializeFolders([]);

      $this->assertCount(2, $copiedTo);
      $this->assertSame($this->modulePath() . '/img/nlp_logo.png', $copiedTo[0][0]);
      $this->assertSame('public://nlp_logo.png', $copiedTo[0][1]);
      $this->assertSame(FileExists::Replace, $copiedTo[0][2]);
      $this->assertSame($this->modulePath() . '/img/nlp_favicon.ico', $copiedTo[1][0]);
      $this->assertSame('public://nlp_favicon.ico', $copiedTo[1][1]);
      $this->assertSame(FileExists::Replace, $copiedTo[1][2]);
    }

  }

}
