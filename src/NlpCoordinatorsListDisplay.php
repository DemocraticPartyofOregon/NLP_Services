<?php

namespace Drupal\nlpservices;

class NlpCoordinatorsListDisplay
{
  private array $header = [];
  
  /**
   * buildCoordinatorTableArray
   *
   * This function builds an array of information about the NLs found in $form_state.
   * The array is used to build the HTML table.
   *
   * @param string $type
   * @param array $nlRecords
   * @return array
   */
  public function buildCoordinatorTableArray( string $type, array $nlRecords ): array
  {
    $rows = [];
    foreach ($nlRecords as $mcid=>$nlRecord)
    {
      $column = [];
      $column['cb'] = ['type'=>'cd', 'mcid'=>$mcid];
      if($type=='current') {
        $column['cb']['cIndex'] = $nlRecord['cIndex'];
      }
      $name = $nlRecord['firstName'].' '.$nlRecord['lastName'];
      $column['name'] = $name;
      $column['email'] = $nlRecord['email'];
      $column['phone'] = $nlRecord['phone'];
      $column['hd'] = $nlRecord['hd'];
      if($type=='current') {
        $column['assignedScope'] = $nlRecord['scope'];
        $column['assignedHd'] = $nlRecord['hd'];
        $column['assignedPrecinctList'] = $nlRecord['precinctList'];
      }
      if($type=='add') {
        $column['precinct'] = $nlRecord['precinct'];
      }
      $column['roles'] = (!empty($nlRecord['roles'])) ? implode(', ', $nlRecord['roles']) : '';
      
      $index = ($type!='current')?$mcid:$nlRecord['cIndex'];
      $rows[$index] = $column;
    }
    return $rows;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * buildCoordinatorsTable
   *
   * This function builds an HTML table using the array of information about
   * the NLs found in $form_state.
   *
   * @param string $type
   * @param array $rows
   * @return array - $form - form elements for the table.
   */
  public function buildCoordinatorsTable( string $type, array $rows ): array
  {
    $this->headerBuild($type);
    $tableName = 'contacts';
    if($type=='current') {
      $tableName = 'display';
    }
    
    $form_element[$tableName] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];
    foreach ($rows as $index => $row) {
      foreach($row as $columnName => $columnValue) {
        if($columnName == 'cb') {
          $rv = ($type=='current')?$columnValue['cIndex']:'checkbox';
          $form_element[$tableName][$index][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_'.$rv.'_'.$columnValue['mcid'],
          ];
        } else {
          $form_element[$tableName][$index][$columnName] = [
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
   * Builds the header for the coordinator table.
   * The header is built based on the type of the coordinator table.
   *
   * @param string $type
   * @return void
   */
  private function headerBuild( string $type ):void
  {
    $this->header = [];
    $this->header[] = ['data' => t('cb'), 'field' => 't.cb', 'class' => ['nlp-th-color'] ];
    $this->header[] =   ['data' => t('Name'), 'field' => 'n.name', 'class' => ['nlp-th-color'] ];
    $this->header[] =   ['data' => t('Email'), 'field' => 'n.email', 'class' => ['nlp-th-color'] ];
    $this->header[] =   ['data' => t('Phone'), 'field' => 'n.phone', 'class' => ['nlp-th-color'] ];
    $this->header[] =   ['data' => t('HD'), 'field' => 'n.hd', 'class' => ['nlp-th-color'] ];
    if($type=='current') {
      $this->header[] = ['data' => t('Assigned<br>Scope'), 'field' => 'n.assignedScope', 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Assigned<br>HD'), 'field' => 'n.assignedHd', 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Assigned<br>Precinct List'), 'field' => 'n.assignedPrecinctList', 'class' => ['nlp-th-color'] ];
    }
    if($type=='add') {
      $this->header[] = ['data' => t('Precinct'), 'field' => 'n.precinct', 'class' => ['nlp-th-color'] ];
    }
    $this->header[] = ['data' => t('Roles'), 'field' => 'n.roles', 'class' => ['nlp-th-color'] ];
  }
  
  /**
   * validateCoordinatorSelection
   *
   * This function validates the coordinator selection made by the user.
   * It checks the 'cb' value of each contact and builds an array of selected coordinators.
   * The 'cb' value is expected to be in the format 'CB_cIndex_mcid'.
   *
   * @param mixed $contacts
   * @return array
   */
  public function validateCoordinatorSelection( mixed $contacts ):array
  {
    if(!is_array($contacts)) {
      return [];
    }
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_',$contact['cb']);
        $selections[$contactParts[2]] = ['cIndex'=>$contactParts[1], 'mcid'=>$contactParts[2]];
      }
    }
    return $selections;
  }
  
  
  /**
   * getSortField
   *
   * This function returns the field name for the given sort order.
   * The field name is used to sort the coordinator table.
   *
   * @param string $data
   * @param string $type
   * @return string
   */
  public function getSortField( string $data, string $type ):string
  {
    $this->headerBuild($type);
    foreach ($this->header as $headerInfo) {
      if($headerInfo['data'] == $data) {
        return $headerInfo['field'];
      }
    }
    return '';
  }
  
  /**
   * sortNlsTable
   *
   * This function sorts the coordinator table based on the given sort order.
   * The sort order is used to sort the coordinator table.
   * The sort order is based on the field name returned by getSortField().
   *
   * @param array $nlsTable
   * @param string $sort
   * @param string $order
   * @param string $type
   * @return void
   */
  public function sortNlsTable( array &$nlsTable, string $sort, string $order, string $type ):void
  {
    $sortField = $this->getSortField($order,$type);
    if(empty($sortField)) {return;}
    $sortFieldParts = explode('.',$sortField);
    if($sortFieldParts[0]!='n') {return;}
    $sortColumn = $sortFieldParts[1];
    $sortOrder = ($sort=='asc')?SORT_ASC:SORT_DESC;
    $column = array_column($nlsTable, $sortColumn);
    array_multisort($column, $sortOrder, $nlsTable);
  }
  
}