<?php

namespace Drupal\nlpservices;

class NlpSearchBar
{

  /**
   * @param array $hdOptions
   * @param int $hdIndex
   * @return array
   */
  public function searchBar(array $hdOptions=[], int $hdIndex=0): array
  {
    $divClass = (!empty($hdOptions)) ? '<div class="search-bar">' : '<div class="search-bar-small">';

    $formElement['bar-box']['start'] = [
      '#markup' => $divClass.'<section class="search-bar">',
    ];

    if(!empty($hdOptions)) {
      $formElement['#attached'] = ['library' => ['nlpservices/hd-filter']];
      $formElement['bar-box']['hd-select-box'] = [
        '#markup' => '<div class="search-hd-select-left" >',
      ];
      $formElement['bar-box']['hd-select'] = [
        '#type' => 'select',
        '#title' => 'HD select.',
        '#options' => $hdOptions,
        '#default_value' => $hdIndex,
        '#attributes' => ['class' => ['no-white', 'nlp-hd-select']],
      ];

      $formElement['bar-box']['hd-select-end'] = [
        '#markup' => '</div>',
      ];
    }

    $formElement['bar-box']['search-nl'] = $this->nlSearch();

    $formElement['bar-box']['hd-select-box-end'] = [
      '#markup' => '</section>',
    ];

    $formElement['bar-box']['bar-float-end'] = [
      '#markup' => '<div class="end-float"></div></div>',
    ];

    return $formElement;
  }

  private function nlSearch(): array
  {
    $form['search-box'] = [
      '#markup' => " \n".'<div class="search-needle-left">'." \n",
    ];

    $form['search-needle'] = [
      '#type' => 'textfield',
      '#title' => "Enter a first name, last name, ID, or other value.",
      '#size' => 32,
      '#maxlength' => 64,
      '#prefix' => '<div class="search-needle-text-left">',
      '#suffix' => '</div>',
    ];

    $form['search-submit'] = [
      '#type' => 'submit',
      '#name' => 'search_submit',
      '#value' => 'Search the table.',
      '#prefix' => '<div class="search-button-left">',
      '#suffix' => '</div>',
    ];

    $form['search-box-end'] = [
      '#markup' => " \n".'<div class="end-float"></div></div>'." \n",
    ];

    $help = '<span class="hint-list">'
      . 'You can search the table for name or other information.  The value can be an incomplete fragment
      of the search fields and is case-insensitive.</span>';

    $form['search']['help'] = [
      '#markup' => '<div class="hint_note hint-link-text">'
        . '<p>Help' . $help . '</p></div>',
      '#prefix' => '<div class="search-needle-help-right">',
      '#suffix' => '</div>',
    ];
    return $form;
  }

  /**
   * @param string $needle
   * @param array $infoArray
   * @return array
   */
  public function searchInfoArray(string $needle, array $infoArray): array
  {
    $matchFields = ['email','userName','mcid','phone','lastName','firstName',
      'nlLastName','nlFirstName','turfName','precinct'];
    $matches = [];
    foreach ($infoArray as $index => $info) {
      $match = FALSE;
      foreach ($matchFields as $matchField) {
        if(!empty($info[$matchField])) {
          if(stripos($info[$matchField], $needle) !== FALSE) {
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
