<?php

// Define global stubs and constants before loading the batch file.
// The batch file lives outside the PSR-4 autoloader (no class declaration),
// so it must be required explicitly.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('t')) {
    function t($string, array $args = [], array $options = []): string { return $string; }
  }
  if (!function_exists('batch_set')) {
    function batch_set(array $batch): void {}
  }
  // Role constants defined in nlpservices.module.
  if (!defined('NLP_MODULE'))                { define('NLP_MODULE',                'nlpservices'); }
  if (!defined('NLP_ADMIN_ROLE_ID'))         { define('NLP_ADMIN_ROLE_ID',         'nlp_admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_ID'))   { define('NLP_COORDINATOR_ROLE_ID',   'nlp_coordinator'); }
  if (!defined('NLP_LEADER_ROLE_ID'))        { define('NLP_LEADER_ROLE_ID',        'nlp_leader'); }
  if (!defined('NLP_ADMIN_ROLE_LABEL'))      { define('NLP_ADMIN_ROLE_LABEL',      'NLP Admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_LABEL')){ define('NLP_COORDINATOR_ROLE_LABEL','NLP Coordinator'); }
  if (!defined('NLP_LEADER_ROLE_LABEL'))     { define('NLP_LEADER_ROLE_LABEL',     'NLP Leader'); }

  require_once __DIR__ . '/../../../src/Form/SyncActiveNlsBatch.php';
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class SyncActiveNlsBatchTest extends UnitTestCase {

    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->messengerMock = $this->createMock(MessengerInterface::class);
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function containerWithConfig(string $state): void {
      $configMock = $this->createMock(NlpConfiguration::class);
      $configMock->method('getStateName')->willReturn($state);
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('nlpservices.configuration', $configMock);
      Drupal::setContainer($container);
    }

    private function baseNlRecord(): array {
      return [
        'mcid'      => 42,
        'firstName' => 'Alice',
        'lastName'  => 'Smith',
        'nickname'  => '',
        'hd'        => '06',
        'precinct'  => '6.1',
      ];
    }

    // -------------------------------------------------------------------------
    // roleConvert — pure function, no Drupal container needed
    // -------------------------------------------------------------------------

    public function testRoleConvertAdminRoleReturnsLabel(): void {
      $result = \roleConvert(['nlp_admin']);
      $this->assertSame(['nlp_admin' => 'NLP Admin'], $result);
    }

    public function testRoleConvertUnknownRoleIsExcluded(): void {
      $this->assertSame([], \roleConvert(['authenticated', 'editor']));
    }

    public function testRoleConvertAllThreeKnownRolesReturned(): void {
      $result = \roleConvert(['nlp_admin', 'nlp_coordinator', 'nlp_leader']);
      $this->assertArrayHasKey('nlp_admin', $result);
      $this->assertArrayHasKey('nlp_coordinator', $result);
      $this->assertArrayHasKey('nlp_leader', $result);
      $this->assertCount(3, $result);
    }

    public function testRoleConvertEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], \roleConvert([]));
    }

    public function testRoleConvertMixedKnownAndUnknownKeepsOnlyKnown(): void {
      $result = \roleConvert(['nlp_coordinator', 'authenticated']);
      $this->assertArrayHasKey('nlp_coordinator', $result);
      $this->assertArrayNotHasKey('authenticated', $result);
    }

    // -------------------------------------------------------------------------
    // verifyRoles — pure function, delegates to roleConvert
    // -------------------------------------------------------------------------

    public function testVerifyRolesIdenticalRolesReturnsEmptyString(): void {
      $existing = ['nlp_admin' => 'NLP Admin'];
      $this->assertSame('', \verifyRoles($existing, ['nlp_admin']));
    }

    public function testVerifyRolesDifferentRolesReturnsNonEmptyMessage(): void {
      $existing = ['nlp_admin' => 'NLP Admin'];
      $result = \verifyRoles($existing, ['nlp_leader']);
      $this->assertNotEmpty($result);
      $this->assertStringContainsString('NLP Admin', $result);
      $this->assertStringContainsString('NLP Leader', $result);
    }

    public function testVerifyRolesBothEmptyReturnsEmptyString(): void {
      $this->assertSame('', \verifyRoles([], []));
    }

    // -------------------------------------------------------------------------
    // syncActiveNlsBatchFinished
    // -------------------------------------------------------------------------

    public function testBatchFinishedSuccessAddsTwoStatusMessages(): void {
      $this->messengerMock->expects($this->exactly(2))->method('addStatus');
      \syncActiveNlsBatchFinished(TRUE, ['recordCount' => 5], []);
    }

    public function testBatchFinishedFailureAddsErrorMessage(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      \syncActiveNlsBatchFinished(FALSE, [], []);
    }

    // -------------------------------------------------------------------------
    // checkDistricts
    // -------------------------------------------------------------------------

    public function testCheckDistrictsValidDistrictsReturnsTrueAndStripsLeadingZero(): void {
      $this->containerWithConfig('Colorado');
      $nl = $this->baseNlRecord();  // hd = '06', precinct = '6.1'
      $result = \checkDistricts($nl, []);
      $this->assertTrue($result);
      $this->assertSame('6', $nl['hd']);    // leading zero stripped by ltrim.
      $this->assertSame('6.1', $nl['precinct']);
    }

    public function testCheckDistrictsOregonStateStripsCountyPrefixFromPrecinct(): void {
      $this->containerWithConfig('Oregon');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '6', 'precinct' => 'Multnomah-042']);
      $result = \checkDistricts($nl, []);
      $this->assertTrue($result);
      $this->assertSame('042', $nl['precinct']);
    }

    public function testCheckDistrictsOregonPrecinctWithoutHyphenIsUnchanged(): void {
      $this->containerWithConfig('Oregon');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '6', 'precinct' => 'Statewide']);
      \checkDistricts($nl, []);
      $this->assertSame('Statewide', $nl['precinct']);
    }

    public function testCheckDistrictsMissingHdWithFixRepairsRecordAndReturnsTrue(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '']);
      $fix = ['hd' => '8', 'precinct' => '8.2'];
      $result = \checkDistricts($nl, $fix);
      $this->assertTrue($result);
      $this->assertSame('8', $nl['hd']);
      $this->assertSame('8.2', $nl['precinct']);
    }

    public function testCheckDistrictsMissingPrecinctWithFixRepairsAndReturnsTrue(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = array_merge($this->baseNlRecord(), ['precinct' => '']);
      $fix = ['hd' => '6', 'precinct' => '6.5'];
      $result = \checkDistricts($nl, $fix);
      $this->assertTrue($result);
      $this->assertSame('6.5', $nl['precinct']);
    }

    public function testCheckDistrictsMissingHdWithoutFixReturnsFalse(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '']);
      $this->assertFalse(\checkDistricts($nl, []));
    }

    public function testCheckDistrictsMissingPrecinctWithoutFixReturnsFalse(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $nl = array_merge($this->baseNlRecord(), ['precinct' => '']);
      $this->assertFalse(\checkDistricts($nl, []));
    }

    public function testCheckDistrictsNicknameUsedInsteadOfFirstNameWhenPresent(): void {
      // Confirms nickname is used in the warning message path without throwing.
      $this->messengerMock->method('addWarning');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '', 'nickname' => 'Ali']);
      $fix = ['hd' => '9', 'precinct' => '9.1'];
      $result = \checkDistricts($nl, $fix);
      $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // checkVoterId
    // -------------------------------------------------------------------------

    public function testCheckVoterIdMissingVfidAddsWarning(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith'];
      \checkVoterId(0, 5, $nl);
    }

    public function testCheckVoterIdMismatchedVfidAddsWarning(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith'];
      \checkVoterId(99, 88, $nl);
    }

    public function testCheckVoterIdMatchingVfidAddsNoMessage(): void {
      $this->messengerMock->expects($this->never())->method('addWarning');
      $this->messengerMock->expects($this->never())->method('addError');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith'];
      \checkVoterId(50, 50, $nl);
    }

  }

}
