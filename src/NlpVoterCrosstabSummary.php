<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpVoterCrosstabSummary
{

  protected Connection $connection;
  protected NlpTurfs $turfs;

  public function __construct($connection, $turfs) {
    $this->connection = $connection;
    $this->turfs = $turfs;
  }

  public static function create(ContainerInterface $container): NlpVoterCrosstabSummary
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.turfs'),
    );
  }

  public function getCountiesWithTurfs(): array
  {
    return $this->turfs->getCountyNames();
  }

  /**
   * getCountySummary
   *
   * For the given crosstab table, sums totalPeople and accepted for each
   * county (excluding rows where cd = 0), and computes state totals.
   *
   * @param string $tableName - NlpTargetVoterCrosstabCounts::TARGET_VOTER_CROSSTAB_TBL
   *                            or NlpTargetVoterCrosstabCounts::ALL_VOTER_CROSSTAB_TBL
   * @return array - [
   *   'counties' => [ countyName => ['totalPeople' => int, 'accepted' => int], ... ],
   *   'state'    => [ 'totalPeople' => int, 'accepted' => int ],
   * ]
   */
  public function getCountySummary(string $tableName): array
  {
    try {
      $query = $this->connection->select($tableName, 'c');
      $query->addField('c', 'county');
      $query->addExpression('SUM(totalPeople)', 'totalPeople');
      $query->addExpression('SUM(accepted)', 'accepted');
      $query->condition('cd', 0, '!=');
      $query->groupBy('county');
      $query->orderBy('county');
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getCountySummary', $e->getMessage());
      return ['counties' => [], 'state' => ['totalPeople' => 0, 'accepted' => 0]];
    }

    $counties         = [];
    $stateTotalPeople = 0;
    $stateAccepted    = 0;

    do {
      $row = $result->fetchAssoc();
      if (empty($row)) {break;}
      $totalPeople = (int) $row['totalPeople'];
      $accepted    = (int) $row['accepted'];
      $counties[$row['county']] = [
        'totalPeople' => $totalPeople,
        'accepted'    => $accepted,
      ];
      $stateTotalPeople += $totalPeople;
      $stateAccepted    += $accepted;
    } while (TRUE);

    return [
      'counties' => $counties,
      'state'    => ['totalPeople' => $stateTotalPeople, 'accepted' => $stateAccepted],
    ];
  }

  /**
   * buildCountySummaryDisplay
   *
   * Builds a Drupal render array showing totalPeople and accepted (voted)
   * for each participating county and a state total row, for the given table.
   *
   * @param string $county
   * @param array $targetVoters   - ['totalPeople' => int, 'accepted' => int]
   * @param array $allVoters      - ['totalPeople' => int, 'accepted' => int]
   * @param array $turfStats      - keys: total, totalVoted, contacted, contactedVoted, surveyed, surveyedVoted
   * @return array - Drupal render array.
   */
  public function buildCountySummaryDisplay(string $county, array $targetVoters, array $allVoters, array $turfStats = []): array
  {
    $rows = [];

    $totalPeople = $allVoters['totalPeople'];
    $accepted    = $allVoters['accepted'];
    $pct = ($totalPeople > 0) ? round($accepted / $totalPeople * 100, 1) . '%' : '0%';
    $rows[] = [
      'type'        => t('All voters'),
      'totalPeople' => number_format($totalPeople),
      'accepted'    => number_format($accepted),
      'pct'         => $pct,
    ];

    $totalPeople = $targetVoters['totalPeople'];
    $accepted    = $targetVoters['accepted'];
    $pct = ($totalPeople > 0) ? round($accepted / $totalPeople * 100, 1) . '%' : '0%';
    $rows[] = [
      'type'        => t('Target voters'),
      'totalPeople' => number_format($totalPeople),
      'accepted'    => number_format($accepted),
      'pct'         => $pct,
    ];

    if (!empty($turfStats)) {
      $total = $turfStats['total'];
      $pct = ($total > 0) ? round($turfStats['totalVoted'] / $total * 100, 1) . '%' : '0%';
      $rows[] = [
        'type'        => t('NLP voters'),
        'totalPeople' => number_format($total),
        'accepted'    => number_format($turfStats['totalVoted']),
        'pct'         => $pct,
      ];

      $contacted = $turfStats['contacted'];
      $pct = ($contacted > 0) ? round($turfStats['contactedVoted'] / $contacted * 100, 1) . '%' : '0%';
      $rows[] = [
        'type'        => t('Contact attempted'),
        'totalPeople' => number_format($contacted),
        'accepted'    => number_format($turfStats['contactedVoted']),
        'pct'         => $pct,
      ];

      $surveyed = $turfStats['surveyed'];
      $pct = ($surveyed > 0) ? round($turfStats['surveyedVoted'] / $surveyed * 100, 1) . '%' : '0%';
      $rows[] = [
        'type'        => t('Survey responded'),
        'totalPeople' => number_format($surveyed),
        'accepted'    => number_format($turfStats['surveyedVoted']),
        'pct'         => $pct,
      ];
    }

    return [
      '#type'    => 'table',
      '#caption' => $county,
      '#header'  => [
        'type'        => '',
        'totalPeople' => t('Total Voters'),
        'accepted'    => t('Voted'),
        'pct'         => t('Participation'),
      ],
      '#rows' => $rows,
    ];
  }

}