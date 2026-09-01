<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\nlpservices\NlpDocuments;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpDocumentsTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpPaths $pathsMock;
    private array $tempDirs = [];

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
      $this->pathsMock      = $this->createMock(NlpPaths::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
      foreach ($this->tempDirs as $dir) {
        $this->removeTempDir($dir);
      }
      $this->tempDirs = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpDocuments {
      return new NlpDocuments($this->connectionMock, $this->pathsMock);
    }

    private function buildSelectMock(\ArrayObject $rows): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('execute')->willReturn($rows);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function baseRecord(array $overrides = []): array {
      return array_replace([
        'name'        => 'nlInstruct',
        'weight'      => 10,
        'docFileName' => 'InstructionsCanvass_2024.docx',
        'pdfFileName' => 'InstructionsCanvass_2024.pdf',
        'description' => 'NL Instructions Canvass',
        'missing'     => 0,
        'createDate'  => '2024-01-01',
      ], $overrides);
    }

    private function setupContainerWithFileUrlGenerator(): void {
      $fileUrlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlGeneratorMock->method('generateAbsoluteString')->willReturn('https://example.com/file');
      $container = new ContainerBuilder();
      $container->set('file_url_generator', $fileUrlGeneratorMock);
      \Drupal::setContainer($container);
    }

    private function setupContainerWithModuleList(string $modulePath): void {
      $moduleListMock = $this->createMock(ModuleExtensionList::class);
      $moduleListMock->method('getPath')->willReturn($modulePath);
      $container = new ContainerBuilder();
      $container->set('extension.list.module', $moduleListMock);
      \Drupal::setContainer($container);
    }

    private function createTempDocsDir(array $fileNames): string {
      $tempDir = sys_get_temp_dir() . '/nlp_test_' . uniqid('', true);
      mkdir($tempDir . '/docs', 0777, true);
      foreach ($fileNames as $fileName) {
        file_put_contents($tempDir . '/docs/' . $fileName, '');
      }
      $this->tempDirs[] = $tempDir;
      return $tempDir;
    }

    private function removeTempDir(string $dir): void {
      if (!is_dir($dir)) { return; }
      foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = $dir . '/' . $item;
        is_dir($path) ? $this->removeTempDir($path) : unlink($path);
      }
      rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // displayList
    // -------------------------------------------------------------------------

    public function testDisplayListReturnsOneEntryPerNameListItem(): void {
      $this->assertCount(8, $this->buildService()->displayList());
    }

    public function testDisplayListReturnsStringDescriptions(): void {
      foreach ($this->buildService()->displayList() as $description) {
        $this->assertIsString($description);
      }
    }

    public function testDisplayListContainsExpectedKeys(): void {
      $result = $this->buildService()->displayList();
      $this->assertArrayHasKey('nlInstruct', $result);
      $this->assertArrayHasKey('cutTurf', $result);
    }

    public function testDisplayListDescriptionMatchesNameList(): void {
      $result = $this->buildService()->displayList();
      $this->assertSame('Neighborhood Leader Instructions - canvass', $result['nlInstruct']);
    }

    // -------------------------------------------------------------------------
    // buildDefaultFilesInfo
    // -------------------------------------------------------------------------

    public function testBuildDefaultFilesInfoEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildService()->buildDefaultFilesInfo([]));
    }

    public function testBuildDefaultFilesInfoMatchesDocxFileByDefaultFilename(): void {
      $result = $this->buildService()->buildDefaultFilesInfo(['InstructionsCanvass_2024.docx']);
      $entry = reset($result);
      $this->assertSame('InstructionsCanvass_2024.docx', $entry['docFileName']);
    }

    public function testBuildDefaultFilesInfoMatchesPdfFileByDefaultFilename(): void {
      $result = $this->buildService()->buildDefaultFilesInfo(['InstructionsCanvass_2024.pdf']);
      $entry = reset($result);
      $this->assertSame('InstructionsCanvass_2024.pdf', $entry['pdfFileName']);
    }

    public function testBuildDefaultFilesInfoGroupsDocxAndPdfWithSameRootIntoOneEntry(): void {
      $files = ['InstructionsCanvass_2024.docx', 'InstructionsCanvass_2024.pdf'];
      $result = $this->buildService()->buildDefaultFilesInfo($files);
      $this->assertCount(1, $result);
      $entry = reset($result);
      $this->assertSame('InstructionsCanvass_2024.docx', $entry['docFileName']);
      $this->assertSame('InstructionsCanvass_2024.pdf', $entry['pdfFileName']);
    }

    public function testBuildDefaultFilesInfoSetsCorrectNameAndWeight(): void {
      $result = $this->buildService()->buildDefaultFilesInfo(['InstructionsCanvass_2024.docx']);
      $entry = reset($result);
      $this->assertSame('nlInstruct', $entry['name']);
      $this->assertSame(10, $entry['weight']);
    }

    public function testBuildDefaultFilesInfoSetsDescriptionFromNameList(): void {
      $result = $this->buildService()->buildDefaultFilesInfo(['InstructionsCanvass_2024.docx']);
      $entry = reset($result);
      $this->assertSame('Neighborhood Leader Instructions - canvass', $entry['description']);
    }

    public function testBuildDefaultFilesInfoMatchingIsCaseInsensitive(): void {
      $result = $this->buildService()->buildDefaultFilesInfo(['instructionscanvass_v1.docx']);
      $this->assertNotEmpty($result);
    }

    public function testBuildDefaultFilesInfoIgnoresFilesWithNoNameListMatch(): void {
      $this->assertSame([], $this->buildService()->buildDefaultFilesInfo(['unrelated_file.docx']));
    }

    public function testBuildDefaultFilesInfoNonDocxPdfMatchingFileCreatesEntryWithEmptyFileNames(): void {
      // A name-matching .txt file creates a record but with empty doc/pdf slots.
      $result = $this->buildService()->buildDefaultFilesInfo(['InstructionsCanvass_2024.txt']);
      $this->assertNotEmpty($result);
      $entry = reset($result);
      $this->assertSame('', $entry['docFileName']);
      $this->assertSame('', $entry['pdfFileName']);
    }

    public function testBuildDefaultFilesInfoHandlesMultipleDistinctNameListMatches(): void {
      $files = ['InstructionsCanvass_2024.docx', 'ActiveNLList_2024.docx'];
      $result = $this->buildService()->buildDefaultFilesInfo($files);
      $this->assertCount(2, $result);
    }

    public function testBuildDefaultFilesInfoAssignsUniqueKeysForMultipleRootsUnderSameName(): void {
      $files = ['InstructionsCanvass_v1.docx', 'InstructionsCanvass_v2.docx'];
      $result = $this->buildService()->buildDefaultFilesInfo($files);
      $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // createDocument
    // -------------------------------------------------------------------------

    public function testCreateDocumentCallsMergeOnCorrectTable(): void {
      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpDocuments::DOCUMENTS_TBL)
        ->willReturn($this->buildMergeMock());
      $this->buildService()->createDocument($this->baseRecord());
    }

    public function testCreateDocumentExceptionDoesNotPropagate(): void {
      $this->connectionMock->method('merge')
        ->willThrowException(new \Exception('DB error'));
      $this->buildService()->createDocument($this->baseRecord());
      $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // getDocuments
    // -------------------------------------------------------------------------

    public function testGetDocumentsEmptyTableReturnsEmptyArray(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([])));
      $this->assertSame([], $this->buildService()->getDocuments());
    }

    public function testGetDocumentsReturnsResultKeyedByDocumentName(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$this->baseRecord()])));
      $this->assertArrayHasKey('nlInstruct', $this->buildService()->getDocuments());
    }

    public function testGetDocumentsExcludesCreateDateField(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$this->baseRecord()])));
      $doc = $this->buildService()->getDocuments()['nlInstruct'];
      $this->assertArrayNotHasKey('createDate', $doc);
    }

    public function testGetDocumentsIncludesExpectedFields(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$this->baseRecord()])));
      $doc = $this->buildService()->getDocuments()['nlInstruct'];
      $this->assertArrayHasKey('name', $doc);
      $this->assertArrayHasKey('weight', $doc);
      $this->assertArrayHasKey('docFileName', $doc);
      $this->assertArrayHasKey('pdfFileName', $doc);
      $this->assertArrayHasKey('description', $doc);
    }

    public function testGetDocumentsSortsByWeightAscending(): void {
      $rows = new \ArrayObject([
        $this->baseRecord(['name' => 'nlPostcard', 'weight' => 20]),
        $this->baseRecord(['name' => 'nlInstruct',  'weight' => 10]),
      ]);
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($rows));
      $result = $this->buildService()->getDocuments();
      $this->assertSame('nlInstruct', array_key_first($result));
    }

    public function testGetDocumentsExecuteExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($selectMock);
      $this->assertSame([], $this->buildService()->getDocuments());
    }

    // -------------------------------------------------------------------------
    // buildDocumentDisplay
    // -------------------------------------------------------------------------

    public function testBuildDocumentDisplayNoDocumentsReturnsMissingMarkup(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([])));
      $result = $this->buildService()->buildDocumentDisplay();
      $this->assertStringContainsString('missing', $result['documents']['#markup']);
    }

    public function testBuildDocumentDisplayWithDocumentsReturnsTableStructure(): void {
      $this->setupContainerWithFileUrlGenerator();
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$this->baseRecord()])));
      $this->pathsMock->method('getPath')->willReturn('public://docs/');
      $result = $this->buildService()->buildDocumentDisplay();
      $this->assertArrayHasKey('document_form', $result);
      $this->assertArrayHasKey('table', $result['document_form']);
    }

    public function testBuildDocumentDisplayDocxLinkContainsFilename(): void {
      $this->setupContainerWithFileUrlGenerator();
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$this->baseRecord()])));
      $this->pathsMock->method('getPath')->willReturn('public://docs/');
      $result = $this->buildService()->buildDocumentDisplay();
      $docMarkup = $result['document_form']['table']['#rows']['nlInstruct']['doc']['data']['#markup'];
      $this->assertStringContainsString('InstructionsCanvass_2024.docx', $docMarkup);
    }

    public function testBuildDocumentDisplayEmptyDocFileNameShowsDash(): void {
      $this->setupContainerWithFileUrlGenerator();
      $row = $this->baseRecord(['docFileName' => '']);
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$row])));
      $this->pathsMock->method('getPath')->willReturn('public://docs/');
      $result = $this->buildService()->buildDocumentDisplay();
      $docMarkup = $result['document_form']['table']['#rows']['nlInstruct']['doc']['data']['#markup'];
      $this->assertSame('-', $docMarkup);
    }

    public function testBuildDocumentDisplayEmptyPdfFileNameShowsDash(): void {
      $this->setupContainerWithFileUrlGenerator();
      $row = $this->baseRecord(['pdfFileName' => '']);
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$row])));
      $this->pathsMock->method('getPath')->willReturn('public://docs/');
      $result = $this->buildService()->buildDocumentDisplay();
      $pdfMarkup = $result['document_form']['table']['#rows']['nlInstruct']['pdf']['data']['#markup'];
      $this->assertSame('-', $pdfMarkup);
    }

    public function testBuildDocumentDisplayUsesNameListDescriptionWhenDocumentDescriptionEmpty(): void {
      $this->setupContainerWithFileUrlGenerator();
      $row = $this->baseRecord(['description' => '']);
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([$row])));
      $this->pathsMock->method('getPath')->willReturn('public://docs/');
      $result = $this->buildService()->buildDocumentDisplay();
      $description = $result['document_form']['table']['#rows']['nlInstruct']['description'];
      $this->assertSame('Neighborhood Leader Instructions - canvass', $description);
    }

    // -------------------------------------------------------------------------
    // verifyDefaultDocuments
    // -------------------------------------------------------------------------

    public function testVerifyDefaultDocumentsReturnsEmptyWhenAllPresentInDb(): void {
      $dbRows = array_map(
        fn($name) => $this->baseRecord(['name' => $name]),
        ['nlInstruct', 'nlPostcard', 'nlList', 'cutTurf', 'deliverTurf', 'committeeCoordinator', 'editEmail', 'nlpConfiguration']
      );
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject($dbRows)));
      $tempDir = $this->createTempDocsDir([]);
      $this->setupContainerWithModuleList($tempDir);
      $this->assertSame([], $this->buildService()->verifyDefaultDocuments());
    }

    public function testVerifyDefaultDocumentsAllEightMissingWithNoDbRowsAndNoFiles(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([])));
      $tempDir = $this->createTempDocsDir([]);
      $this->setupContainerWithModuleList($tempDir);
      $this->assertCount(8, $this->buildService()->verifyDefaultDocuments());
    }

    public function testVerifyDefaultDocumentsFilesOnDiskFulfillMissingDbEntries(): void {
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([])));
      $files = [
        'InstructionsCanvass_2024.docx',
        'InstructionsPostcard_2024.docx',
        'ActiveNLList_2024.docx',
        'CuttingTurf_2024.docx',
        'TurfDelivery_2024.docx',
        'CommitteeCoordinator_2024.docx',
        'EditTurfEmail_2024.docx',
        'NlpConfiguration_2024.docx',
      ];
      $tempDir = $this->createTempDocsDir($files);
      $this->setupContainerWithModuleList($tempDir);
      $this->assertSame([], $this->buildService()->verifyDefaultDocuments());
    }

    public function testVerifyDefaultDocumentsDbAndFileCoverDifferentEntries(): void {
      // DB covers nlInstruct; disk covers nlPostcard; rest are missing
      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock(new \ArrayObject([
          $this->baseRecord(['name' => 'nlInstruct']),
        ])));
      $tempDir = $this->createTempDocsDir(['InstructionsPostcard_2024.docx']);
      $this->setupContainerWithModuleList($tempDir);
      $result = $this->buildService()->verifyDefaultDocuments();
      $this->assertNotContains('nlInstruct', $result);
      $this->assertNotContains('nlPostcard', $result);
      $this->assertContains('nlList', $result);
    }

  }

}
