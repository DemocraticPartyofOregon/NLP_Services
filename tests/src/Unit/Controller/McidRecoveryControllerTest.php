<?php

namespace Drupal\Tests\nlpservices\Unit\Controller {

  use Drupal\nlpservices\Controller\McidRecoveryController;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpMcidRecovery;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class McidRecoveryControllerTest extends UnitTestCase {

    private NlpMcidRecovery $mcidRecoveryMock;
    private MagicWord $magicWordMock;
    private DrupalUser $drupalUserMock;

    protected function setUp(): void {
      parent::setUp();
      $this->mcidRecoveryMock = $this->createMock(NlpMcidRecovery::class);
      $this->magicWordMock    = $this->createMock(MagicWord::class);
      $this->drupalUserMock   = $this->createMock(DrupalUser::class);
    }

    private function reveal(string $key): array {
      $requestStack = new RequestStack();
      $requestStack->push(new Request(['key' => $key]));
      $controller = new McidRecoveryController(
        $this->mcidRecoveryMock,
        $this->magicWordMock,
        $requestStack,
        $this->drupalUserMock,
      );
      $response = $controller->getMagicWord();
      return [$response->getStatusCode(), $response->getContent()];
    }

    public function testMissingKeyIsRejected(): void {
      $this->mcidRecoveryMock->expects($this->never())->method('getMcid');
      $this->assertSame(400, $this->reveal('')[0]);
    }

    public function testUnknownOrExpiredKeyIsRejected(): void {
      $this->mcidRecoveryMock->method('getMcid')->willReturn(0);
      $this->magicWordMock->expects($this->never())->method('getMagicWord');
      $this->assertSame(403, $this->reveal('old-key')[0]);
    }

    public function testActiveNlSeesTheirPasswordOnce(): void {
      $this->mcidRecoveryMock->method('getMcid')->with('good-key')->willReturn(42);
      $this->drupalUserMock->method('getUserByMcid')->with(42)->willReturn(['userName' => 'alice.smith']);
      $this->magicWordMock->method('getMagicWord')->with(42)->willReturn('planet7');
      $this->mcidRecoveryMock->expects($this->once())->method('deleteKey')->with('good-key');

      $this->assertSame([200, 'Your password is: planet7'], $this->reveal('good-key'));
    }

    public function testNlWithoutAnAccountGetsNoPassword(): void {
      // The NL is no longer active, so their account is gone.
      $this->mcidRecoveryMock->method('getMcid')->willReturn(42);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->expects($this->never())->method('getMagicWord');
      $this->mcidRecoveryMock->expects($this->once())->method('deleteKey')->with('sent-earlier');

      [$status, $content] = $this->reveal('sent-earlier');
      $this->assertSame(403, $status);
      $this->assertStringNotContainsString('password is', $content);
    }

    public function testNoSavedPasswordRevealsNothing(): void {
      $this->mcidRecoveryMock->method('getMcid')->willReturn(42);
      $this->drupalUserMock->method('getUserByMcid')->willReturn(['userName' => 'alice.smith']);
      $this->magicWordMock->method('getMagicWord')->willReturn('');

      $this->assertSame(404, $this->reveal('good-key')[0]);
    }

  }

}
