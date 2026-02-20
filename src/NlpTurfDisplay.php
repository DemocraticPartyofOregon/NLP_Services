<?php

namespace Drupal\nlpservices;

class NlpTurfDisplay
{
  private array $header = [];
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * displayTurfs
   *
   * Create the form elements for a table of turfs to selection one or more
   * turfs.  The table is constructed to allow the identification of the selected
   * turf when the form is submitted.
   *
   * @param $turfs - The array of turfs to display.
   * @param string $displayType
   * @return array
   */
  function displayTurfs($turfs, string $displayType='other'): array
  {
    $this->headerBuild($displayType);
    
    // create an array of information to display as rows in a table for selection of a turf.
    // The array will be different depending on the intended use of the turf display.
    $rows = $turfIds = [];
    $row = 1;
    foreach ($turfs as $turf)
    {
      $columns = [];
      $columns['cb'] = 'cb';
      
      $noPdf = '';
      if($displayType=='walksheet' AND empty($turf['turfPDF'])) {
        $noPdf = '&#134;&#134;';
      }
      $columns['turfIndex'] = $turf['turfIndex'].$noPdf;
      $columns['hd'] = $turf['hd'];
      $columns['precinct'] = $turf['precinct'];
      if($displayType!='replacement') {
        $columns['nlLastName'] = $turf['nlLastName'] . ', ' . $turf['nlFirstName'] . '<br>' . $turf['mcid'];
      }
      $columns['turfName'] = $turf['turfName'].$noPdf.'<br>'.$turf['mapRegionName'];
      $columns['folderName'] = $turf['folderName'];
      if($displayType!='replacement') {
        $columns['minivanNumber'] = '';
        if ($displayType == 'walksheet' AND !empty($turf['minivanNumber'])) {
          $createDate = date('Y-m-d', strtotime($turf['minivanCreateDate']));
          $columns['minivanNumber'] = $turf['minivanNumber'] . '<br>' . $createDate;
        }
      }
      
      if($displayType=='walksheet') {
        $columns['email'] = $turf['email'];
      }
      $turfIds[$row] = ['turfIndex' => $turf['turfIndex'], 'rowCount' => $row];
      $rows[$row++] = $columns;
    }
    
    // Create the form element for the table.  The form element index is named 'contacts' and this
    // name is used to extract the selected turf IDs from the form submission.
    $form_element = [];
    $form_element['contacts'] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];
    
    foreach ($rows as $rowNumber => $row) {
      foreach($row as $columnName => $columnValue) {
        if($columnName == 'cb') {
          $form_element['contacts'][$rowNumber][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_'.$turfIds[$rowNumber]['turfIndex'].'_'.$turfIds[$rowNumber]['rowCount'],
          ];
        } else {
          $form_element['contacts'][$rowNumber][$columnName] = [
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
   * Build the header for the table.
   * The header is an array of arrays.
   * Each array has the following keys:
   * - data - The text to display in the header cell.
   * - field - The field name to sort by.
   * - class - The CSS classes to apply to the header cell.
   *
   * The displayType is used to determine the columns to display.  The types are used
   * to adjust the table columns based on the intended use of the turf display.
   * Possible values are:
   * - other - Display all columns.
   * - replacement - Display the replacement columns.
   * - walksheet - Display the walksheet columns.
   *
   * @param string $displayType
   * @return void
   */
  private function headerBuild(string $displayType='other'):void
  {
    $this->header = [
      ['data' => t('cb'), 'field' => 't.cb', 'class' => ['nlp-th-color'] ],
      ['data' => t('ti'), 'field' => 't.turfIndex', 'class' => ['nlp-th-color'] ],
      ['data' => t('HD'), 'field' => 'n.hd', 'class' => ['nlp-th-color'] ],
      ['data' => t('Pct'), 'field' => 'n.precinct', 'class' => ['nlp-th-color'] ],
    ];
    if($displayType!='replacement') {
      $this->header[] = ['data' => t('Name<br>MCID'), 'field' => 'n.nlLastName', 'class' => ['nlp-th-color']];
    }
    $this->header[] = ['data' => t('Turf name<br>Map Region Name'), 'field' => 'n.turfName', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Folder name'), 'field' => 'n.folderName', 'class' => ['nlp-th-color'] ];
    if($displayType!='replacement') {
      $this->header[] = ['data' => t('MiniVan#<br>Create Date'), 'field' => 'n.minivanNumber', 'class' => ['nlp-th-color']];
    }
    
    if($displayType=='walksheet') {
      $this->header[] = ['data' => t('Email'), 'field' => 'n.email', 'class' => ['nlp-th-color'] ];
    }
  }
  
  /**
   * validateTurfSelection
   *
   * Get the turf selected by the user.  The user checked a box to make the selections.
   * Returns an array of turf IDs and row numbers.
   * The keys are:
   * - turfIndex - The turf ID.
   * - rowCount - The row number.
   *
   * @param $contacts - The array from the form submission with the checkbox values.
   * @return array
   */
  public function validateTurfSelection($contacts):array
  {
    if(empty($contacts)) {return [];}
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_',$contact['cb']);
        $selections[$contactParts[1]] = ['rowCount'=>$contactParts[2], 'turfIndex'=>$contactParts[1]];
      }
    }
    return $selections;
  }
  
  /**
   * getSortField
   *
   * Get the column the user wanted to use to sort the table.
   *
   * @param $data
   * @return string
   */
  public function getSortField($data):string
  {
    $this->headerBuild(TRUE);
    foreach ($this->header as $headerInfo) {
      if($headerInfo['data'] == $data) {
        return $headerInfo['field'];
      }
    }
    return '';
  }
  
}
