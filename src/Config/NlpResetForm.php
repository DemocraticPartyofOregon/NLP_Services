<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\nlpservices\ApiExportJobs;
use Drupal\nlpservices\NlpCrosstabCounts;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\DrupalUser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class NlpResetForm extends FormBase {

  protected NlpVoters $votersObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpMatchbacks $matchbackObj;
  protected ApiExportJobs $exportJobsObj;
  protected NlpCrosstabCounts $crosstabCountsObj;
  protected NlpInstructions $instructionsObj;
  protected Connection $connection;
  protected NlpCoordinators $coordinatorObj;
  protected DrupalUser $drupalUserObj;

  public function __construct($votersObj, $nlsObj, $turfsObj, $matchbackObj, $exportJobsObj, $crosstabCountsObj,
                              $instructionsObj, $connection, $coordinatorObj, $drupalUserObj) {
    $this->votersObj = $votersObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->matchbackObj = $matchbackObj;
    $this->exportJobsObj = $exportJobsObj;
    $this->crosstabCountsObj = $crosstabCountsObj;
    $this->instructionsObj = $instructionsObj;
    $this->connection = $connection;
    $this->coordinatorObj = $coordinatorObj;
    $this->drupalUserObj = $drupalUserObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.matchbacks'),
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.crosstab_counts'),
      $container->get('nlpservices.instructions'),
      $container->get('database'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.drupal_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlp_reset_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Okay to reset NLP Services.'),
      '#name' => 'add_coordinator',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tablesToTruncate = [
      'voters' => $this->votersObj::VOTER_TBL,
      'voterStatus' => $this->votersObj::VOTER_STATUS_TBL,
      'voterAddress' => $this->votersObj::VOTER_ADDRESS_TBL,
      'voterTurf' => $this->votersObj::VOTER_TURF_TBL,
      'nls' => $this->nlsObj::NLS_TBL,
      'nlsStatus' => $this->nlsObj::NLS_STATUS_TBL,
      'turf' => $this->turfsObj::TURF_TBL,
      'ballotCount' => $this->crosstabCountsObj::CROSSTAB_COUNTS_TBL,
      'matchback' => $this->matchbackObj::MATCHBACK_TBL,
      'exportJobs' => $this->exportJobsObj::EXPORT_JOBS_TBL,
      'instructions' => $this->instructionsObj::INSTRUCTIONS_TBL,
      'coordinators' => $this->coordinatorObj::COORDINATOR_TBL,
    ];

    foreach ($tablesToTruncate as $table) {
      try {
        $this->connection->truncate($table)->execute();
        $this->messenger()->addStatus($this->t('The @table database was emptied.', ['@table' => $table]));
      }
      catch (Exception $e) {
        nlp_debug_msg('e', $e);
      }
    }

    // Empty the county directories of files used in the election.
    $nlpFilesDir = 'public://nlp_files';
    $counties = $this->getDirContent($nlpFilesDir);
    foreach ($counties['directories'] as $countyDir) {
      $countyDir = $nlpFilesDir . '/' . $countyDir;
      $countyDirContent = $this->getDirContent($countyDir);
      foreach ($countyDirContent['files'] as $countyFile) {
        $fileName = $countyDir . '/' . $countyFile;
        unlink($fileName);
      }

      foreach ($countyDirContent['directories'] as $countyContentDir) {
        $countyContentDir = $countyDir . '/' . $countyContentDir;
        $countyContentDirContent = $this->getDirContent($countyContentDir);
        foreach ($countyContentDirContent['files'] as $countyFile) {
          $fileName = $countyContentDir . '/' . $countyFile;
          unlink($fileName);
        }
      }
    }

    // Remove NL login accounts.
    $drupalUsers = $this->drupalUserObj->getUsers();
    foreach ($drupalUsers as $drupalUser) {
      $roles = $drupalUser['roles'];
      $roleNames = array_flip($roles);
      if (!isset($roleNames['nlp_leader'])) { continue; }
      if (isset($roleNames['nlp_coordinator']) || isset($roleNames['nlp_admin']) || isset($roleNames['administrator'])) { continue; }

      unset($roleNames['nlp_leader']);
      unset($roleNames['authenticated']);
      if (count($roleNames) > 0) {
        nlp_debug_msg('Removing ' . $drupalUser['firstName'] . ' ' . $drupalUser['lastName']
          . ' from all roles.', implode(', ', $roles));
        continue;
      }
      $this->drupalUserObj->deleteUser($drupalUser['uid']);
    }
  }

  /**
   * Retrieves the content of a given directory, separating files and directories.
   *
   * @param string $dir The directory path to read from.
   * @return array An associative array containing two keys:
   *               'files' - an array of filenames.
   *               'directories' - an array of directory names.
   */
  private function getDirContent(string $dir): array
  {
    $results = ['files' => [], 'directories' => []];
    if (!is_dir($dir)) {
      return $results;
    }
    if ($dh = opendir($dir)) {
      while (($file = readdir($dh)) !== false) {
        if ($file != '.' && $file != '..') {
          if (is_dir($dir . '/' . $file)) {
            $results['directories'][] = $file;
          } else {
            $results['files'][] = $file;
          }
        }
      }
      closedir($dh);
    }
    return $results;
  }

}
