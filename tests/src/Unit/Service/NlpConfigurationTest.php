<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Config\Config;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\ImmutableConfig;
  use Drupal\nlpservices\ApiKeyManagement;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpEncryption;
  use Drupal\Tests\UnitTestCase;

  class NlpConfigurationTest extends UnitTestCase {

    private ConfigFactoryInterface $configFactoryMock;
    private ImmutableConfig $nlpConfigMock;
    private Config $editableConfigMock;
    private ApiKeyManagement $apiKeyManagementMock;
    private NlpEncryption $nlpEncryptMock;

    private array $sampleResponseCodes = [
      'Walk' => [
        'code'      => 1,
        'responses' => ['Left Message/Lit' => 101, 'Contacted' => 102],
      ],
      'Phone' => [
        'code'      => 2,
        'responses' => ['Left Message' => 201, 'Contacted' => 202],
      ],
    ];

    protected function setUp(): void {
      parent::setUp();
      $this->apiKeyManagementMock = $this->createMock(ApiKeyManagement::class);
      $this->nlpEncryptMock       = $this->createMock(NlpEncryption::class);
      $this->nlpConfigMock        = $this->createMock(ImmutableConfig::class);
      $this->editableConfigMock   = $this->createMock(Config::class);

      $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $this->configFactoryMock->method('get')
        ->with('nlpservices.configuration')
        ->willReturn($this->nlpConfigMock);
      $this->configFactoryMock->method('getEditable')
        ->with('nlpservices.configuration')
        ->willReturn($this->editableConfigMock);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildConfiguration(): NlpConfiguration {
      return new NlpConfiguration(
        $this->apiKeyManagementMock,
        $this->nlpEncryptMock,
        $this->configFactoryMock,
      );
    }

    private function setupNlpConfigGet(array $map): void {
      $this->nlpConfigMock->method('get')->willReturnMap($map);
    }

    private function baseElectionConfig(): array {
      return [
        'nlp_election_cycle'   => '2024',
        'nlp_cycle_name'       => 'General Election 2024',
        'nlp_election_date'    => '2024-11-05',
        'nlp_ballot_drop_date' => '2024-10-18',
      ];
    }

    private function setupEditableChain(): void {
      $this->editableConfigMock->method('set')->willReturnSelf();
      $this->editableConfigMock->method('save')->willReturnSelf();
    }

    // -------------------------------------------------------------------------
    // Constructor / getCanvassResponseCodes
    // -------------------------------------------------------------------------

    public function testConstructorSetsEmptyCanvassCodesWhenConfigIsNull(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame([], $this->buildConfiguration()->getCanvassResponseCodes());
    }

    public function testConstructorLoadsCanvassCodesFromConfig(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame($this->sampleResponseCodes, $this->buildConfiguration()->getCanvassResponseCodes());
    }

    // -------------------------------------------------------------------------
    // getDebug
    // -------------------------------------------------------------------------

    public function testGetDebugReturnsFalseWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertFalse($this->buildConfiguration()->getDebug());
    }

    public function testGetDebugReturnsTrueWhenEnabled(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-debug', true],
      ]);
      $this->assertTrue($this->buildConfiguration()->getDebug());
    }

    // -------------------------------------------------------------------------
    // setDebug
    // -------------------------------------------------------------------------

    public function testSetDebugSavesValueToConfig(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->editableConfigMock->expects($this->once())
        ->method('set')->with('nlpservices-debug', true)->willReturnSelf();
      $this->editableConfigMock->expects($this->once())->method('save')->willReturnSelf();
      $this->buildConfiguration()->setDebug(true);
    }

    // -------------------------------------------------------------------------
    // set
    // -------------------------------------------------------------------------

    public function testSetSavesArbitraryKeyValue(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->editableConfigMock->expects($this->once())
        ->method('set')->with('custom-key', 'custom-value')->willReturnSelf();
      $this->editableConfigMock->method('save')->willReturnSelf();
      $this->buildConfiguration()->set('custom-key', 'custom-value');
    }

    // -------------------------------------------------------------------------
    // getActivistCode
    // -------------------------------------------------------------------------

    public function testGetActivistCodeReturnsZeroWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame(0, $this->buildConfiguration()->getActivistCode('nlpservices-activist-code-x'));
    }

    public function testGetActivistCodeReturnsConfiguredValue(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-activist-code-x', 456],
      ]);
      $this->assertSame(456, $this->buildConfiguration()->getActivistCode('nlpservices-activist-code-x'));
    }

    // -------------------------------------------------------------------------
    // getCycle
    // -------------------------------------------------------------------------

    public function testGetCycleReturnsEmptyStringWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame('', $this->buildConfiguration()->getCycle());
    }

    public function testGetCycleReturnsConfiguredCycle(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $this->assertSame('2024', $this->buildConfiguration()->getCycle());
    }

    // -------------------------------------------------------------------------
    // getElectionName
    // -------------------------------------------------------------------------

    public function testGetElectionNameReturnsConfiguredName(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $this->assertSame('General Election 2024', $this->buildConfiguration()->getElectionName());
    }

    // -------------------------------------------------------------------------
    // getElectionDate
    // -------------------------------------------------------------------------

    public function testGetElectionDateReturnsRawDateWhenNotFormatted(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $this->assertSame('2024-11-05', $this->buildConfiguration()->getElectionDate());
    }

    public function testGetElectionDateReturnsFormattedDateWhenRequested(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $result = $this->buildConfiguration()->getElectionDate(TRUE);
      $this->assertStringContainsString('November', $result);
      $this->assertStringContainsString('2024', $result);
    }

    // -------------------------------------------------------------------------
    // getBallotDropDate
    // -------------------------------------------------------------------------

    public function testGetBallotDropDateReturnsRawDate(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $this->assertSame('2024-10-18', $this->buildConfiguration()->getBallotDropDate());
    }

    public function testGetBallotDropDateReturnsFormattedDate(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-election-configuration', $this->baseElectionConfig()],
      ]);
      $result = $this->buildConfiguration()->getBallotDropDate(TRUE);
      $this->assertStringContainsString('October', $result);
      $this->assertStringContainsString('2024', $result);
    }

    // -------------------------------------------------------------------------
    // getStateName
    // -------------------------------------------------------------------------

    public function testGetStateNameReturnsEmptyWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame('', $this->buildConfiguration()->getStateName());
    }

    public function testGetStateNameReturnsConfiguredState(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-county-names', ['State' => 'Oregon', 'Multnomah' => [6, 7]]],
      ]);
      $this->assertSame('Oregon', $this->buildConfiguration()->getStateName());
    }

    // -------------------------------------------------------------------------
    // getVoterBuilderUpdateDate
    // -------------------------------------------------------------------------

    public function testGetVoterBuilderUpdateDateReturnsEmptyWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame('', $this->buildConfiguration()->getVoterBuilderUpdateDate());
    }

    public function testGetVoterBuilderUpdateDateReturnsConfiguredDate(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-votebuilder-update-date', '2024-09-01'],
      ]);
      $this->assertSame('2024-09-01', $this->buildConfiguration()->getVoterBuilderUpdateDate());
    }

    // -------------------------------------------------------------------------
    // getCountyNames
    // -------------------------------------------------------------------------

    public function testGetCountyNamesReturnsEmptyWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame([], $this->buildConfiguration()->getCountyNames());
    }

    public function testGetCountyNamesReturnsArrayOfKeys(): void {
      $counties = ['State' => 'Oregon', 'Multnomah' => [6, 7], 'Washington' => [8, 9]];
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-county-names', $counties],
      ]);
      $result = $this->buildConfiguration()->getCountyNames();
      $this->assertContains('Multnomah', $result);
      $this->assertContains('Washington', $result);
    }

    // -------------------------------------------------------------------------
    // getPledgeSurveyQuestion
    // -------------------------------------------------------------------------

    public function testGetPledgeSurveyQuestionReturnsEmptyWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame([], $this->buildConfiguration()->getPledgeSurveyQuestion());
    }

    public function testGetPledgeSurveyQuestionReturnsConfiguredArray(): void {
      $question = ['surveyQuestionId' => 55, 'scriptQuestion' => 'Will you vote?'];
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices_pledge_to_vote', $question],
      ]);
      $this->assertSame($question, $this->buildConfiguration()->getPledgeSurveyQuestion());
    }

    // -------------------------------------------------------------------------
    // getNlParticipationQuestion
    // -------------------------------------------------------------------------

    public function testGetNlParticipationQuestionReturnsEmptyWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame([], $this->buildConfiguration()->getNlParticipationQuestion());
    }

    public function testGetNlParticipationQuestionReturnsConfiguredArray(): void {
      $question = ['surveyQuestionId' => 77, 'scriptQuestion' => 'Did you participate?'];
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices_nl_participation', $question],
      ]);
      $this->assertSame($question, $this->buildConfiguration()->getNlParticipationQuestion());
    }

    // -------------------------------------------------------------------------
    // setCanvassResponseCodes
    // -------------------------------------------------------------------------

    public function testSetCanvassResponseCodesUpdatesInMemoryState(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->setupEditableChain();
      $config = $this->buildConfiguration();
      $config->setCanvassResponseCodes($this->sampleResponseCodes);
      $this->assertSame($this->sampleResponseCodes, $config->getCanvassResponseCodes());
    }

    public function testSetCanvassResponseCodesSavesToConfig(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->editableConfigMock->expects($this->once())
        ->method('set')
        ->with('nlpservices_canvass_response_codes', $this->sampleResponseCodes)
        ->willReturnSelf();
      $this->editableConfigMock->expects($this->once())->method('save')->willReturnSelf();
      $this->buildConfiguration()->setCanvassResponseCodes($this->sampleResponseCodes);
    }

    // -------------------------------------------------------------------------
    // getResponseId
    // -------------------------------------------------------------------------

    public function testGetResponseIdReturnsIdWhenFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame(101, $this->buildConfiguration()->getResponseId('Walk', 'Left Message/Lit'));
    }

    public function testGetResponseIdReturnsZeroWhenMethodNotFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame(0, $this->buildConfiguration()->getResponseId('Email', 'Contacted'));
    }

    public function testGetResponseIdReturnsZeroWhenResponseNotFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame(0, $this->buildConfiguration()->getResponseId('Walk', 'Unknown Response'));
    }

    // -------------------------------------------------------------------------
    // getContactResponses
    // -------------------------------------------------------------------------

    public function testGetContactResponsesReturnsEmptyWhenMethodNotFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame([], $this->buildConfiguration()->getContactResponses(['name' => 'Email']));
    }

    public function testGetContactResponsesReturnsResponseMapForMethod(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $result = $this->buildConfiguration()->getContactResponses(['name' => 'Walk']);
      $this->assertSame(['Left Message/Lit' => 101, 'Contacted' => 102], $result);
    }

    // -------------------------------------------------------------------------
    // getContactResponse
    // -------------------------------------------------------------------------

    public function testGetContactResponseReturnsNameWhenFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame('Left Message/Lit', $this->buildConfiguration()->getContactResponse('Walk', 101));
    }

    public function testGetContactResponseReturnsEmptyStringWhenNotFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame('', $this->buildConfiguration()->getContactResponse('Walk', 999));
    }

    // -------------------------------------------------------------------------
    // getContactId
    // -------------------------------------------------------------------------

    public function testGetContactIdReturnsCodeForMethod(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame(1, $this->buildConfiguration()->getContactId('Walk'));
      $this->assertSame(2, $this->buildConfiguration()->getContactId('Phone'));
    }

    // -------------------------------------------------------------------------
    // getContactMethods
    // -------------------------------------------------------------------------

    public function testGetContactMethodsReturnsCodeToNameMap(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame([1 => 'Walk', 2 => 'Phone'], $this->buildConfiguration()->getContactMethods());
    }

    public function testGetContactMethodsReturnsEmptyWhenNoCodesConfigured(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame([], $this->buildConfiguration()->getContactMethods());
    }

    // -------------------------------------------------------------------------
    // getContactMethod
    // -------------------------------------------------------------------------

    public function testGetContactMethodReturnsNameForCode(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame('Walk', $this->buildConfiguration()->getContactMethod(1));
    }

    public function testGetContactMethodReturnsEmptyStringWhenNotFound(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', $this->sampleResponseCodes],
      ]);
      $this->assertSame('', $this->buildConfiguration()->getContactMethod(99));
    }

    // -------------------------------------------------------------------------
    // getCrosstabCreateTime
    // -------------------------------------------------------------------------

    public function testGetCrosstabCreateTimeReturnsZeroWhenNotSet(): void {
      $this->nlpConfigMock->method('get')->willReturn(null);
      $this->assertSame(0, $this->buildConfiguration()->getCrosstabCreateTime());
    }

    public function testGetCrosstabCreateTimeReturnsConfiguredValue(): void {
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-crosstab-create-time', 1704067200],
      ]);
      $this->assertSame(1704067200, $this->buildConfiguration()->getCrosstabCreateTime());
    }

    // -------------------------------------------------------------------------
    // getNotificationEmail
    // -------------------------------------------------------------------------

    public function testGetNotificationEmailReturnsConfiguredEmail(): void {
      $emailConfig = ['notifications' => ['email' => 'admin@example.com']];
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-email-configuration', $emailConfig],
      ]);
      $this->assertSame('admin@example.com', $this->buildConfiguration()->getNotificationEmail());
    }

    // -------------------------------------------------------------------------
    // getEmailConfiguration
    // -------------------------------------------------------------------------

    public function testGetEmailConfigurationReturnsConfigForType(): void {
      $emailConfig = [
        'notifications' => ['email' => 'admin@example.com'],
        'welcome'       => ['subject' => 'Welcome!'],
      ];
      $this->setupNlpConfigGet([
        ['nlpservices_canvass_response_codes', null],
        ['nlpservices-email-configuration', $emailConfig],
      ]);
      $this->assertSame(['subject' => 'Welcome!'], $this->buildConfiguration()->getEmailConfiguration('welcome'));
    }

  }

}
