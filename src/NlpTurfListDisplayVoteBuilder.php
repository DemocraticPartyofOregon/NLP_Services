<?php

namespace Drupal\nlpservices;

class NlpTurfListDisplayVoteBuilder
{
  private array $header = [];

  /**
   * displayVoteBuilderTurfs
   *
   * Build a table of turfs visible in VoteBuilder.
   *
   * @param array $turfs - Typically this is an array of turfs from a map region.   Atypically,
   * this is an array of saved lists in a folder. Use of saved lists should be rare.
   * @param bool $showLists - True if the display is for saved lists in a folder.
   * @return array - Drupal form elements to display a table for turf selection.
   */
  public function displayVoteBuilderTurfs(array $turfs, bool $showLists): array
  {
    $form_element = [];
    if(empty($turfs)) {
      $emptyDisplay = (!$showLists)?
        "There are no turfs in the map region.":
        "There are no saved lists in this folder.";
      $form_element['turf-empty'] = [
        '#markup' => "<p><b><br>$emptyDisplay</b></p>",
      ];
      return $form_element;
    }

    $rows = [];
    foreach ($turfs as $turfId => $turf) {
      $row = [];
      $row['cb'] = ['type' => 'cb', 'turfId' => $turfId, 'mcid' => $turf['mcid']];
      $row['turfAssigned'] = $turf['turfAssigned'];
      $row['nlName'] = $turf['nl'];
      $row['turfName'] = $turf['turfName'];
      $row['mapRegionName'] = (!empty($turf['mapRegionName'])) ? $turf['mapRegionName'] : ' ';
      $row['doorCount'] = $turf['doorCount'];
      $row['voterCount'] = $turf['voterCount'];
      $row['minivanNumber'] = (!empty($turf['minivanNumber'])) ? $turf['minivanNumber'] : '';
      $row['minivanCreateDate'] = (!empty($turf['minivanCreateDate'])) ?
        date('Y-m-d', strtotime($turf['minivanCreateDate'])) : '';
      $row['turfId'] = $turf['turfId'];
      $row['mcid'] = $turf['mcid'];
      $rows[] = $row;
    }

    $this->headerBuild();
    $form_element['contacts'] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];

    foreach ($rows as $rowNumber => $row) {
      foreach($row as $columnName => $columnValue) {
        if(is_array($columnValue) && $columnValue['type'] == 'cb') {
          $form_element['contacts'][$rowNumber][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_' . $columnValue['turfId'] . '_' . $columnValue['mcid'],
          ];
        } else {
          $form_element['contacts'][$rowNumber][$columnName] = [
            '#markup' => $columnValue,
          ];
        }
      }
    }
    $form_element['footnote'] = [
      '#markup' => '<p><b>Note:</b><br>
<span class="nlp-footnote-warning">*</span> This NL was previously assigned to this turf.<br>
<span class="nlp-footnote-warning">**</span> This NL was previously assigned to this turf but is not an active NL
and cannot be assigned to this turf.<br>
 </p>',
    ];
    return $form_element;
  }

  /**
   * headerBuild
   *
   * Build the header for the turf list display.
   * The result is stored in $this->header.
   */
  private function headerBuild(): void
  {
    $this->header = [
      ['data' => t('cb'), 'class' => ['nlp-th-color']],
      ['data' => t('A'), 'field' => 'turfAssigned', 'class' => ['nlp-th-color']],
      ['data' => t('Name'), 'field' => 'nl', 'class' => ['nlp-th-color']],
      ['data' => t('Turf Name'), 'field' => 'turfName', 'class' => ['nlp-th-color']],
    ];
    $this->header[] = ['data' => t('Map Region Name'), 'field' => 'mapRegionName', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Doors'), 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Voters'), 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Minivan Number'), 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Created'), 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Turf ID'), 'field' => 'turfId', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('MCID'), 'field' => 'mcid', 'class' => ['nlp-th-color']];
  }

  /**
   * validateTurfSelection
   *
   * Validate the turf selection from the form.
   *
   * @param array $contacts
   * @return array
   */
  public function validateTurfSelection(array $contacts): array
  {
    if(empty($contacts)) { return []; }
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_', $contact['cb']);
        $turfId = (int) $contactParts[1];

        $mcidRaw = $contactParts[2] ?? '';
        $lessThanPos = strpos($mcidRaw, '<');
        $mcidPart = ($lessThanPos === FALSE) ? $mcidRaw : substr($mcidRaw, 0, $lessThanPos);
        $mcid = (int) trim($mcidPart);

        $selections[$contactParts[1]] = ['turfId' => $turfId, 'mcid' => $mcid];
      }
    }
    return $selections;
  }

  /**
   * Given the information provided by Drupal, determine which column is to be sorted.
   *
   * @param string $data
   * @return string
   */
  public function getSortField( string $data ):string
  {
    $this->headerBuild();
    foreach ($this->header as $headerInfo) {
      if((string) $headerInfo['data'] == $data) {
        return $headerInfo['field'] ?? '';
      }
    }
    return '';
  }

  public function sortTurfs(array &$turfs, string $sort, string $order): void
  {
    $sortColumn = $this->getSortField($order);
    if (empty($sortColumn)) { return; }
    $direction = ($sort === 'asc') ? 1 : -1;
    uasort($turfs, function($a, $b) use ($sortColumn, $direction) {
      $aVal = strtolower((string) ($a[$sortColumn] ?? ''));
      $bVal = strtolower((string) ($b[$sortColumn] ?? ''));
      return $direction * strcmp($aVal, $bVal);
    });
  }

}
