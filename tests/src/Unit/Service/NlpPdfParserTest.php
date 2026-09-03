<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\NlpPdfParser;
  use Drupal\Tests\UnitTestCase;
  use ReflectionMethod;

  class NlpPdfParserTest extends UnitTestCase {

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildParser(): NlpPdfParser {
      return new NlpPdfParser();
    }

    private function invokePrivate(object $obj, string $method, array $args): mixed {
      $ref = new ReflectionMethod($obj, $method);
      $ref->setAccessible(true);
      return $ref->invoke($obj, ...$args);
    }

    private function seg(float $x, float $y, string $text): array {
      return ['x' => $x, 'y' => $y, 'text' => $text];
    }

    private function fixturePath(string $name): string {
      return dirname(__DIR__, 3) . '/fixtures/' . $name;
    }

    // -------------------------------------------------------------------------
    // extractRegistrationTable
    // -------------------------------------------------------------------------

    public function testExtractRegistrationTableUnreadableFileReturnsFalse(): void {
      $path = sys_get_temp_dir() . '/nlp_pdf_missing_' . uniqid('', true) . '.pdf';
      $this->assertFalse($this->buildParser()->extractRegistrationTable($path));
    }

    // -------------------------------------------------------------------------
    // extractRegistrationTable — real fixture (multi-page county absentee report)
    // -------------------------------------------------------------------------

    public function testExtractRegistrationTableRealFixtureReturnsAllDataRowsAcrossPages(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      // 7 pages; each page-break repeats the "Precinct / Name / Receive Date"
      // header, which must be detected and skipped rather than counted as data.
      $this->assertIsArray($result);
      $this->assertCount(202, $result);
    }

    public function testExtractRegistrationTableRealFixtureFirstRowMatchesSource(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      $this->assertSame([
        'precinct'    => '111 - Lamar Park',
        'name'        => 'BALL, JABORIUS DERON',
        'receiveDate' => '11/01/2025',
      ], $result[0]);
    }

    public function testExtractRegistrationTableRealFixtureLastRowMatchesSource(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      $this->assertSame([
        'precinct'    => '551 - Breland',
        'name'        => 'WADE, CHRYSTAL A',
        'receiveDate' => '10/30/2025',
      ], end($result));
    }

    public function testExtractRegistrationTableRealFixtureHandlesBlankReceiveDate(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      // Some voters in the source report have not yet had a ballot received,
      // so the Receive Date cell is blank; the row must still be captured
      // with the other two columns intact rather than dropped or misaligned.
      $row = current(array_filter($result, static fn(array $r) => $r['name'] === 'AULTMAN, INA MARILYN'));

      $this->assertNotFalse($row);
      $this->assertSame('222 - South Purvis', $row['precinct']);
      $this->assertSame('', $row['receiveDate']);
    }

    public function testExtractRegistrationTableRealFixtureEveryRowHasPrecinctAndName(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      foreach ($result as $row) {
        $this->assertNotSame('', $row['precinct']);
        $this->assertStringContainsString(',', $row['name']);
      }
    }

    public function testExtractRegistrationTableRealFixtureCoversAllPrecincts(): void {
      $result = $this->buildParser()->extractRegistrationTable($this->fixturePath('Absentee Vote List - Lamar.pdf'));

      $precincts = array_unique(array_column($result, 'precinct'));

      $this->assertContains('111 - Lamar Park', $precincts);
      $this->assertContains('551 - Breland', $precincts);
    }

    // -------------------------------------------------------------------------
    // groupIntoRows (private)
    // -------------------------------------------------------------------------

    public function testGroupIntoRowsEmptySegmentsReturnsEmptyArray(): void {
      $result = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [[]]);
      $this->assertSame([], $result);
    }

    public function testGroupIntoRowsSegmentsWithinToleranceGroupIntoOneRow(): void {
      // All three y-values are pairwise within the 3.0pt tolerance, so the
      // sort comparator falls back to x-ordering for every pair.
      $segments = [
        $this->seg(50.0, 100.0, 'B'),
        $this->seg(10.0, 100.5, 'A'),
        $this->seg(90.0, 99.5, 'C'),
      ];

      $rows = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [$segments]);

      $this->assertCount(1, $rows);
      $this->assertSame(['A', 'B', 'C'], array_column($rows[0], 'text'));
    }

    public function testGroupIntoRowsSegmentsOutsideToleranceFormSeparateRows(): void {
      $segments = [
        $this->seg(10.0, 100.0, 'Row1'),
        $this->seg(10.0, 120.0, 'Row2'),
      ];

      $rows = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [$segments]);

      $this->assertCount(2, $rows);
      $this->assertSame('Row1', $rows[0][0]['text']);
      $this->assertSame('Row2', $rows[1][0]['text']);
    }

    public function testGroupIntoRowsOrdersRowsTopToBottom(): void {
      $segments = [
        $this->seg(10.0, 200.0, 'Bottom'),
        $this->seg(10.0, 50.0, 'Top'),
      ];

      $rows = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [$segments]);

      $this->assertSame('Top', $rows[0][0]['text']);
      $this->assertSame('Bottom', $rows[1][0]['text']);
    }

    public function testGroupIntoRowsToleranceBoundaryIsInclusive(): void {
      $segments = [
        $this->seg(10.0, 100.0, 'A'),
        $this->seg(10.0, 103.0, 'B'),
      ];

      $rows = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [$segments]);

      $this->assertCount(1, $rows);
    }

    public function testGroupIntoRowsJustBeyondToleranceSplits(): void {
      $segments = [
        $this->seg(10.0, 100.0, 'A'),
        $this->seg(10.0, 103.1, 'B'),
      ];

      $rows = $this->invokePrivate($this->buildParser(), 'groupIntoRows', [$segments]);

      $this->assertCount(2, $rows);
    }

    // -------------------------------------------------------------------------
    // assignToColumn (private)
    // -------------------------------------------------------------------------

    public function testAssignToColumnBeforeFirstMidpointReturnsFirstColumn(): void {
      $result = $this->invokePrivate($this->buildParser(), 'assignToColumn', [
        5.0, [10.0, 50.0, 90.0], ['precinct', 'name', 'receiveDate'],
      ]);
      $this->assertSame('precinct', $result);
    }

    public function testAssignToColumnBetweenFirstAndSecondReturnsSecondColumn(): void {
      $result = $this->invokePrivate($this->buildParser(), 'assignToColumn', [
        55.0, [10.0, 50.0, 90.0], ['precinct', 'name', 'receiveDate'],
      ]);
      $this->assertSame('name', $result);
    }

    public function testAssignToColumnBeyondLastMidpointReturnsLastColumn(): void {
      $result = $this->invokePrivate($this->buildParser(), 'assignToColumn', [
        500.0, [10.0, 50.0, 90.0], ['precinct', 'name', 'receiveDate'],
      ]);
      $this->assertSame('receiveDate', $result);
    }

    public function testAssignToColumnExactlyAtMidpointGoesToNextColumn(): void {
      // Midpoint between 10 and 50 is 30; boundary check is strict '<', so 30
      // itself belongs to the next column.
      $result = $this->invokePrivate($this->buildParser(), 'assignToColumn', [
        30.0, [10.0, 50.0, 90.0], ['precinct', 'name', 'receiveDate'],
      ]);
      $this->assertSame('name', $result);
    }

    public function testAssignToColumnSingleColumnAlwaysMatches(): void {
      $result = $this->invokePrivate($this->buildParser(), 'assignToColumn', [
        9999.0, [10.0], ['precinct'],
      ]);
      $this->assertSame('precinct', $result);
    }

  }

}
