<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Delete;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpLegislativeFixes;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpLegislativeFixesTest extends UnitTestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    private function buildService(): NlpLegislativeFixes {
      return new NlpLegislativeFixes($this->connectionMock);
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function buildDeleteMock(): Delete {
      $deleteMock = $this->createMock(Delete::class);
      $deleteMock->method('condition')->willReturnSelf();
      $deleteMock->method('execute')->willReturn(1);
      return $deleteMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    public function testCreateFactoryUsesDatabaseServiceFromContainer(): void {
      $container = new ContainerBuilder();
      $container->set('database', $this->connectionMock);

      $service = NlpLegislativeFixes::create($container);

      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpLegislativeFixes::LEG_FIX_TBL)
        ->willReturn($this->buildMergeMock());

      $service->createLegFix([
        'mcid' => 42,
        'county' => 'Denver',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'cd' => '1',
        'hd' => '2',
        'precinct' => '3',
      ]);
    }

    public function testCreateLegFixFiltersOutUnexpectedFields(): void {
      $capturedKeys = [];
      $capturedFields = [];
      $mergeMock = $this->buildMergeMock();
      $mergeMock->method('keys')
        ->willReturnCallback(function (array $keys) use (&$capturedKeys, $mergeMock): Merge {
          $capturedKeys = $keys;
          return $mergeMock;
        });
      $mergeMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $mergeMock): Merge {
          $capturedFields = $fields;
          return $mergeMock;
        });

      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $result = $this->buildService()->createLegFix([
        'mcid' => 42,
        'county' => 'Denver',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'cd' => '1',
        'hd' => '2',
        'precinct' => '3',
        'extra' => 'ignored',
      ]);

      $this->assertTrue($result);
      $this->assertSame(['mcid' => 42], $capturedKeys);
      $this->assertCount(7, $capturedFields);
      $this->assertArrayNotHasKey('extra', $capturedFields);
      $this->assertSame('Denver', $capturedFields['county']);
      $this->assertSame('Alice', $capturedFields['firstName']);
    }

    public function testCreateLegFixReturnsFalseOnException(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->createLegFix([
        'mcid' => 42,
        'county' => 'Denver',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'cd' => '1',
        'hd' => '2',
        'precinct' => '3',
      ]));
    }

    public function testGetLegFixesReturnsRowsKeyedByMcid(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls(
        [
          'mcid' => 10,
          'county' => 'Denver',
          'firstName' => 'Alice',
          'lastName' => 'Smith',
          'cd' => '1',
          'hd' => '2',
          'precinct' => '3',
        ],
        [
          'mcid' => 20,
          'county' => 'Denver',
          'firstName' => 'Bob',
          'lastName' => 'Jones',
          'cd' => '1',
          'hd' => '2',
          'precinct' => '4',
        ],
        false,
      );

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getLegFixes('Denver');

      $this->assertArrayHasKey(10, $result);
      $this->assertArrayHasKey(20, $result);
      $this->assertSame('Smith', $result[10]['lastName']);
      $this->assertSame('Jones', $result[20]['lastName']);
    }

    public function testGetLegFixesAppliesExpectedSortOrder(): void {
      $orderBys = [];
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')
        ->willReturnCallback(function (string $field) use (&$orderBys, $selectMock): Select {
          $orderBys[] = $field;
          return $selectMock;
        });

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $selectMock->method('execute')->willReturn($stmtMock);

      $this->connectionMock->method('select')->willReturn($selectMock);

      $this->buildService()->getLegFixes('Denver');

      $this->assertSame(['hd', 'precinct', 'lastName', 'firstName'], $orderBys);
    }

    public function testGetLegFixesReturnsEmptyArrayOnException(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));

      $this->connectionMock->method('select')->willReturn($selectMock);

      $this->assertSame([], $this->buildService()->getLegFixes('Denver'));
    }

    public function testDeleteLegFixReturnsTrueOnSuccess(): void {
      $this->connectionMock->method('delete')->willReturn($this->buildDeleteMock());

      $this->assertTrue($this->buildService()->deleteLegFix(42));
    }

    public function testDeleteLegFixReturnsFalseOnException(): void {
      $this->connectionMock->method('delete')->willThrowException(new \Exception('DB error'));

      $this->assertFalse($this->buildService()->deleteLegFix(42));
    }

    public function testGetLegFixReturnsRowWhenFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn([
        'mcid' => 42,
        'county' => 'Denver',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'cd' => '1',
        'hd' => '2',
        'precinct' => '3',
      ]);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getLegFix(42);

      $this->assertSame(42, $result['mcid']);
      $this->assertSame('Smith', $result['lastName']);
    }

    public function testGetLegFixReturnsEmptyArrayWhenNotFound(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $this->assertSame([], $this->buildService()->getLegFix(42));
    }

    public function testGetLegFixReturnsEmptyArrayOnException(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));

      $this->connectionMock->method('select')->willReturn($selectMock);

      $this->assertSame([], $this->buildService()->getLegFix(42));
    }

  }

}
