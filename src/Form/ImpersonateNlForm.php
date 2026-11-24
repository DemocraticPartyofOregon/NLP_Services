<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpImpersonation;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfs;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the county's NLs so a coordinator or admin can impersonate one and see
 * their turfs without knowing the NL's password.
 *
 * @noinspection PhpUnused
 */
class ImpersonateNlForm extends FormBase
{

  public function __construct(
    protected DrupalUser $drupalUser,
    protected NlpImpersonation $impersonationObj,
    protected NlpSearchBar $searchBarObj,
    protected NlpSessionData $nlpSessionDataObj,
    protected NlpTurfs $turfsObj,
    protected NlpNls $nlsObj,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.impersonation'),
      $container->get('nlpservices.search_bar'),
      $container->get('nlpservices.session_data'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.nls'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_impersonate_nl_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $county = $this->nlpSessionDataObj->getCommittee();
    if (empty($county)) {
      $this->messenger()->addWarning($this->t('The county is not specified. Use the "Manage County" tab
        to select a county.'));
      return [];
    }

    $nls = $this->countyNls($county);
    $hdOptions = array_merge(['All'], $this->hdOptions($nls));
    $currentHdIndex = $this->nlpSessionDataObj->getSavedHdIndex();
    if (!isset($hdOptions[$currentHdIndex])) {
      $currentHdIndex = 0;
      $this->nlpSessionDataObj->saveHdIndex(0);
    }
    if ($currentHdIndex > 0) {
      $hd = $hdOptions[$currentHdIndex];
      $nls = array_filter($nls, fn(array $nl) => (string) $nl['hd'] === (string) $hd);
    }

    // Precinct choices are the precincts of the NLs in the selected HD, taken
    // before any name search so an option's position doesn't move.
    [$pctOptions, $currentPctIndex] = $this->precinctOptions($nls);
    if ($currentPctIndex > 0) {
      $pct = (string) $pctOptions[$currentPctIndex];
      $nls = array_filter($nls, fn(array $nl) => (string) $nl['precinct'] === $pct);
    }

    $needle = $form_state->get('search-needle');
    if (!empty($needle)) {
      $nls = $this->searchBarObj->searchInfoArray($needle, $nls);
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose an NL to see NLP Services as they do, including their turfs.
        A notice at the top of each page has a link to return to your own account.') . '</p>',
    ];
    $form['options_display'] = $this->searchBarObj->searchBar($hdOptions, $currentHdIndex,
      $pctOptions, $currentPctIndex);
    $form['nl-table'] = $this->nlTable($nls, $this->nlsWithTurfs($county));

    $form['#attached']['library'][] = 'nlpservices/nlp-table';
    $form['#attached']['library'][] = 'nlpservices/search-boxes';
    $form['#attached']['library'][] = 'nlpservices/hint-note';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $triggeringElement = $form_state->getTriggeringElement();
    if (($triggeringElement['#name'] ?? '') === 'search_submit') {
      $form_state->set('search-needle', $form_state->getValue('search-needle'));
      $form_state->setRebuild();
      return;
    }
    $uid = (int) ($triggeringElement['#nlp_uid'] ?? 0);
    if ($uid === 0) {
      return;
    }
    $refusal = $this->impersonationObj->switchTo($uid);
    if ($refusal !== '') {
      $this->messenger()->addError($refusal);
      $form_state->setRebuild();
      return;
    }
    $form_state->setRedirect('nlpservices.leader_start');
  }

  /**
   * countyNls
   *
   * Returns the county's accounts that can be impersonated: NLs who aren't
   * also coordinators or admins, sorted by last then first name.  Drupal
   * accounts don't hold a precinct, so each NL's is taken from their NL record
   * ('' if they have none).
   *
   * @param string $county
   * @return array - NLP user arrays keyed by uid, with a 'precinct' field.
   */
  private function countyNls(string $county): array
  {
    $impersonator = $this->drupalUser->getCurrentUser();
    $nls = array_filter($this->drupalUser->getUsers($county),
      fn(array $user) => $this->impersonationObj->impersonationRefusal($impersonator, $user) === '');
    $precincts = $this->nlsObj->getPrecinctsByMcid($county);
    foreach ($nls as $uid => $nl) {
      $nls[$uid]['precinct'] = $precincts[$nl['mcid'] ?? ''] ?? '';
    }
    uasort($nls, fn(array $a, array $b) => strcasecmp($a['lastName'] . ' ' . $a['firstName'],
      $b['lastName'] . ' ' . $b['firstName']));
    return $nls;
  }

  /**
   * precinctOptions
   *
   * Builds the precinct select options ('All' first, then each precinct with
   * NLs, in natural order) and the selected index.  A stale index (e.g., the
   * precinct isn't in the newly selected HD) falls back to 'All'.
   *
   * @param array $nls - the NLs in the selected HD.
   * @return array - [$pctOptions, $currentPctIndex]
   */
  private function precinctOptions(array $nls): array
  {
    $precincts = array_unique(array_filter(array_map('strval', array_column($nls, 'precinct')), 'strlen'));
    natcasesort($precincts);
    $pctOptions = array_merge(['All'], array_values($precincts));
    $currentPctIndex = $this->nlpSessionDataObj->getSavedPrecinctIndex();
    if (!isset($pctOptions[$currentPctIndex])) {
      $this->nlpSessionDataObj->savePrecinctIndex(0);
      $currentPctIndex = 0;
    }
    return [$pctOptions, $currentPctIndex];
  }

  /**
   * hdOptions
   *
   * @param array $nls
   * @return array - the HDs that have NLs, in ascending order.
   */
  private function hdOptions(array $nls): array
  {
    $hds = array_unique(array_filter(array_column($nls, 'hd')));
    sort($hds, SORT_NUMERIC);
    return array_values($hds);
  }

  /**
   * nlsWithTurfs
   *
   * @param string $county
   * @return array - the MCIDs of the county's NLs who have a turf, as keys.
   */
  private function nlsWithTurfs(string $county): array
  {
    return array_flip(array_map('strval', $this->turfsObj->getNlsWithTurfs($county)));
  }

  /**
   * nlTable
   *
   * A table of the NLs with an Impersonate button on each row.
   *
   * @param array $nls
   * @param array $withTurfs - MCIDs with turfs, as keys.
   * @return array
   */
  private function nlTable(array $nls, array $withTurfs): array
  {
    if (empty($nls)) {
      return ['#markup' => '<p>' . $this->t('No NLs to display.') . '</p>'];
    }
    $table = [
      '#type' => 'table',
      '#header' => [$this->t('HD'), $this->t('Precinct'), $this->t('MCID'), $this->t('Last name'), $this->t('First name'),
        $this->t('Username'), $this->t('Turf'), ''],
    ];
    foreach ($nls as $uid => $nl) {
      $hasTurf = isset($withTurfs[(string) $nl['mcid']]);
      $table[$uid] = [
        'hd' => ['#markup' => Html::escape((string) $nl['hd'])],
        'precinct' => ['#markup' => Html::escape((string) $nl['precinct'])],
        'mcid' => ['#markup' => Html::escape((string) $nl['mcid'])],
        'lastName' => ['#markup' => Html::escape((string) $nl['lastName'])],
        'firstName' => ['#markup' => Html::escape((string) $nl['firstName'])],
        'userName' => ['#markup' => Html::escape((string) $nl['userName'])],
        'turf' => ['#markup' => $hasTurf ? $this->t('Yes') : $this->t('No')],
        'impersonate' => [
          '#type' => 'submit',
          '#name' => 'impersonate_' . $uid,
          '#value' => $this->t('Impersonate'),
          '#nlp_uid' => $uid,
          '#limit_validation_errors' => [],
          '#submit' => ['::submitForm'],
        ],
      ];
    }
    return $table;
  }

}
