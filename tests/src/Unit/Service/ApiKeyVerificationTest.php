<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiKeyVerification;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;

  class ApiKeyVerificationTest extends UnitTestCase {

    private ApiKeyManagement $apiKeyManagementMock;
    private NlpConfiguration $configurationMock;
    private ApiNls $apiNlsMock;
    private ApiFolders $apiFoldersMock;

    protected function setUp(): void {
      parent::setUp();
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiNlsMock           = $this->createMock(ApiNls::class);
      $this->apiFoldersMock       = $this->createMock(ApiFolders::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiKeyVerification {
      return new ApiKeyVerification(
        $this->apiKeyManagementMock,
        $this->configurationMock,
        $this->apiNlsMock,
        $this->apiFoldersMock,
      );
    }

    private function stubAllOk(string $committee = ''): void {
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(function (string $c, int $database): array {
          return $database === 1
            ? [10 => 'TEST_NLs Active']
            : [11 => 'TEST VoterFile'];
        });
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('ok');
    }

    // -------------------------------------------------------------------------
    // apiKeyVerification — key collection
    // -------------------------------------------------------------------------

    public function testApiKeyVerificationReturnsEmptyWhenNoKeysFound(): void {
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([]);
      $this->configurationMock->method('getCountyNames')->willReturn([]);

      $this->assertSame([], $this->buildService()->apiKeyVerification());
    }

    public function testApiKeyVerificationIncludesStateCommitteeResult(): void {
      $stateKey = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($stateKey);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->stubAllOk();

      $results = $this->buildService()->apiKeyVerification();

      $this->assertCount(1, $results);
      $this->assertStringContainsString('State Committee', $results[0]['county']);
    }

    public function testApiKeyVerificationIncludesOneResultPerCommittee(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn(['Benton', 'Clackamas']);
      $this->stubAllOk();

      $results = $this->buildService()->apiKeyVerification();

      $this->assertCount(3, $results); // State Committee + 2 counties
    }

    public function testApiKeyVerificationSkipsCommitteesWithNoKey(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')
        ->willReturnCallback(function (string $id) use ($key): array {
          return $id === 'State Committee' ? $key : [];
        });
      $this->configurationMock->method('getCountyNames')->willReturn(['Benton', 'Clackamas']);
      $this->stubAllOk();

      $results = $this->buildService()->apiKeyVerification();

      $this->assertCount(1, $results);
    }

    // -------------------------------------------------------------------------
    // testApiKey — myCampaign status (via apiKeyVerification)
    // -------------------------------------------------------------------------

    public function testMyCampaignIsOkWhenFolderNameContainsNls(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(fn(string $c, int $db) => $db === 1 ? [10 => 'State_NLs'] : []);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('ok', $result['myCampaign']);
    }

    public function testMyCampaignIsFailWhenFolderListIsEmpty(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('fail', $result['myCampaign']);
    }

    public function testMyCampaignIsFailWhenFolderListHasNoNlsEntry(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(fn(string $c, int $db) => $db === 1 ? [10 => 'Some Other Folder'] : []);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('fail', $result['myCampaign']);
    }

    public function testMyCampaignIsFailWhenFolderListIsErrorPayload(): void {
      // An indexed [0] key signals an error response from the API, not a real folder list.
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(fn(string $c, int $db) => $db === 1 ? ['0' => 'folder is empty'] : []);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('fail', $result['myCampaign']);
    }

    // -------------------------------------------------------------------------
    // testApiKey — voterFile status
    // -------------------------------------------------------------------------

    public function testVoterFileIsOkWhenFoldersReturnedWithoutErrorPayload(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(fn(string $c, int $db) => $db === 0 ? [11 => 'TEST VoterFile'] : []);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('ok', $result['voterFile']);
    }

    public function testVoterFileIsUnsureWhenApiReturnsErrorPayload(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(fn(string $c, int $db) => $db === 0 ? ['0' => 'folder is empty'] : []);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('Unsure, no folders found', $result['voterFile']);
    }

    public function testVoterFileIsFailWhenFolderListIsEmpty(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $result = $this->buildService()->apiKeyVerification()[0];

      $this->assertSame('fail', $result['voterFile']);
    }

    // -------------------------------------------------------------------------
    // testApiKey — nls status
    // -------------------------------------------------------------------------

    public function testNlsIsOkWhenApiKeyVerifyReturnsOk(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('ok');

      $this->assertSame('ok', $this->buildService()->apiKeyVerification()[0]['nls']);
    }

    public function testNlsIsOkWhenApiKeyVerifyReturnsInvalidMcid(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('Invalid mcid');

      $this->assertSame('ok', $this->buildService()->apiKeyVerification()[0]['nls']);
    }

    public function testNlsIsFailWhenApiKeyVerifyReturnsFail(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiFoldersMock->method('getApiFolders')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $this->assertSame('fail', $this->buildService()->apiKeyVerification()[0]['nls']);
    }

    // -------------------------------------------------------------------------
    // testApiKey — state committee uses empty string as committee id for folders
    // -------------------------------------------------------------------------

    public function testStateCommitteePassesEmptyStringToGetApiFolders(): void {
      $key = ['API Key' => 'key', 'Url' => 'api.example.com', 'App Name' => 'app'];
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn($key);
      $this->configurationMock->method('getCountyNames')->willReturn([]);
      $this->apiNlsMock->method('apiKeyVerify')->willReturn('fail');

      $capturedCommittees = [];
      $this->apiFoldersMock->method('getApiFolders')
        ->willReturnCallback(function (string $committee, int $db) use (&$capturedCommittees): array {
          $capturedCommittees[] = $committee;
          return [];
        });

      $this->buildService()->apiKeyVerification();

      $this->assertContains('', $capturedCommittees);
    }

  }

}
