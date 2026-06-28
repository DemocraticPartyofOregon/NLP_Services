<?php

namespace Drupal\nlpservices;

class HtmlText {

  protected string $html = '';

  public function setHtml(string $html): void
  {
    $this->html = $html;
  }

  public function getText(): string
  {
    $text = $this->html;

    // Remove <head>, <script>, <style> blocks entirely.
    $text = preg_replace('/<(head|script|style)\b[^>]*>.*?<\/\1>/is', '', $text);

    // Convert block-level elements to newlines.
    $text = preg_replace('/<(\/?(p|div|h[1-6]|ul|ol|li|table|tr))\b[^>]*>/i', "\n", $text);

    // <br> and <hr> become newlines.
    $text = preg_replace('/<br\b[^>]*>/i', "\n", $text);
    $text = preg_replace('/<hr\b[^>]*>/i', "\n-------------------------\n", $text);

    // Render links as "display text [url]", skip mailto/javascript/anchors.
    $text = preg_replace_callback(
      '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
      static function (array $m): string {
        $url = $m[1];
        $display = strip_tags($m[2]);
        if (preg_match('/^(mailto:|javascript:|#)/i', $url)) {
          return $display;
        }
        return $display === $url ? $display : "$display [$url]";
      },
      $text
    );

    // Strip remaining tags.
    $text = strip_tags($text);

    // Decode HTML entities.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse horizontal whitespace within lines, normalize line endings.
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\r\n?/', "\n", $text);

    // Collapse more than two consecutive blank lines.
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
  }

}
