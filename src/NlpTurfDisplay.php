<?php

namespace Drupal\nlpservices;

class NlpTurfDisplay
{
  private array $header = [];

  /**
   * buildTurfTableArray
   *
   * Converts an array of turfs into an array of rows for a table.  The turf array is the working
   * structure for NLP, and the resulting array is organized for displaying a table form element.  The array of
   * turfs is typically from a map region, but it could be from a folder of saved lists.  The displayType is
   * used to determine the columns to display.  The types are used to adjust the table columns based on the
   * intended use of the turf display.  Possible values are:
   * - other - Display all columns.
   *
   * @param array $turfs
   * @param string $displayType
   * @return array
   */
  public function buildTurfTableArray(array $turfs, string $displayType = 'other'): array
  {
    $rows = [];
    $row = 1;
    foreach ($turfs as $turf) {
      $columns = [];
      if($displayType != 'display') {
        $columns['cb'] = [
          'type' => 'cb',
          'turfIndex' => $turf['turfIndex'],
          'rowCount' => $row,
        ];
      }

      $noPdf = '';
      if($displayType == 'walksheet' && empty($turf['turfPDF'])) {
        $noPdf = '&#134;&#134;';
      }
      $columns['turfIndex'] = $turf['turfIndex'] . $noPdf;
      $columns['hd'] = $turf['turfHd'];
      $columns['precinct'] = $turf['turfPrecinct'];
      if($displayType != 'replacement') {
        $columns['nlLastName'] = $turf['nlLastName'] . ', ' . $turf['nlFirstName'] . '<br>' . $turf['mcid'];
      }
      $columns['turfName'] = $turf['turfName'] . $noPdf . '<br>' . $turf['mapRegionName'];
      $columns['folderName'] = $turf['folderName'];
      if($displayType == 'display') {
        $columns['minivanNumber'] = '';
        if(!empty($turf['minivanNumber'])) {
          $createDate = date('Y-m-d', strtotime($turf['minivanCreateDate']));
          $columns['minivanNumber'] = $turf['minivanNumber'] . '<br>' . $createDate;
        }
      }
      if($displayType == 'walksheet') {
        $columns['email'] = $turf['email'];
      }
      // Key rows by turfIndex so a checkbox always refers to the same turf even
      // if the turf list changes between page load and submit.
      $rows[$turf['turfIndex']] = $columns;
      $row++;
    }
    return $rows;
  }

  /**
   * displayTurfs
   *
   * Create the form elements for a table of turfs to select one or more
   * turfs.  The table is constructed to allow the identification of the selected
   * turf when the form is submitted.
   *
   * @param array $rows - The array of turfs to display.
   * @param string $displayType
   * @return array
   */
  public function displayTurfs(array $rows, string $displayType = 'other'): array
  {
    $this->headerBuild($displayType);

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
            '#return_value' => 'CB_' . $columnValue['turfIndex'] . '_' . $columnValue['rowCount'],
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
  private function headerBuild(string $displayType = 'other'): void
  {
    $this->header = [];

    if($displayType != 'display') {
      $this->header[] = ['data' => t('cb'), 'class' => ['nlp-th-color']];
    }

    $this->header[] = ['data' => t('ti'), 'field' => 'turfIndex', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('HD'), 'field' => 'hd', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Pct'), 'field' => 'precinct', 'class' => ['nlp-th-color']];

    if($displayType != 'replacement') {
      $this->header[] = ['data' => t('Name<br>MCID'), 'field' => 'nlLastName', 'class' => ['nlp-th-color']];
    }
    $this->header[] = ['data' => t('Turf name<br>Map Region Name'), 'field' => 'turfName', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Folder name'), 'field' => 'folderName', 'class' => ['nlp-th-color']];

    if($displayType == 'display') {
      $this->header[] = ['data' => t('MiniVan#<br>Create Date'), 'class' => ['nlp-th-color']];
    }

    if($displayType == 'walksheet') {
      $this->header[] = ['data' => t('Email'), 'class' => ['nlp-th-color']];
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
   * @param array $contacts - The array from the form submission with the checkbox values.
   * @return array
   */
  public function validateTurfSelection(array $contacts): array
  {
    if(empty($contacts)) {return [];}
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_', $contact['cb']);
        $selections[$contactParts[1]] = ['rowCount' => $contactParts[2], 'turfIndex' => $contactParts[1]];
      }
    }
    return $selections;
  }

  /**
   * getSortField
   *
   * Get the column the user wanted to use to sort the table.
   *
   * @param string $data
   * @param string $displayType
   * @return string
   */
  public function getSortField(string $data, string $displayType = 'other'): string
  {
    $this->headerBuild($displayType);
    foreach ($this->header as $headerInfo) {
      if(empty($headerInfo['field'])) { continue; }
      if((string)$headerInfo['data'] == $data) {
        return $headerInfo['field'];
      }
    }
    return '';
  }

  /**
   * sortTurfTable
   *
   * Sort the turf table by the selected field and order.
   *
   * @param array $turfTable - The turf table to sort.
   * @param string $sort - The sort order, either 'asc' or 'desc'.
   * @param string $order - The field to sort by.
   */
  public function sortTurfTable(array &$turfTable, string $sort, string $order): void
  {
    if(empty($turfTable)) {
      return;
    }

    $sortColumn = $this->getSortField($order);
    if($sortColumn === '') {
      return;
    }

    $sortDirection = ($sort === 'desc') ? -1 : 1;
    uasort($turfTable, function ($left, $right) use ($sortColumn, $sortDirection) {
      $leftValue  = trim(strip_tags((string) ($left[$sortColumn]  ?? '')));
      $rightValue = trim(strip_tags((string) ($right[$sortColumn] ?? '')));
      return $sortDirection * strnatcasecmp($leftValue, $rightValue);
    });
  }

}
