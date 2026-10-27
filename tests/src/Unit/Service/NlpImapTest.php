<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpImap;
  use Drupal\Tests\UnitTestCase;
  use stdClass;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  /**
   * Exposes protected methods for unit testing without a live IMAP connection.
   */
  class TestableNlpImap extends NlpImap {

    public function parseMailHeadersPublic(string $headers): array {
      return $this->parseMailHeadersIntoArray($headers);
    }

    public function mailDecodePartPublic(int $messageNumber, object $part, string $prefix): array {
      return $this->mailDecodePart($messageNumber, $part, $prefix);
    }

  }

  class NlpImapTest extends UnitTestCase {

    private NlpConfiguration $configMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configMock = $this->createMock(NlpConfiguration::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpImap {
      return new NlpImap($this->configMock);
    }

    private function buildTestableService(): TestableNlpImap {
      return new TestableNlpImap($this->configMock);
    }

    /**
     * Builds a minimal stdClass part object for mailDecodePart tests.
     * Extra keys in $props are set directly on the object (e.g. 'bytes', 'subtype').
     * $part->parts is intentionally NOT set unless included in $props, so the
     * large-size early-return branch is triggered when bytes > MAX_MAIL_PART_SIZE_BYTES.
     */
    private function makePart(array $props = []): object {
      $part = new stdClass();
      $part->type           = $props['type']           ?? 0;
      $part->ifsubtype      = $props['ifsubtype']      ?? 0;
      $part->ifdescription  = $props['ifdescription']  ?? 0;
      $part->ifdparameters  = $props['ifdparameters']  ?? 0;
      $part->ifparameters   = $props['ifparameters']   ?? 0;
      foreach ($props as $key => $value) {
        $part->$key = $value;
      }
      return $part;
    }

    // -------------------------------------------------------------------------
    // Public properties
    // -------------------------------------------------------------------------

    public function testMinivanSubjectsContainsThreeExportKeys(): void {
      $service = $this->buildService();
      $this->assertArrayHasKey('nlpresponsesexport',    $service->minivanSubjects);
      $this->assertArrayHasKey('nlpcontactsexport',     $service->minivanSubjects);
      $this->assertArrayHasKey('nlpactivistcodesexport', $service->minivanSubjects);
    }

    public function testMinivanSubjectsMapsToCorrectFileTypes(): void {
      $service = $this->buildService();
      $this->assertSame('survey',   $service->minivanSubjects['nlpresponsesexport']);
      $this->assertSame('canvass',  $service->minivanSubjects['nlpcontactsexport']);
      $this->assertSame('activist', $service->minivanSubjects['nlpactivistcodesexport']);
    }

    public function testMatchbackSubjectContainsMatchbackKey(): void {
      $service = $this->buildService();
      $this->assertArrayHasKey('matchback', $service->matchbackSubject);
      $this->assertSame('matchback', $service->matchbackSubject['matchback']);
    }

    // -------------------------------------------------------------------------
    // getImapConnection — early-return paths (no imap calls)
    // -------------------------------------------------------------------------

    public function testGetImapConnectionReturnsFalseWhenEmailConfigIsEmpty(): void {
      $this->configMock->method('getEmailConfiguration')->willReturn([]);
      $this->assertFalse($this->buildService()->getImapConnection('minivan'));
    }

    // -------------------------------------------------------------------------
    // closeConnection — null connection guard
    // -------------------------------------------------------------------------

    public function testCloseConnectionDoesNotThrowWhenConnectionIsNull(): void {
      $this->buildService()->closeConnection();
      $this->assertTrue(true);  // reaching here means no exception was thrown
    }

    // -------------------------------------------------------------------------
    // moveEmailToTrash — null connection guard
    // -------------------------------------------------------------------------

    public function testMoveEmailToTrashDoesNotThrowWhenConnectionIsNull(): void {
      $this->buildService()->moveEmailToTrash(1);
      $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // setReadAndFlagged — null connection guard
    // -------------------------------------------------------------------------

    public function testSetReadAndFlaggedReturnsFalseWhenConnectionIsNull(): void {
      $this->assertFalse($this->buildService()->setReadAndFlagged(1));
    }

    // -------------------------------------------------------------------------
    // testImapConnection — live call with bad credentials
    // imap_open emits warnings that Symfony's DeprecationErrorHandler would
    // promote to exceptions; suppress them with a custom error handler.
    // -------------------------------------------------------------------------

    public function testTestImapConnectionReturnsErrorResultWithBadCredentials(): void {
      $emailInfo = ['server' => '127.0.0.1', 'port' => 1, 'email' => 'x@x.com', 'password' => 'x'];
      set_error_handler(fn() => true);
      $result = $this->buildService()->testImapConnection($emailInfo);
      restore_error_handler();
      $this->assertSame('error', $result['result']);
    }

    public function testTestImapConnectionIncludesMessageKeyOnFailure(): void {
      $emailInfo = ['server' => '127.0.0.1', 'port' => 1, 'email' => 'x@x.com', 'password' => 'x'];
      set_error_handler(fn() => true);
      $result = $this->buildService()->testImapConnection($emailInfo);
      restore_error_handler();
      $this->assertArrayHasKey('message', $result);
      $this->assertIsString($result['message']);
    }

    // -------------------------------------------------------------------------
    // parseMailHeadersIntoArray
    // -------------------------------------------------------------------------

    public function testParseMailHeadersReturnsEmptyArrayForEmptyString(): void {
      $this->assertSame([], $this->buildTestableService()->parseMailHeadersPublic(''));
    }

    public function testParseMailHeadersParsesSimpleSingleHeader(): void {
      $result = $this->buildTestableService()->parseMailHeadersPublic("From: sender@example.com\r\n");
      $this->assertSame('sender@example.com', $result['From']);
    }

    public function testParseMailHeadersParsesMultipleHeaders(): void {
      $input = "From: sender@example.com\r\nSubject: Hello World\r\n";
      $result = $this->buildTestableService()->parseMailHeadersPublic($input);
      $this->assertSame('sender@example.com', $result['From']);
      $this->assertSame('Hello World', $result['Subject']);
    }

    public function testParseMailHeadersPreservesHeaderNameCase(): void {
      $result = $this->buildTestableService()->parseMailHeadersPublic("Content-Type: text/plain\r\n");
      $this->assertArrayHasKey('Content-Type', $result);
      $this->assertSame('text/plain', $result['Content-Type']);
    }

    public function testParseMailHeadersFoldsMultiLineContinuation(): void {
      // RFC 2822: continuation lines start with whitespace and are folded onto the previous header
      $input = "Subject: Long subject\r\n that continues\r\n";
      $result = $this->buildTestableService()->parseMailHeadersPublic($input);
      $this->assertArrayHasKey('Subject', $result);
      $this->assertStringContainsString('Long subject', $result['Subject']);
    }

    public function testParseMailHeadersIgnoresLineThatHasNoColon(): void {
      // A bad line AFTER a valid header doesn't absorb the valid header's name,
      // and it doesn't appear as a standalone key in the result.
      $input = "From: valid@example.com\r\nBadLine\r\n";
      $result = $this->buildTestableService()->parseMailHeadersPublic($input);
      $this->assertArrayHasKey('From', $result);
      $this->assertArrayNotHasKey('BadLine', $result);
    }

    // -------------------------------------------------------------------------
    // mailDecodePart — large-part early-return branch
    // Tests use parts with bytes > MAX_MAIL_PART_SIZE_BYTES and no sub-parts,
    // so the method returns before reaching imap_fetchbody().
    // -------------------------------------------------------------------------

    public function testMailDecodePartReturnsDataKeyWhenBytesExceedLimit(): void {
      $part   = $this->makePart(['bytes' => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertArrayHasKey('data', $result);
    }

    public function testMailDecodePartSetsTypeToTextForTypeZero(): void {
      $part   = $this->makePart(['type' => 0, 'bytes' => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertSame('Text', $result['type']);
    }

    public function testMailDecodePartUsesRawTypeIntForUnknownType(): void {
      $part   = $this->makePart(['type' => 99, 'bytes' => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertSame(99, $result['type']);
    }

    public function testMailDecodePartIncludesSubtypeWhenIfsubtypeIsSet(): void {
      $part = $this->makePart([
        'type'       => 0,
        'ifsubtype'  => 1,
        'subtype'    => 'PLAIN',
        'bytes'      => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1,
      ]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertSame('PLAIN', $result['subtype']);
    }

    public function testMailDecodePartOmitsSubtypeWhenIfsubtypeIsZero(): void {
      $part   = $this->makePart(['type' => 0, 'bytes' => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertArrayNotHasKey('subtype', $result);
    }

    public function testMailDecodePartIncludesDescriptionWhenIfdescriptionIsSet(): void {
      $part = $this->makePart([
        'type'          => 0,
        'ifdescription' => 1,
        'description'   => 'An attachment',
        'bytes'         => NlpImap::MAX_MAIL_PART_SIZE_BYTES + 1,
      ]);
      $result = $this->buildTestableService()->mailDecodePartPublic(1, $part, '1');
      $this->assertSame('An attachment', $result['description']);
    }

  }

}
