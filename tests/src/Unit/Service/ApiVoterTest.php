<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\ApiVoter;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use GuzzleHttp\Promise\FulfilledPromise;
  use GuzzleHttp\Promise\RejectedPromise;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;

  class ApiVoterTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock          = $this->createMock(ClientInterface::class);
      $this->configurationMock   = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildApiVoter(): ApiVoter {
      return new ApiVoter(
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

    private function stubCycle(string $cycle = '2024-11-G'): void {
      $this->configurationMock->method('getCycle')->willReturn($cycle);
    }

    private function makeResponse(mixed $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn(json_encode($body));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    private function makeGuzzleException(int $code): RequestException {
      $request = $this->createMock(RequestInterface::class);
      return new RequestException('error', $request, NULL, NULL, ['response_code' => $code]);
    }

    private function baseApiResult(int $vanid = 100123): array {
      return [
        'vanId'         => $vanid,
        'firstName'     => 'Jane',
        'lastName'      => 'Doe',
        'nickname'      => '',
        'dateOfBirth'   => '1980-06-15T00:00:00',
        'party'         => 'D',
        'sex'           => 'F',
        'addresses'     => [
          [
            'type'            => 'Voting',
            'addressLine1'    => '1234 NW Main St',
            'addressLine2'    => '',
            'city'            => 'Portland',
            'zipOrPostalCode' => '97201',
          ],
        ],
        'phones'        => [],
        'districts'     => [
          ['name' => 'State House',   'districtFieldValues' => [['name' => '033']]],
          ['name' => 'County',        'districtFieldValues' => [['name' => 'Multnomah']]],
          ['name' => 'Precinct',      'districtFieldValues' => [['name' => 'Multnomah-0042']]],
          ['name' => 'Congressional', 'districtFieldValues' => [['name' => '003']]],
        ],
        'electionRecords' => [],
      ];
    }

    // -------------------------------------------------------------------------
    // getApiVoter — happy path
    // -------------------------------------------------------------------------

    public function testGetApiVoterReturnsVoterArray(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame(100123, $result['vanid']);
      $this->assertSame('Jane', $result['firstName']);
      $this->assertSame('Doe', $result['lastName']);
      $this->assertSame('D', $result['party']);
      $this->assertSame('F', $result['sex']);
    }

    public function testGetApiVoterCalculatesAge(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $data = $this->baseApiResult();
      $data['dateOfBirth'] = '1980-01-01T00:00:00';
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $expectedAge = (string) ((int) date('Y') - 1980 - (date('md') < '0101' ? 1 : 0));
      $this->assertSame($expectedAge, $result['age']);
    }

    public function testGetApiVoterParsesVotingAddress(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame('1234', $result['address']['streetNo']);
      $this->assertSame('NW', $result['address']['streetPrefix']);
      $this->assertSame('Main', $result['address']['streetName']);
      $this->assertSame('St', $result['address']['streetType']);
      $this->assertSame('Portland', $result['address']['city']);
    }

    public function testGetApiVoterParsesMailingAddress(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $data = $this->baseApiResult();
      $data['addresses'][] = [
        'type'              => 'Mailing',
        'addressLine1'      => 'PO Box 99',
        'city'              => 'Portland',
        'stateOrProvince'   => 'OR',
        'zipOrPostalCode'   => '97202',
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame('PO Box 99', $result['address']['mAddress']);
      $this->assertSame('97202', $result['address']['mZip']);
    }

    public function testGetApiVoterParsesDistricts(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->clientMock->method('request')->willReturn($this->makeResponse($this->baseApiResult()));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame('033', $result['address']['hd']);
      $this->assertSame('Multnomah', $result['address']['county']);
      $this->assertSame('Multnomah-0042', $result['address']['precinct']);
      $this->assertSame('003', $result['address']['cd']);
    }

    // -------------------------------------------------------------------------
    // getApiVoter — phone parsing
    // -------------------------------------------------------------------------

    public function testGetApiVoterSetsPreferredCellPhone(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'      => 'Cell',
        'deviceType'     => 'Cell',
        'phoneNumber'    => '5035551234',
        'phoneId'        => 1,
        'isPreferred'    => TRUE,
        'smsOptInStatus' => 'OptedIn',
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame('5035551234', $result['cellPhone']);
      $this->assertSame('C', $result['preferredPhoneType']);
    }

    public function testGetApiVoterSetsPreferredHomePhone(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $data = $this->baseApiResult();
      $data['phones'] = [[
        'phoneType'   => 'Personal (Home)',
        'deviceType'  => 'Landline',
        'phoneNumber' => '5035559876',
        'phoneId'     => 2,
        'isPreferred' => TRUE,
      ]];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertSame('5035559876', $result['homePhone']);
      $this->assertSame('H', $result['preferredPhoneType']);
    }

    // -------------------------------------------------------------------------
    // getApiVoter — voting history via findVotingRecord
    // -------------------------------------------------------------------------

    public function testGetApiVoterVotingHistoryMarksParticipationYes(): void {
      $this->stubKeyValues();
      $this->stubCycle('2024-11-G');
      $data = $this->baseApiResult();
      $data['electionRecords'] = [
        ['electionRecordType' => '2024 - General', 'participation' => 'Y'],
        ['electionRecordType' => '2024 - Primary', 'participation' => 'Y'],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertStringContainsString('G24:@Y', $result['votingHistory']);
      $this->assertStringContainsString('P24:@Y', $result['votingHistory']);
    }

    public function testGetApiVoterVotingHistoryDefaultsToNoParticipation(): void {
      $this->stubKeyValues();
      $this->stubCycle('2024-11-G');
      $data = $this->baseApiResult();
      $data['electionRecords'] = [];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertStringNotContainsString('@Y', $result['votingHistory']);
    }

    public function testGetApiVoterVotingHistoryOddYearUsesEvenYear(): void {
      $this->stubKeyValues();
      $this->stubCycle('2025-05-P');
      $data = $this->baseApiResult();
      $data['electionRecords'] = [
        ['electionRecordType' => '2024 - General', 'participation' => 'Y'],
      ];
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));

      $result = $this->buildApiVoter()->getApiVoter(100123);

      $this->assertStringContainsString('G24:@Y', $result['votingHistory']);
    }

    // -------------------------------------------------------------------------
    // getApiVoter — error cases
    // -------------------------------------------------------------------------

    public function testGetApiVoterGuzzleExceptionReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException($this->makeGuzzleException(500));

      $this->assertSame([], $this->buildApiVoter()->getApiVoter(100123));
    }

    public function testGetApiVoterInvalidJsonReturnsEmpty(): void {
      $this->stubKeyValues();
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('not-json{{');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willReturn($response);

      $this->assertSame([], $this->buildApiVoter()->getApiVoter(100123));
    }

    public function testGetApiVoterEmptyResponseReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->makeResponse([]));

      $this->assertSame([], $this->buildApiVoter()->getApiVoter(100123));
    }

    // -------------------------------------------------------------------------
    // parseAddress (exercised indirectly via getApiVoter)
    // -------------------------------------------------------------------------

    private function getAddressFor(string $addressLine1): array {
      $this->stubKeyValues();
      $this->stubCycle();
      $data = $this->baseApiResult();
      $data['addresses'][0]['addressLine1'] = $addressLine1;
      $this->clientMock->method('request')->willReturn($this->makeResponse($data));
      $result = $this->buildApiVoter()->getApiVoter(100123);
      return $result['address'];
    }

    public function testParseAddressSimpleStreet(): void {
      $addr = $this->getAddressFor('1234 Main St');
      $this->assertSame('1234', $addr['streetNo']);
      $this->assertNull($addr['streetPrefix']);
      $this->assertSame('Main', $addr['streetName']);
      $this->assertSame('St', $addr['streetType']);
    }

    public function testParseAddressWithDirectionalPrefix(): void {
      $addr = $this->getAddressFor('1234 NW Main St');
      $this->assertSame('NW', $addr['streetPrefix']);
      $this->assertSame('Main', $addr['streetName']);
    }

    public function testParseAddressWithAptNumber(): void {
      $addr = $this->getAddressFor('1234 Main St Apt 5');
      $this->assertSame('Apt', $addr['aptType']);
      $this->assertSame('5', $addr['aptNo']);
      $this->assertSame('Main', $addr['streetName']);
    }

    public function testParseAddressWithMultiWordStreetName(): void {
      $addr = $this->getAddressFor('100 SW Oak Grove Blvd');
      $this->assertSame('SW', $addr['streetPrefix']);
      $this->assertSame('Oak Grove', $addr['streetName']);
      $this->assertSame('Blvd', $addr['streetType']);
    }

    // -------------------------------------------------------------------------
    // voterExists
    // -------------------------------------------------------------------------

    public function testVoterExistsReturnsTrueOnSuccess(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willReturn($this->createMock(ResponseInterface::class));

      $this->assertTrue($this->buildApiVoter()->voterExists(100123));
    }

    public function testVoterExistsReturnsFalseOn404(): void {
      $this->stubKeyValues();
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn(404);
      $guzzleException = new RequestException('Not Found', $this->createMock(RequestInterface::class), $response);
      $this->clientMock->method('request')->willThrowException($guzzleException);

      $this->assertFalse($this->buildApiVoter()->voterExists(100123));
    }

    public function testVoterExistsReturnsFalseOnOtherException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException($this->makeGuzzleException(500));

      $this->assertFalse($this->buildApiVoter()->voterExists(100123));
    }

    // -------------------------------------------------------------------------
    // getVotersByAddress
    // -------------------------------------------------------------------------

    public function testGetVotersByAddressReturnsResults(): void {
      $this->stubKeyValues();
      $payload = [['vanId' => 100123, 'firstName' => 'Jane']];
      $this->clientMock->method('request')->willReturn($this->makeResponse($payload));

      $result = $this->buildApiVoter()->getVotersByAddress([
        'addressLine1' => '1234 Main St',
        'city'         => 'Portland',
      ]);

      $this->assertSame($payload, $result);
    }

    public function testGetVotersByAddressReturnsEmptyOn404(): void {
      $this->stubKeyValues();
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn(404);
      $guzzleException = new RequestException('Not Found', $this->createMock(RequestInterface::class), $response);
      $this->clientMock->method('request')->willThrowException($guzzleException);

      $result = $this->buildApiVoter()->getVotersByAddress([
        'addressLine1' => '1234 Main St',
        'city'         => 'Portland',
      ]);

      $this->assertSame([], $result);
    }

    public function testGetVotersByAddressReturnsInvalidKeyOn401(): void {
      $this->stubKeyValues();
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getStatusCode')->willReturn(401);
      $guzzleException = new RequestException('Unauthorized', $this->createMock(RequestInterface::class), $response);
      $this->clientMock->method('request')->willThrowException($guzzleException);

      $result = $this->buildApiVoter()->getVotersByAddress([
        'addressLine1' => '1234 Main St',
        'city'         => 'Portland',
      ]);

      $this->assertSame(['Invalid key'], $result);
    }

    public function testGetVotersByAddressInvalidJsonReturnsEmpty(): void {
      $this->stubKeyValues();
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn('{bad json');
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willReturn($response);

      $result = $this->buildApiVoter()->getVotersByAddress([
        'addressLine1' => '1234 Main St',
        'city'         => 'Portland',
      ]);

      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // getVoters — Pool-based concurrent requests (mocks sendAsync, not get)
    // -------------------------------------------------------------------------

    public function testGetVotersUsesNicknameWhenPresent(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $data = $this->baseApiResult();
      $data['nickname'] = 'JJ';
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($data)));

      $result = $this->buildApiVoter()->getVoters([100123]);

      $this->assertSame('JJ', $result[100123]['nickname']);
    }

    public function testGetVotersFallsBackToFirstNameWhenNoNickname(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($this->baseApiResult())));

      $result = $this->buildApiVoter()->getVoters([100123]);

      $this->assertSame('Jane', $result[100123]['nickname']);
    }

    public function testGetVotersReturnsMultipleVoters(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturnOnConsecutiveCalls(
          new FulfilledPromise($this->makeResponse($this->baseApiResult(100123))),
          new FulfilledPromise($this->makeResponse($this->baseApiResult(100124))),
        );

      $result = $this->buildApiVoter()->getVoters([100123, 100124]);

      $this->assertArrayHasKey(100123, $result);
      $this->assertArrayHasKey(100124, $result);
    }

    public function testGetVotersOregonStripsCountyFromPrecinct(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($this->baseApiResult())));

      $result = $this->buildApiVoter()->getVoters([100123]);

      // baseApiResult has precinct 'Multnomah-0042'; Oregon strips the county prefix and leading zeros.
      $this->assertSame('42', $result[100123]['address']['precinct']);
    }

    public function testGetVotersNonOregonRemovesSpacesFromPrecinct(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Washington');
      $data = $this->baseApiResult();
      $data['districts'][2] = ['name' => 'Precinct', 'districtFieldValues' => [['name' => 'King 042']]];
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($data)));

      $result = $this->buildApiVoter()->getVoters([100123]);

      $this->assertSame('King042', $result[100123]['address']['precinct']);
    }

    public function testGetVotersRemovesLeadingZerosFromHd(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($this->baseApiResult())));

      $result = $this->buildApiVoter()->getVoters([100123]);

      $this->assertSame('33', $result[100123]['address']['hd']);
    }

    public function testGetVotersReplacesSpacesInCountyWithUnderscore(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $data = $this->baseApiResult();
      $data['districts'][1] = ['name' => 'County', 'districtFieldValues' => [['name' => 'Hood River']]];
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse($data)));

      $result = $this->buildApiVoter()->getVoters([100123]);

      $this->assertSame('Hood_River', $result[100123]['address']['county']);
    }

    public function testGetVotersEmptyApiResponseReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturn(new FulfilledPromise($this->makeResponse([])));

      $this->assertSame([], $this->buildApiVoter()->getVoters([100123]));
    }

    public function testGetVotersRejectedRequestReturnsEmpty(): void {
      $this->stubKeyValues();
      $this->stubCycle();
      $this->configurationMock->method('getStateName')->willReturn('Oregon');
      $this->clientMock->method('sendAsync')
        ->willReturn(new RejectedPromise(
          new RequestException('error', $this->createMock(RequestInterface::class))
        ));

      $this->assertSame([], $this->buildApiVoter()->getVoters([100123]));
    }

    public function testGetVotersEmptyListReturnsEmpty(): void {
      $this->assertSame([], $this->buildApiVoter()->getVoters([]));
    }

  }

}
