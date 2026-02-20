<?php

namespace Drupal\nlpservices;

class NlpCoordinatorsListDisplay
{
  private array $header = [];
  
  /**
   * @param $type
   * @param $nlRecords
   * @return array
   */
  public function buildCoordinatorTableArray($type, $nlRecords): array
  {
    $rows = [];
    foreach ($nlRecords as $mcid=>$nlRecord)
    {
      $column = [];
      //if($type!='current') {
        $column['cb'] = ['type'=>'cd', 'mcid'=>$mcid];
      //}
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
      $rolesDisplay = (!empty($nlRecord['roles']))?implode('<br>',$nlRecord['roles']):'';
      $column['roles'] = $rolesDisplay;
      
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
   * @param $type
   * @param  $rows
   * @return array - $form - form elements for the table.
   */
  function buildCoordinatorsTable($type, $rows): array
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
   * @param string $type
   * @return void
   */
  private function headerBuild(string $type):void
  {
    $this->header = [];
    //if($type!='current') {
      $this->header[] = ['data' => t('cb'), 'field' => 't.cb', 'class' => ['nlp-th-color'] ];
    //}
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
   * @param $contacts
   * @return array
   */
  public function validateCoordinatorSelection($contacts):array
  {
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
   * @param $data
   * @param $type
   * @return string
   */
  public function getSortField($data,$type):string
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
   * @param $nlsTable
   * @param $sort
   * @param $order
   * @param $type
   * @return void
   */
  public function sortNlsTable(&$nlsTable,$sort,$order,$type):void
  {
    $sortField = $this->getSortField($order,$type);
    //nlp_debug_msg('$sortField',$sortField);
    if(empty($sortField)) {return;}
    $sortFieldParts = explode('.',$sortField);
    if($sortFieldParts[0]!='n') {return;}
    $sortColumn = $sortFieldParts[1];
    $sortOrder = ($sort=='asc')?SORT_ASC:SORT_DESC;
    $column = array_column($nlsTable, $sortColumn);
    //nlp_debug_msg('$column',$column);
    array_multisort($column, $sortOrder, $nlsTable);
  }
  
}