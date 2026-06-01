<?php

// nlp_debug_msg lives in nlpservices.module, which is not autoloaded in unit tests.
// Bracketed namespace syntax is required to declare a global stub alongside a named namespace.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
}

namespace Drupal\Tests\nlpservices\Unit {

  use Drupal;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Query\Merge;
  use Drupal\Core\Database\Query\Select;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpVotersTest extends UnitTestCase {

    private Connection $connectionMock;
    private NlpMatchbacks $matchbacksMock;
    private NlpReports $reportsMock;
    private NlpConfiguration $configurationMock;

    protected function setUp(): void {
      parent::setUp();
      $this->connectionMock  = $this->createMock(Connection::class);
      $this->matchbacksMock  = $this->createMock(NlpMatchbacks::class);
      $this->reportsMock     = $this->createMock(NlpReports::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildVoters(): NlpVoters {
      return new NlpVoters(
        $this->connectionMock,
        $this->matchbacksMock,
        $this->reportsMock,
        $this->configurationMock,
      );
    }

    private function buildSelectMock(StatementInterface $statementMock): Select {
      $selectMock = $this->createMock(Select::class);
      $selectMock->method('addField')->willReturnSelf();
      $selectMock->method('fields')->willReturnSelf();
      $selectMock->method('condition')->willReturnSelf();
      $selectMock->method('join')->willReturn('alias');
      $selectMock->method('leftJoin')->willReturn('alias');
      $selectMock->method('orderBy')->willReturnSelf();
      $selectMock->method('distinct')->willReturnSelf();
      $selectMock->method('execute')->willReturn($statementMock);
      return $selectMock;
    }

    private function buildMergeMock(): Merge {
      $mergeMock = $this->createMock(Merge::class);
      $mergeMock->method('keys')->willReturnSelf();
      $mergeMock->method('fields')->willReturnSelf();
      $mergeMock->method('execute')->willReturn(1);
      return $mergeMock;
    }

    private function setupContainerWithLogger(): void {
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    private function baseAddress(): array {
      return [
        'streetNo'     => '123',
        'streetPrefix' => 'N',
        'streetName'   => 'Main',
        'streetType'   => 'St',
        'streetSuffix' => '',
        'aptType'      => '',
        'aptNo'        => '',
        'city'         => 'Springfield',
      ];
    }

    // -------------------------------------------------------------------------
    // addressCompare
    // -------------------------------------------------------------------------

    public function testAddressCompareSameAddressReturnsTrue(): void {
      $addr = $this->baseAddress();
      $this->assertTrue($this->buildVoters()->addressCompare($addr, $addr));
    }

    public function testAddressCompareDifferentStreetNameReturnsFalse(): void {
      $addr1 = $this->baseAddress();
      $addr2 = array_merge($this->baseAddress(), ['streetName' => 'Elm']);
      $this->assertFalse($this->buildVoters()->addressCompare($addr1, $addr2));
    }

    public function testAddressCompareEmptyAddress1ReturnsFalse(): void {
      $this->assertFalse($this->buildVoters()->addressCompare([], $this->baseAddress()));
    }

    public function testAddressCompareEmptyAddress2ReturnsFalse(): void {
      $this->assertFalse($this->buildVoters()->addressCompare($this->baseAddress(), []));
    }

    // -------------------------------------------------------------------------
    // addressExtract
    // -------------------------------------------------------------------------

    public function testAddressExtractValidAddressReturnsAddressFields(): void {
      $full = array_merge($this->baseAddress(), ['vanid' => 999, 'turfIndex' => 1, 'moved' => 0]);
      $result = $this->buildVoters()->addressExtract($full);
      $this->assertSame($this->baseAddress(), $result);
    }

    public function testAddressExtractEmptyAddressReturnsEmpty(): void {
      $this->assertSame([], $this->buildVoters()->addressExtract([]));
    }

    // -------------------------------------------------------------------------
    // nullVoterStatus
    // -------------------------------------------------------------------------

    public function testNullVoterStatusReturnsAllNullExceptCycle(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $status = $this->buildVoters()->nullVoterStatus();
      $this->assertNull($status['vanid']);
      $this->assertNull($status['deceased']);
      $this->assertNull($status['hostile']);
      $this->assertNull($status['voter']);
      $this->assertNull($status['voted']);
      $this->assertSame('2024', $status['cycle']);
    }

    // -------------------------------------------------------------------------
    // fetchVanIdsByNl
    // -------------------------------------------------------------------------

    public function testFetchVanIdsByNlReturnsKeyedVanids(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls(['vanid' => 101], ['vanid' => 202], false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildVoters()->fetchVanIdsByNl(5);
      $this->assertSame([101 => 101, 202 => 202], $result);
    }

    public function testFetchVanIdsByNlEmptyResultReturnsEmptyArray(): void {
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame([], $this->buildVoters()->fetchVanIdsByNl(5));
    }

    public function testFetchVanIdsByNlExceptionReturnsEmptyArray(): void {
      $this->connectionMock->method('select')->willThrowException(new \Exception('DB error'));
      $this->assertSame([], $this->buildVoters()->fetchVanIdsByNl(5));
    }

    // -------------------------------------------------------------------------
    // getVoterStatus
    // -------------------------------------------------------------------------

    public function testGetVoterStatusNoRecordReturnsNullStatus(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn(false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->matchbacksMock->method('getMatchbackDate')->willReturn('');
      $status = $this->buildVoters()->getVoterStatus(101);
      $this->assertNull($status['voted']);
      $this->assertSame('2024', $status['cycle']);
    }

    public function testGetVoterStatusExistingStatusWithVotedReturnsStatus(): void {
      $existing = ['vanid' => 101, 'cycle' => '2024', 'voted' => '2024-11-05',
        'deceased' => NULL, 'hostile' => NULL, 'voter' => NULL];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($existing);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $result = $this->buildVoters()->getVoterStatus(101);
      $this->assertSame('2024-11-05', $result['voted']);
    }

    public function testGetVoterStatusMatchbackFoundUpdatesVotedDate(): void {
      $existing = ['vanid' => 101, 'cycle' => '2024', 'voted' => NULL,
        'deceased' => NULL, 'hostile' => NULL, 'voter' => NULL];
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturn($existing);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->matchbacksMock->method('getMatchbackDate')->willReturn('2024-11-05');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $result = $this->buildVoters()->getVoterStatus(101);
      $this->assertSame('2024-11-05', $result['voted']);
    }

    // -------------------------------------------------------------------------
    // setVoterStatus
    // -------------------------------------------------------------------------

    public function testSetVoterStatusWithCycleReturnsTrue(): void {
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $result = $this->buildVoters()->setVoterStatus(101, ['cycle' => '2024', 'voted' => '2024-11-05']);
      $this->assertTrue($result);
    }

    public function testSetVoterStatusWithoutCycleFillsFromConfig(): void {
      $this->configurationMock->expects($this->once())->method('getCycle')->willReturn('2024');
      $this->connectionMock->method('merge')->willReturn($this->buildMergeMock());
      $result = $this->buildVoters()->setVoterStatus(101, ['voted' => '2024-11-05']);
      $this->assertTrue($result);
    }

    public function testSetVoterStatusExceptionReturnsFalse(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $result = $this->buildVoters()->setVoterStatus(101, ['cycle' => '2024']);
      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // updateVoterPhone
    // -------------------------------------------------------------------------

    public function testUpdateVoterPhoneCallsMerge(): void {
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->once())->method('execute')->willReturn(1);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $this->buildVoters()->updateVoterPhone(101, ['cellPhone' => '5035551234', 'cellPhoneId' => NULL]);
    }

    public function testUpdateVoterPhoneExceptionHandledGracefully(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $this->buildVoters()->updateVoterPhone(101, ['cellPhone' => '5035551234']);
      $this->assertTrue(TRUE);
    }

    // -------------------------------------------------------------------------
    // createTurfVoter
    // -------------------------------------------------------------------------

    public function testCreateTurfVoterAddsCycleAndReturnsTrue(): void {
      $this->configurationMock->expects($this->once())->method('getCycle')->willReturn('2024');
      $insertMock = $this->createMock(Insert::class);
      $insertMock->method('fields')->willReturnSelf();
      $insertMock->method('execute')->willReturn('1');
      $this->connectionMock->method('insert')->willReturn($insertMock);
      $result = $this->buildVoters()->createTurfVoter(['vanid' => 101, 'mcid' => 55]);
      $this->assertTrue($result);
    }

    public function testCreateTurfVoterExceptionReturnsFalse(): void {
      $this->configurationMock->method('getCycle')->willReturn('2024');
      $this->connectionMock->method('insert')->willThrowException(new \Exception('DB error'));
      $result = $this->buildVoters()->createTurfVoter(['vanid' => 101, 'mcid' => 55]);
      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // updateTurfVoter
    // -------------------------------------------------------------------------

    public function testUpdateTurfVoterCallsMergeAndReturnsTrue(): void {
      $mergeMock = $this->buildMergeMock();
      $mergeMock->expects($this->once())->method('execute')->willReturn(2);
      $this->connectionMock->method('merge')->willReturn($mergeMock);
      $result = $this->buildVoters()->updateTurfVoter(['turfIndex' => 10, 'vanid' => 101, 'mcid' => 55]);
      $this->assertTrue($result);
    }

    public function testUpdateTurfVoterExceptionReturnsFalse(): void {
      $this->connectionMock->method('merge')->willThrowException(new \Exception('DB error'));
      $result = $this->buildVoters()->updateTurfVoter(['turfIndex' => 10, 'vanid' => 101]);
      $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // getDoorCount
    // -------------------------------------------------------------------------

    public function testGetDoorCountEmptyVanidsReturnsZero(): void {
      $this->assertSame(0, $this->buildVoters()->getDoorCount([]));
    }

    public function testGetDoorCountOneVoterReturnsOneDoor(): void {
      $this->setupContainerWithLogger();
      $address = array_merge($this->baseAddress(), ['vanid' => 101, 'turfIndex' => 1]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')->willReturnOnConsecutiveCalls($address, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(1, $this->buildVoters()->getDoorCount([101]));
    }

    public function testGetDoorCountTwoVotersSameAddressReturnsOneDoor(): void {
      $this->setupContainerWithLogger();
      $address = array_merge($this->baseAddress(), ['vanid' => 101, 'turfIndex' => 1]);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls($address, false, $address, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(1, $this->buildVoters()->getDoorCount([101, 102]));
    }

    public function testGetDoorCountTwoVotersDifferentAddressReturnsTwoDoors(): void {
      $this->setupContainerWithLogger();
      $address1 = array_merge($this->baseAddress(), ['vanid' => 101, 'turfIndex' => 1]);
      $address2 = array_merge($this->baseAddress(), ['vanid' => 102, 'turfIndex' => 1, 'streetNo' => '456']);
      $stmtMock = $this->createMock(StatementInterface::class);
      $stmtMock->method('fetchAssoc')
        ->willReturnOnConsecutiveCalls($address1, false, $address2, false);
      $this->connectionMock->method('select')->willReturn($this->buildSelectMock($stmtMock));
      $this->assertSame(2, $this->buildVoters()->getDoorCount([101, 102]));
    }

  }

}
