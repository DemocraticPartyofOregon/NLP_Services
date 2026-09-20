<?php

namespace Drupal\nlpservices;

use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleExtensionList;
use DateTime;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class NlpDataEntryHelper
{
  protected NlpMatchbacks $matchbacksObj;
  protected NlpConfiguration $nlpConfigurationObj;

  public function __construct( $matchbacksObj, $nlpConfigurationObj, private readonly ModuleExtensionList $moduleExtensionList) {
    $this->matchbacksObj = $matchbacksObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
  }

  public static function create(ContainerInterface $container): NlpDataEntryHelper
  {
    return new static(
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.configuration'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * dataEntryHelp
   *
   * Implements the text for the help tab for the data entry page.
   *
   * @return string
   */
  public function dataEntryHelp(): string
  {
    $version = Html::escape($this->moduleExtensionList->getExtensionInfo('nlpservices')['version'] ?? 0);
    $output = "<h1>NLP Services Version $version</h1>";

    $electionDate = Html::escape($this->nlpConfigurationObj->getElectionDate(TRUE));
    $earlyVotingStartDate = Html::escape($this->nlpConfigurationObj->getBallotDropDate(TRUE));

    $output .= "<h1>Election dates</h1>";
    $output .= "<p>Election date: $electionDate<br>
      Start date of early voting: $earlyVotingStartDate</p>";

    $latestBallotReceivedDate = $this->matchbacksObj->getLatestMatchbackDate();
    if (!empty($latestBallotReceivedDate)) {
      try {
        $date = new DateTime($latestBallotReceivedDate);
        $timestamp = $date->getTimestamp();
        $ballotDate = Html::escape(date('l jS \of F Y', $timestamp));
      } catch (Exception $e) {
        nlp_debug_msg('dataEntryHelp', $e->getMessage());
        $ballotDate = 'None received yet';
      }
      $output .= "<p>The latest date reported for a ballot received: $ballotDate.</p>";
    }

    $output .= '<h1>Helpful hints</h1>';
    $output .= '<h3><u>Date of voter contact</u></h3>You should start your data entry by selecting the date of the voter
contacts you are reporting.  If you do not enter a date, the report will use today\'s date.';
    $output .= '<h3><u>Method of voter contact</u></h3>The voter database for the Democratic party requires that each report
of a voter contact specify both a method and a result.   To report results, you should start by entering the method.
This can be done by selecting the method for each voter or by specifying the default method for all voters.
The default is set using the tab above.   Also, there is a checkbox to report that you send a postcard to the voter.
This checkbox is the same result as selecting the Method/result pair.  The checkbox can\'t be cleared once set.';
    $output .= '<h3><u>Selecting a result</u></h3>The purpose of the NL program is to get our Democratic base to vote.  The
most important result is to record that you asked the voter to commit to vote and to record that commitment.  Of
secondary importance is to make at least one attempt to contact the voter and record that attempt.  Your report of
a voter contact should be done as soon as possible after the contact.  Our candidates and the coordinated campaign
are relying on you to contact our base voters, and they need to see that the contact was attempted and/or successful. ';
    $output .= '<h5>Voter Contacted</h5>The survey question is typically a "pledge to vote" question,
but it can be something else from time to time.   If you were able to contact the voter and get an answer to the
question, select the response from the drop-down menu.  For some elections or voter contact projects, you will see a
secondary survey question.   The reply from the voter is recorded with a drop-down selection.';
    $output .= '<h5>Voter Not Contacted</h5>If you tried but failed to contact a voter, select the response from the
drop-down menu.  This menu changes based on the voter contact method you selected. ';
    $output .= '<h3><u>Important Updates</u></h3>When a voter contact is not possible, these options will change the
voter database for future turfs. ';
    $output .= '<h5>Moved</h5>Check this box if you discover that the voter is no longer living at the address
on your list.  The address in the VoterFile will be marked as incorrect if it matches the one on your list.   Otherwise,
only your list is marked.   When a voter changes registration information, the VoterFile may already be changed.  Please be
sure the voter has moved.   You cannot correct your report.  If you did make an error, you have to contact your
coordinator and get help from the state VAN support.';
    $output .= "<h5>Hostile</h5>When you encounter a voter you suspect is not voting for Democrats, check this
box to remove the voter from future NLP turfs.  We don\'t want to encourage voters who do not vote for Democrats.';
    $output .= '<h5>Deceased</h5>If you discover that a voter has died, you can check this box.   But be careful
as an incorrect setting can only be fixed by an authorized person at the state party.   Note that the state is pretty
good at removing deceased voters from the registered voters lists. ";
    $output .= '<h3><u>Update contact info</u></h3>Occasionally, you will get information from a voter for future
new contact information.  Use this drop-down menu and text box to enter something new. ';
    $output .= '<h5>Bad No.</h5>Use this option to report that a phone number listed for this voter is wrong.  When
you make this choice, the party and NLP databases are updated and the phone number will no longer be displayed.   ';
    $output .= '<h5>Opt out of texting.</h5>If you used the cell phone number displayed for this voter, and they
do not want to receive text messages, you can record this here.  However, you must contact your coordinator
to have them change the status of text messaging in VoteBuilder.  The process is manual for now.   ';
    $output .= '<h5>New cell number</h5>If you get a new cell phone number, select this option and enter the number
in the text box below.  This new number is recorded in the NLP database but not in the party database.  There is no
current method to allow an update of VoteBuilder.  If the voter gave permission, ask your coordinator to enter the
number into VoteBuilder for the party and campaigns to use.  Be sure the voter agrees to opt in to such phone
calls.   ';
    $output .= '<h5>New home number</h5>See the cell phone instructions above.   ';
    $output .= '<h5>New email</h5>If you get an email address for this voter, be sure the voter agrees to get
emails from the state and county parties.  The VoteBuilder database is not updated.  Ask your coordinator to
enter the new email into MyCampaign and subscribe to both the county and state email lists. ';
    $output .= '<h3><u>Navigation</u></h3>You use the navigation buttons to save your voter contact reports and
move from page to page.  Most turfs will have approximately 50 voters, and the data entry page has these voters in
approximately the same order as found on the walk sheets.  The data entry page displays 10 voters at a time, so you
have to move from page to page with the navigation buttons.   Clicking any button will save any reports you made
on the current page.';
    $output .= '<h5>Page buttons</h5>The navigation box displays buttons with a number.  You can navigate directly
to a page by clicking the appropriate number button.  You may know the page you want, but it may be easier to
search for the voter by the last name.';
    $output .= '<h5>Next and Previous buttons</h5>If there are more than 60 voters on the turf, you will see the
Next and Previous buttons.  Use these to reveal the first or last block of pages.';
    $output .= '<h5>Last name search</h5>The search looks for the first match of the text you provide in the last
name field.  The search is case-insensitive and can be a partial name.  The response will be the page containing
the voter that matches the search.  If you make any voter contact reports, they will be processed first.';

    $output .= '<h3><u>Voter Contact Status</u></h3>The last line of the data entry box for a voter is a reminder
of the status of the contact for the current election.  This message has three states: not yet contacted, attempted,
and responded.  The purpose is to encourage an attempt to contact each voter once.  And if you get an answer to the
pledge-to-vote question, to record that answer.   The display does not go backwards, so if you get an answer to
the P2V question, further contacts do not revert to the attempt messages.  A full history of voter contacts is
available by hovering the mouse icon over the "View contact history".  ';

    $output .= '<h1>Turf Refresh</h1>';
    $output .= 'You may occasionally see a button to refresh your turf.  This function only appears when the state\'s
    database of registered voters has been updated.   It\'s possible the some of your voters have changed their
    registration and any new information will be available to you if you click the refresh button.  And, voters that
    have moved will be removed from your list.';

    return $output;
  }

}
