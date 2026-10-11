<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpDataEntryHelper;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryHelperTest extends UnitTestCase {

    private NlpMatchbacks $matchbacksMock;
    private NlpConfiguration $configMock;
    private ModuleExtensionList $moduleListMock;

    protected function setUp(): void {
      parent::setUp();
      $this->matchbacksMock = $this->createMock(NlpMatchbacks::class);
      $this->configMock     = $this->createMock(NlpConfiguration::class);
      $this->moduleListMock = $this->createMock(ModuleExtensionList::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('extension.list.module', $this->moduleListMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpDataEntryHelper {
      return new NlpDataEntryHelper($this->matchbacksMock, $this->configMock, $this->moduleListMock);
    }

    private function setupStandardMocks(string $latestMatchbackDate = ''): void {
      $this->moduleListMock->method('getExtensionInfo')
        ->willReturn(['version' => '7.2.0']);
      $this->configMock->method('getElectionDate')->willReturn('November 5, 2024');
      $this->configMock->method('getBallotDropDate')->willReturn('October 15, 2024');
      $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn($latestMatchbackDate);
    }

    // -------------------------------------------------------------------------
    // dataEntryHelp
    // -------------------------------------------------------------------------

    public function testDataEntryHelpContainsVersionNumber(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('7.2.0', $output);
    }

    public function testDataEntryHelpDefaultsVersionToZeroWhenMissing(): void {
      $this->moduleListMock->method('getExtensionInfo')->willReturn([]);
      $this->configMock->method('getElectionDate')->willReturn('November 5, 2024');
      $this->configMock->method('getBallotDropDate')->willReturn('October 15, 2024');
      $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('Version 0', $output);
    }

    public function testDataEntryHelpEscapesVersionToPreventXss(): void {
      $this->moduleListMock->method('getExtensionInfo')
        ->willReturn(['version' => '<script>alert(1)</script>']);
      $this->configMock->method('getElectionDate')->willReturn('November 5, 2024');
      $this->configMock->method('getBallotDropDate')->willReturn('October 15, 2024');
      $this->matchbacksMock->method('getLatestMatchbackDate')->willReturn('');
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringNotContainsString('<script>', $output);
      $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testDataEntryHelpContainsElectionDate(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('November 5, 2024', $output);
    }

    public function testDataEntryHelpContainsBallotDropDate(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('October 15, 2024', $output);
    }

    public function testDataEntryHelpContainsHelpfulHintsHeading(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('Helpful hints', $output);
    }

    public function testDataEntryHelpContainsTurfRefreshSection(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('Turf Refresh', $output);
    }

    public function testDataEntryHelpIncludesBallotReceivedDateWhenLatestDatePresent(): void {
      $this->setupStandardMocks('2024-10-20');
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('latest date reported for a ballot received', $output);
    }

    public function testDataEntryHelpFormatsLatestBallotDateAsReadableString(): void {
      $this->setupStandardMocks('2024-10-20');
      $output = $this->buildService()->dataEntryHelp();
      // date('l jS \of F Y', ...) produces e.g., "Sunday 20th of October 2024"
      $this->assertStringContainsString('October 2024', $output);
    }

    public function testDataEntryHelpOmitsBallotReceivedSectionWhenNoLatestDate(): void {
      $this->setupStandardMocks('');
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringNotContainsString('latest date reported for a ballot received', $output);
    }

    public function testDataEntryHelpShowsNoneReceivedYetForInvalidDate(): void {
      $this->setupStandardMocks('not-a-valid-date');
      $output = $this->buildService()->dataEntryHelp();
      $this->assertStringContainsString('None received yet', $output);
    }

    public function testDataEntryHelpReturnsString(): void {
      $this->setupStandardMocks();
      $output = $this->buildService()->dataEntryHelp();
      $this->assertIsString($output);
      $this->assertNotEmpty($output);
    }

  }

}
