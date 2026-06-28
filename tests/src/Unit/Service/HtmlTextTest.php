<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\HtmlText;
  use Drupal\Tests\UnitTestCase;

  class HtmlTextTest extends UnitTestCase {

    private function getText(string $html): string {
      $obj = new HtmlText();
      $obj->setHtml($html);
      return $obj->getText();
    }

    public function testEmptyInputReturnsEmptyString(): void {
      $this->assertSame('', $this->getText(''));
    }

    public function testPlainTextPassesThrough(): void {
      $this->assertSame('Hello world', $this->getText('Hello world'));
    }

    public function testScriptBlockIsRemoved(): void {
      $result = $this->getText('<p>Before</p><script>alert("xss")</script><p>After</p>');
      $this->assertStringNotContainsString('alert', $result);
      $this->assertStringContainsString('Before', $result);
      $this->assertStringContainsString('After', $result);
    }

    public function testStyleBlockIsRemoved(): void {
      $result = $this->getText('<style>body { color: red; }</style><p>Text</p>');
      $this->assertStringNotContainsString('color', $result);
      $this->assertStringContainsString('Text', $result);
    }

    public function testHeadBlockIsRemoved(): void {
      $result = $this->getText('<head><title>Page Title</title></head><p>Body</p>');
      $this->assertStringNotContainsString('Page Title', $result);
      $this->assertStringContainsString('Body', $result);
    }

    public function testParagraphsProduceNewlines(): void {
      $result = $this->getText('<p>First</p><p>Second</p>');
      $this->assertStringContainsString("\n", $result);
      $this->assertStringContainsString('First', $result);
      $this->assertStringContainsString('Second', $result);
    }

    public function testDivProducesNewline(): void {
      $result = $this->getText('<div>Line one</div><div>Line two</div>');
      $this->assertStringContainsString("\n", $result);
    }

    public function testHeadingTagsProduceNewlines(): void {
      foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
        $result = $this->getText("<{$tag}>Title</{$tag}><p>Body</p>");
        $this->assertStringContainsStringIgnoringCase('title', $result);
        $this->assertStringContainsString("\n", $result);
      }
    }

    public function testListItemsProduceNewlines(): void {
      $result = $this->getText('<ul><li>Item 1</li><li>Item 2</li></ul>');
      $this->assertStringContainsString('Item 1', $result);
      $this->assertStringContainsString('Item 2', $result);
      $this->assertStringContainsString("\n", $result);
    }

    public function testTableRowsProduceNewlines(): void {
      $result = $this->getText('<table><tr><td>Cell</td></tr></table>');
      $this->assertStringContainsString('Cell', $result);
    }

    public function testBrBecomesNewline(): void {
      $result = $this->getText('Line one<br>Line two');
      $this->assertStringContainsString("\n", $result);
      $this->assertStringContainsString('Line one', $result);
      $this->assertStringContainsString('Line two', $result);
    }

    public function testBrWithAttributesBecomesNewline(): void {
      $result = $this->getText('Line one<br />Line two');
      $this->assertStringContainsString("\n", $result);
    }

    public function testHrBecomesSeparator(): void {
      $result = $this->getText('Above<hr>Below');
      $this->assertStringContainsString('-------------------------', $result);
      $this->assertStringContainsString('Above', $result);
      $this->assertStringContainsString('Below', $result);
    }

    public function testHttpLinkRenderedWithUrl(): void {
      $result = $this->getText('<a href="https://example.com">Visit us</a>');
      $this->assertSame('Visit us [https://example.com]', $result);
    }

    public function testLinkWhereDisplayEqualsUrlRenderedWithoutBrackets(): void {
      $result = $this->getText('<a href="https://example.com">https://example.com</a>');
      $this->assertSame('https://example.com', $result);
    }

    public function testMailtoLinkRendersDisplayOnly(): void {
      $result = $this->getText('<a href="mailto:foo@example.com">Email us</a>');
      $this->assertSame('Email us', $result);
    }

    public function testJavascriptLinkRendersDisplayOnly(): void {
      $result = $this->getText('<a href="javascript:void(0)">Click</a>');
      $this->assertSame('Click', $result);
    }

    public function testAnchorLinkRendersDisplayOnly(): void {
      $result = $this->getText('<a href="#section">Jump</a>');
      $this->assertSame('Jump', $result);
    }

    public function testArbitraryTagsAreStripped(): void {
      $result = $this->getText('<span class="x"><strong>Bold</strong></span>');
      $this->assertStringNotContainsString('<span', $result);
      $this->assertStringNotContainsString('<strong', $result);
      $this->assertStringContainsStringIgnoringCase('bold', $result);
    }

    public function testHtmlEntitiesAreDecoded(): void {
      $result = $this->getText('AT&amp;T &lt;rocks&gt; &quot;yes&quot;');
      $this->assertSame('AT&T <rocks> "yes"', $result);
    }

    public function testMultipleSpacesCollapsed(): void {
      $result = $this->getText('Hello     world');
      $this->assertSame('Hello world', $result);
    }

    public function testTabsCollapsed(): void {
      $result = $this->getText("Hello\t\tworld");
      $this->assertSame('Hello world', $result);
    }

    public function testExcessiveBlankLinesCollapsedToTwo(): void {
      $result = $this->getText("<p>A</p>\n\n\n\n\n<p>B</p>");
      $this->assertStringNotContainsString("\n\n\n", $result);
      $this->assertStringContainsString('A', $result);
      $this->assertStringContainsString('B', $result);
    }

    public function testResultIsTrimmed(): void {
      $result = $this->getText('   Hello world   ');
      $this->assertSame('Hello world', $result);
    }

    public function testWindowsLineEndingsNormalized(): void {
      $result = $this->getText("Line one\r\nLine two");
      $this->assertStringNotContainsString("\r", $result);
    }

    public function testComplexHtmlDocument(): void {
      $html = <<<HTML
        <head><title>My Page</title><style>body{}</style></head>
        <h1>Welcome</h1>
        <p>Visit <a href="https://example.com">our site</a> or <a href="mailto:hi@example.com">email us</a>.</p>
        <ul>
          <li>Item A</li>
          <li>Item B</li>
        </ul>
        <script>doEvil();</script>
        <p>Footer &copy; 2026</p>
        HTML;

      $result = $this->getText($html);

      $this->assertStringNotContainsString('doEvil', $result);
      $this->assertStringNotContainsString('body{}', $result);
      $this->assertStringNotContainsString('My Page', $result);
      $this->assertStringContainsStringIgnoringCase('welcome', $result);
      $this->assertStringContainsString('our site [https://example.com]', $result);
      $this->assertStringContainsString('email us', $result);
      $this->assertStringNotContainsString('mailto:', $result);
      $this->assertStringContainsString('Item A', $result);
      $this->assertStringContainsString('Item B', $result);
      $this->assertStringContainsString('Footer © 2026', $result);
    }

  }

}
