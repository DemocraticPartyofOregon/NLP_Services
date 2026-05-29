<?php

namespace Drupal\nlpservices;

class NlpVoterListDisplay
{
  private array $header = [];
  
  /**
   * buildVoterTableArray
   *
   * This method converts an array of voters into an array of rows for a table.  The voter
   * array is the working structure for NLP, and the resulting array is organized for display
   * in a table form element.
   *
   * @param array $voters
   * @return array
   */
  public function buildVoterTableArray(array $voters): array
  {
    $rows = [];
    foreach ($voters as $voter) {
      $column = [];
      $column['cb'] = ['type'=>'cd', 'vanid'=>$voter['vanid']];
      $column['vanid'] = $voter['vanid'];
      $column['lastName'] = $voter['lastName'];
      $column['firstName'] = $voter['firstName'];
      $column['age'] = $voter['age'];
      $column['sex'] = $voter['sex'];
      $column['party'] = $voter['party'];
      $mark = ($voter['moved'])?"<b>&#134;&#134;</b>":'';
      $column['address'] = $voter['address'].$mark;
      $column['city'] = $voter['city'].$mark;
      $rows[$voter['vanid']] = $column;
    }
    return $rows;
  }
  
  /**
   * displayListOfVoters
   *
   * This method will convert an array of voters into a table form element.
   * The first element of each row is a checkbox for selecting the voters.
   *
   * @param array $rows
   * @return array
   */
  public function displayListOfVoters(array $rows): array
  {
    $this->headerBuild();
    $tableName = 'contacts';
    
    $form_element[$tableName] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];
    
    foreach ($rows as $mcid => $row) {
      foreach($row as $columnName => $columnValue) {
        if($columnName == 'cb') {
          $form_element[$tableName][$mcid][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_checkbox_'.$columnValue['vanid'],
          ];
        } else {
          $form_element[$tableName][$mcid][$columnName] = [
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
   * Create the header information for a table of voters for selection of one or more of them.
   * The result is stored in $this->header.
   *
   * @return void
   */
  private function headerBuild():void
  {
    $this->header = [];
    $this->header[] = ['data' => t('cb'), 'field' => 't.cb', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('VANID'), 'field' => 'v.vanid', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Last Name'), 'field' => 'v.lastName', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('First Name'), 'field' => 'v.firstName', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Age'), 'field' => 'v.age', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Sex'), 'field' => 'v.sex', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Party'), 'field' => 'v.party', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('Address'), 'field' => 'v.address', 'class' => ['nlp-th-color'] ];
    $this->header[] = ['data' => t('City'), 'field' => 'v.city', 'class' => ['nlp-th-color'] ];
  }
  
  /**
   * validateVoterSelection
   *
   * This is a method to determine which voters were selected.
   *
   * @param array $contacts
   * @return array
   */
  public function validateVoterSelection(array $contacts): array
  {
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_',$contact['cb']);
        $selections[$contactParts[2]] = ['rowCount'=>$contactParts[1], 'vanid'=>$contactParts[2]];
      }
    }
    return $selections;
  }
  
}
