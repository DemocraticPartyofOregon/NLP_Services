<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\Markup;
use DateTimeZone;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryVoterListDisplay
{
  protected NlpReports $reportsObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpSessionData $nlpSessionDataObj;

  public function __construct(
    $reportsObj,
    $nlpConfigurationObj,
    $nlpSessionDataObj,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly DateFormatterInterface $dateFormatter,
  )
  {
    $this->reportsObj = $reportsObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
  }

  public static function create(ContainerInterface $container): NlpDataEntryVoterListDisplay
  {
    return new static(
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.session_data'),
      $container->get('extension.list.module'),
      $container->get('date.formatter'),
    );
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildDataEntryVoterArray
   *
   * Given the NLP voter record, reformat the information for display in the data entry form.
   * The information is used to build the various cells in the data entry table.
   *
   * @param array $voter
   * @return array
   */
  public function buildDataEntryVoterArray( array $voter ):array
  {
    $voterArray = [];
    // Basic voter information.
    $vanid = $voter['vanid'];
    $voterArray['vanid'] = $voter['vanid'];
    $firstName = Html::escape($voter['firstName'] ?? '');
    $voterArray['firstName'] = $firstName;
    $lastName = Html::escape($voter['lastName'] ?? '');
    $voterArray['lastName'] = $lastName;
    $age = Html::escape($voter['age'] ?? '');
    $gender = Html::escape($voter['sex'] ?? '');
    $party = Html::escape($voter['party'] ?? '');
    $voterArray['name'] = "<b>$lastName, $firstName</b> - Age:$age Gender:$gender Party:$party";
    $voterArray['nickname'] = $voter['nickname'];
    // Address information.
    $street = Html::escape($voter['address']['streetNo'] ?? '')." ".Html::escape($voter['address']['streetPrefix'] ?? '')." "
      .Html::escape($voter['address']['streetName'] ?? '')." ".Html::escape($voter['address']['streetType'] ?? '');
    $apt = (!empty($voter['address']['aptType']))
      ? '<br>'.Html::escape($voter['address']['aptType'])." ".Html::escape($voter['address']['aptNo'] ?? '')
      : '';
    $city = Html::escape($voter['address']['city'] ?? '');
    $voterArray['address'] = "$street$apt<br>$city";
    $voterArray['cd'] = $voter['address']['cd'];
    // Contact information.
    $homePhone = (!empty($voter['homePhone']))?$this->formatTelephone($voter['homePhone']):'';
    $cellPhone = (!empty($voter['cellPhone']))?$this->formatTelephone($voter['cellPhone']):'';
    $voterArray['homePhone'] = $homePhone;
    $voterArray['cellPhone'] = $cellPhone;
    $voterArray['homePhoneId'] = $voter['homePhoneId'];
    $voterArray['cellPhoneId'] = $voter['cellPhoneId'];
    $phones = "<br/>";
    if (empty($homePhone) && empty($cellPhone)) {
      $phones .= "<i>No phone numbers.</i>";
    }
    if (!empty($homePhone)) {
      $phones .= "H: $homePhone";
      if($voter['preferredPhoneType'] == 'H') {
        $phones .= ' <span class="voter-sms-status">Preferred</span>';
      }
    }
    if (!empty($cellPhone)) {
      if (!empty($homePhone)) {
        $phones .= "<br/>";
      }
      $phones .= "C: $cellPhone";
      if($voter['preferredPhoneType'] == 'C') {
        $phones .= ' <span class="voter-sms-status">Preferred</span>';
      }
    }
    $voterArray['address'] .= $phones;
    // The note created by the NL.
    $voterContacts = $this->reportsObj->getCanvassResults($vanid);
    if(empty($voterContacts['note'])) {
      $voterArray['note'] = '';
      $voterArray['reportIndex'] = 0;
    } else {
      $noteText = $voterContacts['note']['text'];
      $noteText = (!empty($noteText))?html_entity_decode($noteText, ENT_QUOTES ):'';
      $voterArray['note'] = $noteText;
      $voterArray['reportIndex'] = $voterContacts['note']['reportIndex'];
    }
    // Content result checkboxes
    $voterArray['deceased'] = !empty($voterContacts['deceased']);
    $voterArray['hostile'] = !empty($voterContacts['hostile']);
    $voterArray['moved'] = !empty($voterContacts['moved']);
    // New or changed phones or email.
    $contactUpdate = [''=>'Report something'];
    if(!empty($cellPhone)) {
      $contactUpdate['BC'] = 'Bad No. '.$cellPhone;
      $contactUpdate['OO'] = 'Opt out of texting '.$cellPhone;
    }
    if(!empty($homePhone)) {
      $contactUpdate['BH'] = 'Bad No. '.$homePhone;
    }
    $contactUpdate['NC'] = 'New Cell Number';
    $contactUpdate['NH'] = 'New Home Number';
    $contactUpdate['NE'] = 'New Email';
    if($this->nlpConfigurationObj->getStateName() !== 'Oregon') {
      $contactUpdate['LS'] = 'Wants lawn sign';
      $contactUpdate['RTP'] = 'Needs ride to polls';
    }
    $voterArray['contactUpdateOptions'] = $contactUpdate;
    // Historical contact information.
    $voterArray['historical'] = $voter['historic']['display'];
    $voterArray['historicalLines'] = $voter['historic']['displayLines'];
    // Voting history.
    $yes = '<span class="yes">Y</span> ';
    $no = '<span class="no">N</span> ';
    $formattedVotingHistory = str_replace(['@Y','@N'],[$yes,$no],$voter['votingHistory']);
    $voterArray['votingRecord'] = '<div><b>Voting Record</b><br>'.$formattedVotingHistory.'<br></div>';
    // The gold star for voting if the ballot was returned.
    $nickname = Html::escape($voter['nickname']);
    $star = '<div><span class="not-voted">'.$nickname." hasn't voted yet.<br></span></div>";
    if(!empty($voter['status']['voted'])) {
      $votedMsg = ' Success! <br>'.$nickname.' voted on '.Html::escape($voter['status']['voted']);
      $color = 'green';
      $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);
      $goldStar = $modulePath."/img/nlp-gold-star_rotator-50.gif";
      $star = '<div class="star-box">
               <div class="star-img"><img alt="" src="'.$goldStar.'" ></div>
               <div class="star-text"><span style="color:'.$color.'; font-weight: bold; ">'.$votedMsg.'</span></div>
               </div><div class="end-big-box"></div>';
    }
    $voterArray['star'] = $star;
    //  The status message.
    $statusMessage = [
      'deceased' => ['class'=>'voter-deceased', 'text'=>':fn has been marked as deceased.'],
      'hostile' => ['class'=>'voter-hostile', 'text'=>':fn has been marked as hostile and will be removed from future turfs.  Do not attempt to contact this voter again.'],
      'moved' => ['class'=>'voter-moved', 'text'=>':fn has moved.'],
      'pledge' => ['class'=>'voter-pledge', 'text'=>'On :contactDate you asked :fn to pledge to vote. The response was - :response.'],
      'county' => ['class'=>'voter-county', 'text'=>':scriptQuestion.  On :contactDate the response was - :response'],
      'postcard' => ['class'=>'voter-postcard', 'text'=>'On :contactDate you sent :fn a postcard.'],
      'attempt' => ['class'=>'voter-attempt', 'text'=>'On :contactDate you attempted to contact :fn.  The result was - :response'],
      'noAttempt' => ['class'=>'voter-no-attempt', 'text'=>'You have not yet attempted to contact :fn.'],
    ];
    $contactDate = $scriptQuestion = $response = NULL;
    $fn = $voter['nickname'];
    $dateFormatter = $this->dateFormatter;

    $timeZone = date_default_timezone_get();
    try {
      $timeZoneObj = new DateTimeZone($timeZone);
    } catch (Exception $e) {
      nlp_debug_msg('Time zone error', $e);
      return $voterArray;
    }

    if(!empty($voter['status']['deceased'])) {
      $contactStatusType = 'deceased';
    } elseif(!empty($voter['status']['hostile'])) {
      $contactStatusType = 'hostile';
    } elseif($voter['address']['moved']) {
      $contactStatusType = 'moved';
    } elseif (!empty($voter['turfVoter']['pledgedToVote'])) {
      $rIndex = $voter['turfVoter']['pledgedToVote'];
      $pledgeReport = $this->reportsObj->getNlpReport($rIndex);
      if(!empty($pledgeReport)) {
        $response = '['.$pledgeReport['contactType'].'] '.$pledgeReport['value'];
        $dateTime = new DrupalDateTime($pledgeReport['contactDate'], $timeZoneObj);
        $timestamp = $dateTime->getTimestamp();
        $contactDate = $dateFormatter->format($timestamp, 'custom' ,'M j');
        $contactStatusType = 'pledge';
      } else {
        $contactStatusType = 'noAttempt';
      }
    } elseif(!empty($voter['turfVoter']['attemptedContact'])) {
      $rIndex = $voter['turfVoter']['attemptedContact'];
      $attemptReport = $this->reportsObj->getNlpReport($rIndex);
      $dateTime = new DrupalDateTime($attemptReport['contactDate'], $timeZoneObj);
      $timestamp = $dateTime->getTimestamp();
      $contactDate = $dateFormatter->format($timestamp, 'custom' ,'M j');
      if($attemptReport['value'] == 'Postcard') {
        $contactStatusType = 'postcard';
      } else {
        $contactStatusType = 'attempt';
        $response = '['.$attemptReport['contactType'].'] '.$attemptReport['value'];
      }
    } else {
      $contactStatusType = 'noAttempt';
    }
    $rawMsg = $statusMessage[$contactStatusType]['text'];
    $contactStatusMsg = t($rawMsg, [
      ':fn' => $fn ?? '',
      ':contactDate' => $contactDate ?? '',
      ':scriptQuestion' => $scriptQuestion ?? '',
      ':response' => $response ?? '',
    ]);
    $voterArray['contactStatusMsg'] = Markup::create(
      '<div class="' . $statusMessage[$contactStatusType]['class'] . '">' . $contactStatusMsg . '</div>'
    );
    return $voterArray;
  }


  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * formatTelephone
   *
   * Convert the phone number into a standard form.
   *
   * @param  string $phoneNumber
   * @return string
   */
  private function formatTelephone( string $phoneNumber ): string
  {
    if(empty($phoneNumber)) {return'';}
    $cleaned = preg_replace('/[^[:digit:]]/', '', $phoneNumber);
    $matches = [];
    preg_match('/(\d{3})(\d{3})(\d{4})/', $cleaned, $matches);
    return "($matches[1]) $matches[2]-$matches[3]";
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildVoterDataEntryTable
   *
   * Creates the Drupal form elements for the voter data entry page.
   *
   * @param array $voterArrays - An array of voter data arrays created above.
   * @return array
   */
  public function buildVoterDataEntryTable( array $voterArrays ):array
  {

    $form['dataEntry'] = [
      '#type' => 'table',
      '#rows' => [],
      '#theme' => 'table',
    ];

    foreach ($voterArrays as $voterArray) {
      $vanid = $voterArray['vanid'];
      $commentMax = $this->reportsObj::MAX_COMMENT;
      // The name cell (actually a whole row that spans all 5 columns).
      $name = $voterArray['name'];
      $form['dataEntry']['row_1_' . $vanid]['col_1_1'] = [
        '#markup' => "$name",
        '#wrapper_attributes' => ['colspan' => 5, 'class' => ['det-background',],]
      ];

      $form['dataEntry']['row_2_' . $vanid] = [
        '#wrapper_attributes' => ['class' => ['det-background',],]
      ];
      // The address cell including the contact phone numbers.
      $address = $voterArray['address'];
      $form['dataEntry']['row_2_' . $vanid]['col_2_1'] = [
        '#type' => 'markup',
        '#markup' => '<div class="det-text">' . $address . '</div>',
        '#wrapper_attributes' => ['class' => ['det-top', 'det-background',],]
      ];
      // The contact method cell.
      $form['dataEntry']['row_2_' . $vanid]['col_2_2'] = [
        '#type' => 'markup',
        '#markup' => "<b>Contact Method</b>",
        '#wrapper_attributes' => ['rowspan' => 2, 'class' => ['det-top', 'det-background',],]
      ];
      $selectedContactMethod = $this->nlpSessionDataObj->getVoterContactMethod($vanid);
      $contactMethods = $this->nlpConfigurationObj->getContactMethods();
      $form['dataEntry']['row_2_' . $vanid]['col_2_2']['line_2_2_2'] = [
        '#type' => 'select',
        '#options' => $contactMethods,
        '#default_value' => $selectedContactMethod['code'],
        // Only remembered for the session, not a report to save (unsaved-changes.js).
        '#attributes' => ['class' => ['nlp-not-an-edit']],
        '#ajax' => [
          'callback' => '::formCallback',
          'wrapper' => 'data-entry-div',
        ],
      ];
      // The pledge to vote survey question and response. Used to record the response to the survey
      // question and indicates an actual face-to-face contact with the voter.  The survey question
      // can be anything, but is usually a question about whether the voter will vote in the next election.
      $form['dataEntry']['row_2_' . $vanid]['col_2_3'] = [
        '#type' => 'markup',
        '#markup' => "<b>Voter Contacted</b>",
        '#wrapper_attributes' => ['rowspan' => 2, 'class' => ['det-top', 'det-background',],]
      ];
      $pledgeSurveyQuestion = $this->nlpConfigurationObj->getPledgeSurveyQuestion();
      $question = strip_tags(t($pledgeSurveyQuestion['scriptQuestion'],
        [':fn' => $voterArray['nickname']]));
      $responseList = [0 => 'Select Response'];
      foreach ($pledgeSurveyQuestion['responses'] as $responseId => $response) {
        $responseList[$responseId] = $response['name'];
      }
      $responseList = (!empty($voterArray['nickname'])) ?
        str_replace(":fn", $voterArray['nickname'], $responseList) : $responseList;
      $form['dataEntry']['row_2_' . $vanid]['col_2_3']['line_2_3_2'] = [
        '#type' => 'markup',
        '#markup' => "<div>$question</div>",
      ];
      $form['dataEntry']['row_2_' . $vanid]['col_2_3']['line_2_3_3'] = [
        '#type' => 'select',
        '#options' => $responseList,
      ];
      $form['dataEntry']['row_2_' . $vanid]['col_2_3']['line_2_3_4'] = [
        '#type' => 'markup',
        '#markup' => '<div class="response-title-note line-spacer">
            (Skip this report unless the voter responds directly.)</div>',
      ];

      $form['dataEntry']['row_2_' . $vanid]['col_2_4'] = [
        '#type' => 'markup',
        '#markup' => "<b>Voter Wasn't Contacted</b>",
        '#wrapper_attributes' => ['rowspan' => 3, 'class' => ['det-top', 'det-background',],]
      ];

      // The cell to report an attempt to contact the voter without getting a response to the P2V.
      $responses = $this->nlpConfigurationObj->getContactResponses($selectedContactMethod);
      $hidden = ['Deceased', 'Hostile', 'Moved'];  // Checkboxes record these.
      foreach ($hidden as $hide) {
        unset($responses[$hide]);
      }
      $responses = ['Select response' => 0] + $responses;
      $responseOptionsDisplay = array_flip($responses);
      $form['dataEntry']['row_2_' . $vanid]['col_2_4']['line_2_4_2'] = [
        '#type' => 'select',
        '#options' => $responseOptionsDisplay,
      ];
      // Display of a popup link for the historical contacts by an NL, past and current.
      $historical = $voterArray['historical'];
      if(!empty($historical)) {
        $historicalContacts = '<span class="historical-report-list">' . $historical . '</span>';
        $form['dataEntry']['row_2_' . $vanid]['col_2_4']['line_2_4_3'] = [
          '#type' => 'markup',
          '#markup' => '<div><div class="line-spacer"></div><div class="hint_note historical-report-title">'
            . '<p><br>View historical contacts' . $historicalContacts . '</p></div></div>',
        ];
      }
      // The checkbox to indicate the reason that the voter was not contacted.
      $form['dataEntry']['row_2_' . $vanid]['col_2_5'] = [
        '#type' => 'markup',
        '#markup' => "<div><b>Important Updates:</b></div>",
        '#wrapper_attributes' => ['rowspan' => 4, 'class' => ['det-top', 'det-background',],]
      ];

      $form['dataEntry']['row_2_' . $vanid]['col_2_5']['line_2_5_2'] = [
        '#type' => 'checkbox',
        '#title' => 'Moved',
        '#default_value' => (int)$voterArray['moved'],
      ];

      $form['dataEntry']['row_2_' . $vanid]['col_2_5']['line_2_5_3'] = [
        '#type' => 'checkbox',
        '#title' => 'Hostile',
        '#default_value' => (int)$voterArray['hostile'],
      ];

      $form['dataEntry']['row_2_' . $vanid]['col_2_5']['line_2_5_4'] = [
        '#type' => 'checkbox',
        '#title' => 'Deceased',
        '#default_value' => (int)$voterArray['deceased'],
      ];
      // Update contact information, like new phone numbers or email.
      $contactUpdateOptions = $voterArray['contactUpdateOptions'];
      $form['dataEntry']['row_2_' . $vanid]['col_2_5']['line_2_5_5'] = [
        '#type' => 'select',
        '#title' => 'Update contact Info.',
        '#options' => $contactUpdateOptions,
        '#default_value' => '',
      ];
      $form['dataEntry']['row_2_' . $vanid]['col_2_5']['line_2_5_6'] = [
        '#type' => 'textfield',
        '#size' => 20,
        '#maxlength' => 128,
        '#default_value' => '',
      ];
      // Voting record and gold star.
      $votingRecord = $voterArray['votingRecord'];
      $star = $voterArray['star'];
      $form['dataEntry']['row_3_' . $vanid]['col_3_1'] = [
        '#markup' => "<div>$star</div><div>$votingRecord</div>",
        '#wrapper_attributes' => ['rowspan' => 2, 'class' => ['det-background',],]
      ];
      // Note cell.
      $form['dataEntry']['row_4_' . $vanid]['col_4_1'] = [
        '#type' => 'markup',
        '#markup' => "Your (optional) note about this voter ($commentMax characters max).",
        '#wrapper_attributes' => ['colspan' => 2, 'class' => ['det-background',],]
      ];
      $note = $voterArray['note'];
      $form['dataEntry']['row_4_' . $vanid]['col_4_1']['line_4_1_2'] = [
        '#type' => 'textarea',
        '#rows' => 1,
        '#cols' => 13,
        '#default_value' => $note,
      ];
      // Voter contact status message.
      $contactStatusMsg = $voterArray['contactStatusMsg'];
      $form['dataEntry']['row_5_' . $vanid]['col_5_1'] = [
        '#markup' => $contactStatusMsg,
        '#wrapper_attributes' => ['colspan' => 4, 'class' => ['det-background',],]
      ];

      $form['dataEntry']['row_6_' . $vanid]['col_6_1'] = [
        '#markup' => "&nbsp;",
        '#wrapper_attributes' => ['colspan' => 5, 'class' => ['det-spacer',],]
      ];
    }
    return $form;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * validateDataEntrySelection
   *
   * Extract the user-entered information from the $values array returned
   * by the forms API.
   *
   * Returns an array of input for each voter indexed by the vanid.
   *  The array has the following structure:
   *     'vanid' = voter id from voterFile
   *     'contactMethod' = The contact method selected by the user.
   *     'surveyResponse' = The response to the survey question.
   *     'notContacted' = The response to the not contacted question.
   *     'Moved' = 1 if the voter has moved, 0 otherwise.
   *     'Hostile' = 1 if the voter is hostile, 0 otherwise.
   *     'Deceased' = 1 if the voter is deceased, 0 otherwise.
   *     'updateContact' = An array with the contact change and new contact information.
   *           'contactChange' = The type of contact change,
   *                'BC' for a bad cell phone.
   *                'BH' for a bad home phone,
   *                'NC' for a new cell phone,
   *                'NH' for a new home phone,
   *                'NE' for a new email,
   *           'newContact' = The new contact information, e.g., a new phone number or email.
   *     'Note' = The note entered by the user for this voter.
   *
   * @param array $dataEntry
   * @return array
   */
  public function validateDataEntrySelection( array $dataEntry ):array
  {
    $selections = [];
    foreach($dataEntry as $rowId=>$row) {
      $rowIdParts = explode('_',$rowId);
      $rowKey = $rowIdParts[0].$rowIdParts[1];
      $vanid = $rowIdParts[2];
      switch ($rowKey) {
        case 'row2':
          $contactMethod = $row['col_2_2']['line_2_2_2'];
          $selections[$vanid]['contactMethod'] = $contactMethod;
          $response = $row['col_2_3']['line_2_3_3'];
          $selections[$vanid]['surveyResponse'] = $response;
          $notContacted = $row['col_2_4']['line_2_4_2'];
          $selections[$vanid]['notContacted'] = $notContacted;
          $moved = empty($row['col_2_5']['line_2_5_2'])?0:$row['col_2_5']['line_2_5_2'];
          $selections[$vanid]['moved'] = $moved;
          $hostile = empty($row['col_2_5']['line_2_5_3'])?0:$row['col_2_5']['line_2_5_3'];
          $selections[$vanid]['hostile'] = $hostile;
          $deceased = empty($row['col_2_5']['line_2_5_4'])?0:$row['col_2_5']['line_2_5_4'];
          $selections[$vanid]['deceased'] = $deceased;
          $updateContact = $row['col_2_5']['line_2_5_5'];
          $newContact = $row['col_2_5']['line_2_5_6'];
          $selections[$vanid]['updateContact'] = ['contactChange'=>$updateContact, 'newContact'=>$newContact];
          break;

        case 'row4':
          $note = $row['col_4_1']['line_4_1_2'];
          $selections[$vanid]['note'] = $note;
          break;
      }
    }
    return $selections;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * whatChanged
   *
   * Compares the selections made by the user with the saved voter data.   The selections
   * that are different are converted to actions.   These actions will result in reports
   * recorded in the NLP database and VoteBuilder.
   *
   * Returns an array of actions indexed by the vanid.  Each action is an array with the following structure:
   * *   'type' = The type of action, e.g., 'surveyResponse', 'notContacted', 'moved', 'hostile', 'deceased', 'updateContact', 'note'.
   * *   'contactMethod' = The contact method used for this action, e.g., 'Phone', 'Walk', etc.
   *
   * @param array $selections - from above
   * @param array $voterArrays
   * @return array
   */
  public function whatChanged( array $selections, array $voterArrays ): array
  {
    $actions = [];
    foreach ($selections as $vanid => $selection) {
      if(empty($voterArrays[$vanid])) {
        continue;
      }
      $voterArray = $voterArrays[$vanid];
      $savedContactMethod = $this->nlpSessionDataObj->getVoterContactMethod($vanid);
      $contactMethod = $savedContactMethod['code'];
      if($savedContactMethod['code'] != $selection['contactMethod']) {
        // Remember the method the NL chose for this voter.
        $contactMethods = $this->nlpConfigurationObj->getContactMethods();
        $setContactMethod = [
          'vanid' => $vanid,
          'code' => $selection['contactMethod'],
          'name' => $contactMethods[$selection['contactMethod']] ?? '',
        ];
        $this->nlpSessionDataObj->setVoterContactMethod($setContactMethod);
        $contactMethod = $selection['contactMethod'];
      }

      $surveyResponse = $selection['surveyResponse'];
      if(!empty($surveyResponse)) {
        $actions[$vanid][] = ['type'=>'surveyResponse', 'contactMethod'=>$contactMethod, 'surveyResponse'=>$surveyResponse];
      }

      $notContacted = $selection['notContacted'];
      if(!empty($notContacted)) {
        $actions[$vanid][] = ['type'=>'notContacted', 'contactMethod'=>$contactMethod, 'notContacted'=>$notContacted];
      }

      if(isset($selection['moved']) && isset($voterArray['moved'])) {
        if($selection['moved'] != $voterArray['moved']) {
          $actions[$vanid][] = ['type'=>'moved', 'contactMethod'=>$contactMethod, 'moved'=>$selection['moved']];
        }
      } else {
        $msg = var_export($selection, true);
        Drupal::logger('nlpservices')->notice('Moved error: '.$msg);
      }

      if($selection['hostile'] != $voterArray['hostile']) {
        $actions[$vanid][] = ['type'=>'hostile', 'contactMethod'=>$contactMethod, 'hostile'=>$selection['hostile']];
      }

      if($selection['deceased'] != $voterArray['deceased']) {
        $actions[$vanid][] = ['type'=>'deceased', 'contactMethod'=>$contactMethod, 'deceased'=>$selection['deceased']];
      }

      $newContact = (empty($selection['updateContact']['newContact']))?'':$selection['updateContact']['newContact'];
      if(!empty($selection['updateContact']['contactChange'])) {
        $actions[$vanid][] = ['type'=>'updateContact', 'contactMethod'=>$contactMethod,
          'updateContact'=>$selection['updateContact'], 'newContact'=>$newContact];
      }

      if($selection['note'] != $voterArray['note']) {
        $actions[$vanid][] = ['type'=>'note', 'contactMethod'=>$contactMethod, 'note'=>$selection['note']];
      }

    }
    return $actions;
  }

}
