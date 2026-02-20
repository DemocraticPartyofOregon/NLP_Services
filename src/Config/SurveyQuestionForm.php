<?php

namespace Drupal\nlpservices\Config;

//use Drupal;
use Drupal;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiSurveyQuestion;
use Drupal\nlpservices\NlpEncryption;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpConfiguration;

/**
 * @noinspection PhpUnused
 */
class SurveyQuestionForm extends ConfigFormBase
{
  protected ApiSurveyQuestion $apiSurveyQuestion;
  protected NlpEncryption $nlpEncrypt;
  protected NlpNls $nlsObj;
  protected NlpConfiguration $configurationObj;
  
  public function __construct( $config_factory,$apiSurveyQuestion, $nlpEncrypt, $nlsObj, $configurationObj ) {
    parent::__construct($config_factory);
    $this->apiSurveyQuestion = $apiSurveyQuestion;
    $this->nlpEncrypt = $nlpEncrypt;
    $this->nlsObj = $nlsObj;
    $this->configurationObj = $configurationObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): SurveyQuestionForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.survey_question'),
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array
  {
    return ['nlpservices.configuration'];
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
    $cycleParts = explode('-',$cycle);
    $cycleYear = $cycleParts[0];
  
    // Get the pledge-to-vote survey question.
    $cycleQuestions = $this->apiSurveyQuestion->getApiSurveyQuestions($cycleYear);
    $form_state->set('cycleQuestions',$cycleQuestions);
  
    $pledgeSurveyQuestion = $this->configurationObj->getPledgeSurveyQuestion();
    $form['standard_question'] = $this->SurveyQuestionDisplay('pledge',$cycleQuestions,$cycleYear,$pledgeSurveyQuestion);
    
    // Get the active NL signup survey question.
    $nlParticipationQuestions = $this->apiSurveyQuestion->getApiSurveyQuestions($cycleYear,1);
    $form_state->set('nlParticipationQuestions',$nlParticipationQuestions);
    
    $nlParticipationQuestion = $this->configurationObj->getNlParticipationQuestion();
    $form['participation'] = $this->SurveyQuestionDisplay('participation',$nlParticipationQuestions,$cycleYear,$nlParticipationQuestion);
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    
    $config = $this->config('nlpservices.configuration');
    $cycleQuestions = $form_state->get('cycleQuestions');
    $surveyQuestions = $config->get('nlpservices_survey_questions');
  
    if(!empty($form_state->getValue('pledgeRemoveQuestion'))) {
      $messenger->addStatus(t('The current pledge-to-vote question is deselected'));
      $surveyQuestions['pledgeToVote'] = [];
    }
  
    $surveyQuestionId = $form_state->getValue('pledgeQuestionChoice');
    if($surveyQuestionId !=1) {
      $messenger->addStatus(t('The pledge-to-vote question is selected.'));
      $question = $cycleQuestions[$surveyQuestionId];
      $question['surveyQuestionId'] = $surveyQuestionId;
      $surveyQuestions['pledgeToVote'] = $question;
    }
    
    if(!empty($form_state->getValue('participationRemoveQuestion'))) {
      $messenger->addStatus(t('The current participation question is deselected'));
      $surveyQuestions['nlParticipation'] = [];
    }
    
    $nlParticipationQuestions = $form_state->get('nlParticipationQuestions');
    $surveyQuestionId = $form_state->getValue('participationQuestionChoice');
    if($surveyQuestionId !=1) {
      $question = $nlParticipationQuestions[$surveyQuestionId];
      $askList = $this->verifyAskResponses($question);
      if(!empty($askList)) {
        $messenger->addStatus(t('The participation question is selected.'));
        $surveyQuestions['nlParticipation'] = $question;
        $surveyQuestions['askList'] = $askList;
      }
      
    }
    
    $config->set('nlpservices_survey_questions',$surveyQuestions)->save();
    
    parent::submitForm($form, $form_state);
  
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * SurveyQuestionDisplay
   *
   * Returns form elements to select a survey question from a list of possible questions
   * in VoteBuilder.
   *
   * @param string $type - pledge or participation
   * @param array $cycleQuestions - the array of choices
   * @param string $cycle
   * @param array $surveyQuestion - the current survey question
   * @return array
   */
  function SurveyQuestionDisplay(string $type,array $cycleQuestions,string $cycle,array $surveyQuestion): array
  {
    $form_element[$type.'_question'] = [
      '#type' => 'fieldset',
      '#title' => "Selection of the $cycle $type survey question.",
    ];
    $currentQuestion = '';
    if($type == 'pledge') {
      
      if(empty($surveyQuestion)) {
        $form_element[$type.'_question']['noQuestion'] =  [
          '#markup' => "<p>There is no survey question chosen. </p>",
        ];
      } else {
        $currentQuestion = '<u>NAME</u>: <b>'.$surveyQuestion['name']
          . '</b> <u>ID</u>: '.$surveyQuestion['surveyQuestionId']
          . '</b> <u>CYCLE</u>: '.$surveyQuestion['cycle']
          . ' <u>TYPE</u>: '.$surveyQuestion['type']
          . ' <u>SCRIPT</u>: '.$surveyQuestion['scriptQuestion'];
        $form_element[$type.'_question']['currentQuestion'] = [
          '#markup' => "<p><b>The currently chosen survey question is:</b><br>".$currentQuestion."</p>",
        ];
        
        $responseDisplay = '';
        foreach($surveyQuestion['responses'] as $response) {
          if(!empty($responseDisplay)) {
            $responseDisplay .= '<br>';
          }
          $responseDisplay .= '</b> <u>ID</u>: '.$response['surveyResponseId'].' <u>Name</u>: '.$response['name'];
        }
        $form_element[$type.'_question']['responses'] = [
          '#markup' => "<p><b>Responses:</b><br>".$responseDisplay."</p>",
        ];
        
        $form_element[$type.'_question'][$type.'RemoveQuestion'] = [
          '#type' => 'checkbox',
          '#title' => 'Remove the currently chosen survey question',
        ];
      }
    } else {
      if(empty($surveyQuestion)) {
        $form_element[$type.'_question']['noQuestion'] = [
          '#markup' => "<p>There is no survey question chosen. </p>",
        ];
      } else {
        $currentQuestion = '<u>NAME</u>: <b>'.$surveyQuestion['name']
          . '</b> <u>ID</u>: '.$surveyQuestion['surveyQuestionId']
          . '</b> <u>CYCLE</u>: '.$surveyQuestion['cycle']
          . ' <u>TYPE</u>: '.$surveyQuestion['type']
          . ' <u>SCRIPT</u>: '.$surveyQuestion['scriptQuestion'];
      }
      
      if(!empty($currentQuestion)) {
        $form_element[$type.'_question']['countyQuestions'] = [
          '#markup' => $currentQuestion,
        ];
      }
      
      if (!empty($surveyQuestion)) {
        if (!empty($surveyQuestion['askList'])) {
          $askList = implode(', ', $surveyQuestion['askList']);
          $form_element[$type . '_question']['askList'] = [
            '#markup' => "<p><b>Ask List:</b><br>" . $askList . "</p>",
          ];
        }
        $responseDisplay = '';
        foreach ($surveyQuestion['responses'] as $response) {
          if (!empty($responseDisplay)) {
            $responseDisplay .= '<br>';
          }
          $responseDisplay .= '</b> <u>ID</u>: ' . $response['surveyResponseId'] . ' <u>Name</u>: ' . $response['name'];
        }
        $form_element[$type . '_question']['responses'] = [
          '#markup' => "<p><b>Responses:</b><br>" . $responseDisplay . "</p>",
        ];
      }
      $form_element[$type.'_question'][$type.'RemoveQuestion'] = [
        '#type' => 'checkbox',
        '#title' => 'Remove the currently chosen survey question',
      ];

    }
    
    $questionList = [];
    if(!empty($cycleQuestions)) {
      $questionList[1] = '<b>no change </b>';
      foreach ($cycleQuestions as $surveyQuestion) {
        if($surveyQuestion['type'] != 'Candidate') {
          $questionList[$surveyQuestion['surveyQuestionId']] = '<u>NAME</u>: <b>'.$surveyQuestion['name']
            . '</b> <u>CYCLE</u>: '.$surveyQuestion['cycle']
            . ' <u>TYPE</u>: '.$surveyQuestion['type']
            . ' <u>SCRIPT</u>: '.$surveyQuestion['scriptQuestion'];
        }
      }
    }
    if(empty($questionList)) {
      $form_element[$type . '_question']['note'] = [
        '#markup' => "<p>There are no survey questions visible to the API </p>",
      ];

    } else {
      $form_element[$type.'_question'][$type.'QuestionChoice'] = [
        '#type' => 'radios',
        '#title' => 'Survey Question Choice',
        '#default_value' => 1,
        '#options' => $questionList,
        '#description' => "Choose a $type survey question for the county.",
      ];
    }
    return $form_element;
  }
  
  /**
   * verifyAskResponses
   *
   * @param array $question
   * @return array
   */
  function verifyAskResponses(array $question): array
  {
    $messenger = Drupal::messenger();
    
    $askOptions = $this->nlsObj->askOptions;
    // Use the defined survey question
    $responses = $question['responses'];
    $askList = ['dash' => $this->nlsObj::DASH];
    $error = FALSE;
    foreach ($responses as $response) {
      $responseIndex = array_search($response['name'], $askOptions);
      if ($responseIndex === FALSE) {
        $messenger->addWarning('Ask response not supported: ['.$response['name'].']');
        $error = TRUE;
        continue;
      }
      $askList[$responseIndex] = $response['name'];
    }
    
    foreach ($askOptions as $askIndex=>$askOption) {
      if(empty($askList[$askIndex])) {
        $messenger->addWarning('Ask response is missing: ['.$askOption.']');
        $error = TRUE;
      }
    }
    
    if($error) {return [];}
    return $askList;
  }
}
