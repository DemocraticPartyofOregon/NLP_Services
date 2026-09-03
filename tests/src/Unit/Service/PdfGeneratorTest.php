<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\File\FileExists;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\file\FileInterface;
  use Drupal\file\FileRepositoryInterface;
  use Drupal\nlpservices\PdfGenerator;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class PdfGeneratorTest extends UnitTestCase {

    private array $tempFiles = [];

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
      foreach ($this->tempFiles as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
      $this->tempFiles = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildGenerator(): PdfGenerator {
      return new PdfGenerator();
    }

    /**
     * Loads the fixture array (a var_export() of NlpCreateWalksheet::createWalksheetArray()'s
     * output) and turns it into a small HTML table, mirroring the shape of real
     * walksheet content that gets passed to generatePdf().
     */
    private function loadFixtureAsHtml(): string {
      $path = dirname(__DIR__, 3) . '/fixtures/ArrayInputForPdfGenerator.txt';
      include $path;
      /** @var array $values populated by the included fixture */
      $html = '<table>';
      foreach ($values as $household) {
        foreach ($household['votersInHousehold'] as $voter) {
          $html .= '<tr>';
          $html .= '<td>' . $voter['address'] . '</td>';
          $html .= '<td>' . $voter['name'] . '</td>';
          $html .= '<td>' . $voter['votingHistory'] . '</td>';
          $html .= '</tr>';
        }
      }
      $html .= '</table>';
      return $html;
    }

    private function newTempPdfPath(): string {
      $path = sys_get_temp_dir() . '/nlp_pdfgen_test_' . uniqid('', true) . '.pdf';
      $this->tempFiles[] = $path;
      return $path;
    }

    private function makeFileMock(string $fileUri): FileInterface {
      $fileMock = $this->createMock(FileInterface::class);
      $fileMock->method('getFileUri')->willReturn($fileUri);
      return $fileMock;
    }

    private function setupContainer(FileRepositoryInterface $repoMock, FileUrlGeneratorInterface $urlGeneratorMock): void {
      $container = new ContainerBuilder();
      $container->set('file.repository', $repoMock);
      $container->set('file_url_generator', $urlGeneratorMock);
      Drupal::setContainer($container);
    }

    // -------------------------------------------------------------------------
    // generatePdf
    // -------------------------------------------------------------------------

    public function testGeneratePdfWritesARealPdfFileFromFixtureData(): void {
      $html = $this->loadFixtureAsHtml();
      $tempPath = $this->newTempPdfPath();
      $fileMock = $this->makeFileMock($tempPath);

      $repoMock = $this->createMock(FileRepositoryInterface::class);
      $repoMock->method('writeData')->willReturn($fileMock);
      $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $urlGeneratorMock->method('generateAbsoluteString')->willReturn('https://example.com/temp/walksheet.pdf');
      $this->setupContainer($repoMock, $urlGeneratorMock);

      $result = $this->buildGenerator()->generatePdf($html, 'public://temp/walksheet.pdf');

      $this->assertSame('https://example.com/temp/walksheet.pdf', $result);
      $this->assertFileExists($tempPath);
      $this->assertStringStartsWith('%PDF-', file_get_contents($tempPath, false, null, 0, 5));
      $this->assertGreaterThan(1000, filesize($tempPath));
    }

    public function testGeneratePdfWritesDataUsingPlaceholderAndGivenTempFile(): void {
      $tempPath = $this->newTempPdfPath();
      $fileMock = $this->makeFileMock($tempPath);

      $repoMock = $this->createMock(FileRepositoryInterface::class);
      $repoMock->expects($this->once())->method('writeData')
        ->with('Placeholder', 'public://temp/walksheet.pdf', FileExists::Replace)
        ->willReturn($fileMock);
      $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $urlGeneratorMock->method('generateAbsoluteString')->willReturn('https://example.com/temp/walksheet.pdf');
      $this->setupContainer($repoMock, $urlGeneratorMock);

      $this->buildGenerator()->generatePdf('<p>Hello</p>', 'public://temp/walksheet.pdf');
    }

    public function testGeneratePdfSetsFileTemporaryAndSaves(): void {
      $tempPath = $this->newTempPdfPath();
      $fileMock = $this->makeFileMock($tempPath);
      $fileMock->expects($this->once())->method('setTemporary');
      $fileMock->expects($this->once())->method('save');

      $repoMock = $this->createMock(FileRepositoryInterface::class);
      $repoMock->method('writeData')->willReturn($fileMock);
      $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $urlGeneratorMock->method('generateAbsoluteString')->willReturn('https://example.com/temp/walksheet.pdf');
      $this->setupContainer($repoMock, $urlGeneratorMock);

      $this->buildGenerator()->generatePdf('<p>Hello</p>', 'public://temp/walksheet.pdf');
    }

    public function testGeneratePdfPassesFileUriToUrlGenerator(): void {
      $tempPath = $this->newTempPdfPath();
      $fileMock = $this->makeFileMock($tempPath);

      $repoMock = $this->createMock(FileRepositoryInterface::class);
      $repoMock->method('writeData')->willReturn($fileMock);
      $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $urlGeneratorMock->expects($this->once())->method('generateAbsoluteString')
        ->with($tempPath)
        ->willReturn('https://example.com/temp/walksheet.pdf');
      $this->setupContainer($repoMock, $urlGeneratorMock);

      $this->buildGenerator()->generatePdf('<p>Hello</p>', 'public://temp/walksheet.pdf');
    }

  }

}
