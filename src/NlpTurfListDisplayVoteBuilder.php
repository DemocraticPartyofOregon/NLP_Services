<?php

namespace Drupal\nlpservices;

class NlpTurfListDisplayVoteBuilder
{
  private array $header = [];
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * displayTurfs
   *
   * @param array $turfs - Typically this is an array of turfs from a map region.   Atypically,
   * this is an array of saved lists in a folder. Use of saved lists should be rare.
   * @param bool $showLists - True if the display is for saved lists in a folder.
   * @return array - Drupal form elements to display a table for turf selection.
   */
  function displayVoteBuilderTurfs(array $turfs, bool $showLists): array
  {
    if(empty($turfs)) {
      $emptyDisplay = (!$showLists)?
        "There are no turfs in the map region.":
        "There are no saved lists in this folder.";
      $form_element['turf-empty'] = [
        '#markup' => "<p><b><br>$emptyDisplay</b></p>",
      ];
      return $form_element;
    }
    
    $turfIds = [];
    $rows = $row = [];
    foreach ($turfs as $turfId => $turf) {
      $row['cb'] = ['type'=>'cb','turfId'=>$turfId];
      $row['turfId'] = $turf['turfId'];
      $row['turfAssigned'] = $turf['turfAssigned'];
      $row['nl'] = $turf['nl'];
      $row['mcid'] = $turf['mcid'];
      $row['turfName'] = $turf['turfName'];
      $row['mapRegionName'] = (!empty($turf['mapRegionName']))?$turf['mapRegionName']:' ';
      $row['doorCount'] = $turf['doorCount'];
      $row['voterCount'] = $turf['voterCount'];
      $row['minivanNumber'] = (!empty($turf['minivanNumber']))?$turf['minivanNumber']:'';
      $row['minivanCreateDate'] = (!empty($turf['minivanCreateDate']))?
        date('Y-m-d',strtotime($turf['minivanCreateDate'])):'';
      
      $rows[] = $row;
      $turfIds[] = ['type'=>'cb', 'turfId'=>$turfId, 'mcid'=>$turf['mcid'] ];
    }
    
    $this->headerBuild();
    $form_element['contacts'] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];
    
    foreach ($rows as $rowNumber => $row) {
      foreach($row as $columnName => $columnValue) {
        if(is_array($columnValue) AND $columnValue['type']=='cb') {
          $form_element['contacts'][$rowNumber][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_'.$turfIds[$rowNumber]['turfId'].'_'.$turfIds[$rowNumber]['mcid'],
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
  private function headerBuild():void
  {
    $idTitle = 'Turf ID';
    $nameTitle = 'Turf Name';
    $this->header = [
      ['data' => t('cb'), 'class' => ['nlp-th-color'] ],
      ['data' => t($idTitle), 'field' => 'id', 'class' => ['nlp-th-color'] ],
      ['data' => t('A'), 'field' => 'hd', 'class' => ['nlp-th-color'] ],
      ['data' => t('Name'), 'field' => 'nlLastName', 'class' => ['nlp-th-color'] ],
      ['data' => t('MCID'), 'field' => 'mcid', 'class' => ['nlp-th-color'] ],
      ['data' => t($nameTitle), 'field' => 'turfName', 'class' => ['nlp-th-color'] ],
    ];
    $this->header[] = ['data' => t('Map Region Name'), 'field' => 'mapRegionName', 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Doors'), 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Voters'), 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Minivan Number'), 'class' => ['nlp-th-color']];
    $this->header[] = ['data' => t('Created'), 'class' => ['nlp-th-color']];
  }
  
  /**
   * validateTurfSelection
   *
   * Validate the turf selection from the form.
   *
   * @param $contacts
   * @return array
   */
  public function validateTurfSelection($contacts):array
  {
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_',$contact['cb']);
        $selections[$contactParts[1]] = ['turfId'=>$contactParts[1], 'mcid'=>$contactParts[2]];
      }
    }
    return $selections;
  }
  
}
