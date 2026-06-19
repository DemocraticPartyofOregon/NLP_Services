<?php

namespace Drupal\nlpservices;

use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpVoterAcProcessor {

  protected ApiActivistCodes $activistCodesObj;
  protected NlpFileFunctions $fileFunctionsObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpVoters $votersObj;

  public function __construct(
    $activistCodesObj,
    $fileFunctionsObj,
    $nlsObj,
    $turfsObj,
    $votersObj,
  ) {
    $this->activistCodesObj = $activistCodesObj;
    $this->fileFunctionsObj = $fileFunctionsObj;
    $this->nlsObj           = $nlsObj;
    $this->turfsObj         = $turfsObj;
    $this->votersObj        = $votersObj;
  }

  public static function create(ContainerInterface $container): NlpVoterAcProcessor
  {
    return new static(
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.file_functions'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
    );
  }

  public function processTurf(int $turfIndex): void
  {
    $voters = array_values($this->votersObj->getVotersInTurf($turfIndex));
    if (empty($voters)) {
      return;
    }
    $missingVanids = $this->activistCodesObj->hasApiActivistCodeBatch($voters, 'NLPVoterAC');
    if (!empty($missingVanids)) {
      $this->activistCodesObj->setApiActivistCodeBatch($missingVanids, 'NLPVoterAC');
    }
  }

  public function processBatch(array $arg, array &$context): void
  {
    $turfIndexes = array_values($arg['turfIndexes']);
    $turfCount   = count($turfIndexes);
    $stopAtLimit = $arg['stopAtLimit'] ?? FALSE;
    $reportOnly  = $arg['reportOnly'] ?? FALSE;

    if (empty($context['sandbox']['initialized'])) {
      $context['sandbox']['initialized']  = TRUE;
      $context['sandbox']['currentTurf']  = 0;
    }

    if (!isset($context['results']['votersChecked'])) {
      $context['results']['votersChecked']    = 0;
      $context['results']['votersMissingAc']  = 0;
      $context['results']['turfsProcessed']   = 0;
      $context['results']['missingAcRecords'] = [];
      $context['results']['reportOnly']       = $reportOnly;
    }

    $currentTurf = $context['sandbox']['currentTurf'];

    if ($currentTurf >= $turfCount) {
      $context['finished'] = 1;
      return;
    }

    $turfIndex       = $turfIndexes[$currentTurf];
    $turf            = $this->turfsObj->getTurf($turfIndex);
    $turfName        = $turf['turfName'] ?? (string) $turfIndex;
    $county          = $turf['county'] ?? '';
    $mcid            = $turf['mcid'] ?? 0;
    $coordinatorName = '';
    if (!empty($mcid)) {
      $nl              = $this->nlsObj->getNlById($mcid);
      $coordinatorName = trim(($nl['firstName'] ?? '') . ' ' . ($nl['lastName'] ?? ''));
    }

    $voters = array_values($this->votersObj->getVotersInTurf($turfIndex));
    $context['results']['votersChecked'] += count($voters);

    $missingVanids = $this->activistCodesObj->hasApiActivistCodeBatch($voters, 'NLPVoterAC');

    $toApply = [];
    foreach ($missingVanids as $vanid) {
      if ($stopAtLimit && $context['results']['votersMissingAc'] >= 100) {
        $context['finished'] = 1;
        return;
      }
      $context['results']['missingAcRecords'][] = [
        'vanid'       => $vanid,
        'turfName'    => $turfName,
        'county'      => $county,
        'coordinator' => $coordinatorName,
      ];
      $context['results']['votersMissingAc']++;
      if (!$reportOnly) {
        $toApply[] = $vanid;
      }
    }

    if (!empty($toApply)) {
      $this->activistCodesObj->setApiActivistCodeBatch($toApply, 'NLPVoterAC');
    }
    $context['results']['turfsProcessed']++;

    $context['sandbox']['currentTurf']++;
    $context['finished'] = $context['sandbox']['currentTurf'] / $turfCount;
  }

}
