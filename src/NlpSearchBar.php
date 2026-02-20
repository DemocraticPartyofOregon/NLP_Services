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
  public function searchBar(array $hdOptions=NULL, int $hdIndex=NULL, string $callback='selectbox',
                            string $multi=NULL): array
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
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * nlSearch
   *
   * @param $multi
   * @return array
   */
  private function nlSearch($multi): array
  {
    $form['nl-search-box'] = [
      '#markup' => " \n".'<div class="search-needle-left">'." \n",
    ];
    
    $form['nl-search-needle'.$multi] = [
      '#type' => 'textfield',
      '#title' => "Enter a first name, last name, ID, or other value.",
      //'#title' => 'Search value',
      '#size' => 32,
      '#maxlength' => 64,
      //'#description' => "Enter a first name, last name, ID, or other value.",
      '#prefix' => '<div class="search-needle-text-left">',
      '#suffix' => '</div>',
    ];
    
    $form['nl-search-submit'.$multi] = [
      '#type' => 'submit',
      '#name' => 'search_submit'.$multi,
      '#value' => 'Search the table. ',
      '#prefix' => '<div class="search-button-left">',
      '#suffix' => '</div>',
    ];
    
    $form['nl-search-box-end'] = [
      '#markup' => " \n".'<div class="end-float"></div></div>'." \n",
    ];
    
    $helpText = '<span class="hint-list">'
      . 'You can search the table for name or other information.  The value can be an incomplete fragment
      of the search fields and is case-insensitive.</span>';
    
    $help = '<span class="hint-list">'.$helpText.'</span>';
    
    $form['nl_search']['help'] = [
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
