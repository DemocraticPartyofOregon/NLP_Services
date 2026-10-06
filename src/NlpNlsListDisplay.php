<?php

namespace Drupal\nlpservices;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class NlpNlsListDisplay
{

  protected DrupalUser $drupalUserObj;
  protected NlpCoordinators $coordinatorsObj;
  protected NlpNls $nlsObj;

  private array $header = [];

  public function __construct(
    $drupalUserObj,
    $coordinatorsObj,
    $nlsObj,
    private readonly RequestStack $requestStack,
  ) {
    $this->drupalUserObj = $drupalUserObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->nlsObj = $nlsObj;
  }

  public static function create(ContainerInterface $container): NlpNlsListDisplay
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.nls'),
      $container->get('request_stack'),
    );
  }

  /**
   * createTableArray
   *
   * Given the NL record from NLP, this function creates an array of information about
   * the NLs to be displayed in a table.  It formats the information in an array for
   * each cell of the table.
   *
   * @param $nlRecords - An array of NL records, keyed by MCID.
   * @param bool $brief - See headerBuild() for details.
   * @return array - An array of rows, each row is an array of column information.
   * The column keys are:
   * - cb - checkbox for selection of the NL. (only if $brief is TRUE).
   * - hd - the HD of the NL.
   * - precinct - the precinct of the NL.
   * - name - the name of the NL, with MCID and coordinator information.
   * - address - the address of the NL.
   * - email - the email and phone number of the NL.
   * - notes - the notes for the NL, if $brief is FALSE.
   * - nl - the NL status, if $brief is FALSE. Dropdown select box with options for:
   *        Asked, Yes, Not this election, Dropout, and Did not respond.
   * - tc - turf-cut status, if $brief is FALSE. A checkbox to be checked if the turf is cut.
   * - td - turf-delivered status, if $brief is FALSE. A checkbox to be checked if the turf is delivered.
   * - co - preferred contact method, if $brief is FALSE. A dropdown select box with options for:
   *        canvass, phone, mail, minivan.
   * - lastAccess - the last date the NL accessed the turf, if $brief is FALSE.
   * - voterCount - the voter count and door count for the NL, if $brief is FALSE.
   * - attempts - the number of attempts and contacts for the NL, if $brief is FALSE.
   * * @see headerBuild() for details on the $brief parameter.
   *
   */
  public function createTableArray(array $nlRecords, bool $brief=FALSE): array
  {
    $row = $rows = [];
    $rowCount = 1;
    foreach ($nlRecords as $mcid => $nlRecord)
    {
      if($brief) {
        $row['cb'] = ['type' => 'cb', 'rowCount' => $rowCount, 'mcid' => $mcid ];
      }

      $row['hd'] = $nlRecord['hd'];

      $row['precinct'] = $nlRecord['precinct'];

      $coordinator = $this->coordinatorsObj->getAssignedCoordinator($nlRecord);
      if(empty($coordinator)) {
        $coordinator = "<i>Coordinator&nbsp;not&nbsp;assigned</i>";
      } else {
        $firstName = $coordinator['firstName'];
        $lastName = $coordinator['lastName'];
        $coordinator = "($lastName,&nbsp;$firstName)";
      }
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $nlName = "<b>$lastName,&nbsp;$firstName</b>";
      $row['name'] = "$nlName<br>$mcid<br>$coordinator";

      $row['address'] = $nlRecord['address'];

      $row['email'] = $nlRecord['email'].'<br>'.$nlRecord['phone'].'<br>'.$nlRecord['vfid'];

      if(!$brief) {

        $noteText = $nlRecord['status']['notes'];
        $notesWrap = 150;
        $wrap = '';
        if(!empty($noteText)) {
          $wrap = wordwrap($noteText,$notesWrap,"\n",true);
        }
        $row['notes'] = ['type'=>'ta','text'=>$wrap, 'mcid'=>$mcid];

        $askOptions = $this->nlsObj->askOptions;
        $currentAsk = $nlRecord['status']['asked'];
        $defaultAsk = array_search($currentAsk,$askOptions);
        $row['nl'] = ['type'=>'se','options'=>$askOptions, 'default'=>$defaultAsk, 'mcid'=>$mcid ];

        $default = ($nlRecord['status']['turfCut']=='Y')?1:0;
        $row['tc'] = ['type'=>'tc','default'=>$default, 'mcid'=>$mcid];

        $default = ($nlRecord['status']['turfDelivered']=='Y')?1:0;
        $row['td'] = ['type'=>'td','default'=>$default, 'mcid'=>$mcid];

        $contact = $this->nlsObj->contactList;
        $currentContact = $nlRecord['status']['contact'];
        $defaultContact = array_search($currentContact,$contact);
        $row['co'] = ['type'=>'se','options'=>$contact,'default'=>$defaultContact, 'mcid'=>$mcid];

        $user = $this->drupalUserObj->getUserByMcid($mcid);
        $access = (empty($user['access']))?'':$user['access'];
        $row['lastAccess'] = (empty($access))?'never':date('Y-m-d',$access);

        $attempts = $contacts = $voterCount = $doorCount = 0;
        if(!empty($nlRecord['progress'])) {
          $progress = $nlRecord['progress'];
          $attempts = $progress['attempts'];
          $contacts = $progress['contacts'];
          $voterCount = (!empty($progress['voterCount']))?$progress['voterCount']:0;
          $doorCount = (!empty($progress['doorCount']))?$progress['doorCount']:0;
        }

        $row['voterCount'] = "$voterCount<br>$doorCount";

        $row['attempts'] = "$attempts<br>$contacts";
      }
      $rowCount++;
      $rows[$nlRecord['mcid']] = $row;

    }
    return $rows;
  }

  /**
   * buildNlsTable
   *
   * This function builds an HTML table using the array of information about
   * the NLs found in $form_state.
   *
   * @param array $rows
   * @param bool $brief - see headerBuild() for details.
   * @param string $type - Identifier for a page with more than one display of NLs for selection.
   * @param string $callback - Unused; retained for call-site compatibility.
   * @return array - $form - form elements for the table.
   */
  public function buildNlsTable(array $rows, bool $brief=FALSE, string $type='contacts', string $callback='nlsForm'): array
  {
    $this->headerBuild($brief);

    // Build sort links manually instead of using #tablesort => TRUE.
    // TableSort::getQueryParameters appends all current request query params to
    // sort link URLs (via `+ request->query->all()`). When the form is rebuilt
    // during an AJAX POST, any AJAX-identifying params in the request URL (e.g.,
    // ajax_form=1) would be inherited by the sort links, causing the browser's
    // subsequent GET click to be treated as AJAX and returning a JSON error.
    // Manual links with only ?sort=…&order=… keep the URLs clean.
    $request = $this->requestStack->getCurrentRequest();
    $currentSort  = $request->query->get('sort', '');
    $currentOrder = $request->query->get('order', '');
    foreach ($this->header as &$cell) {
      if (empty($cell['field']) || $cell['field'] === 'cb') {
        continue;
      }
      $label    = (string) ($cell['data'] ?? $cell['field']);
      $nextSort = ($currentOrder === $label && $currentSort === 'asc') ? 'desc' : 'asc';
      $url  = Url::fromRoute('<current>', [], ['query' => ['sort' => $nextSort, 'order' => $label]]);
      $href = Html::escape($url->toString());
      $cell['data'] = Markup::create('<a href="' . $href . '">' . $label . '</a>');
      unset($cell['field']);
    }
    unset($cell);

    $form_element[$type] = [
      '#type'       => 'table',
      '#header'     => $this->header,
      '#attributes' => ['class' => ['nlp-nls-table']],
    ];

    foreach ($rows as $row) {
      // Identify the row by the mcid embedded in its cell data rather than
      // by its position in $rows.
      // The sortNlsTable() re-indexes $rows with
      // sequential integer keys, so the array key can no longer be trusted
      // to be the mcid once the table has been sorted.
      $rowMcid = 0;
      foreach ($row as $columnValue) {
        if (is_array($columnValue) && !empty($columnValue['mcid'])) {
          $rowMcid = $columnValue['mcid'];
          break;
        }
      }
      foreach($row as $columnName => $columnValue) {
        $mcid = empty($columnValue['mcid'])?0:$columnValue['mcid'];
        $columnName = "$columnName-$mcid";
        if(is_array($columnValue)) {
          switch ($columnValue['type']) {
            case 'cb':
              // Only one NL can be selected, so every row's radio shares the same
              // #parents (and so the same name attribute) to form a single radio
              // group. The value lands at $type[selected][cb], which keeps the
              // row shape validateNlSelection() expects.
              $form_element[$type][$rowMcid][$columnName] = [
                '#type' => 'radio',
                '#parents' => [$type, 'selected', 'cb'],
                '#return_value' => 'CB_'.$rowMcid.'_'.$columnValue['mcid'],
              ];
              break;
            case 'tc':
              $mcid = $columnValue['mcid'];
              $default = ($columnValue['default']==1)?"cb_turfCut_$mcid":'';
              $form_element[$type][$rowMcid][$columnName] = [
                '#type' => 'checkbox',
                '#return_value' => "cb_turfCut_$mcid",
                '#default_value' => $default,
              ];
              break;
            case 'td':
              $mcid = $columnValue['mcid'];
              $default = ($columnValue['default']==1)?"cb_turfDelivered_$mcid":'';
              $form_element[$type][$rowMcid][$columnName] = [
                '#type' => 'checkbox',
                '#return_value' => "cb_turfDelivered_$mcid",
                '#default_value' => $default,
              ];
              break;
            case 'ta':
              $form_element[$type][$rowMcid][$columnName] = [
                '#type' => 'textarea',
                '#attributes' => ['class' => ['textarea-width', 'no-white', 'nl-notes-textarea']],
                '#cols' => 10,
                '#rows' => 2,
                '#resizable' => FALSE,
                '#default_value' => $columnValue['text'],
              ];
              break;
            case 'nl':
              $form_element[$type][$rowMcid][$columnName] = [
                '#markup' => 'NL',
              ];
              break;
            case 'se':
              $form_element[$type][$rowMcid][$columnName] = [
                '#type' => 'select',
                '#options' => $columnValue['options'],
                '#default_value' => $columnValue['default'],
              ];
              break;
          }
        } else {
          $form_element[$type][$rowMcid][$columnName] = [
            '#markup' => $columnValue,
          ];
        }
      }
    }
    return $form_element;
  }

  /**
   * headerBuild
   *
   * Build the header for the NLs list display.
   *
   * @param bool $brief - if TRUE, the NL information will be brief, with only the checkbox
   * and information to identify the NL.  It is used to select and NL.   If FALSE, the table
   * displays a full set of information about the NL and the NLP activity.  This table is used
   * to display the list of active NLs.
   *
   * @return void - the result is stored in $this->header.
   */
  private function headerBuild(bool $brief=FALSE):void
  {
    $this->header = [];
    if($brief) {
      $this->header[] = ['data' => '', 'field' => 'cb', 'class' => ['nlp-th-color']];
    }
    $this->header[] = ['data' => t('HD'), 'field' => 'hd', 'class' => ['nlp-th-color'] ];
    $pctLabel = ($brief) ? t('Precinct') : t('Pct');
    $this->header[] = ['data' => $pctLabel, 'field' => 'precinct', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Name<br>MCID<br>(Coordinator)'), 'field' => 'name', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Address'), 'field' => 'address', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Email<br>Phone<br>VFID'), 'field' => 'email', 'class' => ['nlp-th-color'] ];
    if(!$brief) {
      $this->header[] = ['data' => t('Notes'), 'field' => 'notes', 'class' => ['nlp-th-color', 'nl-notes-col'] ];
      $this->header[] = ['data' => t('NLP<br>Participation'),  'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('TC'),  'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('TD'),  'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Contact<br>Method'),  'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Last access'), 'field' => 'lastAccess', 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Vtrs<br>Drs'),  'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Att<br>P2V'),  'class' => ['nlp-th-color'] ];
    }

  }

  /**
   * validateNlSelection
   *
   * Validate the NL selection from the form.  It searches the array of contacts
   * for the checkboxes that were selected.  The checkboxes identify which NLs were selected.
   *
   * The form API provides the contact array in the form_state values.   The syntax is created
   * in the buildNlsTable() function, where the checkbox is created.
   *
   * @param array $contacts
   * @return array
   */
  public function validateNlSelection(array $contacts): array
  {
    $selections = [];
    foreach($contacts as $contact) {
      foreach ($contact as $key => $value) {
        $keyParts = explode('-',$key);
        if($keyParts[0] != 'cb' || empty($value)) {continue 2;}
        $contactParts = explode('_',$value);
        $selections[$contactParts[1]] = ['rowCount'=>$contactParts[1], 'mcid'=>$contactParts[2]];
        break;
      }
    }
    return $selections;
  }

  /**
   * getSortField
   *
   * Given the data field, this function returns the sort field for the NLs list display.
   * It is used to sort the NLs list by the selected field.
   *
   * @param $data - The data field to get the sort field for.
   * @param bool $brief - If TRUE, the sort field is for a brief display of NLs.
   * @return string - The sort field for the NLs list display.
   */
  public function getSortField(string $data, bool $brief=FALSE): string
  {
    $this->headerBuild($brief);
    foreach ($this->header as $headerInfo) {
      if(empty($headerInfo['field'])) {continue;}
      if($headerInfo['data'] == $data) {
        return $headerInfo['field'];
      }
    }
    return '';
  }

  /**
   * sortNlsTable
   *
   * Sort the NLs table by the selected field and order.
   *
   * @param $nlsTable - The NLs table to sort.
   * @param $sort - The sort order, either 'asc' or 'desc'.
   * @param $order - The field to sort by.
   * @param bool $brief - If TRUE, the sort is for a brief display of NLs.
   */
  public function sortNlsTable(array &$nlsTable, string $sort, string $order, bool $brief=FALSE): void
  {
    $sortColumn = $this->getSortField($order,$brief);
    $sortOrder = ($sort=='asc')?SORT_ASC:SORT_DESC;
    $column = array_column($nlsTable, $sortColumn);
    array_multisort($column, $sortOrder, $nlsTable);
  }

}
