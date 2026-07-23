<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\Tests\UnitTestCase;

  class NlpSearchBarTest extends UnitTestCase {

    private function bar(): NlpSearchBar {
      return new NlpSearchBar();
    }

    // -------------------------------------------------------------------------
    // searchInfoArray
    // -------------------------------------------------------------------------

    public function testSearchInfoArrayEmptyArrayReturnsEmpty(): void {
      $this->assertSame([], $this->bar()->searchInfoArray('Smith', []));
    }

    public function testSearchInfoArrayNeedleMatchesFirstNameReturnsRecord(): void {
      $info = [7 => ['firstName' => 'Alice', 'lastName' => 'Jones']];
      $result = $this->bar()->searchInfoArray('Alice', $info);
      $this->assertArrayHasKey(7, $result);
    }

    public function testSearchInfoArrayNeedleMatchesLastNameReturnsRecord(): void {
      $info = [3 => ['firstName' => 'Bob', 'lastName' => 'Smith']];
      $result = $this->bar()->searchInfoArray('Smith', $info);
      $this->assertArrayHasKey(3, $result);
    }

    public function testSearchInfoArrayMatchIsCaseInsensitive(): void {
      $info = [1 => ['firstName' => 'Alice']];
      $this->assertNotEmpty($this->bar()->searchInfoArray('alice', $info));
      $this->assertNotEmpty($this->bar()->searchInfoArray('ALICE', $info));
    }

    public function testSearchInfoArrayPartialNeedleMatches(): void {
      $info = [5 => ['lastName' => 'Thompson']];
      $result = $this->bar()->searchInfoArray('omp', $info);
      $this->assertArrayHasKey(5, $result);
    }

    public function testSearchInfoArrayNoMatchReturnsEmpty(): void {
      $info = [1 => ['firstName' => 'Alice', 'lastName' => 'Jones']];
      $this->assertSame([], $this->bar()->searchInfoArray('Zzzz', $info));
    }

    public function testSearchInfoArrayFiltersToMatchingRecordsOnly(): void {
      $info = [
        1 => ['firstName' => 'Alice'],
        2 => ['firstName' => 'Bob'],
        3 => ['firstName' => 'Alice'],
      ];
      $result = $this->bar()->searchInfoArray('Alice', $info);
      $this->assertArrayHasKey(1, $result);
      $this->assertArrayNotHasKey(2, $result);
      $this->assertArrayHasKey(3, $result);
    }

    public function testSearchInfoArrayMissingFieldIsSkippedSafely(): void {
      // Record has none of the match fields — should not error, just not match.
      $info = [9 => ['irrelevantField' => 'Alice']];
      $this->assertSame([], $this->bar()->searchInfoArray('Alice', $info));
    }

    public function testSearchInfoArrayMatchesMcidField(): void {
      $info = [4 => ['mcid' => '12345']];
      $result = $this->bar()->searchInfoArray('123', $info);
      $this->assertArrayHasKey(4, $result);
    }

    public function testSearchInfoArrayMatchesTurfNameField(): void {
      $info = [6 => ['turfName' => 'Denver-001']];
      $result = $this->bar()->searchInfoArray('Denver', $info);
      $this->assertArrayHasKey(6, $result);
    }

    public function testSearchInfoArrayPreservesOriginalIndex(): void {
      $info = [42 => ['firstName' => 'Alice']];
      $result = $this->bar()->searchInfoArray('Alice', $info);
      $this->assertArrayHasKey(42, $result);
      $this->assertSame($info[42], $result[42]);
    }

    // -------------------------------------------------------------------------
    // searchBar
    // -------------------------------------------------------------------------

    public function testSearchBarWithHdOptionsIncludesSelectElement(): void {
      $form = $this->bar()->searchBar(['All', 6, 8], 0);
      $this->assertArrayHasKey('hd-select', $form['bar-box']);
      $this->assertSame('select', $form['bar-box']['hd-select']['#type']);
    }

    public function testSearchBarWithHdOptionsSetsDefaultIndex(): void {
      $form = $this->bar()->searchBar(['All', 6, 8], 2);
      $this->assertSame(2, $form['bar-box']['hd-select']['#default_value']);
    }

    public function testSearchBarWithHdOptionsAttachesLibrary(): void {
      $form = $this->bar()->searchBar(['All', 6]);
      $this->assertContains('nlpservices/hd-filter', $form['#attached']['library']);
    }

    public function testSearchBarWithHdOptionsUsesFullWidthDivClass(): void {
      $form = $this->bar()->searchBar(['All', 6]);
      $this->assertStringContainsString('search-bar">', $form['bar-box']['start']['#markup']);
    }

    public function testSearchBarWithoutHdOptionsOmitsSelectElement(): void {
      $form = $this->bar()->searchBar();
      $this->assertArrayNotHasKey('hd-select', $form['bar-box']);
      $this->assertArrayNotHasKey('#attached', $form);
    }

    public function testSearchBarWithoutHdOptionsUsesSmallDivClass(): void {
      $form = $this->bar()->searchBar();
      $this->assertStringContainsString('search-bar-small">', $form['bar-box']['start']['#markup']);
    }

    public function testSearchBarAlwaysIncludesSearchNeedle(): void {
      $withOptions = $this->bar()->searchBar(['All', 6]);
      $withoutOptions = $this->bar()->searchBar();
      foreach ([$withOptions, $withoutOptions] as $form) {
        $this->assertArrayHasKey('search-nl', $form['bar-box']);
        $this->assertArrayHasKey('search-needle', $form['bar-box']['search-nl']);
        $this->assertArrayHasKey('search-submit', $form['bar-box']['search-nl']);
      }
    }

    public function testSearchBarSearchSubmitHasCorrectName(): void {
      $form = $this->bar()->searchBar();
      $this->assertSame('search_submit', $form['bar-box']['search-nl']['search-submit']['#name']);
    }

    public function testSearchBarWithPctOptionsIncludesSelectElement(): void {
      $form = $this->bar()->searchBar([], 0, ['All', 'Pct1', 'Pct2'], 0);
      $this->assertArrayHasKey('pct-select', $form['bar-box']);
      $this->assertSame('select', $form['bar-box']['pct-select']['#type']);
    }

    public function testSearchBarWithPctOptionsSetsDefaultIndex(): void {
      $form = $this->bar()->searchBar([], 0, ['All', 'Pct1', 'Pct2'], 2);
      $this->assertSame(2, $form['bar-box']['pct-select']['#default_value']);
    }

    public function testSearchBarWithPctOptionsAttachesLibrary(): void {
      $form = $this->bar()->searchBar([], 0, ['All', 'Pct1'], 0);
      $this->assertContains('nlpservices/pct-filter', $form['#attached']['library']);
    }

    public function testSearchBarWithoutPctOptionsOmitsPctSelectElement(): void {
      $form = $this->bar()->searchBar();
      $this->assertArrayNotHasKey('pct-select', $form['bar-box']);
    }

  }

}
