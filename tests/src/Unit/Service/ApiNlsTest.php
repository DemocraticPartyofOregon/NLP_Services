<?php

namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Promise\FulfilledPromise;
  use GuzzleHttp\Promise\RejectedPromise;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiNlsTest extends UnitTestCase {

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

    private function buildApiNls(): ApiNls {
      return new ApiNls(
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

    private function makeGuzzleException(int $code): RequestException {
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn($code);
      return new RequestException(
        'error',
        $this->createMock(RequestInterface::class),
        $response,
      );
    }

    private function baseApiResult(int $vanid = 200456): array {
      return [
        'vanId'      => $vanid,
        'firstName'  => 'John',
        'middleName' => 'Q',
        'lastName'   => 'Public',
        'nickname'   => 'JQ',
        'addresses'  => [
          [
            'type'            => 'Voting',
            'addressLine1'    => '567 Oak Ave',
            'city'            => 'Salem',
            'zipOrPostalCode' => '97301-1234',
          ],
        ],
        'phones'      => [],
        'emails'      => [],
        'districts'   => [
          ['name' => 'State House', 'districtFieldValues' => [['name' => '021']]],
          ['name' => 'Precinct',    'districtFieldValues' => [['name' => 'Marion-0015']]],
          ['name' => 'County',      'districtFieldValues' => [['name' => 'Marion']]],
        ],
        'identifiers' => [],
      ];
    }

    // -------------------------------------------------------------------------
    // getApiNls — happy path
    // -------------------------------------------------------------------------

    public function testGetApiNlsReturnsNlArray(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame(200456, $result['mcid']);
      $this->assertSame('John', $result['firstName']);
      $this->assertSame('Q', $result['middleName']);
      $this->assertSame('Public', $result['lastName']);
      $this->assertSame('JQ', $result['nickname']);
    }

    public function testGetApiNlsParsesVotingAddress(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('567 Oak Ave', $result['address']);
      $this->assertSame('Salem', $result['city']);
      $this->assertSame('97301', $result['zip']);
    }

    public function testGetApiNlsStripsZipPlusFour(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['addresses'][0]['zipOrPostalCode'] = '97301-5678';
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('97301', $result['zip']);
    }

    public function testGetApiNlsNoAddressesLeavesBlankFields(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['addresses'] = [];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('', $result['address']);
      $this->assertSame('', $result['city']);
      $this->assertSame('', $result['zip']);
    }

    // -------------------------------------------------------------------------
    // getApiNls — phone parsing
    // -------------------------------------------------------------------------

    public function testGetApiNlsParsesHomePhone(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'   => 'Home',
        'phoneNumber' => '5035551111',
        'phoneId'     => 10,
        'isPreferred' => FALSE,
        'isBest'      => FALSE,
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('5035551111', $result['homePhone']);
      $this->assertSame(10, $result['homePhoneId']);
    }

    public function testGetApiNlsParsesCellPhone(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'   => 'Cell',
        'phoneNumber' => '5035552222',
        'phoneId'     => 11,
        'isPreferred' => FALSE,
        'isBest'      => FALSE,
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('5035552222', $result['cellPhone']);
      $this->assertSame(11, $result['cellPhoneId']);
    }

    public function testGetApiNlsPreferredPhoneSetsToPrimary(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'   => 'Cell',
        'phoneNumber' => '5035553333',
        'phoneId'     => 12,
        'isPreferred' => TRUE,
        'isBest'      => FALSE,
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('5035553333', $result['phone']);
    }

    public function testGetApiNlsBestPhoneSetsToPrimaryWhenNoPreferred(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'   => 'Home',
        'phoneNumber' => '5035554444',
        'phoneId'     => 13,
        'isPreferred' => FALSE,
        'isBest'      => TRUE,
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('5035554444', $result['phone']);
    }

    public function testGetApiNlsNoPhonesLeavesBlankFields(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('', $result['homePhone']);
      $this->assertSame('', $result['cellPhone']);
      $this->assertSame('', $result['phone']);
    }

    // -------------------------------------------------------------------------
    // getApiNls — email parsing
    // -------------------------------------------------------------------------

    public function testGetApiNlsPreferredEmailSet(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['emails'] = [
        ['email' => 'other@example.com', 'isPreferred' => FALSE],
        ['email' => 'primary@example.com', 'isPreferred' => TRUE],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('primary@example.com', $result['email']);
    }

    public function testGetApiNlsNoEmailsLeavesNull(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertNull($result['email']);
    }

    // -------------------------------------------------------------------------
    // getApiNls — district parsing
    // -------------------------------------------------------------------------

    public function testGetApiNlsParsesDistricts(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame('021', $result['hd']);
      $this->assertSame('Marion-0015', $result['precinct']);
      $this->assertSame('Marion', $result['county']);
    }

    public function testGetApiNlsNoDistrictsLeavesNullFields(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['districts'] = [];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertNull($result['hd']);
      $this->assertNull($result['precinct']);
    }

    // -------------------------------------------------------------------------
    // getApiNls — identifier / vfid parsing
    // -------------------------------------------------------------------------

    public function testGetApiNlsVoterVanIdSetAsVfid(): void {
      $this->stubKeyValues();
      $data = $this->baseApiResult();
      $data['identifiers'] = [
        ['type' => 'Other',       'externalId' => 999],
        ['type' => 'VoterVANID',  'externalId' => 88888],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame(88888, $result['vfid']);
    }

    public function testGetApiNlsNoIdentifiersLeavesVfidZero(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiNls()->getApiNls('', 200456);

      $this->assertSame(0, $result['vfid']);
    }

    // -------------------------------------------------------------------------
    // getApiNls — error cases (via nlApiCall)
    // -------------------------------------------------------------------------

    public function testGetApiNlsEmptyApiResultReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildApiNls()->getApiNls('', 200456));
    }

    public function testGetApiNlsInvalidJsonReturnsEmpty(): void {
      $this->stubKeyValues();
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willReturn($response);

      $this->assertSame([], $this->buildApiNls()->getApiNls('', 200456));
    }

    public function testGetApiNlsGuzzle404ReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(404));

      $this->assertSame([], $this->buildApiNls()->getApiNls('', 200456));
    }

    public function testGetApiNlsGuzzle401WithErrorStatusReturnsInvalidKey(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(401));

      $result = $this->buildApiNls()->getApiNls('', 200456, TRUE);

      $this->assertSame(['Invalid key'], $result);
    }

    public function testGetApiNlsGuzzle401WithoutErrorStatusReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(401));

      $this->assertSame([], $this->buildApiNls()->getApiNls('', 200456, FALSE));
    }

    public function testGetApiNlsGuzzle500ReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(500));

      $this->assertSame([], $this->buildApiNls()->getApiNls('', 200456));
    }

    // -------------------------------------------------------------------------
    // nlExists
    // -------------------------------------------------------------------------

    public function testNlExistsReturnsTrueOnSuccess(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->createMock(ResponseInterface::class));

      $this->assertTrue($this->buildApiNls()->nlExists('', 200456));
    }

    public function testNlExistsReturnsFalseOn404(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(404));

      $this->assertFalse($this->buildApiNls()->nlExists('', 200456));
    }

    public function testNlExistsReturnsFalseOnOtherGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(500));

      $this->assertFalse($this->buildApiNls()->nlExists('', 200456));
    }

    // -------------------------------------------------------------------------
    // apiKeyVerify
    // -------------------------------------------------------------------------

    public function testApiKeyVerifyReturnsOkOnSuccess(): void {
      $this->clientMock->method('request')
        ->willReturn($this->createMock(ResponseInterface::class));

      $result = $this->buildApiNls()->apiKeyVerify(
        ['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app'],
        200456,
      );

      $this->assertSame('ok', $result);
    }

    public function testApiKeyVerifyReturnsInvalidMcidOn404(): void {
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(404));

      $result = $this->buildApiNls()->apiKeyVerify(
        ['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app'],
        200456,
      );

      $this->assertSame('Invalid mcid', $result);
    }

    public function testApiKeyVerifyReturnsInvalidKeyOn401(): void {
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(401));

      $result = $this->buildApiNls()->apiKeyVerify(
        ['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app'],
        200456,
      );

      $this->assertSame('Invalid key', $result);
    }

    public function testApiKeyVerifyReturnsInvalidKeyOnOtherGuzzleException(): void {
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException(500));

      $result = $this->buildApiNls()->apiKeyVerify(
        ['API Key' => 'k', 'Url' => 'api.example.com/v4', 'App Name' => 'app'],
        200456,
      );

      $this->assertSame('Invalid key', $result);
    }

    // -------------------------------------------------------------------------
    // getNls — Pool-based concurrent requests (mocks sendAsync, not get)
    // -------------------------------------------------------------------------

    public function testGetNlsReturnsMappedByMcid(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($this->baseApiResult(200456))));

      $result = $this->buildApiNls()->getNls('', [200456]);

      $this->assertArrayHasKey(200456, $result);
      $this->assertSame('John', $result[200456]['firstName']);
    }

    public function testGetNlsReturnsMultipleNls(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->makeResponse($this->baseApiResult(200456))),
          new FulfilledPromise($this->makeResponse($this->baseApiResult(200457))),
        );

      $result = $this->buildApiNls()->getNls('', [200456, 200457]);

      $this->assertArrayHasKey(200456, $result);
      $this->assertArrayHasKey(200457, $result);
    }

    public function testGetNlsEmptyListReturnsEmpty(): void {
      $this->assertSame([], $this->buildApiNls()->getNls('', []));
    }

    public function testGetNlsRejectedRequestSkipsThatMcid(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new RejectedPromise(new RequestException('error', $this->createMock(RequestInterface::class))),
          new FulfilledPromise($this->makeResponse($this->baseApiResult(200457))),
        );

      $result = $this->buildApiNls()->getNls('', [200456, 200457]);

      $this->assertArrayNotHasKey(200456, $result);
      $this->assertArrayHasKey(200457, $result);
    }

    public function testGetNlsEmptyResponseReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse([])));

      $this->assertSame([], $this->buildApiNls()->getNls('', [200456]));
    }

  }

}
