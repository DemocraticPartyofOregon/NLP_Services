<?php

namespace Drupal\Tests\nlpservices\Unit\Hook;

use Drupal\nlpservices\Hook\NlpMailHooks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(NlpMailHooks::class)]
#[Group('nlpservices')]
class NlpMailHooksTest extends UnitTestCase
{
  private function buildHooks(): NlpMailHooks
  {
    $configFactory = $this->getConfigFactoryStub([
      'system.site' => ['mail' => 'site@example.org'],
    ]);
    return new NlpMailHooks($configFactory);
  }

  private function params(array $extra = []): array
  {
    return $extra + [
      'subject' => 'Your turf',
      'plainText' => 'plain body',
      'message' => '<p>html body</p>',
    ];
  }

  public function testBuildsMultipartMessage(): void
  {
    $message = ['headers' => []];
    $this->buildHooks()->mail('deliver_turf', $message, $this->params());

    $this->assertSame('Your turf', $message['subject']);
    $this->assertSame('site@example.org', $message['from']);
    $this->assertMatchesRegularExpression('/^multipart\/alternative; boundary="(np[0-9a-f]+)"$/',
      $message['headers']['Content-Type']);
    preg_match('/boundary="([^"]+)"/', $message['headers']['Content-Type'], $match);
    $boundary = $match[1];
    $this->assertCount(1, $message['body']);
    $this->assertSame(
      "--$boundary\nContent-Type: text/plain; charset=UTF-8\nplain body"
      . "--$boundary\nContent-Type: text/html; charset=utf-8\n<p>html body</p>"
      . "\n--$boundary--",
      $message['body'][0]);
  }

  public function testUsesFromParamWhenGiven(): void
  {
    $message = ['headers' => []];
    $this->buildHooks()->mail('help_request', $message, $this->params(['from' => 'nl@example.org']));
    $this->assertSame('nl@example.org', $message['from']);
  }

  public function testMergesHeadersButKeepsUnsubscribe(): void
  {
    $message = ['headers' => ['X-Existing' => 'yes']];
    $this->buildHooks()->mail('forward_nl_reply', $message, $this->params(['headers' => [
      'X-Extra' => 'extra',
      'List-Unsubscribe' => '<mailto:someone-else@example.org>',
    ]]));

    $this->assertSame('yes', $message['headers']['X-Existing']);
    $this->assertSame('extra', $message['headers']['X-Extra']);
    $this->assertSame(NlpMailHooks::LIST_UNSUBSCRIBE, $message['headers']['List-Unsubscribe']);
  }

  public function testUnknownKeyOnlyGetsUnsubscribeHeader(): void
  {
    $message = ['headers' => [], 'body' => ['original']];
    $this->buildHooks()->mail('some_other_key', $message, []);

    $this->assertSame(['List-Unsubscribe' => NlpMailHooks::LIST_UNSUBSCRIBE], $message['headers']);
    $this->assertSame(['original'], $message['body']);
  }
}
