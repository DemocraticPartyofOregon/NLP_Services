<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal\Core\Form\FormState;
  use Drupal\Core\Form\FormStateInterface;
  use Symfony\Component\HttpFoundation\Request;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiMapRegions;
  use Drupal\nlpservices\ApiSurveyQuestion;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpMatchbacks;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpNlsListDisplay;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfAssignments;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\nlpservices\Form\ActiveNlsDisplayForm;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\File\FileSystemInterface;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\RequestStack;

  class ActiveNlsDisplayFormTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpNls $nlsMock;
    private NlpTurfs $turfsMock;
    private NlpSearchBar $searchBarMock;
    private NlpNlsListDisplay $nlsListDisplayMock;
    private NlpAwards $awardsMock;
    private NlpSessionData $sessionDataMock;
    private ApiSurveyQuestion $apiSurveyQuestionMock;
    private ApiActivistCodes $apiActivistCodesMock;
    private RequestStack $requestStackMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock            = $this->createMock(NlpVoters::class);
      $this->reportsMock           = $this->createMock(NlpReports::class);
      $this->nlsMock               = $this->createMock(NlpNls::class);
      $this->turfsMock             = $this->buildTurfsMock();
      $this->searchBarMock         = $this->createMock(NlpSearchBar::class);
      $this->nlsListDisplayMock    = $this->createMock(NlpNlsListDisplay::class);
      $this->awardsMock            = $this->createMock(NlpAwards::class);
      $this->sessionDataMock       = $this->createMock(NlpSessionData::class);
      $this->apiSurveyQuestionMock = $this->createMock(ApiSurveyQuestion::class);
      $this->apiActivistCodesMock  = $this->createMock(ApiActivistCodes::class);
      $this->requestStackMock      = $this->createMock(RequestStack::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): ActiveNlsDisplayForm {
      return new ActiveNlsDisplayForm(
        $this->votersMock,
        $this->reportsMock,
        $this->nlsMock,
        $this->turfsMock,
        $this->searchBarMock,
        $this->nlsListDisplayMock,
        $this->awardsMock,
        $this->sessionDataMock,
        $this->apiSurveyQuestionMock,
        $this->apiActivistCodesMock,
        $this->requestStackMock,
      );
    }

    private function buildTurfsMock(): NlpTurfs {
      $connectionMock      = $this->createMock(Connection::class);
      $fileSystemMock      = $this->createMock(FileSystemInterface::class);
      $nlsMock             = $this->createMock(NlpNls::class);
      $pathsMock           = $this->createMock(NlpPaths::class);
      $votersMock          = $this->createMock(NlpVoters::class);
      $activistCodesMock   = $this->createMock(ApiActivistCodes::class);
      $configurationMock   = $this->createMock(NlpConfiguration::class);
      $reportsMock         = $this->createMock(NlpReports::class);
      $matchbacksMock      = $this->createMock(NlpMatchbacks::class);
      $foldersMock         = $this->createMock(ApiFolders::class);
      $mapRegionsMock      = $this->createMock(ApiMapRegions::class);
      $turfAssignmentsMock = $this->createMock(NlpTurfAssignments::class);
      return $this->getMockBuilder(NlpTurfs::class)
        ->setConstructorArgs([
          $connectionMock, $fileSystemMock, $nlsMock, $pathsMock,
          $votersMock, $activistCodesMock, $configurationMock, $reportsMock,
          $matchbacksMock, $foldersMock, $mapRegionsMock, $turfAssignmentsMock,
        ])
        ->onlyMethods(['getTurfsById', 'setAllTurfsDelivered'])
        ->getMock();
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testBuildFormAddsHiddenFloatingSaveButton(): void {
      $this->sessionDataMock->method('getSavedHd')->willReturn('All');
      $this->sessionDataMock->method('getHdOptions')->willReturn(['All']);
      $this->nlsMock->method('getNls')->willReturn([]);
      $this->nlsListDisplayMock->method('createTableArray')->willReturn([]);
      $this->requestStackMock->method('getCurrentRequest')->willReturn(Request::create('/active-nls'));

      $formState = new FormState();
      $formState->set('reenter', TRUE);
      $formState->set('county', 'Denver');
      $formState->set('page', 'data_entry');

      $form = $this->buildForm()->buildForm([], $formState);

      // Same name as the other Save Changes buttons, so submitForm treats it identically.
      $this->assertSame('save_changes', $form['save-floating']['save']['#name']);
      $this->assertSame('submit', $form['save-floating']['save']['#type']);
      // The bar is hidden by CSS until unsaved-changes.js sees an edit.
      $this->assertSame('container', $form['save-floating']['#type']);
      $this->assertContains('nlp-floating-save', $form['save-floating']['#attributes']['class']);
      // Last element of the form, so the sticky bar sits after the table.
      $children = array_filter(array_keys($form), fn($key) => $key[0] !== '#');
      $this->assertSame('save-floating', end($children));
      $this->assertContains('nlpservices/unsaved-changes', $form['#attached']['library']);
    }

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_active_nls_display_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // getProgress
    // -------------------------------------------------------------------------

    public function testGetProgressEmptyVanidsReturnsZeroProgress(): void {
      $this->votersMock->method('fetchVanIdsByNl')->willReturn([]);
      $result = $this->buildForm()->getProgress(42);
      $this->assertSame(0, $result['attempts']);
      $this->assertSame(0, $result['contacts']);
      $this->assertSame(0, $result['voterCount']);
    }

    public function testGetProgressNoReportsReturnsZeroAttemptsAndContacts(): void {
      $this->votersMock->method('fetchVanIdsByNl')->willReturn([101 => 101, 102 => 102]);
      $this->turfsMock->method('getTurfsById')->willReturn([['doorCount' => 5]]);
      $this->reportsMock->method('getNlReportsForVoters')->willReturn([]);
      $result = $this->buildForm()->getProgress(42);
      $this->assertSame(2, $result['voterCount']);
      $this->assertSame(5, $result['doorCount']);
      $this->assertSame(0, $result['attempts']);
      $this->assertSame(0, $result['contacts']);
    }

    public function testGetProgressCountsAttemptsAndSurveyContacts(): void {
      $this->votersMock->method('fetchVanIdsByNl')->willReturn([101 => 101, 102 => 102]);
      $this->turfsMock->method('getTurfsById')->willReturn([['doorCount' => 3]]);
      // Voter 101 has a Survey report (counts as attempt + contact).
      // Voter 102 has a non-Survey report (counts as attempt only).
      $reports = [
        1 => [
          ['vanid' => 101, 'type' => NlpReports::SURVEY],
          ['vanid' => 102, 'type' => 'Contact'],
        ],
      ];
      $this->reportsMock->method('getNlReportsForVoters')->willReturn($reports);
      $result = $this->buildForm()->getProgress(42);
      $this->assertSame(2, $result['attempts']);
      $this->assertSame(1, $result['contacts']);
    }

    // -------------------------------------------------------------------------
    // processAsk
    // -------------------------------------------------------------------------

    public function testProcessAskDropoutAppliesActivistCode(): void {
      $this->nlsMock->method('getNlsStatus')->willReturn(['asked' => 'Yes']);
      $this->awardsMock->method('nlParticipation');
      $this->apiSurveyQuestionMock->method('reportSurveyResponse');
      $this->apiActivistCodesMock->expects($this->once())
        ->method('setApiActivistCode')
        ->with($this->callback(fn($r) => $r['action'] === 'Apply' && $r['codeName'] === 'NLPDropoutAC'));
      $this->buildForm()->processAsk(42, 'Dropout');
    }

    public function testProcessAskPreviousDropoutRemovesActivistCode(): void {
      $this->nlsMock->method('getNlsStatus')->willReturn(['asked' => 'Dropout']);
      $this->awardsMock->method('nlParticipation');
      $this->apiSurveyQuestionMock->method('reportSurveyResponse');
      $this->apiActivistCodesMock->expects($this->once())
        ->method('setApiActivistCode')
        ->with($this->callback(fn($r) => $r['action'] === 'Remove' && $r['codeName'] === 'NLPDropoutAC'));
      $this->buildForm()->processAsk(42, 'Yes');
    }

    public function testProcessAskNeitherDropoutSkipsActivistCode(): void {
      $this->nlsMock->method('getNlsStatus')->willReturn(['asked' => 'Yes']);
      $this->awardsMock->method('nlParticipation');
      $this->apiSurveyQuestionMock->method('reportSurveyResponse');
      $this->apiActivistCodesMock->expects($this->never())->method('setApiActivistCode');
      $this->buildForm()->processAsk(42, 'Asked');
    }

    public function testProcessAskDashSkipsReportSurveyResponse(): void {
      $this->nlsMock->method('getNlsStatus')->willReturn(['asked' => 'Asked']);
      $this->awardsMock->method('nlParticipation');
      $this->apiSurveyQuestionMock->expects($this->never())->method('reportSurveyResponse');
      $this->buildForm()->processAsk(42, NlpNls::DASH);
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formStateMock = $this->createMock(FormStateInterface::class);
      $formStateMock->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formStateMock->method('getValue')->with('search-needle')->willReturn('Smith');
      $formStateMock->expects($this->once())
        ->method('set')
        ->with('search-needle', 'Smith');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

    public function testSubmitFormOtherElementDoesNotStoreNeedle(): void {
      $formStateMock = $this->createMock(FormStateInterface::class);
      $formStateMock->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
      $formStateMock->expects($this->never())->method('set');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

  }

}
