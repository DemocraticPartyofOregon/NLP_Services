<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class SurveyQuestionForm extends FormBase
{
  protected ApiSurveyQuestion $apiSurveyQuestion;
  protected NlpNls $nlsObj;
  protected NlpConfiguration $configurationObj;

  public function __construct( $apiSurveyQuestion, $nlsObj, $configurationObj) {
    $this->apiSurveyQuestion = $apiSurveyQuestion;
    $this->nlsObj = $nlsObj;
    $this->configurationObj = $configurationObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.survey_question'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.configuration'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'survey_question_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $cycle = $this->configurationObj->getCycle();
    $cycleParts = explode('-', $cycle);
    $cycleYear = $cycleParts[0];

    $cycleQuestions = $this->apiSurveyQuestion->getApiSurveyQuestions($cycleYear);
    $form_state->set('cycleQuestions', $cycleQuestions);

    $pledgeSurveyQuestion = $this->configurationObj->getPledgeSurveyQuestion();
    $form['standard_question'] = $this->surveyQuestionDisplay('pledge', $cycleQuestions, $cycleYear, $pledgeSurveyQuestion);

    $nlParticipationQuestions = $this->apiSurveyQuestion->getApiSurveyQuestions($cycleYear, 1);
    $form_state->set('nlParticipationQuestions', $nlParticipationQuestions);

    $nlParticipationQuestion = $this->configurationObj->getNlParticipationQuestion();
    $form['participation'] = $this->surveyQuestionDisplay('participation', $nlParticipationQuestions, $cycleYear, $nlParticipationQuestion);

    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $cycleQuestions = $form_state->get('cycleQuestions');

    if (!empty($form_state->getValue('pledgeRemoveQuestion'))) {
      $this->messenger()->addStatus($this->t('The current pledge-to-vote question is deselected'));
      $this->configurationObj->set('nlpservices_pledge_to_vote', []);
    }

    $surveyQuestionId = $form_state->getValue('pledgeQuestionChoice');
    if ((int) $surveyQuestionId !== 1) {
      $this->messenger()->addStatus($this->t('The pledge-to-vote question is selected.'));
      $question = $cycleQuestions[$surveyQuestionId];
      $question['surveyQuestionId'] = $surveyQuestionId;
      $this->configurationObj->set('nlpservices_pledge_to_vote', $question);
    }

    if (!empty($form_state->getValue('participationRemoveQuestion'))) {
      $this->messenger()->addStatus($this->t('The current participation question is deselected'));
      $this->configurationObj->set('nlpservices_nl_participation', []);
    }

    $nlParticipationQuestions = $form_state->get('nlParticipationQuestions');
    $surveyQuestionId = $form_state->getValue('participationQuestionChoice');
    if ((int) $surveyQuestionId !== 1) {
      $question = $nlParticipationQuestions[$surveyQuestionId];
      $askList = $this->verifyAskResponses($question);
      if (!empty($askList)) {
        $this->messenger()->addStatus($this->t('The participation question is selected.'));
        $question['surveyQuestionId'] = $surveyQuestionId;
        $question['askList'] = $askList;
        $this->configurationObj->set('nlpservices_nl_participation', $question);
      }
    }

    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * surveyQuestionDisplay
   *
   * Builds and returns a form element for displaying survey questions.
   *
   * This function handles the rendering of survey question information based on
   * the type, cycle, and data provided in the parameters. It also manages the
   * display of currently chosen survey questions, their responses, and the available
   * question options for selection.
   *
   * @param string $type
   *   The type of survey question (e.g., 'pledge', 'county').
   * @param array $cycleQuestions
   *   An array of survey questions available for the given cycle.
   * @param string $cycle
   *   The cycle of the survey (e.g., election cycle or campaign year).
   * @param array $surveyQuestion
   *   An associative array representing the details of the currently chosen survey question,
   *   including its name, ID, type, script, and responses.
   *
   * @return array
   *   A renderable array representing the form elements for displaying and managing
   *   the survey questions.
   */
  private function surveyQuestionDisplay(string $type, array $cycleQuestions, string $cycle, array $surveyQuestion): array
  {
    $form_element[$type . '_question'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Selection of the @cycle @type survey question.', ['@cycle' => $cycle, '@type' => $type]),
    ];
    $currentQuestion = '';
    if ($type == 'pledge') {

      if (empty($surveyQuestion)) {
        $form_element[$type . '_question']['noQuestion'] = [
          '#markup' => "<p>There is no survey question chosen. </p>",
        ];
      } else {
        $currentQuestion = '<u>NAME</u>: <b>' . Html::escape($surveyQuestion['name'])
          . '</b> <u>ID</u>: ' . Html::escape((string) $surveyQuestion['surveyQuestionId'])
          . ' <u>CYCLE</u>: ' . Html::escape($surveyQuestion['cycle'])
          . ' <u>TYPE</u>: ' . Html::escape($surveyQuestion['type'])
          . ' <u>SCRIPT</u>: ' . Html::escape($surveyQuestion['scriptQuestion']);
        $form_element[$type . '_question']['currentQuestion'] = [
          '#markup' => "<p><b>The currently chosen survey question is:</b><br>" . $currentQuestion . "</p>",
        ];

        $form_element = $this->getArr($surveyQuestion['responses'], $form_element, $type);
      }
    } else {
      if (empty($surveyQuestion)) {
        $form_element[$type . '_question']['noQuestion'] = [
          '#markup' => "<p>There is no survey question chosen. </p>",
        ];
      } else {
        $currentQuestion = '<u>NAME</u>: <b>' . Html::escape($surveyQuestion['name'])
          . '</b> <u>ID</u>: ' . Html::escape((string) $surveyQuestion['surveyQuestionId'])
          . ' <u>CYCLE</u>: ' . Html::escape($surveyQuestion['cycle'])
          . ' <u>TYPE</u>: ' . Html::escape($surveyQuestion['type'])
          . ' <u>SCRIPT</u>: ' . Html::escape($surveyQuestion['scriptQuestion']);
      }

      if (!empty($currentQuestion)) {
        $form_element[$type . '_question']['countyQuestions'] = [
          '#markup' => $currentQuestion,
        ];
      }

      if (!empty($surveyQuestion)) {
        if (!empty($surveyQuestion['askList'])) {
          $askList = implode(', ', $surveyQuestion['askList']);
          $form_element[$type . '_question']['askList'] = [
            '#markup' => "<p><b>Ask List:</b><br>" . Html::escape($askList) . "</p>",
          ];
        }
        $form_element = $this->getArr($surveyQuestion['responses'], $form_element, $type);
      }
    }

    $questionList = [];
    if (!empty($cycleQuestions)) {
      $questionList[1] = '<b>no change </b>';
      foreach ($cycleQuestions as $surveyQuestion) {
        if ($surveyQuestion['type'] != 'Candidate') {
          $questionList[$surveyQuestion['surveyQuestionId']] = '<u>NAME</u>: <b>' . Html::escape($surveyQuestion['name'])
            . '</b> <u>CYCLE</u>: ' . Html::escape($surveyQuestion['cycle'])
            . ' <u>TYPE</u>: ' . Html::escape($surveyQuestion['type'])
            . ' <u>SCRIPT</u>: ' . Html::escape($surveyQuestion['scriptQuestion']);
        }
      }
    }
    if (empty($questionList)) {
      $form_element[$type . '_question']['note'] = [
        '#markup' => "<p>There are no survey questions visible to the API </p>",
      ];
    } else {
      $form_element[$type . '_question'][$type . 'QuestionChoice'] = [
        '#type' => 'radios',
        '#title' => $this->t('Survey Question Choice'),
        '#default_value' => 1,
        '#options' => $questionList,
        '#description' => $this->t('Choose a @type survey question for the county.', ['@type' => $type]),
      ];
    }
    return $form_element;
  }

  /**
   * verifyAskResponses
   *
   * Verify that the ask responses are supported.
   *
   * @param array $question
   * @return array
   */
  private function verifyAskResponses(array $question): array
  {
    $askOptions = $this->nlsObj->askOptions;
    $responses = $question['responses'];
    $askList = ['dash' => $this->nlsObj::DASH];
    $error = FALSE;
    foreach ($responses as $response) {
      $responseIndex = array_search($response['name'], $askOptions);
      if ($responseIndex === FALSE) {
        $this->messenger()->addWarning($this->t('Ask response not supported: [@name]', ['@name' => $response['name']]));
        $error = TRUE;
        continue;
      }
      $askList[$responseIndex] = $response['name'];
    }

    foreach ($askOptions as $askIndex => $askOption) {
      if (empty($askList[$askIndex])) {
        $this->messenger()->addWarning($this->t('Ask response is missing: [@option]', ['@option' => $askOption]));
        $error = TRUE;
      }
    }

    if ($error) { return []; }
    return $askList;
  }

  /**
   * getArr
   *
   * Get the responses for a survey question.
   * 1. Display the responses in a list.
   * 2. Add a checkbox to remove the currently chosen survey question.
   * 3. Add a checkbox to remove all survey questions.
   *
   * @param $responses
   * @param array $form_element
   * @param string $type
   * @return array
   */
  private function getArr($responses, array $form_element, string $type): array
  {
    $responseDisplay = '';
    foreach ($responses as $response) {
      if (!empty($responseDisplay)) {
        $responseDisplay .= '<br>';
      }
      $responseDisplay .= '<u>ID</u>: ' . Html::escape((string)$response['surveyResponseId']) . ' <u>Name</u>: ' . Html::escape($response['name']);
    }
    $form_element[$type . '_question']['responses'] = [
      '#markup' => "<p><b>Responses:</b><br>" . $responseDisplay . "</p>",
    ];

    $form_element[$type . '_question'][$type . 'RemoveQuestion'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove the currently chosen survey question'),
    ];
    return $form_element;
  }

}
