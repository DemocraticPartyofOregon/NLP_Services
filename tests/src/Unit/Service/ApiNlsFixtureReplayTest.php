<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  /**
   * Replays real sandbox responses (captured by
   * tests/bin/record-api-fixtures.php) through ApiNls::getApiNls() and diffs
   * the result against a frozen snapshot of what this module's own parsing
   * produced for that exact response.
   *
   * See ApiVoterFixtureReplayTest for the full rationale - ApiNls hits the
   * same VAN-style API as ApiVoter (per the module's own comments: MyCampaign
   * rather than the VoterFile, selected via the |1 database-mode suffix
   * instead of |0), so it's exposed to the exact same class of risk: a
   * hand-typed mock response can't catch real vendor field-shape drift.
   *
   * Skips entirely (not a failure) when no fixtures have been captured yet.
   */
  class ApiNlsFixtureReplayTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key' => 'unused-fixture-replay-key',
        'Url' => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
    }

    private function makeResponse(string $rawJsonBody): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($rawJsonBody);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    public function testCapturedFixturesReplayToTheirFrozenSnapshot(): void {
      $fixtureDir = __DIR__ . '/../../../fixtures/api/nl';
      $fixtures = glob("$fixtureDir/*.json");
      $fixtures = array_filter($fixtures, fn($path) => !str_ends_with($path, '.expected.json'));

      if (empty($fixtures)) {
        $this->markTestSkipped(
          'No captured NL API fixtures found under tests/fixtures/api/nl. '
          . 'Run tests/bin/record-api-fixtures.php on a live site with sandbox '
          . 'credentials to generate them (see CLAUDE.md).'
        );
      }

      foreach ($fixtures as $fixturePath) {
        $expectedPath = substr($fixturePath, 0, -5) . '.expected.json';
        $this->assertFileExists($expectedPath, "Missing companion snapshot for $fixturePath - re-run the recorder.");

        $rawBody = file_get_contents($fixturePath);
        $expected = json_decode(file_get_contents($expectedPath), TRUE);
        // Filenames are {committee}_{mcid}.json, per the recorder script.
        $committee = preg_replace('/_[^_]+$/', '', basename($fixturePath, '.json'));

        $this->clientMock = $this->createMock(ClientInterface::class);
        $this->clientMock->method('request')->willReturn($this->makeResponse($rawBody));
        $apiNls = new ApiNls($this->clientMock, $this->configurationMock, $this->apiKeyManagementMock);

        $actual = $apiNls->getApiNls($committee, (int) ($expected['mcid'] ?? 0));

        $this->assertSame($expected, $actual, "Parsing drifted for fixture: " . basename($fixturePath));
      }
    }

  }

}
