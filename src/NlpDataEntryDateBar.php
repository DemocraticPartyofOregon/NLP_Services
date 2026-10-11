<?php

namespace Drupal\nlpservices;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpDataEntryDateBar
{
  protected NlpTurfs $turfsObj;
  protected NlpAwards $awardsObj;
  protected NlpDataEntryVoters $dataEntryVotersObj;
  protected NlpSessionData $sessionDataObj;
  protected NlpConfiguration $nlpConfigurationObj;
  public function __construct($turfsObj, $awardsObj,
                              $dataEntryVotersObj, $sessionDataObj, $nlpConfigurationObj,
                              private readonly ModuleExtensionList $moduleExtensionList) {
    $this->turfsObj = $turfsObj;
    $this->awardsObj = $awardsObj;
    $this->dataEntryVotersObj = $dataEntryVotersObj;
    $this->sessionDataObj = $sessionDataObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpDataEntryDateBar
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.data_entry_voters'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.configuration'),
      $container->get('extension.list.module'),
    );
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * createDateBar
   *
   * Creates two data bars at the top of the data entry page.   These two bars contain information
   * about the turf and an opportunity to enter additional information about the turf.
   *
   * @param string $canvassDate
   * @param int $turfIndex
   * @param int $mcid
   * @param int $currentPage
   * @param int $pageCount
   * @param array $voters - the turf's voters, if the caller already fetched them.
   * @return array
   */
  public function createDateBar(string $canvassDate, int $turfIndex, int $mcid,
    int $currentPage, int $pageCount, array $voters = []): array
  {
    // The first bar starts with the award icon to show the number of successfully completed elections.
    $form_element['award_bar'] = [
      '#markup' => '<div class="date-bar">',
    ];
    $form_element['award2_box'] = [
      '#markup' => '<div class="awards-box-left">',
    ];
    $form_element['award2'] = $this->awardDisplay($mcid);
    $form_element['award2_end'] = [
      '#markup' => '</div>',
    ];
    
    // Build a box with the voter counts, voting and contact attempts.  The NLs like to see these
    // percentages.
    $form_element['voter2_counts_box'] = [
      '#markup' => '<div class="counts-box-left">',
    ];
    $form_element['voter2_counts'] = $this->voterCounts($turfIndex, $voters);
    $form_element['voter2_counts_end'] = [
      '#markup' => '</div>',
    ];
    
    // Build a box with the turf name and MiniVAN number.  This box is right-justified
    $form_element['turf_name_box'] = [
      '#markup' => '<div class="turf-name-right">',
    ];
    $form_element['turf_name'] = $this->turfName($turfIndex);
    $form_element['turf_name_end'] = [
      '#markup' => '</div>',
    ];
    
    // The help icon is displayed only once per session.   However, the sessions do expire, and
    // the NL will see this icon again if they are inactive for 3 or more days.  The icon points
    // upward and suggests the NL read the information in the help tab.
    $helpSeen = $this->sessionDataObj->get('helpSeen');
    if(!$helpSeen) {
      $this->sessionDataObj->set('helpSeen',TRUE);
      $form_element['new_nl_box'] = [
        '#markup' => "\n"."<!-- New NL Box -->"."\n".'<div class="new-nl-box-right">',
      ];
      $form_element['new_nl'] = $this->newNl();
      $form_element['new_nl_end'] = [
        '#markup' => "\n".'</div>',
      ];
    }
    
    $form_element['award_bar_end'] = [
      '#markup' => '</div><div class="end-big-box"></div>',
    ];
    
    //  The second bar begins with the canvas date box.
    $form_element['date_bar'] = [
      '#markup' => '<div class="date-bar">',
    ];
    $form_element['date_box'] = [
      '#markup' => '<div class="date-box-left">',
    ];
    $defaultDate = $canvassDate;
    $form_element['canvass_date'] = $this->canvassDate($defaultDate);
    $form_element['date_box_end'] = [
      '#markup' => '</div>',
    ];
    
    // The search box is left-justified and contains a search box for the last name.
    $form_element['search_box'] = [
      '#markup' => '<div class="search-box-left">',
    ];
    $form_element['search'] = $this->searchRequest();
    $form_element['search_end'] = [
      '#markup' => '</div>',
    ];
    
    // The postcard box is left-justified and contains a button to report the postcards sent.
    // Clicking the button will reveal a much simpler page to report the postcards sent.
    $form_element['postcard_box'] = [
      '#markup' => '<div class="search-box-left">',
    ];
    $form_element['postcard'] = $this->postcardReport();
    $form_element['postcard_end'] = [
      '#markup' => '</div>',
    ];
    
    // The navigation box is right-justified and contains the navigation buttons.  The buttons
    // permit the recording of results and changes in the page to display next.
    $form_element['nav_box'] = [
      '#markup' => '<div class="nav-box-right">',
    ];
    $form_element['nav'] = $this->navigate($pageCount,$currentPage);
    $form_element['nav_end'] = [
      '#markup' => '</div>',
    ];
    $form_element['date_bar_end'] = [
      '#markup' => '</div><div class="end-big-box"></div>',
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * awardDisplay
   *
   * Build the award display for the NL participation.
   *
   * @param int $mcid
   * @return array
   */
  private function awardDisplay(int $mcid): array
  {
    // Get the participation information about this NL.
    $awards = $this->awardsObj->getAward($mcid);
    $electionCount = (empty($awards['electionCount']))?0:$awards['electionCount'];
    $countPosition = ($electionCount > 9)?'double-digit':'single-digit';
    // Get the icon.
    $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);
    $badge = "/".$modulePath."/img/nlp_award_seal_2.jpg";
    
    $form_element['award_start'] =  [
      '#markup'=>" \n ".'<div class="award-box">'
    ];
    
    $nlAward = '
      <div class="badge-container">
        <img src="'.$badge.'" alt="Snow" class="badge-img">';
    $nlAward .= '
        <div class="election-count '.$countPosition.'">'.$electionCount.'</div>';
    $nlAward .= '
      </div><div class="note-container">Number of elections you have been a successful Neighborhood Leader.</div>';
    $form_element['award'] =  [
      '#markup'=>" \n ".$nlAward
    ];
    $form_element['award_end'] =  [
      '#markup'=>" \n ".'<div class="end-big-box"></div></div>'
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * voterCounts
   *
   * Creates a box with the voter count, the voted count, the attempted count, and the contacted count.
   *
   * @param int $turfIndex
   * @param array $voters - the turf's voters; fetched here if empty.
   * @return array
   */
  private function voterCounts(int $turfIndex, array $voters = []): array
  {
    $turfCounts = $this->dataEntryVotersObj->turfCounts($turfIndex, $voters);
    $voterCount = $turfCounts['voterCount'];
    $votedCount = $turfCounts['votedCount'];
    $attemptedCount = $turfCounts['attemptedCount'];
    $contactedCount = $turfCounts['contactedCount'];
    
    if(empty($voterCount)) {
      $percentage = $attemptedPercentage = $contactedPercentage = '';
      $voterCount = 1;
    } else {
      $percentage = '('.round($votedCount/$voterCount*100,1).'%)';
      $attemptedPercentage = '('.round($attemptedCount/$voterCount*100,1).'%)';
      $contactedPercentage = '('.round($contactedCount/$voterCount*100,1).'%)';
    }
    
    $form_element['counts'] = [
      '#markup' => "  \n ".'<div class="no-white voter-counts">',
    ];
    
    $form_element['table-start'] = [
      '#markup' => '<table class="counts-table" ><tbody>',
    ];
    $form_element['row-voters'] = [
      '#markup' => '<tr class="counts-row"><td  class="counts-name" >Voters</td>
<td class="counts-numbers" >'.$voterCount.'</td><td class="counts-percent"></td>',
    ];
    $form_element['row-voted'] = [
      '#markup' => '<tr class="counts-row"><td class="counts-name">Voted</td>
<td class="counts-numbers">'.$votedCount.'</td><td class="counts-percent">'.$percentage.'</td>',
    ];
    $form_element['row-attempted'] = [
      '#markup' => '<tr class="counts-row"><td class="counts-name">Attempted</td>
<td class="counts-numbers">'.$attemptedCount.'</td><td class="counts-percent">'.$attemptedPercentage.'</td>',
    ];
    $form_element['row-contacted'] = [
      '#markup' => '<tr class="counts-row"><td class="counts-name">Contacted</td>
<td class="counts-numbers">'.$contactedCount.'</td><td class="counts-percent">'.$contactedPercentage.'</td>',
    ];
    $form_element['table-end'] = [
      '#markup' => '</tbody></table>',
    ];
    $form_element['counts-end'] = [
      '#markup' => " \n   ".'</div>',
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * canvassDate
   *
   * Builds a box to enter the canvass date.  It starts with the current date, and the NL
   * can change it if needed.
   *
   * @param string $defaultDate
   * @return array
   */
  private function canvassDate(string $defaultDate=''): array
  {
    $form_element['date-cell'] = [
      '#markup'=>" \n ".'<!-- date cell --><div class="canvass_date">',
    ];
    $form_element['canvassDate'] = [
      '#type' => 'date',
      '#title' => t('Date of the voter contact'),
    ];
    if(!empty($defaultDate)) {
      $form_element['canvassDate']['#default_value'] = $defaultDate;
    }
    $form_element['date-cell-end'] = [
      '#markup'=>" \n ".'</div>'
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * searchRequest
   *
   * The search box contains a text field to enter the last name of the voter.  It also contains
   * a Drupal submit button to start the search.  The result will be to change to the page that contains
   * the first voter to match the text.
   *
   * @return array
   */
  private function searchRequest(): array
  {
    $form_element['search-cell'] = ['#markup'=>" \n ".'
 <!-- search --><section class="search-box">',];
    $form_element['search-name'] = ['#markup' => '
<table class="no-white search-table"><tbody class="no-white">
<tr class="no-white white-back"><td class="no-white" colspan="2">
<div class="no-white">Search by last name.</div>
</td></tr>'];
    $form_element['search-header'] = ['#markup' => '
<tr class="no-white white-back"><td class="no-white">'];
    $form_element['last-name'] = [
      '#type' => 'textfield',
      //'#description' => t('Last name.'),
      '#size' => 20,
      '#maxlength' => 40,
    ];
    $form_element['search-submit'] = ['#markup' => '</td><td class="no-white">'];
    $form_element['last_name_submit'] = [
      '#type' => 'submit',
      '#name' => 'last_name_search',
      '#value' => t('Search'),
    ];
    $form_element['search-name-end'] = ['#markup' => '</td></tr></tbody></table>'];
    $form_element['search-cell-end'] = ['#markup'=>" \n ".'</section>'];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * postcardReport
   *
   * Builds a list of checkboxes to report the postcards sent.
   *
   * @return array
   */
  private function postcardReport(): array
  {
    $form_element['postcard-box'] = [
      '#markup'=>" \n ".'<!-- postcard --><section class="search-box">
 <div class="no-white">Reveal page for reporting postcards</div>',];
    
    
    $form_element['postcardSubmit'] = [
      '#type' => 'submit',
      '#name' => 'postcardSubmit',
      '#value' => t('postcards sent'),
      '#prefix' => '<div class="no-white">',
      '#suffix' => '</div>'
    ];
    
    $form_element['search-cell-end'] = [
      '#markup'=>" \n ".'</section>'
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * navigate
   *
   * Build the buttons for navigation through the data entry pages.  Most turfs should be created
   * for 35 doors or about 50 voters.   The upper limit is 100 voters.   So, the navigation display favors
   * lists of 70 or fewer voters.  The buttons are displayed in a way that the NL can see the first 6 pages.
   * If there are more than 6 pages, the NL will see a window of 6 pages.
   *
   * @param  int $pageCount - number of pages for this turf.
   * @param  int $currentPage - current page bing viewed.
   * @return array of form elements.
   */
  public function navigate(int $pageCount, int $currentPage): array
  {
    $currentPage++;
    if ($pageCount < 7) {
      $page = 1;
      $elementCount = $pageCount;
      $less = $more = FALSE;
    } else {
      $windowStart = max(1, $currentPage - 2);
      $windowEnd = $windowStart + 5;
      if ($windowEnd > $pageCount) {
        $windowEnd = $pageCount;
        $windowStart = max(1, $windowEnd - 5);
      }
      $page = $windowStart;
      $elementCount = $windowEnd - $windowStart + 1;
      $less = $windowStart > 1;
      $more = $windowEnd < $pageCount;
    }
    
    $form_element['navigation_box'] = [
      '#markup' => "  \n ".'<section class="nav_box">',
    ];
    $form_element['nav'] = [
      '#type' => 'fieldset',
      '#prefix' => " \n".'<div class="nav_div">'." \n",
      '#suffix' => " \n".'</div>'." \n",
      '#attributes' => [
        'style' => ['background-image: none; border:0; padding:0; margin:0; '],],
    ];
    if($less) {
      $form_element['nav']['previous'] = [
        '#type' => 'submit',
        '#value' => t('< Previous'),
        '#name' => 'previous',
        '#prefix' => " \n".'<div class="nav_number" >'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
      $form_element['nav']['dots'] = [
        '#markup' => ' ... ',
        '#prefix' => " \n".'<div class="nav_dots" >'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
    }
    for ($element=1; $element<=$elementCount; $element++) {
      if ($page == $currentPage) {
        $hoverMessage = t('You are on page @page.', ['@page' => $page]);
        $pageClass = 'nav_current_number';
      } else {
        $hoverMessage = t('Go to page @page.', ['@page' => $page]);
        $pageClass = 'nav_number';
      }
      $form_element['nav']['pageSelect-'.$page] = [
        '#type' => 'submit',
        '#value' => $page,
        '#name' => 'pageSelect-'.$page,
        '#prefix' => " \n".'<div class="'.$pageClass.'" title="'.$hoverMessage.'">'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
      $page++;
    }
    if($more) {
      $form_element['nav']['dots'] = [
        '#markup' => ' ... ',
        '#prefix' => " \n".'<div class="nav_dots" >'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
      $form_element['nav']['next'] = [
        '#type' => 'submit',
        '#value' => t('Next >'),
        '#name' => 'next',
        '#prefix' => " \n".'<div class="nav_number" >'." \n",
        '#suffix' => " \n".'</div>'." \n",
      ];
    }
    $form_element['nav']['save_reports'] = [
      '#type' => 'submit',
      '#value' => t('Save reports'),
      '#name' => 'save_reports',
      '#prefix' => " \n".'<div class="nav_number" title="And stay on this page.">'." \n",
      '#suffix' => " \n".'</div>'." \n",
    ];
    $form_element['navigation_end'] = [
      '#markup' => " \n   ".'</section>',
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * newNl
   *
   * Build the icon to suggest a new NL look at the help tab.  The icon is an arrow and is
   * animated to draw attention to the help tab.
   *
   * @return array - a form element with a GIF to suggest a new NL look at the help tab.
   */
  public function newNl(): array
  {
    $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);
    $upArrow = $modulePath."/img/nlp_up_arrow_yellow_40.gif";
    $form_element['new_nl_info'] = [
      '#markup' =>
        " \n".'<section class="new-nl-box">'." \n".
        " \n".'<div class="new_nl_div">'." \n".
        " \n".'<div class="arrow-img"><img alt="" src="'.$upArrow.'" width="26" height="40" ></div>'." \n".
        " \n".'<div class="arrow-text">'."Check out the help tab above.".'</div>'.
        " \n".'</div>'." \n".
        " \n".'</section>'." \n",
    ];
    return $form_element;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * turfName
   *
   * Creates the form elements a box with the turf name and the MiniVAN number.
   * The turf name is derived from the turf index.
   *
   * @param int $turfIndex
   * @return array
   */
  private function turfName(int $turfIndex): array
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    if (!empty($turf)) {
      $turfNameStamp = $turf['turfName'];
      $turfNameParts = explode('_', $turfNameStamp);
      $turfName = Html::escape($turfNameParts[0]);
    } else {
      $turfName = t('Unknown turf');
    }

    $form_element['turf_name_box'] = [
      '#markup' => "  \n ".'<section class="turf-name-box no-white">',
    ];

    $minivanInfo = $this->turfsObj->getMinivanInfo($turfIndex);
    if (!empty($minivanInfo['minivanNumber'])) {
      $minivanNumber = Html::escape($minivanInfo['minivanNumber']);
      $minivanCreateDate = Html::escape($minivanInfo['minivanCreateDate']);
      $minivanDisplay = "MiniVAN No: $minivanNumber, Create date: $minivanCreateDate";
    } else {
      $minivanDisplay = t('The MiniVAN number was not created or has expired.');
    }
    
    $turfDisplay = "<b>You're working with turf named:</b><br>".$turfName."<br>".$minivanDisplay;
    
    $form_element['turfName'] =  [
      '#markup'=>" \n ".'<div class="turf-name">'.$turfDisplay.'</div>',
      ];
    
    $form_element['turf_name_end'] = [
      '#markup' => " \n   ".'</section>',
    ];
    return $form_element;
  }
  
}
