<?php

namespace Drupal\Tests\nlpservices\Traits;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\nlpservices\PdfGenerator;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;

/**
 * Renders HTML to a real PDF through PdfGenerator and reads back where things landed.
 *
 * The walk sheet, mailing list and call list rely on mPDF to keep each household on one
 * page (page-break-inside: avoid) without shrinking it (autosize="1").  Asserting on the
 * HTML only proves the markup asks for that, so these helpers render the PDF and parse the
 * text of each page to check what mPDF actually did.
 */
trait PdfPaginationTrait {

  /**
   * Renders HTML through the real PdfGenerator (and its mPDF settings) and parses it.
   *
   * @param string $html
   * @param string|null $orientation - 'P' or 'L', or NULL to use generatePdf()'s default.
   */
  protected function renderPdf(string $html, ?string $orientation = NULL): Document {
    $tempPath = sys_get_temp_dir() . '/nlp_pagination_test_' . uniqid('', TRUE) . '.pdf';
    $fileMock = $this->createMock(FileInterface::class);
    $fileMock->method('getFileUri')->willReturn($tempPath);
    $repoMock = $this->createMock(FileRepositoryInterface::class);
    $repoMock->method('writeData')->willReturn($fileMock);
    $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
    $urlGeneratorMock->method('generateAbsoluteString')->willReturn('https://example.com/test.pdf');

    $generator = new PdfGenerator($repoMock, $urlGeneratorMock);
    try {
      if ($orientation === NULL) {
        $generator->generatePdf($html, 'public://temp/test.pdf');
      }
      else {
        $generator->generatePdf($html, 'public://temp/test.pdf', $orientation);
      }
      return (new Parser())->parseFile($tempPath);
    }
    finally {
      if (is_file($tempPath)) {
        unlink($tempPath);
      }
    }
  }

  /**
   * Returns the text of each page, indexed from page 1.
   */
  protected function pageTexts(Document $pdf): array {
    $texts = [];
    foreach ($pdf->getPages() as $index => $page) {
      $texts[$index + 1] = $page->getText();
    }
    return $texts;
  }

  /**
   * Returns the pages each household's voters appear on.
   *
   * @param array $pageTexts - From pageTexts().
   * @param string $voterPattern - A regex matching one voter, with a named group 'hh'
   *   identifying the household.
   *
   * @return array - ['hh' => ['pages' => [page numbers], 'voters' => count]].
   */
  protected function householdPages(array $pageTexts, string $voterPattern): array {
    $households = [];
    foreach ($pageTexts as $pageNumber => $text) {
      preg_match_all($voterPattern, $text, $matches);
      foreach ($matches['hh'] as $hh) {
        $households[$hh]['pages'][$pageNumber] = $pageNumber;
        $households[$hh]['voters'] = ($households[$hh]['voters'] ?? 0) + 1;
      }
    }
    foreach ($households as $hh => $household) {
      $households[$hh]['pages'] = array_values($household['pages']);
    }
    return $households;
  }

  /**
   * Asserts each household is on one page, except the one too large to fit on a page, which
   * must run across pages at full size rather than being shrunk onto one.
   *
   * @param array $households - From householdPages().
   * @param array $sizes - The expected number of voters in each household, keyed by 'hh'.
   * @param string $oversized - The 'hh' of the household too large for one page.
   */
  protected function assertHouseholdsKeptTogether(array $households, array $sizes, string $oversized): void {
    foreach ($sizes as $hh => $size) {
      $this->assertArrayHasKey($hh, $households, "Household $hh is missing from the PDF.");
      $this->assertSame($size, $households[$hh]['voters'], "Household $hh is missing voters in the PDF.");
      if ($hh === $oversized) {
        $this->assertGreaterThan(1, count($households[$hh]['pages']),
          "Household $hh is too large for one page, so it should be split at full size, not shrunk to fit.");
      }
      else {
        $this->assertCount(1, $households[$hh]['pages'],
          "Household $hh is split across pages " . implode(', ', $households[$hh]['pages']) . '.');
      }
    }
  }

}
