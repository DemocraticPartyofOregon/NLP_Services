<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiVoter;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  /**
   * Replays real sandbox responses (captured by
   * tests/bin/record-api-fixtures.php) through ApiVoter::getApiVoter() and
   * diffs the result against a frozen snapshot of what this module's own
   * parsing produced for that exact response.
   *
   * This exists alongside ApiVoterTest (which uses hand-typed response
   * arrays) because a hand-typed array only proves the parser handles the
   * shape a developer imagined - it can't catch the vendor's actual field
   * casing, null patterns, or extra/missing fields that only show up in a
   * real response. If the vendor's contract drifts, re-running the recorder
   * regenerates both files and the diff shows exactly what changed.
   *
   * Skips entirely (not a failure) when no fixtures have been captured yet -
   * this repo ships no real fixtures because capturing them requires a
   * decrypted sandbox API key, which only exists on a live site.
   */
  class ApiVoterFixtureReplayTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock = $this->createMock(ClientInterface::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key' => 'unused-fixture-replay-key',
        'Url' => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
      // Cycle just needs to parse as yyyy-mm-t; the specific value only
      // affects which election years findVotingRecord() looks for.
      $this->configurationMock->method('getCycle')->willReturn('2026-11-G');
    }

    private function makeResponse(string $rawJsonBody): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($rawJsonBody);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    public function testCapturedFixturesReplayToTheirFrozenSnapshot(): void {
      $fixtureDir = __DIR__ . '/../../../fixtures/api/voter';
      $fixtures = glob("$fixtureDir/*.json");
      $fixtures = array_filter($fixtures, fn($path) => !str_ends_with($path, '.expected.json'));

      if (empty($fixtures)) {
        $this->markTestSkipped(
          'No captured voter API fixtures found under tests/fixtures/api/voter. '
          . 'Run tests/bin/record-api-fixtures.php on a live site with sandbox '
          . 'credentials to generate them (see CLAUDE.md).'
        );
      }

      foreach ($fixtures as $fixturePath) {
        $expectedPath = substr($fixturePath, 0, -5) . '.expected.json';
        $this->assertFileExists($expectedPath, "Missing companion snapshot for $fixturePath - re-run the recorder.");

        $rawBody = file_get_contents($fixturePath);
        $expected = json_decode(file_get_contents($expectedPath), TRUE);

        $this->clientMock = $this->createMock(ClientInterface::class);
        $this->clientMock->method('request')->willReturn($this->makeResponse($rawBody));
        $apiVoter = new ApiVoter($this->clientMock, $this->configurationMock, $this->apiKeyManagementMock);

        $actual = $apiVoter->getApiVoter((int) ($expected['vanid'] ?? 0));

        $this->assertSame($expected, $actual, "Parsing drifted for fixture: " . basename($fixturePath));
      }
    }

  }

}
