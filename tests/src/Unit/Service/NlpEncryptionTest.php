<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\NlpEncryption;
  use Drupal\Tests\UnitTestCase;

  class NlpEncryptionTest extends UnitTestCase {

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpEncryption {
      return new NlpEncryption();
    }

    // -------------------------------------------------------------------------
    // encrypt_decrypt — empty input
    // -------------------------------------------------------------------------

    public function testEncryptEmptyStringReturnsEmptyString(): void {
      $this->assertSame('', $this->buildService()->encrypt_decrypt('encrypt', ''));
    }

    public function testDecryptEmptyStringReturnsEmptyString(): void {
      $this->assertSame('', $this->buildService()->encrypt_decrypt('decrypt', ''));
    }

    // -------------------------------------------------------------------------
    // encrypt_decrypt — encrypt
    // -------------------------------------------------------------------------

    public function testEncryptReturnsNonEmptyString(): void {
      $result = $this->buildService()->encrypt_decrypt('encrypt', 'hello');
      $this->assertNotEmpty($result);
    }

    public function testEncryptOutputDiffersFromInput(): void {
      $input = 'hello world';
      $result = $this->buildService()->encrypt_decrypt('encrypt', $input);
      $this->assertNotSame($input, $result);
    }

    public function testEncryptOutputIsBase64Encoded(): void {
      $result = $this->buildService()->encrypt_decrypt('encrypt', 'hello');
      $this->assertNotFalse(base64_decode($result, true));
    }

    public function testEncryptProducesDifferentOutputForDifferentInputs(): void {
      $service = $this->buildService();
      $a = $service->encrypt_decrypt('encrypt', 'hello');
      $b = $service->encrypt_decrypt('encrypt', 'world');
      $this->assertNotSame($a, $b);
    }

    public function testEncryptProducesSameOutputForSameInput(): void {
      $service = $this->buildService();
      $a = $service->encrypt_decrypt('encrypt', 'consistent');
      $b = $service->encrypt_decrypt('encrypt', 'consistent');
      $this->assertSame($a, $b);
    }

    // -------------------------------------------------------------------------
    // encrypt_decrypt — decrypt
    // -------------------------------------------------------------------------

    public function testDecryptRestoresOriginalString(): void {
      $service = $this->buildService();
      $original  = 'hello world';
      $encrypted = $service->encrypt_decrypt('encrypt', $original);
      $decrypted = $service->encrypt_decrypt('decrypt', $encrypted);
      $this->assertSame($original, $decrypted);
    }

    public function testRoundTripPreservesSpecialCharacters(): void {
      $service = $this->buildService();
      $original  = 'p@$$w0rd!#%&*()';
      $encrypted = $service->encrypt_decrypt('encrypt', $original);
      $decrypted = $service->encrypt_decrypt('decrypt', $encrypted);
      $this->assertSame($original, $decrypted);
    }

    public function testRoundTripPreservesLongString(): void {
      $service = $this->buildService();
      $original  = str_repeat('abcdefghij', 50);
      $encrypted = $service->encrypt_decrypt('encrypt', $original);
      $decrypted = $service->encrypt_decrypt('decrypt', $encrypted);
      $this->assertSame($original, $decrypted);
    }

    // -------------------------------------------------------------------------
    // encrypt_decrypt — unknown action
    // -------------------------------------------------------------------------

    public function testUnknownActionReturnsEmptyString(): void {
      $this->assertSame('', $this->buildService()->encrypt_decrypt('invalid', 'hello'));
    }

  }

}
