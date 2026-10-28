<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\nlpservices\ApiActivistCodes;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\ApiVoter;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryReports;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpVoters;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\NlpDataEntryReports
 * @group nlpservices
 */
class NlpDataEntryReportsTest extends UnitTestCase {

  protected $reportsMock;
  protected $nlsMock;
  protected $apiSurveyQuestionMock;
  protected $votersMock;
  protected $apiVoterMock;
  protected $awardsMock;
  protected $nlpConfigurationMock;
  protected $apiActivistCodesMock;
  protected NlpDataEntryReports $service;

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock           = $this->createMock(NlpReports::class);
    $this->nlsMock               = $this->createMock(NlpNls::class);
    $this->apiSurveyQuestionMock = $this->createMock(ApiSurveyQuestion::class);
    $this->votersMock            = $this->createMock(NlpVoters::class);
    $this->apiVoterMock          = $this->createMock(ApiVoter::class);
    $this->awardsMock            = $this->createMock(NlpAwards::class);
    $this->nlpConfigurationMock  = $this->createMock(NlpConfiguration::class);
    $this->apiActivistCodesMock  = $this->createMock(ApiActivistCodes::class);

    $this->service = new NlpDataEntryReports(
      $this->reportsMock,
      $this->nlsMock,
      $this->apiSurveyQuestionMock,
      $this->votersMock,
      $this->apiVoterMock,
      $this->awardsMock,
      $this->nlpConfigurationMock,
      $this->apiActivistCodesMock,
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  protected function baseReport(array $overrides = []): array {
    return array_merge([
      'vanid'       => '99999',
      'mcid'        => 101,
      'county'      => 'TestCounty',
      'cd'          => 'CD1',
      'contactDate' => '2026-05-30',
      'firstName'   => 'Jane',
      'lastName'    => 'Doe',
      'nickname'    => 'Jane',
      'qid'         => 0,
      'contactId'   => 0,
    ], $overrides);
  }

  protected function baseAction(array $overrides = []): array {
    return array_merge([
      'contactMethod' => 1,
      'turfIndex'     => 42,
    ], $overrides);
  }

  // ---------------------------------------------------------------------------
  // hostileVoter
  // ---------------------------------------------------------------------------

  /**
   * @covers ::hostileVoter
   */
  public function testHostileVoterAppliesActivistCodeWhenHostile(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->method('getAcReportIndex')->willReturn(5);
    $this->votersMock->method('getVoterStatus')->willReturn([]);

    $this->apiActivistCodesMock->expects($this->once())
      ->method('setApiActivistCode')
      ->with($this->callback(static fn($req) => $req['action'] === 'Apply'));

    $this->service->hostileVoter($this->baseReport(), $this->baseAction(['hostile' => TRUE]));
  }

  /**
   * @covers ::hostileVoter
   */
  public function testHostileVoterRemovesActivistCodeWhenNotHostile(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->method('getAcReportIndex')->willReturn(5);
    $this->votersMock->method('getVoterStatus')->willReturn([]);

    $this->apiActivistCodesMock->expects($this->once())
      ->method('setApiActivistCode')
      ->with($this->callback(static fn($req) => $req['action'] === 'Remove'));

    $this->service->hostileVoter($this->baseReport(), $this->baseAction(['hostile' => FALSE]));
  }

  /**
   * @covers ::hostileVoter
   */
  public function testHostileVoterMergesReportAndUpdatesVoterStatus(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->method('getAcReportIndex')->willReturn(7);
    $this->votersMock->method('getVoterStatus')->willReturn(['existing' => 'data']);

    $this->reportsMock->expects($this->once())->method('mergeReport');
    $this->votersMock->expects($this->once())
      ->method('setVoterStatus')
      ->with('99999', $this->callback(static fn($s) => array_key_exists('hostile', $s)));

    $this->service->hostileVoter($this->baseReport(), $this->baseAction(['hostile' => TRUE]));
  }

  /**
   * @covers ::hostileVoter
   */
  public function testHostileVoterReportsResultsAndNlParticipation(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->reportsMock->method('getAcReportIndex')->willReturn(5);
    $this->votersMock->method('getVoterStatus')->willReturn([]);

    $this->nlsMock->expects($this->once())->method('resultsReported')->with(101, 'TestCounty');
    $this->awardsMock->expects($this->once())->method('nlParticipation')->with(101, 'Completed');

    $this->service->hostileVoter($this->baseReport(), $this->baseAction());
  }

  // ---------------------------------------------------------------------------
  // deceasedVoter
  // ---------------------------------------------------------------------------

  /**
   * @covers ::deceasedVoter
   */
  public function testDeceasedVoterCallsSetNlReport(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->reportsMock->expects($this->once())->method('setNlReport')->willReturn(10);

    $this->service->deceasedVoter($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::deceasedVoter
   */
  public function testDeceasedVoterReportsSurveyResponseToVoteBuilder(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->reportsMock->method('setNlReport')->willReturn(10);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => $r['type'] === 'contactAttempt' && $r['vanid'] === '99999'));

    $this->service->deceasedVoter($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::deceasedVoter
   */
  public function testDeceasedVoterSubstitutesSocialMediaWithPhone(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Social Media');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->nlpConfigurationMock->expects($this->once())
      ->method('getContactId')
      ->with('Phone')
      ->willReturn(2);
    $this->reportsMock->method('setNlReport')->willReturn(10);
    // reportSurveyResponse is void — no willReturn needed.

    $this->service->deceasedVoter($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::deceasedVoter
   */
  public function testDeceasedVoterUpdatesTurfVoterAndReportsResults(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->reportsMock->method('setNlReport')->willReturn(10);
    // reportSurveyResponse is void — no willReturn needed.

    $this->votersMock->expects($this->once())
      ->method('updateTurfVoter')
      ->with($this->callback(static fn($v) => $v['attemptedContact'] === 10));
    $this->nlsMock->expects($this->once())->method('resultsReported');
    $this->awardsMock->expects($this->once())->method('nlParticipation');

    $this->service->deceasedVoter($this->baseReport(), $this->baseAction());
  }

  // ---------------------------------------------------------------------------
  // noContactResponse
  // ---------------------------------------------------------------------------

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseCallsSetNlReport(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->expects($this->once())->method('setNlReport')->willReturn(5);

    $this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    );
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseReportsSurveyResponse(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->method('setNlReport')->willReturn(5);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => $r['type'] === 'contactAttempt'));

    $this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    );
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseIncludesCellPhoneWhenAvailable(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([
      'cellPhone'   => '206-555-1234',
      'cellPhoneId' => 77,
    ]);
    $this->reportsMock->method('setNlReport')->willReturn(5);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => ($r['phoneNumber'] ?? '') === '206-555-1234'));

    $this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    );
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseSkipsPhoneContactForWalkMethod(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([
      'cellPhone' => '206-555-1234',
    ]);
    $this->reportsMock->method('setNlReport')->willReturn(5);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => !isset($r['phoneNumber'])));

    $this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    );
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseUpdatesTurfVoterAndReportsResults(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->method('setNlReport')->willReturn(5);
    // reportSurveyResponse is void — no willReturn needed.

    $this->votersMock->expects($this->once())
      ->method('updateTurfVoter')
      ->with($this->callback(static fn($v) => $v['attemptedContact'] === 5));
    $this->nlsMock->expects($this->once())->method('resultsReported');
    $this->awardsMock->expects($this->once())->method('nlParticipation');

    $this->assertTrue($this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    ));
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseSavesNothingWhenResponseUnknown(): void {
    // A response id that isn't one of the SMS Text responses has no name.
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('SMS Text');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('');

    $this->reportsMock->expects($this->never())->method('setNlReport');
    $this->apiSurveyQuestionMock->expects($this->never())->method('reportSurveyResponse');
    $this->votersMock->expects($this->never())->method('updateTurfVoter');
    $this->nlsMock->expects($this->never())->method('resultsReported');
    $this->awardsMock->expects($this->never())->method('nlParticipation');

    $this->assertFalse($this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    ));
  }

  /**
   * @covers ::noContactResponse
   */
  public function testNoContactResponseSavesResponseName(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getContactResponse')->willReturn('Not Home');
    $this->votersMock->method('getVoterById')->willReturn([]);

    $this->reportsMock->expects($this->once())->method('setNlReport')
      ->with($this->callback(static fn($r) => $r['value'] === 'Not Home' && $r['contactType'] === 'Walk'))
      ->willReturn(5);

    $this->service->noContactResponse(
      $this->baseReport(),
      $this->baseAction(['notContacted' => 2])
    );
  }

  // ---------------------------------------------------------------------------
  // badPhone
  // ---------------------------------------------------------------------------

  /**
   * @covers ::badPhone
   */
  public function testBadPhoneRecordsCorrectPhoneTypeInReport(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(4);
    $this->votersMock->method('getVoterCd')->willReturn(1);

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(static fn($r) => $r['text'] === 'cellPhone'));

    $this->service->badPhone(
      'cellPhone',
      $this->baseReport(),
      $this->baseAction(['phoneNumber' => '206-555-1234', 'phoneId' => 99, 'vanid' => '99999'])
    );
  }

  /**
   * @covers ::badPhone
   */
  public function testBadPhoneClearsPhoneFromVoterRecord(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(4);
    $this->votersMock->method('getVoterCd')->willReturn(1);
    $this->reportsMock->method('setNlReport')->willReturn(0);
    // reportSurveyResponse is void — no willReturn needed.

    $this->votersMock->expects($this->once())
      ->method('updateVoterPhone')
      ->with('99999', ['cellPhone' => NULL, 'cellPhoneId' => NULL]);

    $this->service->badPhone(
      'cellPhone',
      $this->baseReport(),
      $this->baseAction(['phoneNumber' => '206-555-1234', 'phoneId' => 99, 'vanid' => '99999'])
    );
  }

  /**
   * @covers ::badPhone
   */
  public function testBadPhoneReportsSurveyResponseToVoteBuilder(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(4);
    $this->votersMock->method('getVoterCd')->willReturn(1);
    $this->reportsMock->method('setNlReport')->willReturn(0);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(
        static fn($r) => $r['type'] === 'contactAttempt' && $r['phoneNumber'] === '206-555-1234'
      ));

    $this->service->badPhone(
      'cellPhone',
      $this->baseReport(),
      $this->baseAction(['phoneNumber' => '206-555-1234', 'phoneId' => 99, 'vanid' => '99999'])
    );
  }

  // ---------------------------------------------------------------------------
  // addNote
  // ---------------------------------------------------------------------------

  /**
   * @covers ::addNote
   */
  public function testAddNoteCreatesNewNoteWhenNoReportIndex(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(static fn($r) => $r['type'] === 'Comment' && $r['text'] === 'Hello voter'));

    $this->service->addNote(
      $this->baseReport(),
      ['note' => 'Hello voter', 'reportIndex' => NULL]
    );
  }

  /**
   * @covers ::addNote
   */
  public function testAddNoteUpdatesExistingNoteWhenReportIndexSet(): void {
    $this->reportsMock->expects($this->never())->method('setNlReport');
    $this->reportsMock->expects($this->once())
      ->method('updateComment')
      ->with($this->callback(static fn($r) => $r['reportIndex'] === 55 && $r['text'] === 'Updated note'));

    $this->service->addNote(
      $this->baseReport(),
      ['note' => 'Updated note', 'reportIndex' => 55]
    );
  }

  /**
   * @covers ::addNote
   */
  public function testAddNoteTruncatesNotesExceedingMaxLength(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $longNote = str_repeat('A', NlpReports::MAX_COMMENT + 50);

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(
        static fn($r) => strlen($r['text']) === NlpReports::MAX_COMMENT
      ));

    $this->service->addNote(
      $this->baseReport(),
      ['note' => $longNote, 'reportIndex' => NULL]
    );
  }

  /**
   * @covers ::addNote
   */
  public function testAddNoteSanitizesHtmlTags(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(
        static fn($r) => !str_contains($r['text'], '<script>')
      ));

    $this->service->addNote(
      $this->baseReport(),
      ['note' => '<script>alert("xss")</script>Hello', 'reportIndex' => NULL]
    );
  }

  /**
   * @covers ::addNote
   */
  public function testAddNoteReplacesNewlinesWithBrTags(): void {
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(
        static fn($r) => str_contains($r['text'], '<br>')
      ));

    $this->service->addNote(
      $this->baseReport(),
      ['note' => "Line one\nLine two", 'reportIndex' => NULL]
    );
  }

  // ---------------------------------------------------------------------------
  // voterMoved
  // ---------------------------------------------------------------------------

  /**
   * @covers ::voterMoved
   */
  public function testVoterMovedReportsSurveyResponseWhenAddressIsSame(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->votersMock->method('fetchVoterAddress')->willReturn(['street' => '123 Main']);
    $this->votersMock->method('addressCompare')->willReturn(TRUE);
    $this->votersMock->method('addressExtract')->willReturn(['street' => '123 Main']);
    $this->apiVoterMock->method('getApiVoter')->willReturn(['address' => ['street' => '123 Main']]);
    $this->reportsMock->method('setNlReport')->willReturn(0);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => $r['type'] === 'contactAttempt'));

    $this->service->voterMoved($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::voterMoved
   */
  public function testVoterMovedSkipsSurveyResponseWhenAddressDiffers(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->votersMock->method('fetchVoterAddress')->willReturn(['street' => '123 Main']);
    $this->votersMock->method('addressCompare')->willReturn(FALSE);
    $this->votersMock->method('addressExtract')->willReturn(['street' => '456 New St']);
    $this->apiVoterMock->method('getApiVoter')->willReturn(['address' => ['street' => '456 New St']]);
    $this->reportsMock->method('setNlReport')->willReturn(0);

    $this->apiSurveyQuestionMock->expects($this->never())->method('reportSurveyResponse');

    $this->service->voterMoved($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::voterMoved
   */
  public function testVoterMovedCallsSetNlReportAndSetsMovedStatus(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->votersMock->method('fetchVoterAddress')->willReturn([]);
    $this->votersMock->method('addressCompare')->willReturn(TRUE);
    $this->votersMock->method('addressExtract')->willReturn([]);
    $this->apiVoterMock->method('getApiVoter')->willReturn(['address' => []]);
    // reportSurveyResponse is void — no willReturn needed.

    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->with($this->callback(static fn($r) => $r['value'] === 'Moved'));
    $this->votersMock->expects($this->once())
      ->method('setMovedStatus')
      ->with(42, '99999', TRUE);

    $this->service->voterMoved($this->baseReport(), $this->baseAction());
  }

  /**
   * @covers ::voterMoved
   */
  public function testVoterMovedReportsResultsAndNlParticipation(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getResponseId')->willReturn(3);
    $this->votersMock->method('fetchVoterAddress')->willReturn([]);
    $this->votersMock->method('addressCompare')->willReturn(FALSE);
    $this->votersMock->method('addressExtract')->willReturn([]);
    $this->apiVoterMock->method('getApiVoter')->willReturn(['address' => []]);
    $this->reportsMock->method('setNlReport')->willReturn(0);

    $this->nlsMock->expects($this->once())->method('resultsReported')->with(101, 'TestCounty');
    $this->awardsMock->expects($this->once())->method('nlParticipation')->with(101, 'Completed');

    $this->service->voterMoved($this->baseReport(), $this->baseAction());
  }

  // ---------------------------------------------------------------------------
  // pledgeToVote
  // ---------------------------------------------------------------------------

  /**
   * @covers ::pledgeToVote
   */
  public function testPledgeToVoteCallsSetNlReport(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'surveyQuestionId' => 10,
      'name'             => 'Pledge',
      'responses'        => [3 => ['name' => 'Yes']],
    ]);
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->expects($this->once())->method('setNlReport')->willReturn(20);

    $this->service->pledgeToVote(
      $this->baseReport(),
      $this->baseAction(['surveyResponse' => 3, 'firstName' => 'Bob'])
    );
  }

  /**
   * @covers ::pledgeToVote
   */
  public function testPledgeToVoteReportsSurveyResponseToVoteBuilder(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'surveyQuestionId' => 10,
      'name'             => 'Pledge',
      'responses'        => [3 => ['name' => 'Yes']],
    ]);
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->method('setNlReport')->willReturn(20);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => $r['type'] === 'pledgeToVote' && $r['qid'] === 10));

    $this->service->pledgeToVote(
      $this->baseReport(),
      $this->baseAction(['surveyResponse' => 3, 'firstName' => 'Bob'])
    );
  }

  /**
   * @covers ::pledgeToVote
   */
  public function testPledgeToVoteUpdatesTurfVoterWithReportIndex(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'surveyQuestionId' => 10,
      'name'             => 'Pledge',
      'responses'        => [3 => ['name' => 'Yes']],
    ]);
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->method('setNlReport')->willReturn(20);
    // reportSurveyResponse is void — no willReturn needed.

    $this->votersMock->expects($this->once())
      ->method('updateTurfVoter')
      ->with($this->callback(static fn($v) => $v['pledgedToVote'] === 20));

    $this->service->pledgeToVote(
      $this->baseReport(),
      $this->baseAction(['surveyResponse' => 3, 'firstName' => 'Bob'])
    );
  }

  /**
   * @covers ::pledgeToVote
   */
  public function testPledgeToVoteIncludesCellPhoneForPhoneContactMethod(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Phone');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'surveyQuestionId' => 10,
      'name'             => 'Pledge',
      'responses'        => [3 => ['name' => 'Yes']],
    ]);
    $this->votersMock->method('getVoterById')->willReturn([
      'cellPhone'   => '206-555-1234',
      'cellPhoneId' => 77,
    ]);
    $this->reportsMock->method('setNlReport')->willReturn(20);

    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->with($this->callback(static fn($r) => ($r['phoneNumber'] ?? '') === '206-555-1234'));

    $this->service->pledgeToVote(
      $this->baseReport(),
      $this->baseAction(['surveyResponse' => 3, 'firstName' => 'Bob'])
    );
  }

  /**
   * @covers ::pledgeToVote
   */
  public function testPledgeToVoteReportsResultsAndNlParticipation(): void {
    $this->nlpConfigurationMock->method('getContactMethod')->willReturn('Walk');
    $this->nlpConfigurationMock->method('getCycle')->willReturn('2026');
    $this->nlpConfigurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'surveyQuestionId' => 10,
      'name'             => 'Pledge',
      'responses'        => [3 => ['name' => 'Yes']],
    ]);
    $this->votersMock->method('getVoterById')->willReturn([]);
    $this->reportsMock->method('setNlReport')->willReturn(20);
    // reportSurveyResponse is void — no willReturn needed.
    $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);

    $this->nlsMock->expects($this->once())->method('resultsReported')->with(101, 'TestCounty');
    $this->awardsMock->expects($this->once())->method('nlParticipation')->with(101, 'Completed');

    $this->service->pledgeToVote(
      $this->baseReport(),
      $this->baseAction(['surveyResponse' => 3, 'firstName' => 'Bob'])
    );
  }

}
