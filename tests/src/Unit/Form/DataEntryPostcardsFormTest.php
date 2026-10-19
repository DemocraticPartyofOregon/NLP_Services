<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal\Core\Form\FormState;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\DataEntryPostcardsForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpDataEntryVoters;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpVoters;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'buildPostcardSelection')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\DataEntryPostcardsForm::class, 'reportPostcardMailed')]
#[Group('nlpservices')]
class DataEntryPostcardsFormTest extends UnitTestCase {

  private DrupalUser $drupalUserMock;
  private NlpReports $reportsMock;
  private NlpVoters $votersMock;
  private NlpNls $nlsMock;
  private NlpSessionData $sessionDataMock;
  private NlpDataEntryVoters $dataEntryVotersMock;
  private NlpConfiguration $configurationMock;
  private ApiSurveyQuestion $apiSurveyQuestionMock;

  protected function setUp(): void {
    parent::setUp();

    $this->drupalUserMock        = $this->createMock(DrupalUser::class);
    $this->reportsMock           = $this->createMock(NlpReports::class);
    $this->votersMock            = $this->createMock(NlpVoters::class);
    $this->nlsMock               = $this->createMock(NlpNls::class);
    $this->sessionDataMock       = $this->createMock(NlpSessionData::class);
    $this->dataEntryVotersMock   = $this->createMock(NlpDataEntryVoters::class);
    $this->configurationMock     = $this->createMock(NlpConfiguration::class);
    $this->apiSurveyQuestionMock = $this->createMock(ApiSurveyQuestion::class);
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private function buildFormInstance(): DataEntryPostcardsForm {
    $form = new DataEntryPostcardsForm(
      $this->drupalUserMock,
      $this->reportsMock,
      $this->votersMock,
      $this->nlsMock,
      $this->sessionDataMock,
      $this->dataEntryVotersMock,
      $this->configurationMock,
      $this->apiSurveyQuestionMock,
    );
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']));
    $form->setRequestStack($requestStack);
    return $form;
  }

  private function baseVoter(int $vanid = 11111): array {
    return [
      'vanid'     => $vanid,
      'firstName' => 'Jane',
      'lastName'  => 'Doe',
      'address'   => ['mAddress' => '123 Main St', 'mCity' => 'Portland'],
    ];
  }

  private function baseCanvassResponseCodes(): array {
    return [
      'Postcard' => [
        'responses' => ['Mailed' => 42],
        'code'      => 3,
      ],
    ];
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('data_entry_postcard_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildPostcardSelection
  // -------------------------------------------------------------------------

  public function testBuildPostcardSelectionReturnsEmptyArrayForNoVoters(): void {
    $result = $this->buildFormInstance()->buildPostcardSelection([]);
    $this->assertSame([], $result);
  }

  public function testBuildPostcardSelectionFormatsVoterDisplayString(): void {
    $result = $this->buildFormInstance()->buildPostcardSelection([$this->baseVoter(11111)]);

    $this->assertArrayHasKey(11111, $result);
    $this->assertSame('Jane Doe - 123 Main St, Portland', $result[11111]);
  }

  public function testBuildPostcardSelectionHandlesMultipleVoters(): void {
    $voters = [
      $this->baseVoter(11111),
      ['vanid' => 22222, 'firstName' => 'Bob', 'lastName' => 'Smith',
       'address' => ['mAddress' => '456 Oak Ave', 'mCity' => 'Eugene']],
    ];

    $result = $this->buildFormInstance()->buildPostcardSelection($voters);

    $this->assertCount(2, $result);
    $this->assertArrayHasKey(11111, $result);
    $this->assertArrayHasKey(22222, $result);
    $this->assertSame('Bob Smith - 456 Oak Ave, Eugene', $result[22222]);
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormSetsAllVotersRepAndCanvassDateOnFirstRender(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertFalse($formState->get('allVoters'));
    $this->assertSame(1, $formState->get('rep'));
    $this->assertSame(date('Y-m-d'), $formState->get('canvassDate'));
  }

  public function testBuildFormDoesNotResetStateWhenReenterIsSet(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('allVoters', TRUE);
    $formState->set('rep', 5);
    $formState->set('canvassDate', '2026-01-01');

    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('allVoters'));
    $this->assertSame(5, $formState->get('rep'));
    $this->assertSame('2026-01-01', $formState->get('canvassDate'));
  }

  public function testBuildFormContainsExpectedFormElements(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('postcards', $form);
    $this->assertArrayHasKey('canvassDate', $form['postcards']);
    $this->assertArrayHasKey('allVoters', $form['postcards']);
    $this->assertArrayHasKey('reportMailed', $form);
  }

  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertContains('nlpservices/info-boxes', $form['#attached']['library']);
    $this->assertContains('nlpservices/nlp-table', $form['#attached']['library']);
  }

  public function testBuildFormAppendsSentMarkerToVoterWhoAlreadyReceivedPostcard(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([$this->baseVoter(11111)]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(TRUE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('rep', 1);
    $formState->set('canvassDate', '2026-06-27');
    $formState->set('allVoters', FALSE);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $checkboxKey = 'voter-1-11111';
    $this->assertArrayHasKey($checkboxKey, $form['postcards']);
    $this->assertStringContainsString('**', $form['postcards'][$checkboxKey]['#title']);
  }

  public function testBuildFormDoesNotAppendSentMarkerForUnsent(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([$this->baseVoter(11111)]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('rep', 1);
    $formState->set('canvassDate', '2026-06-27');
    $formState->set('allVoters', FALSE);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertStringNotContainsString('**', $form['postcards']['voter-1-11111']['#title']);
  }

  public function testBuildFormSetsCheckboxDefaultToOneWhenAllVotersSelectedAndPostcardNotSent(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([$this->baseVoter(11111)]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(FALSE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('rep', 1);
    $formState->set('canvassDate', '2026-06-27');
    $formState->set('allVoters', TRUE);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame(1, $form['postcards']['voter-1-11111']['#default_value']);
  }

  public function testBuildFormSetsCheckboxDefaultToZeroWhenPostcardAlreadySentEvenWithAllVoters(): void {
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->dataEntryVotersMock->method('fetchVoters')->willReturn([$this->baseVoter(11111)]);
    $this->reportsMock->method('voterSentPostcard')->willReturn(TRUE);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('rep', 1);
    $formState->set('canvassDate', '2026-06-27');
    $formState->set('allVoters', TRUE);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame(0, $form['postcards']['voter-1-11111']['#default_value']);
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFormSetsReenterAndIncrementsRep(): void {
    $formState = new FormState();
    $formState->set('rep', 2);
    $formState->setValue('allVoters', 0);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame(3, $formState->get('rep'));
  }

  public function testValidateFormSetsAllVotersTrueWhenChecked(): void {
    $formState = new FormState();
    $formState->set('rep', 1);
    $formState->setValue('allVoters', 1);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertTrue($formState->get('allVoters'));
  }

  public function testValidateFormSetsAllVotersFalseWhenUnchecked(): void {
    $formState = new FormState();
    $formState->set('rep', 1);
    $formState->setValue('allVoters', 0);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertFalse($formState->get('allVoters'));
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormSetsReenterIncrementsRepAndCallsSetRebuild(): void {
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 99]);

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->set('rep', 3);
    $formState->setValues(['canvassDate' => '2026-06-27']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame(4, $formState->get('rep'));
  }

  public function testSubmitFormCallsReportPostcardMailedForEachCheckedVoter(): void {
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 99]);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->configurationMock->method('getCanvassResponseCodes')
      ->willReturn($this->baseCanvassResponseCodes());
    $this->configurationMock->method('getCycle')->willReturn('2026');
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->votersMock->method('getVoterCd')->willReturn(5);
    $this->reportsMock->method('setNlReport')->willReturn(1);

    $this->apiSurveyQuestionMock->expects($this->exactly(2))->method('reportSurveyResponse');
    $this->votersMock->expects($this->exactly(2))->method('updateTurfVoter');

    $formState = new FormState();
    $formState->set('rep', 1);
    $formState->setValues([
      'canvassDate'    => '2026-06-27',
      'voter-1-11111'  => 1,    // checked
      'voter-1-22222'  => 1,    // checked
      'voter-1-33333'  => 0,    // unchecked — must be skipped
      'allVoters'      => 0,
    ]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormSkipsUncheckedAndNonVoterValues(): void {
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 99]);

    $this->apiSurveyQuestionMock->expects($this->never())->method('reportSurveyResponse');

    $formState = new FormState();
    $formState->set('rep', 1);
    $formState->setValues([
      'canvassDate' => '2026-06-27',
      'allVoters'   => 0,
      'voter-1-111' => 0,       // unchecked
    ]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormResetsAllVotersAndUserInput(): void {
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 99]);

    $formState = new FormState();
    $formState->set('rep', 1);
    $formState->set('allVoters', TRUE);
    $formState->setValues(['canvassDate' => '2026-06-27', 'allVoters' => 1]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertFalse($formState->get('allVoters'));
    $this->assertSame(0, $formState->getUserInput()['allVoters']);
  }

  // -------------------------------------------------------------------------
  // reportPostcardMailed
  // -------------------------------------------------------------------------

  public function testReportPostcardMailedCallsSetNlReportWithCorrectData(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->configurationMock->method('getCanvassResponseCodes')
      ->willReturn($this->baseCanvassResponseCodes());
    $this->configurationMock->method('getCycle')->willReturn('2026');
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->votersMock->method('getVoterCd')->willReturn(5);
    $this->reportsMock->method('setNlReport')->willReturn(10);
    $this->apiSurveyQuestionMock->method('reportSurveyResponse');

    $capturedReport = NULL;
    $this->reportsMock->expects($this->once())
      ->method('setNlReport')
      ->willReturnCallback(function ($report) use (&$capturedReport): int {
        $capturedReport = $report;
        return 10;
      });

    $this->buildFormInstance()->reportPostcardMailed(11111, '2026-06-27', 99);

    $this->assertSame(11111, $capturedReport['vanid']);
    $this->assertSame('2026', $capturedReport['cycle']);
    $this->assertSame('TestCounty', $capturedReport['county']);
    $this->assertSame(99, $capturedReport['mcid']);
    $this->assertSame('Postcard', $capturedReport['contactType']);
    $this->assertSame('Mailed', $capturedReport['value']);
    $this->assertSame('2026-06-27', $capturedReport['contactDate']);
    $this->assertSame('203.0.113.7', $capturedReport['reportIp']);
  }

  public function testReportPostcardMailedCallsReportSurveyResponseWithCorrectData(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->configurationMock->method('getCanvassResponseCodes')
      ->willReturn($this->baseCanvassResponseCodes());
    $this->configurationMock->method('getCycle')->willReturn('2026');
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->votersMock->method('getVoterCd')->willReturn(5);
    $this->reportsMock->method('setNlReport')->willReturn(10);

    $capturedSurvey = NULL;
    $this->apiSurveyQuestionMock->expects($this->once())
      ->method('reportSurveyResponse')
      ->willReturnCallback(function ($survey) use (&$capturedSurvey): void {
        $capturedSurvey = $survey;
      });

    $this->buildFormInstance()->reportPostcardMailed(11111, '2026-06-27', 99);

    $this->assertSame(11111, $capturedSurvey['vanid']);
    $this->assertSame('contactAttempt', $capturedSurvey['type']);
    $this->assertSame(42, $capturedSurvey['rid']);   // from baseCanvassResponseCodes 'Mailed'
    $this->assertSame(3, $capturedSurvey['ContactTypeCode']); // 'code' from Postcard
    $this->assertSame('2026-06-27', $capturedSurvey['dateCanvassed']);
  }

  public function testReportPostcardMailedUpdatesNlReportIndexOnTurfVoter(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->configurationMock->method('getCanvassResponseCodes')
      ->willReturn($this->baseCanvassResponseCodes());
    $this->configurationMock->method('getCycle')->willReturn('2026');
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->votersMock->method('getVoterCd')->willReturn(5);
    $this->reportsMock->method('setNlReport')->willReturn(77);   // rIndex
    $this->apiSurveyQuestionMock->method('reportSurveyResponse');

    $capturedTurfVoter = NULL;
    $this->votersMock->expects($this->once())
      ->method('updateTurfVoter')
      ->willReturnCallback(function ($turfVoter) use (&$capturedTurfVoter): bool {
        $capturedTurfVoter = $turfVoter;
        return TRUE;
      });

    $this->buildFormInstance()->reportPostcardMailed(11111, '2026-06-27', 99);

    $this->assertSame(11111, $capturedTurfVoter['vanid']);
    $this->assertSame(99, $capturedTurfVoter['mcid']);
    $this->assertSame('TestCounty', $capturedTurfVoter['county']);
    $this->assertSame(7, $capturedTurfVoter['turfIndex']);
    $this->assertSame(77, $capturedTurfVoter['attemptedContact']);
  }

  public function testReportPostcardMailedMarksNlAsHavingReportedResults(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->willReturn(7);
    $this->configurationMock->method('getCanvassResponseCodes')
      ->willReturn($this->baseCanvassResponseCodes());
    $this->configurationMock->method('getCycle')->willReturn('2026');
    $this->nlsMock->method('getNlById')->willReturn(['nickname' => 'Jane', 'lastName' => 'Doe']);
    $this->votersMock->method('getVoterCd')->willReturn(5);
    $this->reportsMock->method('setNlReport')->willReturn(10);
    $this->apiSurveyQuestionMock->method('reportSurveyResponse');
    $this->votersMock->method('updateTurfVoter')->willReturn(TRUE);

    $this->nlsMock->expects($this->once())
      ->method('resultsReported')
      ->with(99, 'TestCounty');

    $this->buildFormInstance()->reportPostcardMailed(11111, '2026-06-27', 99);
  }

}
