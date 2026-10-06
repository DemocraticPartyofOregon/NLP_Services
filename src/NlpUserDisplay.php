<?php

namespace Drupal\nlpservices;

class NlpUserDisplay
{
  private array $header = [];
  
  /**
   * displayUsers
   *
   * Create the table elements for a list of login credentials for users of the site.
   * The table is slightly different for the list to reminder a user of their login
   * credentials.  This variation is just to keep the width of the table manageable.
   *
   * @param array $users - Array of user arrays.
   * @param bool $loginReminder - TRUE if the table is for a login reminder.
   * @return array
   */
  public function displayUsers(array $users, bool $loginReminder=FALSE): array
  {
    $form_element = [];
    if(empty($users)) {
      $form_element['table-form']['no-users'] = ['#markup' => '<p>No users to display.</p>',];
      return $form_element;
    }
    
    // Create an array of information about the users for construction of the table element.
    $rows = [];
    $row = 1;
    foreach ($users as $user) {
      $column = [];
      $column['cb'] = ['type'=>'cb','uid'=>$user['uid'], 'rowCount'=>$row];
      // The login reminder table leaves out UID and County to keep its width manageable.
      if(!$loginReminder) {
        $column['uid'] = $user['uid'];
      }
      $column['hd'] = $user['hd'];
      $column['mcid'] = $user['mcid'];
      $column['lastName'] = $user['lastName'];
      $column['firstName'] = $user['firstName'];
      $column['userName'] = $user['userName'];
      if(!$loginReminder) {
        $createDate = date('Y/n/j',$user['created']);
        $accessDate = (!empty($user['access']))?date('Y/n/j',$user['access']):'Never';
        $column['created'] = $createDate;
        $column['access'] = $accessDate;
      } else {
        $column['phone'] = $user['phone'];
        $column['email'] = $user['email'];
      }
      if(!$loginReminder) {
        $column['county'] = $user['county'];
      }
      $roleDisplay = '';
      $roles = $user['roles'];
      foreach ($roles as $roleId => $role) {
        if($roleId != 'authenticated' ) {
          if(!empty($roleDisplay)) {
            $roleDisplay .= ", ";
          }
          $roleDisplay .= $role;
        }
      }
      $column['roles'] = $roleDisplay;
      $rows[$row++] = $column;
    }
    
    // Build the table element.
    $this->headerBuild($loginReminder);
    $form_element['contacts'] = [
      '#type' => 'table',
      '#header' => $this->header,
    ];
    
    foreach ($rows as $rowNumber => $row) {
      foreach($row as $columnName => $columnValue) {
        if(is_array($columnValue) && $columnName == 'cb') {
          $form_element['contacts'][$rowNumber][$columnName] = [
            '#type' => 'checkbox',
            '#return_value' => 'CB_'.$columnValue['rowCount'].'_'.$columnValue['uid'],
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
   * The header is stored in $this->header.
   *
   * @param bool $loginReminder - TRUE if the table is for a login reminder.
   */
  private function headerBuild(bool $loginReminder=FALSE):void
  {
    $this->header = [];
    if(!$loginReminder) {
      $this->header[] = ['data' => t('cb'), 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('UID'), 'field' => 'uid', 'class' => ['nlp-th-color'] ];
    } else {
      $this->header[] = ['data' => '', 'class' => ['nlp-th-color'] ];
    }
    $this->header = array_merge($this->header, [
      ['data' => t('HD'), 'field' => 'hd', 'class' => ['nlp-th-color'] ],
      ['data' => t('MCID'), 'field' => 'mcid', 'class' => ['nlp-th-color'] ],
      ['data' => t('Last&nbsp;Name'), 'field' => 'lastName', 'class' => ['nlp-th-color'] ],
      ['data' => t('First&nbsp;Name'), 'field' => 'firstName', 'class' => ['nlp-th-color'] ],
      ['data' => t('Username'), 'field' => 'userName', 'class' => ['nlp-th-color'] ],
    ]);
    if(!$loginReminder) {
      $this->header[] = ['data' => t('Create'), 'field' => 'created', 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Access'), 'field' => 'access', 'class' => ['nlp-th-color'] ];
    } else {
      $this->header[] = ['data' => t('Phone'), 'field' => 'phone', 'class' => ['nlp-th-color'] ];
      $this->header[] = ['data' => t('Email'), 'field' => 'email', 'class' => ['nlp-th-color'] ];
    }
    if(!$loginReminder) {
      $this->header[] = ['data' => t('County'), 'class' => ['nlp-th-color'] ];
    }
    $this->header[] = ['data' => t('Roles'), 'class' => ['nlp-th-color'] ];
  }
  
  /**
   * validateUserSelection
   *
   * Return an array of the row with the checkbox selected to identify the users.
   * The return array has two keys: rowCount and uid.
   *   The rowCount is the row number of the selected checkbox.
   *   The uid is the uid of the user.
   *
   * @param array $contacts
   * @return array
   */
  public function validateUserSelection(array $contacts): array
  {
    $selections = [];
    foreach($contacts as $contact) {
      if(!empty($contact['cb'])) {
        $contactParts = explode('_',$contact['cb']);
        $selections[$contactParts[1]] = ['rowCount'=>$contactParts[1], 'uid'=>$contactParts[2]];
      }
    }
    return $selections;
  }
  
  /**
   * Given the information provided by Drupal, determine which column is to be sorted.
   *
   * @param string $data
   * @param bool $loginReminder
   * @return string
   */
  public function getSortField( string $data, bool $loginReminder=FALSE):string
  {
    $this->headerBuild($loginReminder);
    foreach ($this->header as $headerInfo) {
      if(empty($headerInfo['field'])) { continue; }
      if((string) $headerInfo['data'] == $data) {
        return $headerInfo['field'];
      }
    }
    return '';
  }
  
  /**
   * sortUsers
   *
   * Sort the user's array by the specified column and order.
   *
   * @param array $users - the array of users being displayed in the table.
   * @param string $sort - 'asc' or 'desc'
   * @param string $order - The column to sort.
   * @param bool $loginReminder - TRUE if the table is for a login reminder.
   * @return void
   */
  public function sortUsers(array &$users, string $sort, string $order, bool $loginReminder=FALSE): void
  {
    $sortColumn = $this->getSortField($order, $loginReminder);
    if (empty($sortColumn)) { return; }
    $direction = ($sort === 'asc') ? 1 : -1;
    uasort($users, function($a, $b) use ($sortColumn, $direction) {
      $aVal = strtolower((string) ($a[$sortColumn] ?? ''));
      $bVal = strtolower((string) ($b[$sortColumn] ?? ''));
      return $direction * strcmp($aVal, $bVal);
    });
  }
  
}
