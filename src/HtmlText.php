<?php

namespace Drupal\nlpservices;

class HtmlText {
  
  const ENCODING = 'UTF-8';
  
  protected int $htmlFuncFlags;
  
  /**
   * Contains the HTML content to convert.
   *
   * @var string $html
   */
  protected string $html;
  
  /**
   * Contains the converted, formatted text.
   *
   * @var string $text
   */
  protected string $text;
  
  /**
   * List of preg* regular expression patterns to search for,
   * used in conjunction with $replace.
   *
   * @var array $search
   * @see $replace
   */
  protected array $search = array(
    "/\r/",                                           // Non-legal carriage return
    "/[\n\t]+/",                                      // Newlines and tabs
    '/<head\b[^>]*>.*?<\/head>/i',                    // <head>
    '/<script\b[^>]*>.*?<\/script>/i',                // <script>s -- which strip_tags supposedly has problems with
    '/<style\b[^>]*>.*?<\/style>/i',                  // <style>s -- which strip_tags supposedly has problems with
    '/<i\b[^>]*>(.*?)<\/i>/i',                        // <i>
    '/<em\b[^>]*>(.*?)<\/em>/i',                      // <em>
    '/<ins\b[^>]*>(.*?)<\/ins>/i',                    // <ins>
    '/(<ul\b[^>]*>|<\/ul>)/i',                        // <ul> and </ul>
    '/(<ol\b[^>]*>|<\/ol>)/i',                        // <ol> and </ol>
    '/(<dl\b[^>]*>|<\/dl>)/i',                        // <dl> and </dl>
    '/<li\b[^>]*>(.*?)<\/li>/i',                      // <li> and </li>
    '/<dd\b[^>]*>(.*?)<\/dd>/i',                      // <dd> and </dd>
    '/<dt\b[^>]*>(.*?)<\/dt>/i',                      // <dt> and </dt>
    '/<li\b[^>]*>/i',                                 // <li>
    '/<hr\b[^>]*>/i',                                 // <hr>
    '/<div\b[^>]*>/i',                                // <div>
    '/(<table\b[^>]*>|<\/table>)/i',                  // <table> and </table>
    '/(<tr\b[^>]*>|<\/tr>)/i',                        // <tr> and </tr>
    '/<td\b[^>]*>(.*?)<\/td>/i',                      // <td> and </td>
    '/<span class="_html2text_ignore">.+?<\/span>/i', // <span class="_html2text_ignore">...</span>
    '/<(img)\b[^>]*alt=\"([^>"]+)\"[^>]*>/i',         // <img> with alt tag
  );
  
  /**
   * List of pattern replacements corresponding to pattern searched.
   *
   * @var array $replace
   * @see $search
   */
  protected array $replace = array(
    '',                              // Non-legal carriage return
    ' ',                             // Newlines and tabs
    '',                              // <head>
    '',                              // <script>s -- which strip_tags supposedly has problems with
    '',                              // <style>s -- which strip_tags supposedly has problems with
    '_\\1_',                         // <i>
    '_\\1_',                         // <em>
    '_\\1_',                         // <ins>
    "\n\n",                          // <ul> and </ul>
    "\n\n",                          // <ol> and </ol>
    "\n\n",                          // <dl> and </dl>
    "\t* \\1\n",                     // <li> and </li>
    " \\1\n",                        // <dd> and </dd>
    "\t* \\1",                       // <dt> and </dt>
    "\n\t* ",                        // <li>
    "\n-------------------------\n", // <hr>
    "<div>\n",                       // <div>
    "\n\n",                          // <table> and </table>
    "\n",                            // <tr> and </tr>
    "\t\t\\1\n",                     // <td> and </td>
    "",                              // <span class="_html2text_ignore">...</span>
    '[\\2]',                         // <img> with alt tag
  );
  
  /**
   * List of preg* regular expression patterns to search for,
   * used in conjunction with $entReplace.
   *
   * @var array $entSearch
   * @see $entReplace
   */
  protected array $entSearch = array(
    '/&#153;/',                                     // Trademark symbol in win-1252
    '/&#151;/',                                     // m-dash in win-1252
    '/&(amp|#38);/i',                                // Ampersand: see converter()
    '/ {2,}/',                                     // Runs of spaces, post-handling
    '/&#39;/',                                      // The apostrophe symbol
  );
  
  /**
   * List of pattern replacements corresponding to pattern searched.
   *
   * @var array $entReplace
   * @see $entSearch
   */
  protected array $entReplace = array(
    '™',         // TM symbol
    '—',         // m-dash
    '|+|amp|+|', // Ampersand: see converter()
    ' ',         // Runs of spaces, post-handling
    '\'',        // Apostrophe
  );
  
  /**
   * List of preg* regular expression patterns to search for
   * and replace using callback function.
   *
   * @var array $callbackSearch
   */
  protected array $callbackSearch = array(
    '/<(h)[123456]( [^>]*)?>(.*?)<\/h[123456]>/i',           // h1 - h6
    '/ *<(p)( [^>]*)?>(.*?)<\/p> */si',                  // <p> with surrounding whitespace.
    '/<(br)[^>]*> */i',                                    // <br> with leading whitespace after the newline.
    '/<(b)( [^>]*)?>(.*?)<\/b>/i',                           // <b>
    '/<(strong)( [^>]*)?>(.*?)<\/strong>/i',                 // <strong>
    '/<(del)( [^>]*)?>(.*?)<\/del>/i',                       // <del>
    '/<(th)( [^>]*)?>(.*?)<\/th>/i',                         // <th> and </th>
    '/<(a) [^>]*href=(["\'])([^"\']+)\2([^>]*)>(.*?)<\/a>/i'  // <a href="">
  );
  
  /**
   * List of preg* regular expression patterns to search for in PRE body,
   * used in conjunction with $preReplace.
   *
   * @var array $preSearch
   * @see $preReplace
   */
  protected array $preSearch = array(
    "/\n/",
    "/\t/",
    '/ /',
    '/<pre[^>]*>/',
    '/<\/pre>/'
  );
  
  /**
   * List of pattern replacements corresponding to pattern searched for PRE body.
   *
   * @var array $preReplace
   * @see $preSearch
   */
  protected array $preReplace = array(
    '<br>',
    '&nbsp;&nbsp;&nbsp;&nbsp;',
    '&nbsp;',
    '',
    '',
  );
  
  /**
   * Temporary workspace used during PRE processing.
   *
   * @var string $preContent
   */
  protected string $preContent = '';
  
  /**
   * Contains the base URL that relative links should resolve to.
   *
   * @var string $baseurl
   */
  protected string $baseurl = '';
  
  /**
   * Indicates whether content in the $html variable has been converted yet.
   *
   * @var boolean $converted
   * @see $html, $text
   */
  protected bool $converted = false;
  
  /**
   * Contains URL addresses from links to be rendered in plain text.
   *
   * @var array $linkList
   * @see buildLinkList()
   */
  protected array $linkList = array();
  
  /**
   * Various configuration options (able to be set in the constructor)
   *
   * @var array $options
   */
  protected array $options = array(
    'do_links' => 'inline', // 'none'
    // 'inline' (show links inline)
    // 'nextline' (show links on the next line)
    // 'table' (if a table of link URLs should be listed after the text).
    // 'bbcode' (show links as bbcode)
    
    'width' => 70,          //  Maximum width of the formatted text, in columns.
    //  Set this value to 0 (or less) to ignore word wrapping
    //  and not constrain text to a fixed-width column.
  );
  
  /**
   * @param string $html    Source HTML
   * @param array $options Set configuration options
   */
  public function __construct(string $html = '', array $options = array())
  {
    $this->html = $html;
    $this->options = array_merge($this->options, $options);
    $this->htmlFuncFlags = (PHP_VERSION_ID < 50400)
      ? ENT_COMPAT
      : ENT_COMPAT | ENT_HTML5;
  }
  
  /**
   * Get the source HTML
   *
   * @return string
   */
  public function getHtml(): string
  {
    return $this->html;
  }
  
  /**
   * Set the source HTML
   *
   * @param string $html HTML source content
   */
  public function setHtml(string $html): void
  {
    $this->html = $html;
    $this->converted = false;
  }

  
  /**
   * Returns the text, converted from HTML.
   *
   * @return string Plain text
   */
  public function getText(): string
  {
    if (!$this->converted) {
      $this->convert();
    }
    
    return $this->text;
  }

  
  protected function convert(): void
  {
    $origEncoding = mb_internal_encoding();
    mb_internal_encoding(self::ENCODING);
    
    $this->doConvert();
    
    mb_internal_encoding($origEncoding);
  }
  
  protected function doConvert(): void
  {
    $this->linkList = array();
    
    $text = trim($this->html);
    
    $this->converter($text);
    
    if ($this->linkList) {
      $text .= "\n\nLinks:\n------\n";
      foreach ($this->linkList as $i => $url) {
        $text .= '[' . ($i + 1) . '] ' . $url . "\n";
      }
    }
    
    $this->text = $text;
    
    $this->converted = true;
  }
  
  protected function converter(&$text): void
  {
    $this->convertBlockquotes($text);
    $this->convertPre($text);
    $text = preg_replace($this->search, $this->replace, $text);
    $text = preg_replace_callback($this->callbackSearch, array($this, 'pregCallback'), $text);
    $text = strip_tags($text);
    $text = preg_replace($this->entSearch, $this->entReplace, $text);
    $text = html_entity_decode($text, $this->htmlFuncFlags, self::ENCODING);
    
    // Remove unknown/unhandled entities (this cannot be done in search-and-replace block)
    $text = preg_replace('/&([a-zA-Z0-9]{2,6}|#[0-9]{2,4});/', '', $text);
    
    // Convert "|+|amp|+|" into "&", need to be done after handling of unknown entities
    // This properly handles situation of "&amp;quot;" in input string
    $text = str_replace('|+|amp|+|', '&', $text);
    
    // Normalise empty lines
    $text = preg_replace("/\n\s+\n/", "\n\n", $text);
    $text = preg_replace("/[\n]{3,}/", "\n\n", $text);
    
    // remove leading empty lines (can be produced by e.g. P tag on the beginning)
    $text = ltrim($text, "\n");
    
    if ($this->options['width'] > 0) {
      $text = wordwrap($text, $this->options['width']);
    }
  }
  
  /**
   * Helper function called by preg_replace() on link replacement.
   *
   * Maintains an internal list of links to be displayed at the end of the
   * text, with numeric indices to the original point in the text they
   * appeared. Also makes an effort at identifying and handling absolute
   * and relative links.
   *
   * @param string $link URL of the link
   * @param string $display Part of the text to associate number with
   * @param null $linkOverride
   * @return string
   */
  protected function buildLinkList(string $link, string $display, $linkOverride = null): string
  {
    $linkMethod = ($linkOverride) ?: $this->options['do_links'];
    if ($linkMethod == 'none') {
      return $display;
    }
    
    // Ignored link types
    if (preg_match('!^(javascript:|mailto:|#)!i', html_entity_decode($link))) {
      return $display;
    }
    
    if (preg_match('!^([a-z][a-z0-9.+-]+:)!i', $link)) {
      $url = $link;
    } else {
      $url = $this->baseurl;
      if (mb_substr($link, 0, 1) != '/') {
        $url .= '/';
      }
      $url .= $link;
    }
    
    if ($linkMethod == 'table') {
      if (($index = array_search($url, $this->linkList)) === false) {
        $index = count($this->linkList);
        $this->linkList[] = $url;
      }
      
      return $display . ' [' . ($index + 1) . ']';
    } elseif ($linkMethod == 'nextline') {
      if ($url === $display) {
        return $display;
      }
      return $display . "\n[" . $url . ']';
    } elseif ($linkMethod == 'bbcode') {
      return sprintf('[url=%s]%s[/url]', $url, $display);
    } else { // link_method defaults to inline
      if ($url === $display) {
        return $display;
      }
      return $display . ' [' . $url . ']';
    }
  }
  
  /**
   * Helper function for PRE body conversion.
   *
   * @param string &$text HTML content
   */
  protected function convertPre(string &$text): void
  {
    // get the content of PRE element
    while (preg_match('/<pre[^>]*>(.*)<\/pre>/ismU', $text, $matches)) {
      // Replace br tags with newlines to prevent the search-and-replace callback from killing whitespace
      $this->preContent = preg_replace('/(<br\b[^>]*>)/i', "\n", $matches[1]);
      
      // Run our defined tags search-and-replace with callback
      $this->preContent = preg_replace_callback(
        $this->callbackSearch,
        array($this, 'pregCallback'),
        $this->preContent
      );
      
      // convert the content
      $this->preContent = sprintf(
        '<div><br>%s<br></div>',
        preg_replace($this->preSearch, $this->preReplace, $this->preContent)
      );
      
      // replace the content (use callback because content can contain $0 variable)
      $text = preg_replace_callback(
        '/<pre[^>]*>.*<\/pre>/ismU',
        array($this, 'pregPreCallback'),
        $text,
        1
      );
      
      // free memory
      $this->preContent = '';
    }
  }
  
  /**
   * Helper function for BLOCKQUOTE body conversion.
   *
   * @param string &$text HTML content
   */
  protected function convertBlockquotes(string &$text): void
  {
    if (preg_match_all('/<\/*blockquote[^>]*>/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
      $originalText = $text;
      $start = 0;
      $tagLength = 0;
      $level = 0;
      $diff = 0;
      foreach ($matches[0] as $m) {
        $m[1] = mb_strlen(substr($originalText, 0, $m[1]));
        if ($m[0][0] == '<' && $m[0][1] == '/') {
          $level--;
          if ($level < 0) {
            $level = 0; // malformed HTML: go to next blockquote
          } elseif ($level <= 0) {
            $end = $m[1];
            $len = $end - $tagLength - $start;
            // Get blockquote content
            $body = mb_substr($text, $start + $tagLength - $diff, $len);
            
            // Set text width
            $pWidth = $this->options['width'];
            if ($this->options['width'] > 0) $this->options['width'] -= 2;
            // Convert blockquote content
            $body = trim($body);
            $this->converter($body);
            // Add citation markers and create PRE block
            $body = preg_replace('/((^|\n)>*)/', '\\1> ', trim($body));
            $body = '<pre>' . htmlspecialchars($body, $this->htmlFuncFlags, self::ENCODING) . '</pre>';
            // Re-set text width
            $this->options['width'] = $pWidth;
            // Replace content
            $text = mb_substr($text, 0, $start - $diff)
              . $body
              . mb_substr($text, $end + mb_strlen($m[0]) - $diff);
            
            $diff += $len + $tagLength + mb_strlen($m[0]) - mb_strlen($body);
            unset($body);
          }
        } else {
          if ($level == 0) {
            $start = $m[1];
            $tagLength = mb_strlen($m[0]);
          }
          $level++;
        }
      }
    }
  }
  
  /**
   * Callback function for preg_replace_callback use.
   *
   * @param array $matches PREG matches
   * @return string
   */
  protected function pregCallback(array $matches): string
  {
    switch (mb_strtolower($matches[1])) {
      case 'p':
        // Replace newlines with spaces.
        $para = str_replace("\n", " ", $matches[3]);
        
        // Trim trailing and leading whitespace within the tag.
        $para = trim($para);
        
        // Add trailing newlines for this para.
        return "\n" . $para . "\n";
      case 'br':
        return "\n";
      case 'b':
      case 'strong':
        return $this->to_upper($matches[3]);
      case 'del':
        return $this->to_strike($matches[3]);
      case 'th':
        return $this->to_upper("\t\t" . $matches[3] . "\n");
      case 'h':
        return $this->to_upper("\n\n" . $matches[3] . "\n\n");
      case 'a':
        // override the link method
        $linkOverride = null;
        if (preg_match('/_html2text_link_(\w+)/', $matches[4], $linkOverrideMatch)) {
          $linkOverride = $linkOverrideMatch[1];
        }
        // Remove spaces in URL (#1487805)
        $url = str_replace(' ', '', $matches[3]);
        
        return $this->buildLinkList($url, $matches[5], $linkOverride);
    }
    
    return '';
  }
  
  /**
   * Callback function for preg_replace_callback use in PRE content handler.
   *
   * @param array $matches PREG matches
   * @return string
   */
  protected function pregPreCallback(/** @noinspection PhpUnusedParameterInspection */ array $matches): string
  {
    return $this->preContent;
  }
  
  /**
   * to_upper function with HTML tags and entities handling.
   *
   * @param string $str Text to convert
   * @return string Converted text
   */
  protected function to_upper(string $str): string
  {
    // string can contain HTML tags
    $chunks = preg_split('/(<[^>]*>)/', $str, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
    
    // convert to_upper only the text between HTML tags
    foreach ($chunks as $i => $chunk) {
      if ($chunk[0] != '<') {
        $chunks[$i] = $this->str_to_upper($chunk);
      }
    }
    
    return implode($chunks);
  }
  
  /**
   * str_to_upper multibyte wrapper function with HTML entities handling.
   *
   * @param string $str Text to convert
   * @return string Converted text
   */
  protected function str_to_upper(string $str): string
  {
    $str = html_entity_decode($str, $this->htmlFuncFlags, self::ENCODING);
    $str = mb_strtoupper($str);
    return htmlspecialchars($str, $this->htmlFuncFlags, self::ENCODING);
  }
  
  /**
   * Helper function for DEL conversion.
   *
   * @param string $str HTML content
   * @return string Converted text
   */
  protected function to_strike(string $str): string
  {
    $rtn = '';
    for ($i = 0; $i < mb_strlen($str); $i++) {
      $chr = mb_substr($str, $i, 1);
      $combiningChr = chr(0xC0 | 0x336 >> 6). chr(0x80 | 0x336 & 0x3F);
      $rtn .= $chr . $combiningChr;
    }
    return $rtn;
  }
}