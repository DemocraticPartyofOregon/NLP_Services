<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoterCrosstabSummary;
use Drupal\nlpservices\NlpTargetVoterCrosstabCounts;

/**
 * @noinspection PhpUnused
 * used in route.
 */
class TargetVoterReportController extends ControllerBase
{
  protected NlpVoterCrosstabSummary $voterCrosstabSummaryObj;
  protected NlpTargetVoterCrosstabCounts $targetVoterCrosstabCountsObj;
  protected NlpTurfs $turfsObj;

  public function __construct( $voterCrosstabSummaryObj, $targetVoterCrosstabCountsObj, $turfsObj ) {
    $this->voterCrosstabSummaryObj = $voterCrosstabSummaryObj;
    $this->targetVoterCrosstabCountsObj = $targetVoterCrosstabCountsObj;
    $this->turfsObj = $turfsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): TargetVoterReportController
  {
    return new static(
      $container->get('nlpservices.voter_crosstab_summary'),
      $container->get('nlpservices.target_voter_crosstab_counts'),
      $container->get('nlpservices.turfs'),
    );
  }

  /**
   * reports
   *
   * Displays the report template with the links to the various reports.
   * This is the main entry point for the reports.
   *
   * @return array - a render array containing the reports template.
   */
  public function targetVoterSummary(): array
  {
    $targetVoterSummary = $this->voterCrosstabSummaryObj->getCountySummary( NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL );
    $allVoterSummary = $this->voterCrosstabSummaryObj->getCountySummary( NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL );
    $committeesWithTurfs = $this->voterCrosstabSummaryObj->getCommitteesWithTurfs();

    $tables = [];
    foreach ($committeesWithTurfs as $committee) {
      $output = [];
      // The crosstab summaries are keyed by geographic county.  Looking
      // them up by committee name is only right where each committee is one
      // whole county, as in Oregon.
      if(!empty($targetVoterSummary['counties'][$committee])) {
        $output['table-start'] = [
          '#markup' => '<div class="summary-report">',
        ];
        $committeeTargetCounts = $targetVoterSummary['counties'][$committee];
        $committeeAllCounts = $allVoterSummary['counties'][$committee];
        $turfVoterStats = $this->turfsObj->getCommitteeTurfVoterStats($committee);
        $table = $this->voterCrosstabSummaryObj->buildCommitteeSummaryDisplay($committee, $committeeTargetCounts, $committeeAllCounts, $turfVoterStats);
        $output['table'] = $table;
        $output['table-end'] = [
          '#markup' => '</div>',
        ];
        $tables[$committee] = $output;
      }
    }

    Drupal::service("page_cache_kill_switch")->trigger();
    return [
      '#attached' => ['library' => ['nlpservices/info-boxes']],
      $tables,
    ];
  }

}
