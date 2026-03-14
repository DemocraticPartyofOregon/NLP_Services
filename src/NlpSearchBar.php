<?php

namespace Drupal\nlpservices;

class NlpSearchBar
{
  
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * searchBar
   *
   * @param array|null $hdOptions
   * @param int|null $hdIndex
   * @param string $callback
   * @param string|null $multi
   * @return array
   */
  public function searchBar(array $hdOptions=[], int $hdIndex=0, string $callback='selectbox',
                            string $multi=''): array
  {
    $multi = (empty($multi))?'':'-'.$multi;
    
    $divClass = (!empty($hdOptions))?'<div class="search-bar">':'<div class="search-bar-small">';
    
    $form_element['bar-box']['start'] = [
      '#markup' => $divClass.'<section class="search-bar">',
    ];
    
    if(!empty($hdOptions)) {
      $form_element['bar-box']['hd-select-box'] = [
        '#markup' => '<div class="search-hd-select-left" >',
      ];
      $callbackDiv = ($callback=='nlsForm')?'nlsFormDiv':$callback.'-div';
      $form_element['bar-box']['hd-select'.$multi] = [
        '#type' => 'select',
        '#title' => 'HD select.',
        '#options' => $hdOptions,
        '#default_value' => $hdIndex,
        '#attributes' => ['class' => ['no-white'], ],
        '#ajax' => [
          'callback' => '::'.$callback.'Callback',
          'wrapper' => $callbackDiv,
          //'wrapper' => 'selectbox-div',
          //'disable-refocus' => FALSE,
        ],
      ];
      
      $form_element['bar-box']['hd-select-end'] = [
        '#markup' => '</div>',
      ];
    }
    
    $form_element['bar-box']['search-nl-'.$multi] = $this->nlSearch($multi);
    
    $form_element['bar-box']['hd-select-box-end'] = [
      '#markup' => '</section>',
    ];
    
    $form_element['bar-box']['bar-float-end'] = [
      '#markup' => '<div class="end-float"></div></div>',
    ];
    
    return $form_element;
  }


  public function searchAndSortBar(array $hdOptions, int $hdIndex=0): array
  {

    $divClass = (!empty($hdOptions))?'<div class="search-bar">':'<div class="search-bar-small">';

    $formElement['bar-box']['start'] = [
        '#markup' => $divClass.'<section class="search-bar">',
    ];

    if(!empty($hdOptions)) {
      $formElement['bar-box']['hd-select-box'] = [
          '#markup' => '<div class="search-hd-left" >',
      ];
      $formElement['bar-box']['hd-select'] = [
          '#type' => 'select',
          '#title' => 'HD select.',
          '#options' => $hdOptions,
          '#default_value' => $hdIndex,
          '#prefix' => '<div class="search-hd-select-left" >',
          '#suffix' => '</div>',
      ];
      $formElement['bar-box']['hd-submit'] = [
          '#type' => 'submit',
          '#name' => 'hd_submit',
          '#value' => 'Change the HD. ',
          '#prefix' => '<div class="hd-button-left">',
          '#suffix' => '</div>',
      ];

      $formElement['bar-box']['hd-select-end'] = [
          '#markup' => '</div>',
      ];
    }

    $formElement['bar-box']['search-nl-'] = $this->nlSearch('');

    $formElement['bar-box']['hd-select-box-end'] = [
        '#markup' => '</section>',
    ];

    $formElement['bar-box']['bar-float-end'] = [
        '#markup' => '<div class="end-float"></div></div>',
    ];

    return $formElement;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlSearch
   *
   * @param string $multi
   * @return array
   */
  private function nlSearch($multi): array
  {
    $form['search-box'] = [
      '#markup' => " \n".'<div class="search-needle-left">'." \n",
    ];
    
    $form['search-needle'.$multi] = [
      '#type' => 'textfield',
      '#title' => "Enter a first name, last name, ID, or other value.",
      //'#title' => 'Search value',
      '#size' => 32,
      '#maxlength' => 64,
      //'#description' => "Enter a first name, last name, ID, or other value.",
      '#prefix' => '<div class="search-needle-text-left">',
      '#suffix' => '</div>',
    ];
    
    $form['search-submit'.$multi] = [
      '#type' => 'submit',
      '#name' => 'search_submit'.$multi,
      '#value' => 'Search the table. ',
      '#prefix' => '<div class="search-button-left">',
      '#suffix' => '</div>',
    ];
    
    $form['search-box-end'] = [
      '#markup' => " \n".'<div class="end-float"></div></div>'." \n",
    ];
    
    $helpText = '<span class="hint-list">'
      . 'You can search the table for name or other information.  The value can be an incomplete fragment
      of the search fields and is case-insensitive.</span>';
    
    $help = '<span class="hint-list">'.$helpText.'</span>';
    
    $form['search']['help'] = [
      '#markup' => '<div class="hint_note hint-link-text">'
        . '<p>Help' . $help . '</p></div>',
      '#prefix' => '<div class="search-needle-help-right">',
      '#suffix' => '</div>',
    ];
    return $form;
  }
  
  public function searchInfoArray($needle,$infoArray): array
  {
    $matchFields = ['email','userName','mcid','phone','lastName','firstName',
      'nlLastName','nlFirstName','turfName','precinct', ];
    $matches = [];
    foreach ($infoArray as $index=>$info) {
      $match = FALSE;
      foreach ($matchFields as $matchField) {
        if(!empty($info[$matchField])) {
          $field = $info[$matchField];
          $pos = strripos($field, $needle);
          if($pos !== FALSE) {
            $match = TRUE;
            break;
          }
        }
      }
      if($match) {
        $matches[$index] = $info;
      }
    }
    return $matches;
  }
  
}
