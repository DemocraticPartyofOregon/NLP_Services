<?php

namespace Drupal\Tests\nlpservices\Unit\Config {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Extension\Requirement\RequirementSeverity;
  use Drupal\Core\Site\Settings;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\Tests\UnitTestCase;

  /**
   * Tests the Status report entries from nlpservices_requirements().
   */
  class NlpservicesRequirementsTest extends UnitTestCase {

    private NlpSendEmail $sendEmailMock;

    protected function setUp(): void {
      parent::setUp();
      require_once __DIR__ . '/../../../../nlpservices.install';
      // The private file path is set, so only the email entry is in question.
      new Settings(['file_private_path' => '/private']);
      $this->sendEmailMock = $this->createMock(NlpSendEmail::class);
      $container = new ContainerBuilder();
      $container->set('nlpservices.send_email', $this->sendEmailMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    public function testStatusReportShowsHtmlEmailAsOk(): void {
      $this->sendEmailMock->method('htmlMailProblem')->willReturn('');

      $entry = nlpservices_requirements('runtime')['nlpservices_html_email'];

      $this->assertSame(RequirementSeverity::OK, $entry['severity']);
      $this->assertSame('HTML', (string) $entry['value']);
      $this->assertArrayNotHasKey('description', $entry);
    }

    public function testStatusReportWarnsAboutPlainTextEmailWithTheReason(): void {
      $this->sendEmailMock->method('htmlMailProblem')
        ->willReturn('The SMTP module is set to send emails as plain text.');

      $entry = nlpservices_requirements('runtime')['nlpservices_html_email'];

      $this->assertSame(RequirementSeverity::Warning, $entry['severity']);
      $this->assertSame('Plain text', (string) $entry['value']);
      $this->assertSame('The SMTP module is set to send emails as plain text.', $entry['description']);
    }

    public function testInstallPhaseDoesNotCheckTheEmailFormat(): void {
      // The module's services don't exist before it's installed.
      $this->sendEmailMock->expects($this->never())->method('htmlMailProblem');
      $this->assertArrayNotHasKey('nlpservices_html_email', nlpservices_requirements('install'));
    }

  }

}
