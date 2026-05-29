<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpSyncTurfBuilder
{
  protected NlpTurfListDisplayVoteBuilder $turfListDisplayVoteBuilderObj;
  protected NlpNlsListDisplay $nlsListDisplayObj;
  protected NlpSessionData $nlpSessionDataObj;

  const string walksheetBaseName = 'turf_walksheet';
  public const array selectType = [
    'pa' => '_previousAssignment',
    'ns' => '_nameSearch',
    'ds' => '_districtSelect',
    're' => '_replacement',
  ];

  public function __construct( $turfListDisplayVoteBuilderObj,
                               $nlsListDisplayObj, $nlpSessionDataObj ) {
    $this->turfListDisplayVoteBuilderObj = $turfListDisplayVoteBuilderObj;
    $this->nlsListDisplayObj = $nlsListDisplayObj;
    $this->nlpSessionDataObj = $nlpSessionDataObj;
  }

  public static function create(ContainerInterface $container): NlpSyncTurfBuilder
  {
    return new static(
      $container->get('nlpservices.turf_list_display_votebuilder'),
      $container->get('nlpservices.nls_list_display'),
      $container->get('nlpservices.session_data'),
    );
  }

  /**
   * Builds the Drupal form elements to search for an NL by district and precinct.
   *
   * @param array $nlsTable - the list of NLs to display in the table.
   * @return array - Drupal form elements to display a table for NL selection.
   */
  public function buildNlSearchByDistrict(array $nlsTable): array
  {
    $form['nl_select_district_title'] = [
      '#markup' => '<div class="big-ts-select-box" ><hr><h2>Search for an NL by HD or precinct</h2></div>',
    ];
    $form['nl_select_district'] = [
      '#type' => 'fieldset',
      '#prefix' => " \n".'<div class="big-ts-select-box" >'." \n",
      '#suffix' => " \n".'</div>'." \n",
    ];

    $hdList = $this->nlpSessionDataObj->getHdOptions();
    array_unshift($hdList, 'Select HD');
    $selectedHdIndex = 0;
    if($this->nlpSessionDataObj->get('hdSelected')) {
      $selectedHdIndex = $this->nlpSessionDataObj->getSavedHdIndex()+1; // +1 because the first element is 'Select HD'.
    }
    // If an HD is not yet selected, the list of precincts will be all in the county.  If the HD is selected,
    //   the precinct list will be only in that HD.
    $pctList = $this->nlpSessionDataObj->getPrecinctList();
    array_unshift($pctList, 'Select Precinct');
    $selectedPrecinctIndex = 0;
    if($this->nlpSessionDataObj->get('precinctSelected')) {
      $selectedPrecinctIndex = $this->nlpSessionDataObj->getSavedPrecinctIndex();
      $selectedPrecinctIndex++;
    }
    $form['nl_select_district']['hd_help'] = [
      '#markup' => '<div id="turf-district-help">',
    ];

    $form['nl_select_district']['nl_select_hd'] = [
      '#type' => 'select',
      '#title' => t('House district'),
      '#options' => $hdList,
      '#default_value' => $selectedHdIndex,
      '#ajax' => [
        'callback' => '::selectboxCallback',
        'wrapper' => 'nlsFormDiv',
      ],
      '#prefix' => '<div class="district_search_left">',
      '#suffix' => '</div>',
    ];

    $help = '<span class="hint-list">'
      . 'You can find an NL to assign the selected turf by displaying a list of active NLs in a
house district or a precinct.  Specifying the precinct will likely yield the best result.
' . '</span>';

    $form['nl_select_district']['help'] = [
      '#markup' => '<div class="hint_note walksheet-help-title">'
        . '<p><br>Help' . $help . '</p></div>',
      '#prefix' => '<div class="needle-help-right">',
      '#suffix' => '</div>',
    ];

    $form['nl_select_district']['hd_help_end'] = [
      '#markup' => '</div><div class="end-big-box"></div>',
    ];

    $form['nl_select_district']['nl_select_precinct'] = [
      '#type' => 'select',
      '#title' => t('Precinct name'),
      '#options' => $pctList,
      '#default_value' => $selectedPrecinctIndex,
      '#ajax' => [
        'callback' => '::selectboxCallback',
        'wrapper' => 'nlsFormDiv',
      ],
    ];

    if(!empty($selectedPrecinctIndex) || !empty($selectedHdIndex)) {
      $form['nl_select_district']['nl_selection'] = $this->buildNlSelect($nlsTable);
    }

    return $form;
  }

  /**
   * Builds the Drupal form elements to search for an NL by name.
   *
   * @param string $needle - the search value for the NL name.
   * @param array $nlsTable - the list of NLs to display in the table.
   * @return array - Drupal form elements to display a table for NL selection.
   */
  public function buildNlSearchByName(string $needle, array $nlsTable): array
  {
    $form['nl_search_title'] = [
      '#markup' => '<div class="big-ts-select-box" id="nlsFormDiv"><hr>
      <h2>Search for an NL by Name</h2></div>',
    ];

    $form['nl_name_search'] = [
      '#type' => 'fieldset',
      '#prefix' => " \n".'<div class="big-ts-select-box" id="turf-sync-search-wrapper">'." \n",
      '#suffix' => " \n".'</div>'." \n",
    ];

    $form['nl_name_search']['hd_help'] = [
      '#markup' => '<div>',
    ];

    $form['nl_name_search']['needle_box'] = [
      '#markup' => '<div class="big-ts-select-box">',
    ];

    $form['nl_name_search']['needle'] = [
      '#type' => 'textfield',
      '#title' => t('Search value'),
      '#size' => 32,
      '#maxlength' => 64,
      '#description' => t("Enter a first name, last name, email, or MCID to find the Active NL."),
      '#prefix' => '<div class="search_needle_left">',
      '#suffix' => '</div>',
    ];

    $help = '<span class="hint-list">'
      . 'You can search for the NL by name or other information.  Since you know the name of the NL,
this search may be the easiest way to find the record for the NL.  The search value can be a first name, last name,
email, or MCID to find the Active NL.  The value can be an incomplete fragment of the search fields and is
case-insensitive.
' . '</span>';

    $form['nl_name_search']['help'] = [
      '#markup' => '<div class="hint_note walksheet-help-title">'
        . '<p><br>Help' . $help . '</p></div>',
      '#prefix' => '<div class="needle-help-right">',
      '#suffix' => '</div>',
    ];

    $form['nl_name_search']['needle_box_end'] = [
      '#markup' => '</div><div class="end-float"> </div>',
    ];

    $form['nl_name_search']['hd_help_end'] = [
      '#markup' => '</div><div class="end-big-box"> </div>',
    ];

    $form['nl_name_search']['search_submit'] = [
      '#type' => 'submit',
      '#name' => 'search_submit',
      '#value' => 'Search for the NL.',
      '#prefix' => '<div>',
    ];

    $form['nl_name_search']['search_goback'] = [
      '#type' => 'submit',
      '#name' => 'search_goback',
      '#value' => 'Go back.',
      '#suffix' => '</div>',
    ];

    if(!empty($needle)) {
      $form['nl_name_search']['nl_select_header'] = [
        '#markup' => '<hr class="dashed-line"><b>Check the box for the NL to be assigned to the turf.</b>',
      ];

      $form['nl_name_search']['select_nl_from_list'] =
        $this->nlsListDisplayObj->buildNlsTable($nlsTable,TRUE,'search');

      $form['nl_name_search']['walksheet'] = $this->walksheet('ns');

      $form['nl_name_search']['search_select'] = [
        '#type' => 'submit',
        '#name' => 'search_select',
        '#value' => 'Assign the turf to the selected NL.',
      ];
    }

    return $form;
  }

  /**
   * Builds the Drupal form elements to display a table of NLs to select for assignment to a turf.
   *
   * @param array $nlList - the list of NLs to display in the table.
   * @return array - Drupal form elements to display a table for NL selection.
   */
  public function buildNlSelect(array $nlList): array
  {
    $form['nl_select_header'] = [
      '#markup' => '<b>Check the box for the NL to be assigned to the turf.</b>',
    ];
    $form['select_nl_from_list'] = $this->nlsListDisplayObj->buildNlsTable($nlList,TRUE,'nlsForm');

    $form['walksheet'] = $this->walksheet('ds');

    $form['add_selected'] = [
      '#type' => 'submit',
      '#name' => 'add_selected',
      '#value' => 'Use the selected NL',
      '#prefix' => '<div>',
    ];

    $form['search_goback'] = [
      '#type' => 'submit',
      '#name' => 'search_goback',
      '#value' => 'Go back.',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * Builds a walksheet file upload form element for the turf select table.  There
   * can be up to three walksheet file uploads displayed in the turf select table.  The displays
   * are associated with the select type:
   *   'pa' - previous NL selected.
   *   'ns' - name search for an NL.
   *   'ds' - district selected for an NL.
   *
   * @param string $type - the type of walksheet element to create.
   * @param bool $note - set to true if the help popup is desired.
   * @return array - of Drupal form elements.
   */
  public function walksheet(string $type, bool $note=TRUE): array
  {
    $formName = $this::walksheetBaseName.$this::selectType[$type];
    $form['walk-sheet-box'] = [
      '#markup' => '<div class="walksheet-box" id="file-box">',
    ];

    $form['file_title'] = [
      '#markup' => 'Walksheet',
      '#prefix' => '<div class="walksheet-title-box-left" id="file-title-box-left">',
      '#suffix' => '</div>',
    ];
    $form[$formName] = [
      '#type' => 'file',
      '#size' => 75,
      '#prefix' => '<div class="walksheet-box-left" id="file-box-left">',
      '#suffix' => '</div>',
    ];

    if($note) {
      $walksheetHelp = '<span class="hint-list">'
        . 'The turf file must be a PDF, XLSX or XLS file format. <i>While the walksheet is optional, it is a good idea
to include it here with the turf.   This is especially true if the NL has more than one turf.  The walksheet is not
needed for NLs that send postcards or use a phone or social media. </i>' . '</span>';

      $form['walk-sheet-help'] = [
        '#markup' => '<div class="hint_note walksheet-help-title" id="hint_note">'
          . '<p class="hint-no-white"><br>Help' . $walksheetHelp . '</p></div>',
        '#prefix' => '<div class="walksheet-note-box-left" id="file-note-box-left">',
        '#suffix' => '</div>',
      ];
    }

    $form['walksheet-box-end'] = [
      '#markup' => '<div class="walksheet-end-box"></div></div>',
    ];

    return $form;
  }

  /**
   * Builds the Drupal form element for the turf select table legend and footnotes.
   *
   * @return array
   */
  public function legendBar(): array
  {
    $form_element['turf_id_bar'] = [
      '#markup' => "\n".'<div class="turf-id-bar" id="turf-id-bar">',
    ];

    $form_element['legend_box'] = [
      '#markup' => '<div class="footnote-box-right" id="legend-box-right">',
    ];
    $form_element['legends'] = $this->legends();
    $form_element['legend_end'] = [
      '#markup' => '</div>',
    ];
    $form_element['footnote_box'] = [
      '#markup' => '<div class="footnote-box-right" id="footnote-box-right">',
    ];
    $form_element['footnotes'] = $this->footnotes();
    $form_element['footnote_end'] = [
      '#markup' => '</div>',
    ];

    $form_element['turf_id_bar_end'] = [
      '#markup' => "\n".'</div><div class="end-big-box" id="turf-id-bar-end"></div>',
    ];

    return $form_element;
  }

  /**
   * Builds the footnote hover over for the turf select table.
   *
   * @return array - of Drupal form elements
   */
  public function footnotes(): array
  {
    $footnote = '<span class="hint-list">'
      . '<b><u>Table Footnotes</u></b><br>
<b>*</b> - The turf was associated with an NL in the past.<br>
<b>**</b> - The turf is associated with an NL who is not an active NL.<br>
' . '</span>';

    $form['footnote'] = [
      '#markup' => '<div class="hint_note walksheet-help-title" id="footnote-hint">'
        . '<p><br>Table Footnotes' . $footnote . '</p></div>',
      '#prefix' => '<div class="footnote-box-left" id="footnote-box-left">',
      '#suffix' => '</div>',
    ];
    return $form;
  }

  /**
   * Builds the Drupal form element for the table legend hover over.
   *
   * @return array - of Drupal form elements.
   */
  public function legends(): array
  {
    $footnote = '<span class="hint-list">'
      . '<b><u>Table Legend</u></b><br>
<b>Turf ID - </b>The id for the turf/list from VoteBuilder.<br>
<b>A</b> - Marked with an X if the turf is already assigned to an NL.<br>
<b>NL\'s Name</b> - The name of the associated NL.<br>
<b>MyCampaign ID</b> - The VANID for the NL in MyCampaign.<br>
<b>Turf Name</b> - The name of the turf from VoteBuilder.<br>
<b>Map Region Name</b> - The most likely name of the Map Region for turfs in this list.<br>
<b>Description</b> - The description field from VoteBuilder.<br>
<b>#</b> - The number of voters in the turf.<br><br>
' . '</span>';

    $form['footnote'] = [
      '#markup' => '<div class="hint_note walksheet-help-title" id="legend-hint">'
        . '<p><br>Table Legend' . $footnote . '</p></div>',
      '#prefix' => '<div class="footnote-box-left" id="legend-box-left">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * Build the Drupal forms elements to display a table of turfs or saved lists to
   * select for assignment to an NL.
   *
   * @param bool $showLists - true if the turf select is for saved lists, false if it is for turfs.
   * @param array $folderOptions
   * @param mixed $currentFolderId
   * @param array $mapRegionOptions
   * @param mixed $mapRegionId
   * @param array $turfOptions
   * @return array
   */
  public function buildTurfSelect(bool $showLists, array $folderOptions, mixed $currentFolderId,
                                  array $mapRegionOptions, mixed $mapRegionId, array $turfOptions): array
  {
    $turfTitle = "Choose a folder and map region then choose a turf from the list of map region turfs.";
    $listTitle = "Choose a folder and then choose a saved list to be used as a turf.";
    $selectTitle = (!$showLists)?$turfTitle:$listTitle;
    $form['turf_select_title'] = [
      '#markup' => '<div class="small-title" id="small-title">'.$selectTitle.'</div>',
    ];

    $form['turf_select'] = [
      '#type' => 'fieldset',
      '#prefix' => '<div id="folder_change_wrapper">',
      '#suffix' => '</div>',
    ];
    // Folder choice.
    $form['turf_select']['folder'] = [
      '#type' => 'select',
      '#title' => t('Choose the folder.'),
      '#options' => $folderOptions,
      '#default_value' => $currentFolderId,
      '#ajax' => [
        'callback' => '::folderSelectedCallback',
        'wrapper' => 'folder_change_wrapper',
      ],
    ];
    // Map region choice in the current folder.
    if(!empty($mapRegionOptions)) {
      $form['turf_select']['map_region_select'] = [
        '#type' => 'select',
        '#title' => t('Choose the Map Region.'),
        '#options' => $mapRegionOptions,
        '#default_value' => $mapRegionId,
        '#ajax' => [
          'callback' => '::folderSelectedCallback',
          'wrapper' => 'folder_change_wrapper',
        ],
      ];
    }

    $emptyDisplay = '';
    if($showLists) {
      if(empty($turfOptions)) {
        $emptyDisplay = "There are no saved lists in this folder.";
      }
    } elseif (empty($turfOptions)) {
      if(empty($mapRegionOptions)) {
        $emptyDisplay = "There are no map regions in the folder.";
      } else {
        $emptyDisplay = "There are no turfs in the map region.";
      }
    }
    if($emptyDisplay) {
      $form['turf_select']['turf-empty'] = [
        '#markup' => "<p><b><br>$emptyDisplay</b></p>",
      ];
    } else {
      // Display a list of turfs in VoteBuilder for the user to select one.
      $form['turf_select']['table'] =
        $this->turfListDisplayVoteBuilderObj->displayVoteBuilderTurfs($turfOptions,$showLists);
    }
    // Submit the selected turf or list for assignment to an NL.
    $form['turf_select']['turf_submit'] = [
      '#name' => 'turf_submit',
      '#type' => 'submit',
      '#value' => 'Use the selected turf.',
    ];

    if(!$showLists) {
      $form['turf_select']['saved_lists_reveal'] = [
        '#name' => 'change_to_lists',
        '#type' => 'submit',
        '#value' => 'Show lists.',
      ];
    } else {
      $form['turf_select']['map_region_return'] = [
        '#name' => 'change_to_turfs',
        '#type' => 'submit',
        '#value' => 'Show Map Regions.',
      ];
    }
    // Display the legend and footnote popups.
    $form['turf_select']['legend'] = $this->legendBar();
    return $form;
  }

}
