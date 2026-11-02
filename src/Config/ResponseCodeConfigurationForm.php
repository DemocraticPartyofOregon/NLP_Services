<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiResponseCodes;
use Drupal\nlpservices\NlpEncryption;

/**
 * @noinspection PhpUnused
 */
class ResponseCodeConfigurationForm extends FormBase {

  protected ConfigFactoryInterface $configFactoryObj;
  protected ApiResponseCodes $apiResponseCodeObj;
  protected NlpEncryption $nlpEncryptObj;

  private array $phoneRcSubs = [
    'Phone' => [
      'Deceased' => ['subContext'=>'Walk','subResponse'=>'Deceased'],
      'Hostile' => ['subContext'=>'Walk','subResponse'=>'Hostile'],
      'Moved' => ['subContext'=>'Walk','subResponse'=>'Moved'],
      'Left Message' => ['subContext'=>'Web Survey','subResponse'=>'Mailed'],
      'Not Home' => ['subContext'=>'Web Survey','subResponse'=>'Not Interested'],
      'Wrong Number' => ['subContext'=>'Web Survey','subResponse'=>'No Such Address'],
      'Other' => ['subContext'=>'Web Survey','subResponse'=>'Canvassed'],
    ],
    'SMS Text' => [
      'Deceased' => ['subContext'=>'Walk','subResponse'=>'Deceased'],
      'Hostile' => ['subContext'=>'Walk','subResponse'=>'Hostile'],
      'Moved' => ['subContext'=>'Walk','subResponse'=>'Moved'],
      'Do Not Text' => ['subContext'=>'Web Survey','subResponse'=>'Do Not Mail'],
      'Texted' => ['subContext'=>'Web Survey','subResponse'=>'Mailed'],
      'Other' => ['subContext'=>'Web Survey','subResponse'=>'Canvassed'],
    ]
  ];

  private array $phoneSubs = [
    'Walk' => ['Moved', 'Deceased', 'Hostile',],
    'Web Survey' => ['Mailed','Not Interested','No Such Address',
      'Canvassed','Refused','Do Not Mail',],
  ];

  public function __construct( $configFactoryObj, $apiResponseCodeObj, $nlpEncryptObj) {
    $this->configFactoryObj = $configFactoryObj;
    $this->apiResponseCodeObj = $apiResponseCodeObj;
    $this->nlpEncryptObj = $nlpEncryptObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ResponseCodeConfigurationForm|ConfigFormBase|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.response_codes'),
      $container->get('nlpservices.encryption'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'canvass_response_code_configuration_form';
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCanvassCodeSelect
   *
   * Build a table of canvass response codes and identify the recommended
   * selections and the current selections that are active.
   *
   * @param $apiKnownResultCodes
   * @param $apiExpectedResultCodes
   * @param $currentResponseCodes
   * @param $codes
   * @return array
   */
  function buildCanvassCodeSelect($apiKnownResultCodes, $apiExpectedResultCodes,$currentResponseCodes,&$codes): array
  {
    if(!empty($apiKnownResultCodes)) {
      $responseCodes = array();
      foreach ($apiKnownResultCodes as $contentType => $typeResponseCodes) {
        $contentTypeId = $typeResponseCodes['code'];
        if(empty($typeResponseCodes['responses'])) {continue;}
        $typeResponses = $typeResponseCodes['responses'];
        foreach ($typeResponses as $responseName => $response) {
          $responseCodes[$contentTypeId]['name'] = $contentType;
          $responseCodes[$contentTypeId][$response] = $responseName;
        }
      }
      $codes['responseCodes'] = $responseCodes;

      $defaultCodes = [];
      if(!empty($currentResponseCodes)) {
        foreach ($currentResponseCodes as $contactType) {
          $contactTypeCode = $contactType['code'];
          foreach ($contactType['responses'] as $responseCode) {
            $defaultCodes[$contactTypeCode][$responseCode] = TRUE;
          }
        }
      }
      $codes['defaultCodes'] = $defaultCodes;
    }

    $form_element['code_display']['table_start'] = [
      '#markup' => " \r ".'<table class="no-white response-code-table form-item " >',
    ];

    // Now construct the header information for each column title.  Uses the table element.
    $hdrRow  = " \n  ".'<th class="table-checkbox">&nbsp;</th>';
    $hdrRow .= " \n  ".'<th class="response-code-recommended">Rec</th>';
    $hdrRow .= " \n  ".'<th class="response-code-contact-type">Contact Type</th>';
    $hdrRow .= " \n  ".'<th class="response-code-value">Code</th>';
    $hdrRow .= " \n  ".'<th class="response-code-responses">Responses</th>';
    $hdrRow .= " \n  ".'<th class="response-code-value">Code</th>';


    // Create the header row.
    $form_element['code_display']['header_row'] = [
      '#children' => Markup::create(" \n <thead> \n <tr>".$hdrRow." \n </tr> \n </thead> "),
    ];
    // Start the table body.
    $form_element['code_display']['body_start'] = [
      '#children' => " \n ".'<tbody>',
    ];

    $row = 0;

    foreach ($apiKnownResultCodes as $contentType => $responseArray) {
      $recommendedResponses = [];
      if(!empty($apiExpectedResultCodes[$contentType])) {
        $recommendedResponses = $apiExpectedResultCodes[$contentType];
      }
      $contentTypeId = $responseArray['code'];
      if(empty($responseArray['responses'])) {continue;}
      foreach ($responseArray['responses'] as $responseName => $responseCodeId) {
        $recommended = '';
        if(!empty($recommendedResponses['responses'][$responseName])) {
          $recommended = '&#x2714;';
        }

        $form_element['code_display']["row-start$row"] = ['#markup' => '<tr class="form-item" >',];

        $form_element['code_display']["CK-".$contentTypeId.'-'.$responseCodeId] = [
          '#type' => 'checkbox',
          '#prefix' => " \n ".'<td class="table-checkbox form-item ">',
          '#suffix' => '</td>',

          '#wrapper_attributes' => [
            'id' => 'odt-filter',
            'class' => ['form-item'],
          ],
        ];

        if(!empty($defaultCodes[$contentTypeId][$responseCodeId])) {
          $form_element['code_display']["CK-".$contentTypeId.'-'.$responseCodeId]['#default_value'] = 1;
        }


        $cell = " \n ".'<td class="response-code-recommended">'.$recommended.'</td>';
        $form_element['code_display']['TX-'.$row.'-recommended'] = ['#markup' => $cell,];

        $cell = " \n ".'<td class="response-code-contact-type">'.$contentType.'</td>';
        $form_element['code_display']['TX-'.$row.'-type'] = ['#markup' => $cell,];

        $cell = " \n ".'<td class="response-code-value">'.$contentTypeId.'</td>';
        $form_element['code_display']['TX-'.$row.'-contactCodeId'] = ['#markup' => $cell,];

        $cell = " \n ".'<td class="response-code-responses">'.$responseName.'</td>';
        $form_element['code_display']['TX-'.$row.'-responseName'] = ['#markup' => $cell,];

        $cell = " \n ".'<td class="response-code-value">'.$responseCodeId.'</td>';
        $form_element['code_display']['TX-'.$row.'-responseCodeId'] = ['#markup' => $cell,];

        $form_element['code_display']["row-end$row"] = ['#markup' => " \n ".'</tr>',];

        $row++;

      }
    }


    // End of table body.
    $form_element['code_display']['body_end'] = ['#markup' => " \n ".'</tbody>',];

    // End of the table.
    $form_element['code_display']['table_end'] = ['#markup' => " \n ".'</table>'." \n ",];



    return $form_element;
  }


  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildPhoneRcSubTable
   *
   * Build a table of the alternate response codes used to report a phone or
   * text contact when the NL can't share the phone number they used.  Each
   * alternate that isn't in the list of response codes from the API is
   * flagged in place of its code.
   *
   * @param array $apiKnownResultCodes
   * @return array
   */
  function buildPhoneRcSubTable(array $apiKnownResultCodes): array
  {
    $form_element['phone_rc_sub_title'] = [
      '#markup' => " \n ".'<h3>Alternate codes for phone and text contacts</h3>'
        ." \n ".'<p>A phone or text contact has to be reported with a phone number.  '
        .'When the NL can\'t share the number, the contact is reported with the alternate code instead.</p>',
    ];

    $rows = '';
    foreach ($this->phoneRcSubs as $contextName => $responseRcSubs) {
      foreach ($responseRcSubs as $responseName => $responseRcSub) {
        $subContext = $responseRcSub['subContext'];
        $subResponse = $responseRcSub['subResponse'];
        $subContextId = $apiKnownResultCodes[$subContext]['code'] ?? NULL;
        $subResponseId = $apiKnownResultCodes[$subContext]['responses'][$subResponse] ?? NULL;
        if(empty($subContextId) || empty($subResponseId)) {
          $codeCell = '<td class="response-code-missing">&#x26A0; Not available</td>';
        } else {
          $codeCell = '<td class="response-code-value">'.$subContextId.'-'.$subResponseId.'</td>';
        }
        $rows .= " \n ".'<tr>'
          .'<td class="response-code-contact-type">'.$contextName.'</td>'
          .'<td class="response-code-responses">'.$responseName.'</td>'
          .'<td class="response-code-contact-type">'.$subContext.'</td>'
          .'<td class="response-code-responses">'.$subResponse.'</td>'
          .$codeCell
          .'</tr>';
      }
    }

    $hdrRow  = '<th class="response-code-contact-type">Contact Type</th>';
    $hdrRow .= '<th class="response-code-responses">Response</th>';
    $hdrRow .= '<th class="response-code-contact-type">Alternate Type</th>';
    $hdrRow .= '<th class="response-code-responses">Alternate Response</th>';
    $hdrRow .= '<th class="response-code-value">Code</th>';

    $form_element['phone_rc_sub_table'] = [
      '#markup' => Markup::create(" \n ".'<table class="no-white response-code-sub-table">'
        ." \n <thead><tr>".$hdrRow.'</tr></thead>'
        ." \n <tbody>".$rows." \n </tbody>"
        ." \n </table> \n "),
    ];

    return $form_element;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * warnOfMissingExpectedResultCodes
   *
   * Warn if any of the API expected response codes are not available in
   * this instance of VoteBuilder.
   *
   * @param array $apiKnownResultCodes
   * @param array $apiExpectedResultCodes
   * @return void
   */
  private function warnOfMissingExpectedResultCodes(array $apiKnownResultCodes, array $apiExpectedResultCodes): void
  {
    foreach ($apiExpectedResultCodes as $contentType => $expectedResultCodes) {
      if(empty($apiKnownResultCodes[$contentType])) {
        $this->messenger()->addWarning($this->t('Contact type not available in VoteBuilder: [@contentType]', ['@contentType' => $contentType]));
        continue;
      }
      $knownResponses = $apiKnownResultCodes[$contentType]['responses'] ?? [];
      foreach ($expectedResultCodes['responses'] as $responseName => $responseText) {
        if(empty($knownResponses[$responseName])) {
          $this->messenger()->addWarning($this->t('Expected response code not available in VoteBuilder: [@contentType - @responseName]', [
            '@contentType' => $contentType,
            '@responseName' => $responseName,
          ]));
        }
      }
    }
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * warnOfMissingPhoneRcSubs
   *
   * Warn if the subContext or subResponse used in $phoneRcSubs are not
   * available in this instance of VoteBuilder.
   *
   * @param array $apiKnownResultCodes
   * @return void
   */
  private function warnOfMissingPhoneRcSubs(array $apiKnownResultCodes): void
  {
    foreach ($this->phoneRcSubs as $contextName => $responseRcSubs) {
      foreach ($responseRcSubs as $responseName => $responseRcSub) {
        $subContext = $responseRcSub['subContext'];
        $subResponse = $responseRcSub['subResponse'];
        if(empty($apiKnownResultCodes[$subContext]['responses'][$subResponse])) {
          $this->messenger()->addWarning($this->t('Phone response substitution not available in VoteBuilder: [@contextName - @responseName -> @subContext - @subResponse]', [
            '@contextName' => $contextName,
            '@responseName' => $responseName,
            '@subContext' => $subContext,
            '@subResponse' => $subResponse,
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $config = Drupal::config('nlpservices.configuration');
    $currentResponseCodes = $config->get('nlpservices_canvass_response_codes');
    if(empty($currentResponseCodes)) {$currentResponseCodes=[];}

    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];

    $apiKnownResponseCodes = $this->apiResponseCodeObj->getApiKnownResultCodes();
    $apiExpectedResultCodes = $this->apiResponseCodeObj->getApiExpectedResultCodes();
    $this->warnOfMissingExpectedResultCodes($apiKnownResponseCodes, $apiExpectedResultCodes);
    $this->warnOfMissingPhoneRcSubs($apiKnownResponseCodes);
    $form['code_select'] = $this->buildCanvassCodeSelect(
      $apiKnownResponseCodes,$apiExpectedResultCodes,$currentResponseCodes,$codes);
    $form_state->set('codes',$codes);
    $form['phone_rc_subs'] = $this->buildPhoneRcSubTable($apiKnownResponseCodes);

    $form['submit'] = [
      '#type' => 'submit',
      '#name' => 'select-response-codes',
      '#value' => t('Save the selected response codes.'),
    ];

    $form['button_link2'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    $form['#attached']['library'][] = 'nlpservices/canvass-response-code';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();

    $codes = $form_state->get('codes');
    $responseCodes = $codes['responseCodes'];
    $selectedCodes = [];
    $altPhone['contentTypeId'] = NULL;
    $rcSub = [];
    foreach ($values as $valueId => $value) {
      $valueIdParts = explode('-',$valueId);
      if($valueIdParts[0] != 'CK') {continue;}  // Not a checkbox.
      $contentTypeId = $valueIdParts[1];
      $responseId = $valueIdParts[2];
      $contentType = $responseCodes[$contentTypeId]['name'];
      $responseName = $responseCodes[$contentTypeId][$responseId];
      if(!empty($value)) {
        $selectedCodes[$contentType]['name'] = $contentType;
        $selectedCodes[$contentType]['code'] = $contentTypeId;
        $selectedCodes[$contentType]['responses'][$responseName] = $responseId;
      }

      //Check for the alternative codes for phone contacts (when we don't know
      //  the actual phone number used).

      foreach ($this->phoneSubs as $contactSub=>$responseSubs) {
        if($contentType == $contactSub) {
          foreach ($responseSubs as $responseSub) {
            if($responseName == $responseSub) {
              $rcSub[$contentType][$responseName] = [
                'responseIdSub' => $responseId,
                'contentIdSub' => $contentTypeId,
              ];
              break;
            }
          }
        }
      }

    }

    $config = Drupal::service('config.factory')->getEditable('nlpservices.configuration');
    $config->set('nlpservices_canvass_response_codes', $selectedCodes);
    $config->save();

    $altPhone = [];
    foreach ($this->phoneRcSubs as $contextName=>$responseRcSubs) {
      foreach ($responseRcSubs as $responseName=>$responseRcSub) {
        $subContext = $responseRcSub['subContext'];
        $subResponse = $responseRcSub['subResponse'];
        if(!empty($rcSub[$subContext][$subResponse])) {
          $rcSubstitution = $rcSub[$subContext][$subResponse];
          $altPhone[$contextName][$responseName] = [
            'subContextName'=>$subContext,
            'subContext'=>$rcSubstitution['contentIdSub'],
            'subResponseName'=>$subResponse,
            'subResponse'=>$rcSubstitution['responseIdSub'],
            ];
        }
      }
    }

    $config->set('nlpservices_alt_phone_response_codes', $altPhone);
    $config->save();
  }

}
