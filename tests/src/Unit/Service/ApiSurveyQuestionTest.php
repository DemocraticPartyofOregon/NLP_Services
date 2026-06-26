<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiSurveyQuestion;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\TransferException;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiSurveyQuestionTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiSurveyQuestion {
      return new ApiSurveyQuestion(
        $this->clientMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    private function stubKeyValues(): void {
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key'  => 'test-key',
        'Url'      => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
    }

    private function makeResponse(mixed $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn(json_encode($body));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    private function stubCanvassResponseCodes(): void {
      $this->configurationMock->method('getCanvassResponseCodes')->willReturn([
        'Walk' => ['code' => 2],
      ]);
    }

    private function stubParticipationQuestion(array $responses = []): void {
      $responses = $responses ?: [
        ['name' => 'Canvassed', 'surveyResponseId' => 201],
        ['name' => 'Called',    'surveyResponseId' => 202],
      ];
      $this->configurationMock->method('getNlParticipationQuestion')->willReturn([
        'surveyQuestionId' => 100,
        'responses'        => $responses,
      ]);
    }

    private function stubPledgeQuestion(array $responses = []): void {
      $responses = $responses ?: [
        ['name' => 'Definitely', 'surveyResponseId' => 401],
        ['name' => 'Probably',   'surveyResponseId' => 402],
      ];
      $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([
        'surveyQuestionId' => 300,
        'responses'        => $responses,
      ]);
    }

    private function baseSurveyResponse(string $type): array {
      return [
        'type'            => $type,
        'vanid'           => 12345,
        'dateCanvassed'   => '2024-01-15',
        'responseName'    => '',
        'ContactTypeCode' => 0,
        'rid'             => 0,
        'phoneNumber'     => '',
        'phoneId'         => 0,
      ];
    }

    // -------------------------------------------------------------------------
    // getApiSurveyQuestions
    // -------------------------------------------------------------------------

    public function testGetApiSurveyQuestionsReturnsEmptyOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new TransferException('connect error'));

      $this->assertSame([], $this->buildService()->getApiSurveyQuestions('2024'));
    }

    public function testGetApiSurveyQuestionsReturnsEmptyOnGenericException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('unexpected'));

      $this->assertSame([], $this->buildService()->getApiSurveyQuestions('2024'));
    }

    public function testGetApiSurveyQuestionsReturnsEmptyWhenNoItemsKey(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildService()->getApiSurveyQuestions('2024'));
    }

    public function testGetApiSurveyQuestionsFiltersToMatchingCycleYear(): void {
      $this->stubKeyValues();
      $apiData = [
        'items' => [
          [
            'surveyQuestionId' => 10,
            'cycle'            => '2024',
            'type'             => 'ScriptQuestion',
            'question'         => 'Can we count on you?',
            'description'      => '',
            'dateCreated'      => '2024-01-01',
            'responses'        => [
              ['surveyResponseId' => 101, 'name' => 'Yes'],
            ],
          ],
          [
            'surveyQuestionId' => 20,
            'cycle'            => '2022',
            'type'             => 'ScriptQuestion',
            'question'         => 'Old question',
            'description'      => '',
            'dateCreated'      => '2022-01-01',
            'responses'        => [],
          ],
        ],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($apiData));

      $result = $this->buildService()->getApiSurveyQuestions('2024');

      $this->assertArrayHasKey(10, $result);
      $this->assertArrayNotHasKey(20, $result);
    }

    public function testGetApiSurveyQuestionsKeysQuestionsById(): void {
      $this->stubKeyValues();
      $apiData = [
        'items' => [
          [
            'surveyQuestionId' => 10,
            'cycle'            => '2024',
            'type'             => 'ScriptQuestion',
            'question'         => 'Q1',
            'description'      => '',
            'dateCreated'      => '2024-01-01',
            'responses'        => [],
          ],
          [
            'surveyQuestionId' => 11,
            'cycle'            => '2024',
            'type'             => 'ScriptQuestion',
            'question'         => 'Q2',
            'description'      => '',
            'dateCreated'      => '2024-01-01',
            'responses'        => [],
          ],
        ],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($apiData));

      $result = $this->buildService()->getApiSurveyQuestions('2024');

      $this->assertCount(2, $result);
      $this->assertSame('Q1', $result[10]['question']);
      $this->assertSame('Q2', $result[11]['question']);
    }

    public function testGetApiSurveyQuestionsKeysResponsesBySurveyResponseId(): void {
      $this->stubKeyValues();
      $apiData = [
        'items' => [
          [
            'surveyQuestionId' => 10,
            'cycle'            => '2024',
            'type'             => 'ScriptQuestion',
            'question'         => 'Q1',
            'description'      => '',
            'dateCreated'      => '2024-01-01',
            'responses'        => [
              ['surveyResponseId' => 101, 'name' => 'Yes'],
              ['surveyResponseId' => 102, 'name' => 'No'],
            ],
          ],
        ],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($apiData));

      $result = $this->buildService()->getApiSurveyQuestions('2024');

      $this->assertArrayHasKey(101, $result[10]['responses']);
      $this->assertArrayHasKey(102, $result[10]['responses']);
      $this->assertSame('Yes', $result[10]['responses'][101]['name']);
    }

    public function testGetApiSurveyQuestionsPassesDatabaseInUrl(): void {
      $this->stubKeyValues();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->makeResponse([]);
        });

      $this->buildService()->getApiSurveyQuestions('2024', 1);

      $this->assertStringContainsString('|1@', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // reportSurveyResponse — nlParticipation
    // -------------------------------------------------------------------------

    public function testReportSurveyResponseNlParticipationPostsWhenResponseNameFound(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubParticipationQuestion();
      $this->clientMock->expects($this->once())->method('request');

      $sr = $this->baseSurveyResponse('nlParticipation');
      $sr['responseName'] = 'Canvassed';
      $this->buildService()->reportSurveyResponse($sr);
    }

    public function testReportSurveyResponseNlParticipationSkipsPostWhenResponseNameNotFound(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubParticipationQuestion();
      $this->clientMock->expects($this->never())->method('request');

      $sr = $this->baseSurveyResponse('nlParticipation');
      $sr['responseName'] = 'Unknown Response';
      $this->buildService()->reportSurveyResponse($sr);
    }

    public function testReportSurveyResponseNlParticipationUsesMyCampaignDatabase(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubParticipationQuestion();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->createMock(ResponseInterface::class);
        });

      $sr = $this->baseSurveyResponse('nlParticipation');
      $sr['responseName'] = 'Canvassed';
      $this->buildService()->reportSurveyResponse($sr);

      $this->assertStringContainsString('|1@', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // reportSurveyResponse — pledgeToVote
    // -------------------------------------------------------------------------

    public function testReportSurveyResponsePledgeToVotePostsWhenResponseNameFound(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubPledgeQuestion();
      $this->clientMock->expects($this->once())->method('request');

      $sr = $this->baseSurveyResponse('pledgeToVote');
      $sr['responseName'] = 'Definitely';
      $this->buildService()->reportSurveyResponse($sr);
    }

    public function testReportSurveyResponsePledgeToVoteSkipsPostWhenResponseNameNotFound(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubPledgeQuestion();
      $this->clientMock->expects($this->never())->method('request');

      $sr = $this->baseSurveyResponse('pledgeToVote');
      $sr['responseName'] = 'Unknown';
      $this->buildService()->reportSurveyResponse($sr);
    }

    public function testReportSurveyResponsePledgeToVoteUsesVoterFileDatabase(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->stubPledgeQuestion();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->createMock(ResponseInterface::class);
        });

      $sr = $this->baseSurveyResponse('pledgeToVote');
      $sr['responseName'] = 'Definitely';
      $this->buildService()->reportSurveyResponse($sr);

      $this->assertStringContainsString('|0@', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // reportSurveyResponse — contactAttempt
    // -------------------------------------------------------------------------

    public function testReportSurveyResponseContactAttemptAlwaysPosts(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->clientMock->expects($this->once())->method('request');

      $sr = $this->baseSurveyResponse('contactAttempt');
      $sr['ContactTypeCode'] = 5;
      $sr['rid'] = 99;
      $this->buildService()->reportSurveyResponse($sr);
    }

    public function testReportSurveyResponseContactAttemptPostsToCorrectVanidEndpoint(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $capturedUrl = null;
      $this->clientMock->method('request')
        ->willReturnCallback(function (string $method, string $url) use (&$capturedUrl) {
          $capturedUrl = $url;
          return $this->createMock(ResponseInterface::class);
        });

      $sr = $this->baseSurveyResponse('contactAttempt');
      $sr['vanid'] = 12345;
      $sr['ContactTypeCode'] = 5;
      $sr['rid'] = 99;
      $this->buildService()->reportSurveyResponse($sr);

      $this->assertStringContainsString('/people/12345/canvassResponses', $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // reportSurveyResponse — exception handling
    // -------------------------------------------------------------------------

    public function testReportSurveyResponseDoesNotThrowOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->clientMock->method('request')->willThrowException(new TransferException('error'));

      $sr = $this->baseSurveyResponse('contactAttempt');
      $sr['ContactTypeCode'] = 5;
      $sr['rid'] = 99;

      // Should complete without throwing.
      $this->buildService()->reportSurveyResponse($sr);
      $this->assertTrue(TRUE);
    }

    public function testReportSurveyResponseDoesNotThrowOnGenericException(): void {
      $this->stubKeyValues();
      $this->stubCanvassResponseCodes();
      $this->clientMock->method('request')->willThrowException(new Exception('error'));

      $sr = $this->baseSurveyResponse('contactAttempt');
      $sr['ContactTypeCode'] = 5;
      $sr['rid'] = 99;

      $this->buildService()->reportSurveyResponse($sr);
      $this->assertTrue(TRUE);
    }

  }

}
