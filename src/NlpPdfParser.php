<?php

namespace Drupal\nlpservices;

use Exception;
use Smalot\PdfParser\Parser;

class NlpPdfParser
{

  // PDF points: typical line height is ~12pt, so 3pt tolerance safely groups
  // glyphs on the same baseline without merging adjacent lines.
  const float ROW_Y_TOLERANCE = 3.0;

  /**
   * extractRegistrationTable
   *
   * Extracts a table from a PDF file using per-glyph X/Y coordinates so that
   * column alignment is preserved regardless of PDF text stream order.
   *
   * @param string $pdfPath Absolute path to the PDF file.
   * @return array[]|false Rows with keys 'precinct', 'name', 'receiveDate',
   *   or false if the header was not found or the file is unreadable.
   */
  public function extractRegistrationTable(string $pdfPath): array|false
  {
    if (!is_readable($pdfPath)) {
      nlp_debug_msg('NlpPdfParser', 'PDF file not readable: ' . $pdfPath);
      return false;
    }

    try {
      $parser = new Parser();
      $pdf = $parser->parseFile($pdfPath);
    } catch (Exception $e) {
      nlp_debug_msg('NlpPdfParser', 'PDF parse error: ' . $e->getMessage());
      return false;
    }

    // Collect every text segment with its x/y position.
    // A large per-page y-offset keeps pages from interleaving when sorted.
    $segments = [];
    $pageOffset = 0.0;
    foreach ($pdf->getPages() as $page) {
      foreach ($page->getDataTm() as [$matrix, $text]) {
        if (trim($text) === '') {
          continue;
        }
        $segments[] = [
          'x' => (float) $matrix[4],
          // PDF y grows upward; negate so rows sort top-to-bottom.
          'y' => $pageOffset - (float) $matrix[5],
          'text' => $text,
        ];
      }
      $pageOffset += 10000.0;
    }

    if (empty($segments)) {
      nlp_debug_msg('NlpPdfParser', 'No text found in PDF: ' . $pdfPath);
      return false;
    }

    $rows = $this->groupIntoRows($segments);

    // Locate the header row and record the x-start of each column label.
    $headerRowIndex = null;
    $colPositions = [];

    foreach ($rows as $i => $rowSegs) {
      $joined = implode(' ', array_column($rowSegs, 'text'));
      if (str_contains($joined, 'Precinct')
        && str_contains($joined, 'Name')
        && str_contains($joined, 'Receive Date')) {
        $headerRowIndex = $i;
        foreach ($rowSegs as $seg) {
          $t = trim($seg['text']);
          if (str_contains($t, 'Precinct')) {
            $colPositions['precinct'] = $seg['x'];
          } elseif (str_contains($t, 'Receive')) {
            $colPositions['receiveDate'] = $seg['x'];
          } elseif (str_contains($t, 'Name')) {
            $colPositions['name'] = $seg['x'];
          }
        }
        break;
      }
    }

    if ($headerRowIndex === null || count($colPositions) < 3) {
      nlp_debug_msg('NlpPdfParser', 'Table header not found in PDF: ' . $pdfPath);
      return false;
    }

    // Sort columns left-to-right by their x position so we can compute
    // midpoint boundaries for assignment.
    asort($colPositions);
    $colNames = array_keys($colPositions);
    $colXs = array_values($colPositions);

    $result = [];
    for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
      // Page breaks repeat the full page header; skip those rows.
      $joined = implode(' ', array_column($rows[$i], 'text'));
      if (str_contains($joined, 'Precinct')
        && str_contains($joined, 'Name')
        && str_contains($joined, 'Receive Date')) {
        continue;
      }

      $values = array_fill_keys($colNames, '');

      foreach ($rows[$i] as $seg) {
        $col = $this->assignToColumn($seg['x'], $colXs, $colNames);
        if ($col !== null) {
          $values[$col] .= $seg['text'];
        }
      }

      $values = array_map('trim', $values);

      // Voter names are always "LASTNAME, FIRSTNAME"; any row without a comma
      // in the name field is a page title, report header, or footnote.
      if (!str_contains($values['name'], ',')) {
        continue;
      }

      $result[] = $values;
    }

    return $result;
  }

  /**
   * groupIntoRows
   *
   * Groups segments into rows by proximity of their y-coordinate, then sorts
   * each row's segments left-to-right by x.
   * @param array $segments
   * @return array
   */
  private function groupIntoRows(array $segments): array
  {
    // Primary sort: y ascending (top of page first). Within the same y-band,
    // sort x ascending (left to right).
    usort($segments, static function ($a, $b) {
      $yDiff = $a['y'] - $b['y'];
      if (abs($yDiff) < 3.0) {
        return $a['x'] <=> $b['x'];
      }
      return $yDiff > 0 ? 1 : -1;
    });

    $rows = [];
    $currentRow = [];
    $rowY = null;

    foreach ($segments as $seg) {
      if ($rowY === null || abs($seg['y'] - $rowY) <= self::ROW_Y_TOLERANCE) {
        $currentRow[] = $seg;
        $rowY ??= $seg['y'];
      } else {
        $rows[] = $currentRow;
        $currentRow = [$seg];
        $rowY = $seg['y'];
      }
    }
    if (!empty($currentRow)) {
      $rows[] = $currentRow;
    }

    return $rows;
  }

  /**
   * assignToColumn
   *
   * Assigns a segment to a column using midpoints between column x-starts
   * as boundaries, so no tolerance is needed and segments never straddle.
   * @param float $x
   * @param array $colXs
   * @param array $colNames
   * @return string|null
   */
  private function assignToColumn(float $x, array $colXs, array $colNames): ?string
  {
    $count = count($colXs);
    for ($i = 0; $i < $count; $i++) {
      $upperBound = ($i + 1 < $count)
        ? ($colXs[$i] + $colXs[$i + 1]) / 2.0
        : PHP_FLOAT_MAX;
      if ($x < $upperBound) {
        return $colNames[$i];
      }
    }
    return $colNames[$count - 1];
  }

}
