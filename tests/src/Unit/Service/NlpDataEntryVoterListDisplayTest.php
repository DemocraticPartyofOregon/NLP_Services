<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryVoterListDisplay;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\NlpDataEntryVoterListDisplay::class)]
#[CoversMethod(\Drupal\nlpservices\NlpDataEntryVoterListDisplay::class, 'validateDataEntrySelection')]
#[CoversMethod(\Drupal\nlpservices\NlpDataEntryVoterListDisplay::class, 'whatChanged')]
#[CoversMethod(\Drupal\nlpservices\NlpDataEntryVoterListDisplay::class, 'buildDataEntryVoterArray')]
#[Group('nlpservices')]
class NlpDataEntryVoterListDisplayTest extends UnitTestCase {

  protected $reportsMock;
  protected $nlpConfigurationMock;
  protected $sessionDataMock;
  protected $dateFormatterMock;
  protected $moduleListMock;
  protected NlpDataEntryVoterListDisplay $service;

  protected function setUp(): void {
    parent::setUp();

    $this->reportsMock          = $this->createMock(NlpReports::class);
    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);

    $this->dateFormatterMock    = $this->createMock(DateFormatterInterface::class);
    $this->dateFormatterMock->method('format')->willReturn('May 30');

    $this->moduleListMock = $this->createMock(ModuleExtensionList::class);
    $this->moduleListMock->method('getPath')->willReturn('modules/custom/nlpservices');

    $loggerMock        = $this->createMock(LoggerInterface::class);
    $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactoryMock->method('get')->willReturn($loggerMock);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('logger.factory', $loggerFactoryMock);
    \Drupal::setContainer($container);

    $this->service = new NlpDataEntryVoterListDisplay(
      $this->reportsMock,
      $this->nlpConfigurationMock,
      $this->sessionDataMock,
      $this->moduleListMock,
      $this->dateFormatterMock,
    );
  }

  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Minimal voter record sufficient for buildDataEntryVoterArray().
   */
  protected function baseVoter(array $overrides = []): array {
    return array_replace_recursive([
      'vanid'             => '99999',
      'firstName'         => 'Bob',
      'lastName'          => 'Smith',
      'age'               => '55',
      'sex'               => 'M',
      'party'             => 'D',
      'nickname'          => 'Bob',
      'homePhone'         => '',
      'homePhoneId'       => NULL,
      'cellPhone'         => '',
      'cellPhoneId'       => NULL,
      'preferredPhoneType'=> '',
      'votingHistory'     => '',
      'address' => [
        'streetNo'     => '123',
        'streetPrefix' => '',
        'streetName'   => 'Main',
        'streetType'   => 'St',
        'aptType'      => '',
        'aptNo'        => '',
        'city'         => 'Portland',
        'cd'           => 'CD1',
        'moved'        => FALSE,
      ],
      'historic' => [
        'display'      => '',
        'displayLines' => 0,
      ],
      'status'     => ['voted' => '', 'deceased' => FALSE, 'hostile' => FALSE],
      'turfVoter'  => ['pledgedToVote' => 0, 'attemptedContact' => 0],
    ], $overrides);
  }

  /**
   * Build a minimal dataEntry input array as the form API would return it,
   * for a single voter with the given vanid.
   */
  protected function buildDataEntry(string $vanid, array $row2 = [], array $row4 = []): array {
    $defaults2 = [
      'col_2_2' => ['line_2_2_2' => 0],
      'col_2_3' => ['line_2_3_3' => 0],
      'col_2_4' => ['line_2_4_2' => 0],
      'col_2_5' => [
        'line_2_5_2' => 0,
        'line_2_5_3' => 0,
        'line_2_5_4' => 0,
        'line_2_5_5' => '',
        'line_2_5_6' => '',
      ],
    ];
    $defaults4 = ['col_4_1' => ['line_4_1_2' => '']];
    return [
      "row_2_$vanid" => array_replace_recursive($defaults2, $row2),
      "row_4_$vanid" => array_replace_recursive($defaults4, $row4),
    ];
  }

  // ---------------------------------------------------------------------------
  // validateDataEntrySelection
  // ---------------------------------------------------------------------------

  public function testValidateDataEntrySelectionReturnsEmptyForEmptyInput(): void {
    $result = $this->service->validateDataEntrySelection([]);
    $this->assertSame([], $result);
  }

  public function testValidateDataEntrySelectionExtractsContactMethod(): void {
    $dataEntry = $this->buildDataEntry('99999', [
      'col_2_2' => ['line_2_2_2' => 2],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame(2, $result['99999']['contactMethod']);
  }

  public function testValidateDataEntrySelectionExtractsSurveyResponse(): void {
    $dataEntry = $this->buildDataEntry('99999', [
      'col_2_3' => ['line_2_3_3' => 5],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame(5, $result['99999']['surveyResponse']);
  }

  public function testValidateDataEntrySelectionExtractsNotContacted(): void {
    $dataEntry = $this->buildDataEntry('99999', [
      'col_2_4' => ['line_2_4_2' => 3],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame(3, $result['99999']['notContacted']);
  }

  public function testValidateDataEntrySelectionExtractsMovedHostileDeceased(): void {
    $dataEntry = $this->buildDataEntry('99999', [
      'col_2_5' => [
        'line_2_5_2' => 1,
        'line_2_5_3' => 1,
        'line_2_5_4' => 0,
      ],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame(1, $result['99999']['moved']);
    $this->assertSame(1, $result['99999']['hostile']);
    $this->assertSame(0, $result['99999']['deceased']);
  }

  public function testValidateDataEntrySelectionExtractsUpdateContact(): void {
    $dataEntry = $this->buildDataEntry('99999', [
      'col_2_5' => [
        'line_2_5_5' => 'NC',
        'line_2_5_6' => '206-555-9999',
      ],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame('NC', $result['99999']['updateContact']['contactChange']);
    $this->assertSame('206-555-9999', $result['99999']['updateContact']['newContact']);
  }

  public function testValidateDataEntrySelectionExtractsNote(): void {
    $dataEntry = $this->buildDataEntry('99999', [], [
      'col_4_1' => ['line_4_1_2' => 'Spoke with voter.'],
    ]);
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame('Spoke with voter.', $result['99999']['note']);
  }

  public function testValidateDataEntrySelectionHandlesMultipleVoters(): void {
    $dataEntry = array_merge(
      $this->buildDataEntry('11111', ['col_2_2' => ['line_2_2_2' => 1]]),
      $this->buildDataEntry('22222', ['col_2_2' => ['line_2_2_2' => 2]]),
    );
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertArrayHasKey('11111', $result);
    $this->assertArrayHasKey('22222', $result);
    $this->assertSame(1, $result['11111']['contactMethod']);
    $this->assertSame(2, $result['22222']['contactMethod']);
  }

  public function testValidateDataEntrySelectionIgnoresUnknownRowKeys(): void {
    $dataEntry = ['row_9_99999' => ['col_9_1' => 'garbage']];
    $result = $this->service->validateDataEntrySelection($dataEntry);
    $this->assertSame([], $result);
  }

  // ---------------------------------------------------------------------------
  // whatChanged
  // ---------------------------------------------------------------------------

  protected function baseVoterArray(array $overrides = []): array {
    return array_merge([
      'vanid'    => '99999',
      'moved'    => FALSE,
      'hostile'  => FALSE,
      'deceased' => FALSE,
      'note'     => '',
    ], $overrides);
  }

  protected function baseSelection(array $overrides = []): array {
    return array_merge([
      'contactMethod' => 1,
      'surveyResponse' => 0,
      'notContacted'   => 0,
      'moved'          => FALSE,
      'hostile'        => FALSE,
      'deceased'       => FALSE,
      'updateContact'  => ['contactChange' => '', 'newContact' => ''],
      'note'           => '',
    ], $overrides);
  }

  public function testWhatChangedReturnsEmptyForEmptySelections(): void {
    $result = $this->service->whatChanged([], []);
    $this->assertSame([], $result);
  }

  public function testWhatChangedSkipsVoterNotInVoterArrays(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection()];
    $voterArrays = [];
    $result = $this->service->whatChanged($selections, $voterArrays);
    $this->assertArrayNotHasKey('99999', $result);
  }

  public function testWhatChangedReturnsSurveyResponseWhenSet(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['surveyResponse' => 3])];
    $voterArrays = ['99999' => $this->baseVoterArray()];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $this->assertCount(1, $result['99999']);
    $this->assertSame('surveyResponse', $result['99999'][0]['type']);
    $this->assertSame(3, $result['99999'][0]['surveyResponse']);
  }

  public function testWhatChangedReturnsNotContactedWhenSet(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['notContacted' => 4])];
    $voterArrays = ['99999' => $this->baseVoterArray()];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('notContacted', $types);
  }

  public function testWhatChangedReturnsMovedWhenStatusChanges(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['moved' => 1])];
    $voterArrays = ['99999' => $this->baseVoterArray(['moved' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('moved', $types);
  }

  public function testWhatChangedDoesNotReturnMovedWhenStatusUnchanged(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['moved' => FALSE])];
    $voterArrays = ['99999' => $this->baseVoterArray(['moved' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'] ?? [], 'type');
    $this->assertNotContains('moved', $types);
  }

  public function testWhatChangedReturnsHostileWhenStatusChanges(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['hostile' => 1])];
    $voterArrays = ['99999' => $this->baseVoterArray(['hostile' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('hostile', $types);
  }

  public function testWhatChangedDoesNotReturnHostileWhenStatusUnchanged(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['hostile' => FALSE])];
    $voterArrays = ['99999' => $this->baseVoterArray(['hostile' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'] ?? [], 'type');
    $this->assertNotContains('hostile', $types);
  }

  public function testWhatChangedReturnsDeceasedWhenStatusChanges(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['deceased' => 1])];
    $voterArrays = ['99999' => $this->baseVoterArray(['deceased' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('deceased', $types);
  }

  public function testWhatChangedReturnsUpdateContactWhenContactChangeSet(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection([
      'updateContact' => ['contactChange' => 'NC', 'newContact' => '206-555-1234'],
    ])];
    $voterArrays = ['99999' => $this->baseVoterArray()];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('updateContact', $types);
    $updateAction = array_values(array_filter($result['99999'], static fn($a) => $a['type'] === 'updateContact'))[0];
    $this->assertSame('NC', $updateAction['updateContact']['contactChange']);
  }

  public function testWhatChangedDoesNotReturnUpdateContactWhenContactChangeEmpty(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection([
      'updateContact' => ['contactChange' => '', 'newContact' => ''],
    ])];
    $voterArrays = ['99999' => $this->baseVoterArray()];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'] ?? [], 'type');
    $this->assertNotContains('updateContact', $types);
  }

  public function testWhatChangedReturnsNoteWhenNoteChanges(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['note' => 'New note text'])];
    $voterArrays = ['99999' => $this->baseVoterArray(['note' => ''])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('note', $types);
  }

  public function testWhatChangedDoesNotReturnNoteWhenNoteUnchanged(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection(['note' => 'Same note'])];
    $voterArrays = ['99999' => $this->baseVoterArray(['note' => 'Same note'])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'] ?? [], 'type');
    $this->assertNotContains('note', $types);
  }

  public function testWhatChangedUpdatesContactMethodWhenSelectionDiffers(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $this->nlpConfigurationMock->method('getContactMethods')->willReturn([1 => 'Walk', 2 => 'Phone']);

    $this->sessionDataMock->expects($this->once())
      ->method('setVoterContactMethod')
      ->with($this->callback(static fn($v) => $v['code'] === 1 && $v['name'] === 'Walk'));

    $selections  = ['99999' => $this->baseSelection(['contactMethod' => 2, 'surveyResponse' => 3])];
    $voterArrays = ['99999' => $this->baseVoterArray()];

    $result = $this->service->whatChanged($selections, $voterArrays);

    // Action uses the new contactMethod (2), not the saved one.
    $this->assertSame(2, $result['99999'][0]['contactMethod']);
  }

  public function testWhatChangedProducesMultipleActionsForSameVoter(): void {
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 1]);
    $selections  = ['99999' => $this->baseSelection([
      'surveyResponse' => 3,
      'hostile'        => 1,
    ])];
    $voterArrays = ['99999' => $this->baseVoterArray(['hostile' => FALSE])];

    $result = $this->service->whatChanged($selections, $voterArrays);

    $types = array_column($result['99999'], 'type');
    $this->assertContains('surveyResponse', $types);
    $this->assertContains('hostile', $types);
  }

  // ---------------------------------------------------------------------------
  // buildDataEntryVoterArray
  // ---------------------------------------------------------------------------

  public function testBuildDataEntryVoterArraySetsBasicFields(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertSame('99999', $result['vanid']);
    $this->assertSame('Bob', $result['firstName']);
    $this->assertSame('Smith', $result['lastName']);
    $this->assertSame('CD1', $result['cd']);
  }

  public function testBuildDataEntryVoterArraySetsNoteAndReportIndexFromCanvassResults(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([
      'note' => ['text' => 'Talked to voter.', 'reportIndex' => 42],
    ]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertSame('Talked to voter.', $result['note']);
    $this->assertSame(42, $result['reportIndex']);
  }

  public function testBuildDataEntryVoterArraySetsEmptyNoteWhenNoCanvassNote(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertSame('', $result['note']);
    $this->assertSame(0, $result['reportIndex']);
  }

  public function testBuildDataEntryVoterArraySetsDeceasedHostileMovedFlags(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([
      'deceased' => TRUE,
      'hostile'  => FALSE,
      'moved'    => TRUE,
    ]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertTrue($result['deceased']);
    $this->assertFalse($result['hostile']);
    $this->assertTrue($result['moved']);
  }

  public function testBuildDataEntryVoterArrayIncludesBcAndOoForCellPhone(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $voter  = $this->baseVoter(['cellPhone' => '2065551234']);
    $result = $this->service->buildDataEntryVoterArray($voter);

    $this->assertArrayHasKey('BC', $result['contactUpdateOptions']);
    $this->assertArrayHasKey('OO', $result['contactUpdateOptions']);
  }

  public function testBuildDataEntryVoterArrayIncludesBhForHomePhone(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $voter  = $this->baseVoter(['homePhone' => '2065555678']);
    $result = $this->service->buildDataEntryVoterArray($voter);

    $this->assertArrayHasKey('BH', $result['contactUpdateOptions']);
    $this->assertArrayNotHasKey('BC', $result['contactUpdateOptions']);
  }

  public function testBuildDataEntryVoterArrayAlwaysIncludesNcNhNe(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);
    $this->nlpConfigurationMock->method('getStateName')->willReturn('Washington');

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertArrayHasKey('NC', $result['contactUpdateOptions']);
    $this->assertArrayHasKey('NH', $result['contactUpdateOptions']);
    $this->assertArrayHasKey('NE', $result['contactUpdateOptions']);
  }

  public function testBuildDataEntryVoterArrayIncludesLawnSignAndRideWhenNotOregon(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);
    $this->nlpConfigurationMock->method('getStateName')->willReturn('Washington');

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertArrayHasKey('LS', $result['contactUpdateOptions']);
    $this->assertArrayHasKey('RTP', $result['contactUpdateOptions']);
  }

  public function testBuildDataEntryVoterArrayExcludesLawnSignAndRideWhenOregon(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);
    $this->nlpConfigurationMock->method('getStateName')->willReturn('Oregon');

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertArrayNotHasKey('LS', $result['contactUpdateOptions']);
    $this->assertArrayNotHasKey('RTP', $result['contactUpdateOptions']);
  }

  public function testBuildDataEntryVoterArrayFormatsPhoneNumber(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $voter  = $this->baseVoter(['cellPhone' => '2065551234']);
    $result = $this->service->buildDataEntryVoterArray($voter);

    $this->assertSame('(206) 555-1234', $result['cellPhone']);
  }

  public function testBuildDataEntryVoterArraySetsNoAttemptStatusWhenNoContact(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $result = $this->service->buildDataEntryVoterArray($this->baseVoter());

    $this->assertStringContainsString('voter-no-attempt', (string) $result['contactStatusMsg']);
  }

  public function testBuildDataEntryVoterArraySetsDeceasedStatusWhenVoterDeceased(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $voter  = $this->baseVoter(['status' => ['deceased' => TRUE, 'hostile' => FALSE, 'voted' => '']]);
    $result = $this->service->buildDataEntryVoterArray($voter);

    $this->assertStringContainsString('voter-deceased', (string) $result['contactStatusMsg']);
  }

  public function testBuildDataEntryVoterArraySetsHostileStatusWhenVoterHostile(): void {
    $this->reportsMock->method('getCanvassResults')->willReturn([]);
    $this->sessionDataMock->method('getVoterContactMethod')->willReturn(['code' => 0]);

    $voter  = $this->baseVoter(['status' => ['deceased' => FALSE, 'hostile' => TRUE, 'voted' => '']]);
    $result = $this->service->buildDataEntryVoterArray($voter);

    $this->assertStringContainsString('voter-hostile', (string) $result['contactStatusMsg']);
  }

}
