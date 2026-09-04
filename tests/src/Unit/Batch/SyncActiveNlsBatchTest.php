<?php

namespace Drupal\Tests\nlpservices\Unit\Batch {

  use Drupal;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\Batch\SyncActiveNlsBatch;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpLegislativeFixes;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSyncActiveNlsProcessor;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class SyncActiveNlsBatchTest extends UnitTestCase {

    private MessengerInterface $messengerMock;
    private NlpConfiguration $configMock;
    private MagicWord $magicWordMock;
    private DrupalUser $drupalUserMock;

    protected function setUp(): void {
      parent::setUp();
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->configMock     = $this->createMock(NlpConfiguration::class);
      $this->magicWordMock  = $this->createMock(MagicWord::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);

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

    private function buildProcessor(): NlpSyncActiveNlsProcessor {
      return new NlpSyncActiveNlsProcessor(
        $this->createMock(ApiNls::class),
        $this->createMock(NlpLegislativeFixes::class),
        $this->configMock,
        $this->magicWordMock,
        $this->drupalUserMock,
        $this->createMock(NlpNls::class),
        $this->createMock(NlpAwards::class),
        $this->messengerMock,
      );
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
    // SyncActiveNlsBatch::finished — static batch callback
    // -------------------------------------------------------------------------

    public function testBatchFinishedSuccessAddsTwoStatusMessages(): void {
      $this->messengerMock->expects($this->exactly(2))->method('addStatus');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
      SyncActiveNlsBatch::finished(TRUE, ['recordCount' => 5], []);
    }

    public function testBatchFinishedFailureAddsErrorMessage(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
      SyncActiveNlsBatch::finished(FALSE, [], []);
    }

    // -------------------------------------------------------------------------
    // NlpSyncActiveNlsProcessor::roleConvert
    // -------------------------------------------------------------------------

    public function testRoleConvertAdminRoleReturnsLabel(): void {
      $result = $this->buildProcessor()->roleConvert(['nlp_admin']);
      $this->assertSame(['nlp_admin' => 'NLP Admin'], $result);
    }

    public function testRoleConvertUnknownRoleIsExcluded(): void {
      $this->assertSame([], $this->buildProcessor()->roleConvert(['authenticated', 'editor']));
    }

    public function testRoleConvertAllThreeKnownRolesReturned(): void {
      $result = $this->buildProcessor()->roleConvert(['nlp_admin', 'nlp_coordinator', 'nlp_leader']);
      $this->assertArrayHasKey('nlp_admin', $result);
      $this->assertArrayHasKey('nlp_coordinator', $result);
      $this->assertArrayHasKey('nlp_leader', $result);
      $this->assertCount(3, $result);
    }

    public function testRoleConvertEmptyInputReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildProcessor()->roleConvert([]));
    }

    public function testRoleConvertMixedKnownAndUnknownKeepsOnlyKnown(): void {
      $result = $this->buildProcessor()->roleConvert(['nlp_coordinator', 'authenticated']);
      $this->assertArrayHasKey('nlp_coordinator', $result);
      $this->assertArrayNotHasKey('authenticated', $result);
    }

    // -------------------------------------------------------------------------
    // NlpSyncActiveNlsProcessor::checkDistricts
    // -------------------------------------------------------------------------

    public function testCheckDistrictsValidDistrictsReturnsTrueAndStripsLeadingZero(): void {
      $this->configMock->method('getStateName')->willReturn('Colorado');
      $nl = $this->baseNlRecord();
      $result = $this->buildProcessor()->checkDistricts($nl, []);
      $this->assertTrue($result);
      $this->assertSame('6', $nl['hd']);
      $this->assertSame('6.1', $nl['precinct']);
    }

    public function testCheckDistrictsOregonStripsCountyPrefixFromPrecinct(): void {
      $this->configMock->method('getStateName')->willReturn('Oregon');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '6', 'precinct' => 'Multnomah-042']);
      $result = $this->buildProcessor()->checkDistricts($nl, []);
      $this->assertTrue($result);
      $this->assertSame('042', $nl['precinct']);
    }

    public function testCheckDistrictsOregonPrecinctWithoutHyphenIsUnchanged(): void {
      $this->configMock->method('getStateName')->willReturn('Oregon');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '6', 'precinct' => 'Statewide']);
      $this->buildProcessor()->checkDistricts($nl, []);
      $this->assertSame('Statewide', $nl['precinct']);
    }

    public function testCheckDistrictsMissingHdWithFixRepairsRecordAndReturnsTrue(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl  = array_merge($this->baseNlRecord(), ['hd' => '']);
      $fix = ['hd' => '8', 'precinct' => '8.2'];
      $result = $this->buildProcessor()->checkDistricts($nl, $fix);
      $this->assertTrue($result);
      $this->assertSame('8', $nl['hd']);
      $this->assertSame('8.2', $nl['precinct']);
    }

    public function testCheckDistrictsMissingPrecinctWithFixRepairsAndReturnsTrue(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl  = array_merge($this->baseNlRecord(), ['precinct' => '']);
      $fix = ['hd' => '6', 'precinct' => '6.5'];
      $result = $this->buildProcessor()->checkDistricts($nl, $fix);
      $this->assertTrue($result);
      $this->assertSame('6.5', $nl['precinct']);
    }

    public function testCheckDistrictsMissingHdWithoutFixReturnsFalse(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $nl = array_merge($this->baseNlRecord(), ['hd' => '']);
      $this->assertFalse($this->buildProcessor()->checkDistricts($nl, []));
    }

    public function testCheckDistrictsMissingPrecinctWithoutFixReturnsFalse(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $nl = array_merge($this->baseNlRecord(), ['precinct' => '']);
      $this->assertFalse($this->buildProcessor()->checkDistricts($nl, []));
    }

    public function testCheckDistrictsNicknameUsedInsteadOfFirstNameWhenPresent(): void {
      $this->configMock->method('getStateName')->willReturn('Colorado');
      $this->messengerMock->method('addWarning');
      $nl  = array_merge($this->baseNlRecord(), ['hd' => '', 'nickname' => 'Ali']);
      $fix = ['hd' => '9', 'precinct' => '9.1'];
      $this->assertTrue($this->buildProcessor()->checkDistricts($nl, $fix));
    }

    // -------------------------------------------------------------------------
    // NlpSyncActiveNlsProcessor::checkVoterId
    // -------------------------------------------------------------------------

    public function testCheckVoterIdMissingVfidAddsWarning(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 0];
      $this->buildProcessor()->checkVoterId(0, 5, $nl);
    }

    public function testCheckVoterIdMismatchedVfidAddsWarning(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 99];
      $this->buildProcessor()->checkVoterId(99, 88, $nl);
    }

    public function testCheckVoterIdMatchingVfidAddsNoMessage(): void {
      $this->messengerMock->expects($this->never())->method('addWarning');
      $this->messengerMock->expects($this->never())->method('addError');
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 50];
      $this->buildProcessor()->checkVoterId(50, 50, $nl);
    }

    public function testCheckVoterIdUnknownSavedVfidUpdatesCredentials(): void {
      $this->magicWordMock->expects($this->once())->method('updateCredentials')
        ->with(['mcid' => 42, 'vfid' => 77]);
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn([]);
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 77];
      $this->buildProcessor()->checkVoterId(77, 0, $nl);
    }

    public function testCheckVoterIdDuplicateVfidAddsErrorAndReturnsFalse(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 99]);
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 55];
      $result = $this->buildProcessor()->checkVoterId(55, 0, $nl);
      $this->assertFalse($result);
    }

    public function testCheckVoterIdSameMcidVfidMatchContinues(): void {
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->never())->method('addWarning');
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 42]);
      $nl = ['mcid' => 42, 'firstName' => 'Alice', 'lastName' => 'Smith', 'vfid' => 55];
      $result = $this->buildProcessor()->checkVoterId(55, 55, $nl);
      $this->assertTrue($result);
    }

  }

}
