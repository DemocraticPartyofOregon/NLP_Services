<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\ApiExportJobs;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use GuzzleHttp\ClientInterface;
  use GuzzleHttp\Exception\RequestException;
  use Psr\Http\Message\RequestInterface;
  use Psr\Http\Message\ResponseInterface;
  use Psr\Http\Message\StreamInterface;
  use Psr\Log\LoggerInterface;

  class ApiExportJobsTest extends UnitTestCase {

    private ClientInterface $clientMock;
    private Connection $connectionMock;
    private NlpConfiguration $configurationMock;
    private ApiKeyManagement $apiKeyManagementMock;

    protected function setUp(): void {
      parent::setUp();
      $this->clientMock           = $this->createMock(ClientInterface::class);
      $this->connectionMock       = $this->createMock(Connection::class);
      $this->configurationMock    = $this->createMock(NlpConfiguration::class);
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);

      $loggerMock        = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): ApiExportJobs {
      return new ApiExportJobs(
        $this->clientMock,
        $this->connectionMock,
        $this->configurationMock,
        $this->apiKeyManagementMock,
      );
    }

    private function buildPartialService(int $insertId = 7, int $statusReturn = 3): ApiExportJobs {
      $svc = $this->getMockBuilder(ApiExportJobs::class)
        ->setConstructorArgs([
          $this->clientMock,
          $this->connectionMock,
          $this->configurationMock,
          $this->apiKeyManagementMock,
        ])
        ->onlyMethods(['insertExportJob', 'getExportJobStatus'])
        ->getMock();
      $svc->method('insertExportJob')->willReturn($insertId);
      $svc->method('getExportJobStatus')->willReturn($statusReturn);
      return $svc;
    }

    private function buildInsertMock(int $returnId = 42): Insert {
      $mock = $this->createMock(Insert::class);
      $mock->method('fields')->willReturnSelf();
      $mock->method('execute')->willReturn($returnId);
      return $mock;
    }

    private function buildUpdateMock(int $affectedRows = 1): Update {
      $mock = $this->createMock(Update::class);
      $mock->method('fields')->willReturnSelf();
      $mock->method('condition')->willReturnSelf();
      $mock->method('execute')->willReturn($affectedRows);
      return $mock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $mock = $this->createMock(Select::class);
      $mock->method('condition')->willReturnSelf();
      $mock->method('fields')->willReturnSelf();
      $mock->method('execute')->willReturn($stmtMock);
      return $mock;
    }

    private function buildDeleteMock(): Delete {
      $mock = $this->createMock(Delete::class);
      $mock->method('condition')->willReturnSelf();
      $mock->method('execute')->willReturn(1);
      return $mock;
    }

    private function stubKeyValues(): void {
      $this->apiKeyManagementMock->method('getKeyValues')->willReturn([
        'API Key'  => 'test-key',
        'Url'      => 'api.example.com/v4',
        'App Name' => 'testapp',
      ]);
    }

    private function makeGuzzleException(): RequestException {
      return new RequestException('HTTP error', $this->createMock(RequestInterface::class));
    }

    private function makeResponse(mixed $body): ResponseInterface {
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn(json_encode($body));
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      return $response;
    }

    // -------------------------------------------------------------------------
    // insertExportJob
    // -------------------------------------------------------------------------

    public function testInsertExportJobReturnsEventId(): void {
      $this->connectionMock->method('insert')->willReturn($this->buildInsertMock(42));

      $result = $this->buildService()->insertExportJob([
        'committee' => 'TEST',
        'listId'    => 99,
        'startTime' => 1000000,
        'endTime'   => 0,
      ]);

      $this->assertSame(42, $result);
    }

    public function testInsertExportJobReturnsZeroOnException(): void {
      $mock = $this->createMock(Insert::class);
      $mock->method('fields')->willReturnSelf();
      $mock->method('execute')->willThrowException(new Exception('DB error'));
      $this->connectionMock->method('insert')->willReturn($mock);

      $result = $this->buildService()->insertExportJob([
        'committee' => 'TEST',
        'listId'    => 99,
        'startTime' => 1000000,
        'endTime'   => 0,
      ]);

      $this->assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // endExportJob
    // -------------------------------------------------------------------------

    public function testEndExportJobReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('update')->willReturn($this->buildUpdateMock());

      $this->assertTrue($this->buildService()->endExportJob(42));
    }

    public function testEndExportJobNeverMerges(): void {
      // A late webhook after the entry was deleted must not recreate it.
      $this->connectionMock->expects($this->never())->method('merge');
      $this->connectionMock->expects($this->once())->method('update')
        ->with('nlp_export_jobs')
        ->willReturn($this->buildUpdateMock(0));

      $this->assertTrue($this->buildService()->endExportJob(42));
    }

    public function testEndExportJobReturnsFalseOnException(): void {
      $mock = $this->createMock(Update::class);
      $mock->method('fields')->willReturnSelf();
      $mock->method('condition')->willReturnSelf();
      $mock->method('execute')->willThrowException(new Exception('DB error'));
      $this->connectionMock->method('update')->willReturn($mock);

      $this->assertFalse($this->buildService()->endExportJob(42));
    }

    // -------------------------------------------------------------------------
    // getExportJobStatus
    // -------------------------------------------------------------------------

    public function testGetExportJobStatusReturnsZeroWhenNotFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(FALSE);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(0, $this->buildService()->getExportJobStatus(42));
    }

    public function testGetExportJobStatusReturnsMinusOneWhenEndTimeEmpty(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'eventId'   => 42,
        'committee' => 'TEST',
        'listId'    => 99,
        'startTime' => 1000000,
        'endTime'   => 0,
      ]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame(-1, $this->buildService()->getExportJobStatus(42));
    }

    public function testGetExportJobStatusReturnsElapsedTimeAndDeletesWhenFast(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'eventId'   => 42,
        'committee' => 'TEST',
        'listId'    => 99,
        'startTime' => 1000000,
        'endTime'   => 1000002,  // 2 seconds < 4 → entry is deleted
      ]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());

      $this->assertSame(2, $this->buildService()->getExportJobStatus(42));
    }

    public function testGetExportJobStatusReturnsElapsedTimeWithoutDeleteWhenSlow(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'eventId'   => 42,
        'committee' => 'TEST',
        'listId'    => 99,
        'startTime' => 1000000,
        'endTime'   => 1000005,  // 5 seconds >= 4 → no delete
      ]);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->connectionMock->expects($this->never())->method('delete');

      $this->assertSame(5, $this->buildService()->getExportJobStatus(42));
    }

    public function testGetExportJobStatusReturnsZeroOnException(): void {
      $mock = $this->createMock(Select::class);
      $mock->method('condition')->willReturnSelf();
      $mock->method('fields')->willReturnSelf();
      $mock->method('execute')->willThrowException(new Exception('DB error'));
      $this->connectionMock->method('select')->willReturn($mock);

      $this->assertSame(0, $this->buildService()->getExportJobStatus(42));
    }

    // -------------------------------------------------------------------------
    // getExportJobs
    // -------------------------------------------------------------------------

    public function testGetExportJobsReturnsEmptyOnGuzzleException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException($this->makeGuzzleException());

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99);

      $this->assertSame([], $result);
    }

    public function testGetExportJobsReturnsEmptyOnGenericException(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willThrowException(new Exception('unexpected error'));

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99);

      $this->assertSame([], $result);
    }

    public function testGetExportJobsReturnsErrorArrayWhenListIdInvalid(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')
        ->willThrowException(new Exception('The savedListId 99 must be a valid saved list ID'));

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99);

      $this->assertSame('error', $result['result']);
      $this->assertStringContainsString('turf', $result['msg']);
    }

    public function testGetExportJobsReturnsErrorArrayWhenVanRejectsListId(): void {
      $this->stubKeyValues();
      $body = '{ "errors": [ { "code": "INVALID_PARAMETER", "text": "\'savedListId\' must be a valid saved list ID" } ] }';
      $stream = $this->createMock(StreamInterface::class);
      $stream->method('__toString')->willReturn($body);
      $response = $this->createMock(ResponseInterface::class);
      $response->method('getBody')->willReturn($stream);
      $this->clientMock->method('request')->willThrowException(
        new RequestException('400 Bad Request (truncated...)', $this->createMock(RequestInterface::class), $response)
      );

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99);

      $this->assertSame('error', $result['result']);
      $this->assertStringContainsString('turf', $result['msg']);
    }

    public function testGetExportJobsReturnsExportJobArrayOnSuccess(): void {
      $this->stubKeyValues();
      $this->clientMock->method('request')->willReturn(
        $this->makeResponse(['savedListId' => 99, 'downloadUrl' => 'https://example.com/file.zip'])
      );

      $result = $this->buildPartialService(7)->getExportJobs('TEST', 0, 99);

      $this->assertSame('ok', $result['result']);
      $this->assertSame(99, $result['savedListId']);
      $this->assertSame('https://example.com/file.zip', $result['downloadUrl']);
      $this->assertSame(7, $result['eventId']);
    }

    public function testGetExportJobsWithActivistCodeString(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')->willReturn(
        $this->makeResponse(['savedListId' => 99, 'downloadUrl' => 'https://example.com/file.zip'])
      );

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99, 'NLPVoterAC');

      $this->assertSame('ok', $result['result']);
    }

    public function testGetExportJobsWithActivistCodeArray(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(1001);
      $this->clientMock->method('request')->willReturn(
        $this->makeResponse(['savedListId' => 99, 'downloadUrl' => 'https://example.com/file.zip'])
      );

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99, ['NLPVoterAC', 'NLPHostileAC']);

      $this->assertSame('ok', $result['result']);
    }

    public function testGetExportJobsWithUnknownActivistCodeExcludesItFromPayload(): void {
      $this->stubKeyValues();
      $this->configurationMock->method('getActivistCode')->willReturn(0);
      $this->clientMock->method('request')->willReturn(
        $this->makeResponse(['savedListId' => 99, 'downloadUrl' => 'https://example.com/file.zip'])
      );

      $result = $this->buildPartialService()->getExportJobs('TEST', 0, 99, 'UnknownCode');

      $this->assertSame('ok', $result['result']);
    }

  }

}
