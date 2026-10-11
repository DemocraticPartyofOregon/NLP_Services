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
    $countiesWithTurfs = $this->voterCrosstabSummaryObj->getCountiesWithTurfs();

    $tables = [];
    foreach ($countiesWithTurfs as $county) {
      $output = [];
      if(!empty($targetVoterSummary['counties'][$county])) {
        $output['table-start'] = [
          '#markup' => '<div class="summary-report">',
        ];
        $countyTargetCounts = $targetVoterSummary['counties'][$county];
        $countyAllCounts = $allVoterSummary['counties'][$county];
        $turfVoterStats = $this->turfsObj->getCountyTurfVoterStats($county);
        $table = $this->voterCrosstabSummaryObj->buildCountySummaryDisplay($county, $countyTargetCounts, $countyAllCounts, $turfVoterStats);
        $output['table'] = $table;
        $output['table-end'] = [
          '#markup' => '</div>',
        ];
        $tables[$county] = $output;
      }
    }

    Drupal::service("page_cache_kill_switch")->trigger();
    return [
      '#attached' => ['library' => ['nlpservices/info-boxes']],
      $tables,
    ];
  }

}
