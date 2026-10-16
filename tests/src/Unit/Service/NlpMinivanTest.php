<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\State\StateInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\ApiSurveyQuestion;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpImap;
  use Drupal\nlpservices\NlpMinivan;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpMinivanTest extends UnitTestCase {

    private ConfigFactoryInterface $configFactoryMock;
    private NlpReports $reportsMock;
    private NlpVoters $votersMock;
    private NlpNls $nlsMock;
    private NlpImap $imapMock;
    private ApiSurveyQuestion $apiSurveyQuestionMock;
    private DrupalUser $drupalUserMock;
    private NlpConfiguration $nlpConfigurationMock;
    private ApiNls $apiNlsMock;
    private NlpCoordinators $coordinatorsMock;
    private NlpSendEmail $sendEmailMock;
    private StateInterface $stateMock;

    protected function setUp(): void {
      parent::setUp();
      $this->configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $this->reportsMock = $this->createMock(NlpReports::class);
      $this->votersMock = $this->createMock(NlpVoters::class);
      $this->nlsMock = $this->createMock(NlpNls::class);
      $this->imapMock = $this->createMock(NlpImap::class);
      $this->apiSurveyQuestionMock = $this->createMock(ApiSurveyQuestion::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
      $this->apiNlsMock = $this->createMock(ApiNls::class);
      $this->coordinatorsMock = $this->createMock(NlpCoordinators::class);
      $this->sendEmailMock = $this->createMock(NlpSendEmail::class);
      $this->stateMock = $this->createMock(StateInterface::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpMinivan {
      return new NlpMinivan(
        $this->configFactoryMock,
        $this->reportsMock,
        $this->votersMock,
        $this->nlsMock,
        $this->imapMock,
        $this->apiSurveyQuestionMock,
        $this->drupalUserMock,
        $this->nlpConfigurationMock,
        $this->apiNlsMock,
        $this->coordinatorsMock,
        $this->sendEmailMock,
        $this->stateMock,
      );
    }

    private function baseNl(array $overrides = []): array {
      return array_replace([
        'nickname' => 'Alice',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'county' => 'Denver',
      ], $overrides);
    }

    private function baseResult(array $overrides = []): array {
      return array_replace([
        'county' => 'Denver',
        'firstName' => 'Alice',
        'lastName' => 'Smith',
        'cycle' => '2026',
      ], $overrides);
    }

    private function setUpLoggerContainer(): void {
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('config.factory', $this->configFactoryMock);
      $container->set('nlpservices.reports', $this->reportsMock);
      $container->set('nlpservices.voters', $this->votersMock);
      $container->set('nlpservices.nls', $this->nlsMock);
      $container->set('nlpservices.imap', $this->imapMock);
      $container->set('nlpservices.survey_question', $this->apiSurveyQuestionMock);
      $container->set('nlpservices.drupal_user', $this->drupalUserMock);
      $container->set('nlpservices.configuration', $this->nlpConfigurationMock);
      $container->set('nlpservices.api_nls', $this->apiNlsMock);
      $container->set('nlpservices.coordinators', $this->coordinatorsMock);
      $container->set('nlpservices.send_email', $this->sendEmailMock);
      $container->set('state', $this->stateMock);

      $service = NlpMinivan::create($container);

      $this->assertInstanceOf(NlpMinivan::class, $service);
    }

    // -------------------------------------------------------------------------
    // extractMinivanFields()
    // -------------------------------------------------------------------------

    public function testExtractMinivanFieldsConvertsIntegerFieldsAndPreservesStrings(): void {
      $result = $this->buildService()->extractMinivanFields(
        ['42', '2026-06-27', '2026-06-28', '14', '33', '44', '55', 'note'],
        [
          'vanid' => 0,
          'dateCanvassed' => 1,
          'dateCreated' => 2,
          'inputTypeId' => 3,
          'surveyQuestionId' => 4,
          'resultId' => 5,
          'contactId' => 6,
          'noteText' => 7,
        ]
      );

      $this->assertSame(42, $result['vanid']);
      $this->assertSame(14, $result['inputTypeId']);
      $this->assertSame('note', $result['noteText']);
    }

    public function testExtractMinivanFieldsDefaultsStringFieldToEmptyStringWhenPositionMissing(): void {
      $result = $this->buildService()->extractMinivanFields(
        ['42'],
        ['vanid' => 0, 'noteText' => 9],
      );

      $this->assertSame(42, $result['vanid']);
      $this->assertSame('', $result['noteText']);
    }

    public function testExtractMinivanFieldsDefaultsIntFieldToZeroWhenPositionMissing(): void {
      $result = $this->buildService()->extractMinivanFields(
        [],
        ['inputTypeId' => 3],
      );

      $this->assertSame(0, $result['inputTypeId']);
    }

    // -------------------------------------------------------------------------
    // headerValidate()
    // -------------------------------------------------------------------------

    public function testHeaderValidateReturnsPositionsForValidCanvassHeader(): void {
      $result = $this->buildService()->headerValidate('canvass', 'myv_van_id,date_canvassed,date_created,input_type_id,contact_type_id,result_id,contacts_contact_id,note_text');

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['vanid']);
      $this->assertSame(7, $result['pos']['noteText']);
    }

    public function testHeaderValidateReturnsPositionsForValidSurveyHeader(): void {
      $result = $this->buildService()->headerValidate('survey', 'myv_van_id,date_canvassed,date_created,input_type_id,survey_question_id,survey_response_id,contacts_contact_id');

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['vanid']);
      $this->assertSame(4, $result['pos']['surveyQuestionId']);
      $this->assertSame(6, $result['pos']['contactId']);
    }

    public function testHeaderValidateReturnsPositionsForValidActivistHeader(): void {
      $result = $this->buildService()->headerValidate('activist', 'myv_van_id,date_created,activist_code_id,contacts_activist_code_id');

      $this->assertTrue($result['ok']);
      $this->assertSame(0, $result['pos']['vanid']);
      $this->assertSame(2, $result['pos']['activistCodeId']);
      $this->assertSame(3, $result['pos']['contactId']);
    }

    public function testHeaderValidateReturnsFalseForUnknownFileType(): void {
      $result = $this->buildService()->headerValidate('unknown', 'a,b,c');

      $this->assertFalse($result['ok']);
    }

    public function testHeaderValidateReturnsFalseWithErrorsForMissingColumn(): void {
      $this->setUpLoggerContainer();

      $result = $this->buildService()->headerValidate('canvass', 'myv_van_id,date_canvassed');

      $this->assertFalse($result['ok']);
      $this->assertNotEmpty($result['err']);
    }

    // -------------------------------------------------------------------------
    // processSurvey()
    // -------------------------------------------------------------------------

    public function testProcessSurveyReturnsPledgedToVoteActionOnValidSurvey(): void {
      $report = [
        'contactId' => 101,
        'surveyQuestionId' => 55,
        'surveyResponseId' => 3,
        'vanid' => 999,
      ];
      $pledgeQuestion = [
        'surveyQuestionId' => 55,
        'name' => 'Pledge to Vote',
        'responses' => [
          3 => ['mediumName' => 'Yes'],
        ],
      ];

      $this->reportsMock->expects($this->once())
        ->method('reportExists')
        ->with('Survey', 101)
        ->willReturn(FALSE);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processSurvey($report, $this->baseResult(), 42, $pledgeQuestion, 7);

      $this->assertTrue($action['processReport']);
      $this->assertFalse($action['mergeReport']);
      $this->assertSame('pledgedToVote', $action['type']);
      $this->assertSame('Survey', $action['result']['type']);
      $this->assertSame('Yes', $action['result']['value']);
      $this->assertSame(1, $action['counts']['newRecordCount']);
    }

    public function testProcessSurveySkipsDuplicateReports(): void {
      $this->reportsMock->method('reportExists')->willReturn(TRUE);

      $action = $this->buildService()->processSurvey(
        ['contactId' => 101, 'surveyQuestionId' => 55, 'surveyResponseId' => 3, 'vanid' => 999],
        $this->baseResult(),
        42,
        ['surveyQuestionId' => 55, 'name' => 'Pledge to Vote', 'responses' => [3 => ['mediumName' => 'Yes']]],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['skippedRecordCount']);
    }

    public function testProcessSurveyRejectsEmptyPledgeQuestion(): void {
      $this->reportsMock->method('reportExists')->willReturn(FALSE);

      $action = $this->buildService()->processSurvey(
        ['contactId' => 101, 'surveyQuestionId' => 55, 'surveyResponseId' => 3, 'vanid' => 999],
        $this->baseResult(),
        42,
        [],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['rejectedRecordCount']);
    }

    public function testProcessSurveyRejectsMismatchedQuestionId(): void {
      $this->reportsMock->method('reportExists')->willReturn(FALSE);

      $action = $this->buildService()->processSurvey(
        ['contactId' => 101, 'surveyQuestionId' => 99, 'surveyResponseId' => 3, 'vanid' => 999],
        $this->baseResult(),
        42,
        ['surveyQuestionId' => 55, 'name' => 'Pledge to Vote', 'responses' => []],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['rejectedRecordCount']);
    }

    public function testProcessSurveyUsesEmptyStringWhenResponseIdNotInList(): void {
      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processSurvey(
        ['contactId' => 101, 'surveyQuestionId' => 55, 'surveyResponseId' => 999, 'vanid' => 999],
        $this->baseResult(),
        42,
        ['surveyQuestionId' => 55, 'name' => 'Pledge to Vote', 'responses' => [3 => ['mediumName' => 'Yes']]],
        7
      );

      $this->assertTrue($action['processReport']);
      $this->assertSame('', $action['result']['value']);
    }

    // -------------------------------------------------------------------------
    // processCanvass()
    // -------------------------------------------------------------------------

    public function testProcessCanvassReturnsNoActionForCanvassedResultId(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => NlpMinivan::CANVASSED,
        'contactTypeId' => 1,
        'noteText' => '',
      ];

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, [], 7);

      $this->assertFalse($action['processReport']);
      $this->assertArrayNotHasKey('counts', $action);
    }

    public function testProcessCanvassSkipsDuplicateContactReport(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => 101,
        'contactTypeId' => 1,
        'noteText' => '',
      ];

      $this->reportsMock->method('reportExists')->willReturn(TRUE);

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, [], 7);

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['skippedRecordCount']);
      $this->assertSame(0, $action['counts']['newRecordCount']);
    }

    public function testProcessCanvassRejectsUnknownContactType(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => 101,
        'contactTypeId' => 99,
        'noteText' => '',
      ];
      $allowedResponseCodes = [
        'Walk' => ['code' => 1, 'responses' => ['Left Message/Lit' => 101]],
      ];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, $allowedResponseCodes, 7);

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['rejectedRecordCount']);
    }

    public function testProcessCanvassReturnsAttemptedContactActionForKnownResponse(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => 101,
        'contactTypeId' => 1,
        'noteText' => '',
      ];
      $allowedResponseCodes = [
        'Walk' => [
          'code' => 1,
          'responses' => ['Left Message/Lit' => 101, 'Contacted' => 102],
        ],
      ];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, $allowedResponseCodes, 7);

      $this->assertTrue($action['processReport']);
      $this->assertSame('attemptedContact', $action['type']);
      $this->assertSame('contact', $action['result']['type']);
      $this->assertSame('Left Message/Lit', $action['result']['value']);
      $this->assertSame('Alice', $action['result']['nickname']);
    }

    public function testProcessCanvassHandlesRefusedResponse(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => 103,
        'contactTypeId' => 1,
        'noteText' => '',
      ];
      $allowedResponseCodes = [
        'Walk' => ['code' => 1, 'responses' => ['Refused' => 103]],
      ];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, $allowedResponseCodes, 7);

      $this->assertTrue($action['processReport']);
      $this->assertSame('attemptedContact', $action['type']);
      $this->assertSame('Refused', $action['result']['value']);
    }

    public function testProcessCanvassWithNoteTextTriggersNoteProcessingAsSideEffect(): void {
      $report = [
        'contactId' => 202,
        'vanid' => 999,
        'resultId' => 101,
        'contactTypeId' => 1,
        'noteText' => 'Voter was friendly.',
      ];
      $allowedResponseCodes = [
        'Walk' => ['code' => 1, 'responses' => ['Left Message/Lit' => 101]],
      ];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->reportsMock->method('setNlReport')->willReturn(5);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->votersMock->method('getTurfIndexByVanid')->willReturn(10);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, $allowedResponseCodes, 7);

      $this->assertTrue($action['processReport']);
      $this->assertSame('attemptedContact', $action['type']);
    }

    public function testProcessCanvassWithNoteTextHandlesMissingNlRecord(): void {
      $report = [
        'contactId' => 203,
        'vanid' => 999,
        'resultId' => 101,
        'contactTypeId' => 1,
        'noteText' => 'Voter was friendly.',
      ];
      $allowedResponseCodes = [
        'Walk' => ['code' => 1, 'responses' => ['Left Message/Lit' => 101]],
      ];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->reportsMock->expects($this->once())->method('setNlReport')
        ->with($this->callback(fn(array $r) => $r['type'] === 'Comment' && $r['county'] === 'Denver'))
        ->willReturn(5);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->votersMock->method('getTurfIndexByVanid')->willReturn(10);
      $this->nlsMock->method('getNlById')->willReturn([]);

      $action = $this->buildService()->processCanvass($report, $this->baseResult(), 42, $allowedResponseCodes, 7);

      $this->assertTrue($action['processReport']);
      $this->assertSame('attemptedContact', $action['type']);
      $this->assertArrayNotHasKey('nickname', $action['result']);
    }

    // -------------------------------------------------------------------------
    // notifyMissingNl()
    // -------------------------------------------------------------------------

    private function vanNl(array $overrides = []): array {
      return array_replace([
        'mcid' => 42,
        'firstName' => 'Bob',
        'lastName' => 'Jones',
        'county' => 'Lane',
        'hd' => '08',
        'precinct' => ' 1234 ',
      ], $overrides);
    }

    private function coordinator(): array {
      return ['firstName' => 'Carol', 'lastName' => 'King', 'email' => 'coord@example.com'];
    }

    public function testNotifyMissingNlEmailsCoordinatorWithNlNameAndRemedy(): void {
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerMock->expects($this->once())->method('notice')
        ->with('Missing NL notification sent to: Carol King [coord@example.com] for Bob Jones, mcid: 42');
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->with('nlpservices')->willReturn($loggerMock);
      $container = new ContainerBuilder();
      $container->set('logger.factory', $loggerFactoryMock);
      \Drupal::setContainer($container);
      $this->stateMock->method('get')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->with('', 42)->willReturn($this->vanNl());
      $this->coordinatorsMock->expects($this->once())->method('getAssignedCoordinator')
        ->with(['county' => 'Lane', 'hd' => '8', 'precinct' => '1234'])
        ->willReturn($this->coordinator());
      $this->sendEmailMock->expects($this->once())->method('sendEmail')
        ->with('missing_nl_notification', 'coord@example.com', $this->anything(),
          $this->callback(fn(string $m) => str_contains($m, 'Bob Jones') && str_contains($m, '42')
            && str_contains($m, 'list of active NLs')))
        ->willReturn(TRUE);
      $this->stateMock->expects($this->once())->method('set')
        ->with(NlpMinivan::MISSING_NL_STATE, $this->callback(fn(array $n) => isset($n[42])));

      $this->assertTrue($this->buildService()->notifyMissingNl(42));
    }

    public function testNotifyMissingNlSkipsNlAlreadyNotifiedToday(): void {
      $this->stateMock->method('get')->willReturn([42 => time() - 3600]);
      $this->apiNlsMock->expects($this->never())->method('getApiNls');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      $this->assertFalse($this->buildService()->notifyMissingNl(42));
    }

    public function testNotifyMissingNlNotifiesAgainAfterADay(): void {
      $this->setUpLoggerContainer();
      $this->stateMock->method('get')->willReturn([42 => time() - NlpMinivan::MISSING_NL_NOTIFY_INTERVAL - 1]);
      $this->apiNlsMock->method('getApiNls')->willReturn($this->vanNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->coordinator());
      $this->sendEmailMock->expects($this->once())->method('sendEmail')->willReturn(TRUE);

      $this->assertTrue($this->buildService()->notifyMissingNl(42));
    }

    public function testNotifyMissingNlRecordsLookupWhenVanHasNoRecord(): void {
      $this->setUpLoggerContainer();
      $this->stateMock->method('get')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn([]);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');
      $this->stateMock->expects($this->once())->method('set')
        ->with(NlpMinivan::MISSING_NL_STATE, $this->callback(fn(array $n) => isset($n[42])));

      $this->assertFalse($this->buildService()->notifyMissingNl(42));
    }

    public function testNotifyMissingNlRecordsLookupWhenNoCoordinatorAssigned(): void {
      $this->setUpLoggerContainer();
      $this->stateMock->method('get')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn($this->vanNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn([]);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');
      $this->stateMock->expects($this->once())->method('set')
        ->with(NlpMinivan::MISSING_NL_STATE, $this->callback(fn(array $n) => isset($n[42])));

      $this->assertFalse($this->buildService()->notifyMissingNl(42));
    }

    public function testNotifyMissingNlRecordsLookupWhenEmailFails(): void {
      $this->stateMock->method('get')->willReturn([]);
      $this->apiNlsMock->method('getApiNls')->willReturn($this->vanNl());
      $this->coordinatorsMock->method('getAssignedCoordinator')->willReturn($this->coordinator());
      $this->sendEmailMock->method('sendEmail')->willReturn(FALSE);
      $this->stateMock->expects($this->once())->method('set')
        ->with(NlpMinivan::MISSING_NL_STATE, $this->callback(fn(array $n) => isset($n[42])));

      $this->assertFalse($this->buildService()->notifyMissingNl(42));
    }

    // -------------------------------------------------------------------------
    // processActivist()
    // -------------------------------------------------------------------------

    public function testProcessActivistSkipsDuplicateContactId(): void {
      $this->reportsMock->method('reportExists')->willReturn(TRUE);

      $action = $this->buildService()->processActivist(
        ['contactId' => 303, 'vanid' => 999, 'activistCodeId' => 77],
        $this->baseResult(),
        42,
        ['activistCodeId' => 77],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['skippedRecordCount']);
    }

    public function testProcessActivistRejectsNonHostileActivistCode(): void {
      $this->reportsMock->method('reportExists')->willReturn(FALSE);

      $action = $this->buildService()->processActivist(
        ['contactId' => 303, 'vanid' => 999, 'activistCodeId' => 99],
        $this->baseResult(),
        42,
        ['activistCodeId' => 77],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['rejectedRecordCount']);
    }

    public function testProcessActivistSkipsAlreadyMarkedHostile(): void {
      $report = [
        'contactId' => 303,
        'vanid' => 999,
        'activistCodeId' => 77,
      ];
      $nlpHostile = ['activistCodeId' => 77];

      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->reportsMock->method('getAcReportIndex')->willReturn(5);

      $action = $this->buildService()->processActivist($report, $this->baseResult(), 42, $nlpHostile, 7);

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['skippedRecordCount']);
    }

    public function testProcessActivistProcessesNewHostileAndReturnsNewRecordCount(): void {
      $this->reportsMock->method('reportExists')->willReturn(FALSE);
      $this->reportsMock->method('getAcReportIndex')->willReturn(0);
      $this->reportsMock->method('setNlReport')->willReturn(5);
      $this->votersMock->method('getVoterCd')->willReturn(1);
      $this->votersMock->method('getVoterStatus')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());

      $action = $this->buildService()->processActivist(
        ['contactId' => 303, 'vanid' => 999, 'activistCodeId' => 77],
        $this->baseResult(),
        42,
        ['activistCodeId' => 77],
        7
      );

      $this->assertFalse($action['processReport']);
      $this->assertSame(1, $action['counts']['newRecordCount']);
    }

    // -------------------------------------------------------------------------
    // fetchReports() / buildReportChunks()
    // -------------------------------------------------------------------------

    public function testFetchReportsSurveyIncludesOnlyMinivanInputTypeRecords(): void {
      // inputTypeId is at position 3; only value 14 is accepted for survey/canvass.
      $pos = ['vanid' => 0, 'dateCanvassed' => 1, 'dateCreated' => 2, 'inputTypeId' => 3,
        'surveyQuestionId' => 4, 'surveyResponseId' => 5, 'contactId' => 6];

      $rawReports = [
        '999,2026-06-01,2026-06-01,14,55,3,101',  // inputTypeId=14 → included
        '888,2026-06-01,2026-06-01,15,55,3,102',  // inputTypeId=15 → excluded
      ];

      $result = $this->buildService()->fetchReports($rawReports, 'survey', 0, $pos);

      $this->assertCount(1, $result[0]);
      $decoded = json_decode($result[0][0], true);
      $this->assertSame(999, $decoded['vanid']);
      $this->assertSame(14, $decoded['inputTypeId']);
    }

    public function testFetchReportsCanvassExcludesNonMinivanRecords(): void {
      $pos = ['vanid' => 0, 'dateCanvassed' => 1, 'dateCreated' => 2, 'inputTypeId' => 3,
        'contactTypeId' => 4, 'resultId' => 5, 'contactId' => 6, 'noteText' => 7];

      $rawReports = ['999,2026-06-01,2026-06-01,99,1,101,202,'];  // inputTypeId=99

      $result = $this->buildService()->fetchReports($rawReports, 'canvass', 0, $pos);

      $this->assertEmpty($result);
    }

    public function testFetchReportsActivistFiltersOnActivistCode(): void {
      $pos = ['vanid' => 0, 'dateCreated' => 1, 'activistCodeId' => 2, 'contactId' => 3];

      $rawReports = [
        '999,2026-06-01,77,303',  // activistCodeId=77 → included
        '888,2026-06-01,99,304',  // activistCodeId=99 → excluded
      ];

      $result = $this->buildService()->fetchReports($rawReports, 'activist', 77, $pos);

      $this->assertCount(1, $result[0]);
      $decoded = json_decode($result[0][0], true);
      $this->assertSame(77, $decoded['activistCodeId']);
    }

    public function testFetchReportsNoteTypeIncludesAllRecords(): void {
      $pos = ['vanid' => 0, 'dateCreated' => 1, 'contactId' => 2];

      $rawReports = ['999,2026-06-01,101', '888,2026-06-01,102'];

      $result = $this->buildService()->fetchReports($rawReports, 'note', 0, $pos);

      $this->assertCount(2, $result[0]);
    }

    public function testFetchReportsTagsEachRecordWithFileType(): void {
      $pos = ['vanid' => 0, 'dateCreated' => 1, 'activistCodeId' => 2, 'contactId' => 3];

      $rawReports = ['999,2026-06-01,77,303'];

      $result = $this->buildService()->fetchReports($rawReports, 'activist', 77, $pos);

      $decoded = json_decode($result[0][0], true);
      $this->assertSame('activist', $decoded['fileType']);
    }

    public function testFetchReportsChunksAtMaxQueueSize(): void {
      $pos = ['vanid' => 0, 'dateCreated' => 1, 'activistCodeId' => 2, 'contactId' => 3];

      $rawReports = [];
      for ($i = 0; $i < NlpMinivan::MINIVAN_MAX_QUEUE + 1; $i++) {
        $rawReports[] = "$i,2026-06-01,77,$i";
      }

      $result = $this->buildService()->fetchReports($rawReports, 'activist', 77, $pos);

      $this->assertCount(2, $result);
      $this->assertCount(NlpMinivan::MINIVAN_MAX_QUEUE, $result[0]);
      $this->assertCount(1, $result[1]);
    }

  }

}
