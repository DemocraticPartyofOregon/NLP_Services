<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpDataEntryVoters;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryVotersTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpConfiguration $configMock;
    private MessengerInterface $messengerMock;
    private NlpNls $nlsMock;
    private ApiNls $apiNlsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock    = $this->createMock(NlpVoters::class);
      $this->reportsMock   = $this->createMock(NlpReports::class);
      $this->configMock    = $this->createMock(NlpConfiguration::class);
      $this->messengerMock = $this->createMock(MessengerInterface::class);
      $this->nlsMock       = $this->createMock(NlpNls::class);
      $this->apiNlsMock    = $this->createMock(ApiNls::class);

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
      return new NlpDataEntryVoters($this->votersMock, $this->reportsMock, $this->configMock,
        $this->nlsMock, $this->apiNlsMock);
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
    // addNlNames
    // -------------------------------------------------------------------------

    private function report(int $mcid, ?string $nickname, ?string $lastName): array {
      return ['mcid' => $mcid, 'nickname' => $nickname, 'lastName' => $lastName, 'type' => 'Comment'];
    }

    public function testAddNlNamesLeavesReportsThatHaveBothNamesAlone(): void {
      $this->nlsMock->expects($this->never())->method('getNlById');
      $this->apiNlsMock->expects($this->never())->method('getApiNls');
      $this->reportsMock->expects($this->never())->method('updateNlName');

      $contacts = [100 => [$this->report(7, 'Jane', 'Doe')]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesFillsMissingNameFromTheNlsTableAndSavesIt(): void {
      $this->nlsMock->method('getNlById')->with(7)
        ->willReturn(['mcid' => 7, 'nickname' => 'Janie', 'firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->apiNlsMock->expects($this->never())->method('getApiNls');
      $this->reportsMock->expects($this->once())->method('updateNlName')->with(7, 'Janie', 'Doe');

      $result = $this->buildService()->addNlNames([100 => [$this->report(7, NULL, '')]]);

      $this->assertSame('Janie', $result[100][0]['nickname']);
      $this->assertSame('Doe', $result[100][0]['lastName']);
    }

    public function testAddNlNamesUsesFirstNameWhenTheNlHasNoNickname(): void {
      $this->nlsMock->method('getNlById')
        ->willReturn(['mcid' => 7, 'nickname' => '', 'firstName' => 'Jane', 'lastName' => 'Doe']);

      $result = $this->buildService()->addNlNames([100 => [$this->report(7, '', 'Doe')]]);

      $this->assertSame('Jane', $result[100][0]['nickname']);
    }

    public function testAddNlNamesAsksMyCampaignWhenTheNlIsNotInTheNlsTable(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->apiNlsMock->expects($this->once())->method('getApiNls')->with('', 7)
        ->willReturn(['mcid' => 7, 'nickname' => NULL, 'firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->reportsMock->expects($this->once())->method('updateNlName')->with(7, 'Jane', 'Doe');

      $result = $this->buildService()->addNlNames([100 => [$this->report(7, NULL, NULL)]]);

      $this->assertSame('Jane', $result[100][0]['nickname']);
      $this->assertSame('Doe', $result[100][0]['lastName']);
    }

    public function testAddNlNamesMarksTheReportsWhenMyCampaignHasNoSuchNl(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn([]);
      $this->apiNlsMock->method('getMcidStatus')->with('', 7)->willReturn(ApiNls::MCID_MISSING);
      $this->reportsMock->expects($this->never())->method('updateNlName');
      $this->reportsMock->expects($this->once())->method('markNlNameNotFound')->with(7);

      $contacts = [100 => [$this->report(7, NULL, NULL)]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesMarksTheReportsWhenNeitherPlaceHasBothNames(): void {
      // The nls table has only the first name; MyCampaign only the last.
      $this->nlsMock->method('getNlById')
        ->willReturn(['mcid' => 7, 'nickname' => 'Jane', 'firstName' => 'Jane', 'lastName' => '']);
      $this->apiNlsMock->method('getApiNls')
        ->willReturn(['mcid' => 7, 'nickname' => NULL, 'firstName' => NULL, 'lastName' => 'Doe']);
      $this->apiNlsMock->expects($this->never())->method('getMcidStatus');
      $this->reportsMock->expects($this->never())->method('updateNlName');
      $this->reportsMock->expects($this->once())->method('markNlNameNotFound')->with(7);

      $contacts = [100 => [$this->report(7, 'Jane', NULL)]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesAsksMyCampaignWhenTheNlsTableLacksTheLastName(): void {
      $this->nlsMock->method('getNlById')
        ->willReturn(['mcid' => 7, 'nickname' => 'Jane', 'firstName' => 'Jane', 'lastName' => '']);
      $this->apiNlsMock->method('getApiNls')
        ->willReturn(['mcid' => 7, 'nickname' => 'Janie', 'firstName' => 'Jane', 'lastName' => 'Doe']);
      $this->reportsMock->expects($this->once())->method('updateNlName')->with(7, 'Janie', 'Doe');

      $result = $this->buildService()->addNlNames([100 => [$this->report(7, NULL, NULL)]]);

      $this->assertSame('Doe', $result[100][0]['lastName']);
    }

    public function testAddNlNamesDoesNotMarkTheReportsWhenTheApiCannotBeReached(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn([]);
      $this->apiNlsMock->method('getMcidStatus')->willReturn(ApiNls::MCID_UNKNOWN);
      $this->reportsMock->expects($this->never())->method('markNlNameNotFound');

      $contacts = [100 => [$this->report(7, NULL, NULL)]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesDoesNotMarkTheReportsForAnInvalidApiKey(): void {
      $this->nlsMock->method('getNlById')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn(['Invalid key']);
      $this->apiNlsMock->method('getMcidStatus')->willReturn(ApiNls::MCID_UNKNOWN);
      $this->reportsMock->expects($this->never())->method('updateNlName');
      $this->reportsMock->expects($this->never())->method('markNlNameNotFound');

      $contacts = [100 => [$this->report(7, NULL, NULL)]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesSkipsReportsAlreadyMarkedNotFound(): void {
      $this->nlsMock->expects($this->never())->method('getNlById');
      $this->apiNlsMock->expects($this->never())->method('getApiNls');

      $contacts = [100 => [$this->report(7, NULL, NULL) + ['nlNameNotFound' => '1']]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesSkipsReportsWithNoMcid(): void {
      $this->nlsMock->expects($this->never())->method('getNlById');

      $contacts = [100 => [$this->report(0, NULL, NULL)]];
      $this->assertSame($contacts, $this->buildService()->addNlNames($contacts));
    }

    public function testAddNlNamesLooksUpEachNlOnlyOnce(): void {
      // The API can't be reached, so nothing is marked and a second lookup
      // would hit the API again.
      $this->nlsMock->expects($this->once())->method('getNlById')->willReturn([]);
      $this->apiNlsMock->expects($this->once())->method('getApiNls')->willReturn([]);
      $this->apiNlsMock->expects($this->once())->method('getMcidStatus')->willReturn(ApiNls::MCID_UNKNOWN);

      $service = $this->buildService();
      $service->addNlNames([100 => [$this->report(7, NULL, NULL), $this->report(7, '', '')]]);
      $service->addNlNames([200 => [$this->report(7, NULL, NULL)]]);
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
