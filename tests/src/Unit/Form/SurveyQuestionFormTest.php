<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\Config\SurveyQuestionForm;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpNls;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SurveyQuestionForm::class)]
#[Group('nlpservices')]
class SurveyQuestionFormTest extends UnitTestCase {

  private ApiSurveyQuestion $apiSurveyQuestionMock;
  private NlpNls $nlsMock;
  private NlpConfiguration $configurationMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->apiSurveyQuestionMock = $this->createMock(ApiSurveyQuestion::class);
    $this->nlsMock               = $this->createMock(NlpNls::class);
    $this->configurationMock     = $this->createMock(NlpConfiguration::class);
    $this->messengerMock         = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(): SurveyQuestionForm {
    $form = new SurveyQuestionForm(
      $this->apiSurveyQuestionMock,
      $this->nlsMock,
      $this->configurationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function stubCycle(string $cycle = '2024-General'): void {
    $this->configurationMock->method('getCycle')->willReturn($cycle);
  }

  private function stubApiSurveyQuestions(array $pledgeCycleQuestions = [], array $participationCycleQuestions = []): void {
    $this->apiSurveyQuestionMock->method('getApiSurveyQuestions')
      ->willReturnCallback(function (string $cycleYear, int $database = 0) use ($pledgeCycleQuestions, $participationCycleQuestions) {
        return $database === 1 ? $participationCycleQuestions : $pledgeCycleQuestions;
      });
  }

  private function defaultCycleQuestions(): array {
    return [
      101 => ['surveyQuestionId' => 101, 'name' => 'Ballot Q', 'cycle' => '2024', 'type' => 'Ballot', 'scriptQuestion' => 'Will you vote?', 'responses' => []],
      102 => ['surveyQuestionId' => 102, 'name' => 'Candidate Q', 'cycle' => '2024', 'type' => 'Candidate', 'scriptQuestion' => 'Who?', 'responses' => []],
    ];
  }

  /**
   * Builds the form once (populating $form_state with cycleQuestions and
   * nlParticipationQuestions), returning the FormState for a subsequent
   * submitForm() call.
   */
  private function buildFormAndState(array $pledgeCycleQuestions, array $participationCycleQuestions, array $pledgeSurveyQuestion = [], array $participationSurveyQuestion = []): FormState {
    $this->stubCycle();
    $this->stubApiSurveyQuestions($pledgeCycleQuestions, $participationCycleQuestions);
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn($pledgeSurveyQuestion);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn($participationSurveyQuestion);

    $formState = new FormState();
    $this->buildForm()->buildForm([], $formState);
    return $formState;
  }

  /**
   * Captures every call to the given messenger method, rendered with its
   * placeholder substitutions applied, into $messages, which the caller owns.
   */
  private function captureMessengerCalls(string $method, array &$messages): void {
    $this->messengerMock->method($method)
      ->willReturnCallback(function ($message) use (&$messages) {
        $messages[] = (string) $message;
      });
  }

  private function assertMessageContains(array $messages, string $needle): void {
    $this->assertTrue(
      array_reduce($messages, fn($found, $m) => $found || str_contains($m, $needle), false),
      "Expected a message containing \"$needle\". Got: " . implode(' | ', $messages)
    );
  }

  private function validParticipationResponses(): array {
    return [
      1 => ['surveyResponseId' => 1, 'name' => 'Asked'],
      2 => ['surveyResponseId' => 2, 'name' => 'Yes'],
      3 => ['surveyResponseId' => 3, 'name' => 'Not this election'],
      4 => ['surveyResponseId' => 4, 'name' => 'Dropout'],
      5 => ['surveyResponseId' => 5, 'name' => 'Did not respond'],
    ];
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('survey_question_configuration_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormExtractsCycleYearFromCycleString(): void {
    $this->stubCycle('2024-General');
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $receivedYears = [];
    $this->apiSurveyQuestionMock->method('getApiSurveyQuestions')
      ->willReturnCallback(function (string $cycleYear, int $database = 0) use (&$receivedYears) {
        $receivedYears[] = $cycleYear;
        return [];
      });

    $this->buildForm()->buildForm([], new FormState());

    $this->assertSame(['2024', '2024'], $receivedYears);
  }

  public function testBuildFormRequestsParticipationQuestionsFromMyCampaignDatabase(): void {
    $this->stubCycle();
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $receivedDatabases = [];
    $this->apiSurveyQuestionMock->method('getApiSurveyQuestions')
      ->willReturnCallback(function (string $cycleYear, int $database = 0) use (&$receivedDatabases) {
        $receivedDatabases[] = $database;
        return [];
      });

    $this->buildForm()->buildForm([], new FormState());

    $this->assertSame([0, 1], $receivedDatabases);
  }

  public function testBuildFormContainsBackLinkAndSubmitButton(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions();
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  public function testBuildFormShowsNoQuestionMessageWhenPledgeQuestionNotConfigured(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions();
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertStringContainsString('no survey question chosen', $form['standard_question']['pledge_question']['noQuestion']['#markup']);
  }

  public function testBuildFormShowsCurrentPledgeQuestionDetailsEscaped(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions();
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([
      'name' => '<b>Pledge</b>',
      'surveyQuestionId' => 55,
      'cycle' => '2024',
      'type' => 'Ballot',
      'scriptQuestion' => 'Will you vote?',
      'responses' => [201 => ['surveyResponseId' => 201, 'name' => 'Yes']],
    ]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $markup = $form['standard_question']['pledge_question']['currentQuestion']['#markup'];
    $this->assertStringContainsString('&lt;b&gt;Pledge&lt;/b&gt;', $markup);
    $this->assertStringContainsString('55', $markup);

    $responsesMarkup = $form['standard_question']['pledge_question']['responses']['#markup'];
    $this->assertStringContainsString('Yes', $responsesMarkup);
    $this->assertSame('checkbox', $form['standard_question']['pledge_question']['pledgeRemoveQuestion']['#type']);
  }

  public function testBuildFormShowsParticipationAskList(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions();
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([
      'name' => 'Participation',
      'surveyQuestionId' => 77,
      'cycle' => '2024',
      'type' => 'Participation',
      'scriptQuestion' => 'Did you participate?',
      'askList' => ['dash' => '-', 'asked' => 'Asked'],
      'responses' => [301 => ['surveyResponseId' => 301, 'name' => 'Asked']],
    ]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $askListMarkup = $form['participation']['participation_question']['askList']['#markup'];
    $this->assertStringContainsString('Asked', $askListMarkup);
  }

  public function testBuildFormShowsNoteWhenNoCycleQuestionsAvailable(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions([], []);
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertStringContainsString('no survey questions visible', $form['standard_question']['pledge_question']['note']['#markup']);
    $this->assertArrayNotHasKey('pledgeQuestionChoice', $form['standard_question']['pledge_question']);
  }

  public function testBuildFormRadiosExcludeCandidateTypeAndIncludeNoChangeOption(): void {
    $this->stubCycle();
    $this->stubApiSurveyQuestions($this->defaultCycleQuestions(), []);
    $this->configurationMock->method('getPledgeSurveyQuestion')->willReturn([]);
    $this->configurationMock->method('getNlParticipationQuestion')->willReturn([]);

    $form = $this->buildForm()->buildForm([], new FormState());

    $options = $form['standard_question']['pledge_question']['pledgeQuestionChoice']['#options'];
    $this->assertArrayHasKey(1, $options);
    $this->assertArrayHasKey(101, $options);
    $this->assertArrayNotHasKey(102, $options);
  }

  // ---------------------------------------------------------------------------
  // submitForm - pledge question
  // ---------------------------------------------------------------------------

  public function testSubmitFormRemovesPledgeQuestionWhenRemoveCheckboxChecked(): void {
    $formState = $this->buildFormAndState([], []);
    $formState->setValues([
      'pledgeRemoveQuestion' => '1',
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '1',
    ]);

    $this->configurationMock->expects($this->once())
      ->method('set')
      ->with('nlpservices_pledge_to_vote', []);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormSetsPledgeQuestionWhenChoiceSelected(): void {
    $formState = $this->buildFormAndState($this->defaultCycleQuestions(), []);
    $formState->setValues([
      'pledgeQuestionChoice' => '101',
      'participationQuestionChoice' => '1',
    ]);

    $this->configurationMock->expects($this->once())
      ->method('set')
      ->with('nlpservices_pledge_to_vote', $this->callback(function ($question) {
        return $question['surveyQuestionId'] === '101' && $question['name'] === 'Ballot Q';
      }));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormDoesNotChangePledgeQuestionWhenChoiceIsNoChange(): void {
    $formState = $this->buildFormAndState([], []);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '1',
    ]);

    $this->configurationMock->expects($this->never())->method('set');

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  // ---------------------------------------------------------------------------
  // submitForm - participation question
  // ---------------------------------------------------------------------------

  public function testSubmitFormRemovesParticipationQuestionWhenRemoveCheckboxChecked(): void {
    $formState = $this->buildFormAndState([], []);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationRemoveQuestion' => '1',
      'participationQuestionChoice' => '1',
    ]);

    $this->configurationMock->expects($this->once())
      ->method('set')
      ->with('nlpservices_nl_participation', []);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormSetsParticipationQuestionWhenAskListValid(): void {
    $participationCycleQuestions = [
      201 => [
        'surveyQuestionId' => 201,
        'name' => 'Participation Q',
        'cycle' => '2024',
        'type' => 'Participation',
        'scriptQuestion' => 'Did you participate?',
        'responses' => $this->validParticipationResponses(),
      ],
    ];
    $formState = $this->buildFormAndState([], $participationCycleQuestions);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '201',
    ]);

    $this->configurationMock->expects($this->once())
      ->method('set')
      ->with('nlpservices_nl_participation', $this->callback(function ($question) {
        return $question['surveyQuestionId'] === '201'
          && $question['askList']['dash'] === '-'
          && $question['askList']['asked'] === 'Asked';
      }));

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

  public function testSubmitFormSkipsParticipationQuestionAndWarnsWhenAskResponseIsMissing(): void {
    $responses = $this->validParticipationResponses();
    unset($responses[4]); // Remove the 'Dropout' response.
    $participationCycleQuestions = [
      201 => [
        'surveyQuestionId' => 201,
        'name' => 'Participation Q',
        'cycle' => '2024',
        'type' => 'Participation',
        'scriptQuestion' => 'Did you participate?',
        'responses' => $responses,
      ],
    ];
    $formState = $this->buildFormAndState([], $participationCycleQuestions);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '201',
    ]);

    $messages = [];
    $this->captureMessengerCalls('addWarning', $messages);
    $this->configurationMock->expects($this->never())->method('set');

    $form = [];
    $this->buildForm()->submitForm($form, $formState);

    $this->assertMessageContains($messages, 'Ask response is missing: [Dropout]');
  }

  public function testSubmitFormSkipsParticipationQuestionAndWarnsWhenAskResponseIsUnsupported(): void {
    $responses = $this->validParticipationResponses();
    $responses[6] = ['surveyResponseId' => 6, 'name' => 'Weird Response'];
    $participationCycleQuestions = [
      201 => [
        'surveyQuestionId' => 201,
        'name' => 'Participation Q',
        'cycle' => '2024',
        'type' => 'Participation',
        'scriptQuestion' => 'Did you participate?',
        'responses' => $responses,
      ],
    ];
    $formState = $this->buildFormAndState([], $participationCycleQuestions);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '201',
    ]);

    $messages = [];
    $this->captureMessengerCalls('addWarning', $messages);
    $this->configurationMock->expects($this->never())->method('set');

    $form = [];
    $this->buildForm()->submitForm($form, $formState);

    $this->assertMessageContains($messages, 'Ask response not supported: [Weird Response]');
  }

  // ---------------------------------------------------------------------------
  // submitForm - final message
  // ---------------------------------------------------------------------------

  public function testSubmitFormAlwaysAddsConfigurationSavedMessage(): void {
    $formState = $this->buildFormAndState([], []);
    $formState->setValues([
      'pledgeQuestionChoice' => '1',
      'participationQuestionChoice' => '1',
    ]);

    $messages = [];
    $this->captureMessengerCalls('addStatus', $messages);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);

    $this->assertMessageContains($messages, 'The configuration options have been saved.');
  }

}
