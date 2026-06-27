<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpDataEntryVoters;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryVotersTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpConfiguration $configMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock    = $this->createMock(NlpVoters::class);
      $this->reportsMock   = $this->createMock(NlpReports::class);
      $this->configMock    = $this->createMock(NlpConfiguration::class);
      $this->messengerMock = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      \Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpDataEntryVoters {
      return new NlpDataEntryVoters($this->votersMock, $this->reportsMock, $this->configMock);
    }

    private function baseVoter(array $overrides = []): array {
      return array_replace_recursive([
        'vanid'     => 100,
        'lastName'  => 'Smith',
        'firstName' => 'John',
      ], $overrides);
    }

    private function baseContact(string $type, string $cycle = '2024'): array {
      return ['type' => $type, 'cycle' => $cycle];
    }

    private function preFetchedVoter(array $voterOverrides = [], array $contactLists = []): array {
      return array_merge($this->baseVoter($voterOverrides), [
        'status'   => [],
        'contacts' => $contactLists,
        'activist' => [],
        'historic' => [],
      ]);
    }

    private function setupFetchVotersReturning(array $rawVoters): void {
      $this->votersMock->method('fetchVotersByTurf')->willReturn($rawVoters);
      $this->votersMock->method('getVoterStatus')->willReturn([]);
      $this->reportsMock->method('getNlpReports')->willReturn([]);
      $this->reportsMock->method('getNlpAcReport')->willReturn([]);
      $this->reportsMock->method('displayNlReports')->willReturn([]);
    }

    // -------------------------------------------------------------------------
    // fetchVoters
    // -------------------------------------------------------------------------

    public function testFetchVotersReturnsEmptyArrayWhenTurfHasNoVoters(): void {
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $result = $this->buildService()->fetchVoters(42);
      $this->assertSame([], $result);
    }

    public function testFetchVotersCallsFetchVotersByTurfWithCorrectIndex(): void {
      $this->votersMock->expects($this->once())
        ->method('fetchVotersByTurf')
        ->with(77)
        ->willReturn([]);
      $this->buildService()->fetchVoters(77);
    }

    public function testFetchVotersKeysResultByVanid(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 999])]);
      $result = $this->buildService()->fetchVoters(42);
      $this->assertArrayHasKey(999, $result);
    }

    public function testFetchVotersPopulatesStatusContactActivistHistoricFields(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 101])]);
      $voter = $this->buildService()->fetchVoters(42)[101];
      $this->assertArrayHasKey('status', $voter);
      $this->assertArrayHasKey('contacts', $voter);
      $this->assertArrayHasKey('activist', $voter);
      $this->assertArrayHasKey('historic', $voter);
    }

    public function testFetchVotersReturnsAllVotersInTurf(): void {
      $rawVoters = [
        $this->baseVoter(['vanid' => 1]),
        $this->baseVoter(['vanid' => 2]),
        $this->baseVoter(['vanid' => 3]),
      ];
      $this->setupFetchVotersReturning($rawVoters);
      $result = $this->buildService()->fetchVoters(42);
      $this->assertCount(3, $result);
    }

    // -------------------------------------------------------------------------
    // turfCounts
    // -------------------------------------------------------------------------

    public function testTurfCountsReturnsExpectedKeys(): void {
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $this->configMock->method('getCycle')->willReturn('2024');
      $result = $this->buildService()->turfCounts(42);
      $this->assertArrayHasKey('voterCount', $result);
      $this->assertArrayHasKey('votedCount', $result);
      $this->assertArrayHasKey('attemptedCount', $result);
      $this->assertArrayHasKey('contactedCount', $result);
    }

    public function testTurfCountsReturnsAllZerosForEmptyTurf(): void {
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $this->configMock->method('getCycle')->willReturn('2024');
      $result = $this->buildService()->turfCounts(42);
      $this->assertSame(['voterCount' => 0, 'votedCount' => 0, 'attemptedCount' => 0, 'contactedCount' => 0], $result);
    }

    public function testTurfCountsCountsTotalVoters(): void {
      $this->configMock->method('getCycle')->willReturn('2024');
      $preFetched = [
        1 => $this->preFetchedVoter(['vanid' => 1]),
        2 => $this->preFetchedVoter(['vanid' => 2]),
      ];
      $result = $this->buildService()->turfCounts(42, $preFetched);
      $this->assertSame(2, $result['voterCount']);
    }

    public function testTurfCountsCountsVotedVoters(): void {
      $this->configMock->method('getCycle')->willReturn('2024');
      $preFetched = [
        1 => array_merge($this->preFetchedVoter(['vanid' => 1]), ['status' => ['voted' => TRUE]]),
        2 => $this->preFetchedVoter(['vanid' => 2]),
      ];
      $result = $this->buildService()->turfCounts(42, $preFetched);
      $this->assertSame(1, $result['votedCount']);
    }

    public function testTurfCountsSurveyTypeSetsContactedAndAttempted(): void {
      $this->configMock->method('getCycle')->willReturn('2024');
      $contact = $this->baseContact(NlpReports::SURVEY, '2024');
      $preFetched = [
        1 => $this->preFetchedVoter(['vanid' => 1], [[$contact]]),
      ];
      $result = $this->buildService()->turfCounts(42, $preFetched);
      $this->assertSame(1, $result['contactedCount']);
      $this->assertSame(1, $result['attemptedCount']);
    }

    public function testTurfCountsContactTypeSetsAttemptedButNotContacted(): void {
      $this->configMock->method('getCycle')->willReturn('2024');
      $contact = $this->baseContact(NlpReports::CONTACT, '2024');
      $preFetched = [
        1 => $this->preFetchedVoter(['vanid' => 1], [[$contact]]),
      ];
      $result = $this->buildService()->turfCounts(42, $preFetched);
      $this->assertSame(0, $result['contactedCount']);
      $this->assertSame(1, $result['attemptedCount']);
    }

    public function testTurfCountsIgnoresContactsFromDifferentCycle(): void {
      $this->configMock->method('getCycle')->willReturn('2024');
      $contact = $this->baseContact(NlpReports::SURVEY, '2023');
      $preFetched = [
        1 => $this->preFetchedVoter(['vanid' => 1], [[$contact]]),
      ];
      $result = $this->buildService()->turfCounts(42, $preFetched);
      $this->assertSame(0, $result['contactedCount']);
      $this->assertSame(0, $result['attemptedCount']);
    }

    public function testTurfCountsUsesPreFetchedVotersWithoutRefetching(): void {
      $this->votersMock->expects($this->never())->method('fetchVotersByTurf');
      $this->configMock->method('getCycle')->willReturn('2024');
      $preFetched = [1 => $this->preFetchedVoter(['vanid' => 1])];
      $this->buildService()->turfCounts(42, $preFetched);
    }

    public function testTurfCountsFetchesVotersWhenNoneProvided(): void {
      $this->votersMock->expects($this->once())
        ->method('fetchVotersByTurf')
        ->willReturn([]);
      $this->configMock->method('getCycle')->willReturn('2024');
      $this->buildService()->turfCounts(42);
    }

    // -------------------------------------------------------------------------
    // voterSearch
    // -------------------------------------------------------------------------

    public function testVoterSearchReturnsFalseWhenVoterNotFound(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smith'])]);
      $result = $this->buildService()->voterSearch(42, 'Jones', 10);
      $this->assertFalse($result['found']);
    }

    public function testVoterSearchReturnsTrueWhenLastNameMatches(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smith'])]);
      $result = $this->buildService()->voterSearch(42, 'Smith', 10);
      $this->assertTrue($result['found']);
    }

    public function testVoterSearchIsCaseInsensitive(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'SMITH'])]);
      $result = $this->buildService()->voterSearch(42, 'smith', 10);
      $this->assertTrue($result['found']);
    }

    public function testVoterSearchMatchesPartialLastName(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smithson'])]);
      $result = $this->buildService()->voterSearch(42, 'smith', 10);
      $this->assertTrue($result['found']);
    }

    public function testVoterSearchReturnsPageZeroForFirstVoter(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smith'])]);
      $result = $this->buildService()->voterSearch(42, 'Smith', 10);
      $this->assertSame(0, $result['page']);
    }

    public function testVoterSearchReturnsPageZeroWhenNotFound(): void {
      $this->setupFetchVotersReturning([]);
      $result = $this->buildService()->voterSearch(42, 'Nobody', 10);
      $this->assertSame(0, $result['page']);
    }

    public function testVoterSearchCalculatesCorrectPageFromPosition(): void {
      // 15 voters with 'Aardvark' before target 'Zebra' at index 15 → page = floor(15/10) = 1
      $rawVoters = [];
      for ($i = 1; $i <= 15; $i++) {
        $rawVoters[] = $this->baseVoter(['vanid' => $i, 'lastName' => 'Aardvark']);
      }
      $rawVoters[] = $this->baseVoter(['vanid' => 16, 'lastName' => 'Zebra']);
      $this->setupFetchVotersReturning($rawVoters);
      $result = $this->buildService()->voterSearch(42, 'Zebra', 10);
      $this->assertSame(1, $result['page']);
    }

    public function testVoterSearchAddsMessengerStatusWhenNotFound(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smith'])]);
      $this->messengerMock->expects($this->once())->method('addStatus');
      $this->buildService()->voterSearch(42, 'Jones', 10);
    }

    public function testVoterSearchDoesNotAddMessengerStatusWhenFound(): void {
      $this->setupFetchVotersReturning([$this->baseVoter(['vanid' => 1, 'lastName' => 'Smith'])]);
      $this->messengerMock->expects($this->never())->method('addStatus');
      $this->buildService()->voterSearch(42, 'Smith', 10);
    }

  }

}
