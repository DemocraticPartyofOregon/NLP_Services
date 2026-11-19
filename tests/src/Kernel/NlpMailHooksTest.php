<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\Hook\NlpMailHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks that Drupal finds the mail hook class, which a unit test can't show,
 * and that the activation email setting is turned off.
 */
#[CoversClass(NlpMailHooks::class)]
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class NlpMailHooksTest extends KernelTestBase
{
  use AssertMailTrait;

  protected static $modules = ['system', 'user', 'file', 'nlpservices'];

  protected function setUp(): void
  {
    parent::setUp();
    $this->installConfig(['system', 'user']);
    $this->config('system.site')->set('mail', 'site@example.org')->save();
  }

  public function testMailGoesThroughHookClass(): void
  {
    $this->container->get('plugin.manager.mail')->mail('nlpservices', 'deliver_turf',
      'nl@example.org', 'en', [
        'subject' => 'Your turf',
        'plainText' => 'plain body',
        'message' => '<p>html body</p>',
      ]);

    $mails = $this->getMails();
    $this->assertCount(1, $mails);
    $this->assertSame('Your turf', $mails[0]['subject']);
    $this->assertSame(NlpMailHooks::LIST_UNSUBSCRIBE, $mails[0]['headers']['List-Unsubscribe']);
    $this->assertStringStartsWith('multipart/alternative; boundary=', $mails[0]['headers']['Content-Type']);
  }

  public function testActivationEmailIsTurnedOff(): void
  {
    $this->assertTrue($this->config('user.settings')->get('notify.status_activated'));
    $this->container->get('module_handler')->loadInclude('nlpservices', 'install');
    _nlpservices_disable_activation_email();
    $this->assertFalse($this->config('user.settings')->get('notify.status_activated'));
  }
}
