<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\nlpservices\NlpInstructions;
  use PHPUnit\Framework\TestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpInstructionsTest extends TestCase {

    private Connection $connectionMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock = $this->createMock(Connection::class);
    }

    private function buildService(): NlpInstructions {
      return new NlpInstructions($this->connectionMock);
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function buildSelectMock(StatementInterface $stmtMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willReturn($stmtMock);
      return $selectMock;
    }

    public function testCreateFactoryUsesDatabaseServiceFromContainer(): void {
      $container = new ContainerBuilder();
      $container->set('database', $this->connectionMock);

      $service = NlpInstructions::create($container);

      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpInstructions::INSTRUCTIONS_TBL)
        ->willReturn($this->buildMergeMock());

      $service->createInstructions([
        'county' => 'Denver',
        'type' => 'canvass',
        'fileName' => 'instructions.pdf',
        'originalFileName' => 'instructions-original.pdf',
      ]);
    }

    public function testCreateInstructionsCallsMergeWithExpectedKeysAndFields(): void {
      $mergeMock = $this->buildMergeMock();

      $mergeMock->expects($this->once())
        ->method('keys')
        ->with([
          'county' => 'Denver',
          'type' => 'canvass',
        ])
        ->willReturnSelf();

      $mergeMock->expects($this->once())
        ->method('fields')
        ->with([
          'fileName' => 'instructions.pdf',
          'originalFileName' => 'instructions-original.pdf',
        ])
        ->willReturnSelf();

      $this->connectionMock->expects($this->once())
        ->method('merge')
        ->with(NlpInstructions::INSTRUCTIONS_TBL)
        ->willReturn($mergeMock);

      $this->buildService()->createInstructions([
        'county' => 'Denver',
        'type' => 'canvass',
        'fileName' => 'instructions.pdf',
        'originalFileName' => 'instructions-original.pdf',
      ]);
    }

    public function testCreateInstructionsSetsOriginalFileNameToNullWhenMissing(): void {
      $capturedFields = [];
      $mergeMock = $this->buildMergeMock();

      $mergeMock->method('fields')
        ->willReturnCallback(function (array $fields) use (&$capturedFields, $mergeMock): Merge {
          $capturedFields = $fields;
          return $mergeMock;
        });

      $this->connectionMock->method('merge')->willReturn($mergeMock);

      $this->buildService()->createInstructions([
        'county' => 'Denver',
        'type' => 'postcard',
        'fileName' => 'postcard.pdf',
      ]);

      $this->assertArrayHasKey('originalFileName', $capturedFields);
      $this->assertNull($capturedFields['originalFileName']);
    }

    public function testCreateInstructionsExceptionDoesNotPropagate(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->buildService()->createInstructions([
        'county' => 'Denver',
        'type' => 'canvass',
        'fileName' => 'instructions.pdf',
      ]);
      $this->addToAssertionCount(1);
    }

    public function testGetInstructionsReturnsDefaultStructureWhenNoRows(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getInstructions('Denver');

      $this->assertArrayHasKey('canvass', $result);
      $this->assertArrayHasKey('postcard', $result);

      foreach (['county', 'type', 'fileName', 'originalFileName'] as $key) {
        $this->assertSame('', $result['canvass'][$key]);
        $this->assertSame('', $result['postcard'][$key]);
      }
    }

    public function testGetInstructionsReturnsRowsKeyedByInstructionType(): void {
      $canvass = [
        'county' => 'Denver',
        'type' => 'canvass',
        'fileName' => 'canvass.pdf',
        'originalFileName' => 'canvass-orig.pdf',
      ];
      $postcard = [
        'county' => 'Denver',
        'type' => 'postcard',
        'fileName' => 'postcard.pdf',
        'originalFileName' => 'postcard-orig.pdf',
      ];

      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($canvass, $postcard, false);

      $this->connectionMock->method('select')
        ->willReturn($this->buildSelectMock($stmtMock));

      $result = $this->buildService()->getInstructions('Denver');

      $this->assertSame('canvass.pdf', $result['canvass']['fileName']);
      $this->assertSame('postcard.pdf', $result['postcard']['fileName']);
      $this->assertSame('canvass-orig.pdf', $result['canvass']['originalFileName']);
      $this->assertSame('postcard-orig.pdf', $result['postcard']['originalFileName']);
    }

    public function testGetInstructionsExecuteExceptionReturnsEmptyArray(): void {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('execute')->willThrowException(new \Exception('DB error'));

      $this->connectionMock->method('select')->willReturn($selectMock);

      $this->assertSame([], $this->buildService()->getInstructions('Denver'));
    }

  }

}
