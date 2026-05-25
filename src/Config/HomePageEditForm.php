<?php

namespace Drupal\nlpservices\Config;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class HomePageEditForm extends FormBase {

  protected NlpConfiguration $configurationObj;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct($configurationObj, $entityTypeManager) {
    $this->configurationObj = $configurationObj;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_home_page_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['note'] = [
      '#markup' => "<p>The purpose of this function is to customize the home page for an election.<br>
  There are three keys possible to reference election information:<br>
  &nbsp;&nbsp;#electionName - the configured name of this election<br>
  &nbsp;&nbsp;#ballotDropDate - the earliest that ballots are mailed to voters<br>
  &nbsp;&nbsp;#electionDate - the date of this election
  </p>",
    ];

    $nid = $this->getHomePageNid();
    if (!$nid) {
      $form['message'] = [
        '#markup' => $this->t('No Home page found.'),
      ];
      return $form;
    }
    $form_state->set('homePageNodeId', $nid);

    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('HomePageEditForm', $e->getMessage());
      return $form;
    }
    if (!$node instanceof NodeInterface) {
      $form['message'] = ['#markup' => $this->t('Home page could not be loaded.')];
      return $form;
    }
    $body = $node->get('body')->value ?? '';

    $form['body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Home Page HTML'),
      '#format' => 'full_html',
      '#default_value' => $body,
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $replace = [];
    $electionName = $this->configurationObj->getElectionName();
    if (!empty($electionName)) {
      $replace['#electionName'] = $electionName;
    }
    $electionDate = $this->configurationObj->getElectionDate(TRUE);
    if (!empty($electionDate)) {
      $replace['#electionDate'] = $electionDate;
    }
    $ballotDropDate = $this->configurationObj->getBallotDropDate(TRUE);
    if (!empty($ballotDropDate)) {
      $replace['#ballotDropDate'] = $ballotDropDate;
    }

    $nid = $form_state->get('homePageNodeId');
    if ($nid) {
      try {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
      } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
        nlp_debug_msg('HomePageEditForm', $e->getMessage());
        return;
      }
      if (!$node instanceof NodeInterface) {
        $this->messenger()->addError($this->t('Home page not found.'));
        return;
      }
      $body = $form_state->getValue('body');

      $node->set('body', [
        'value' => strtr((string) $body['value'], $replace),
        'format' => $body['format'],
      ]);
      try {
        $node->save();
        $this->messenger()->addStatus($this->t('Home page updated.'));
      } catch (EntityStorageException $e) {
        nlp_debug_msg('HomePageEditForm', $e->getMessage());
        $this->messenger()->addError($this->t('Failed to save the home page.'));
      }
    }
    else {
      $this->messenger()->addError($this->t('Home page not found.'));
    }
  }

  private function getHomePageNid(): int
  {
    /** @var NodeStorageInterface $nodeStorage */
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('HomePageEditForm', $e->getMessage());
      return 0;
    }
    $nids = $nodeStorage->getQuery()
      ->condition('type', 'page')
      ->condition('title', 'Home')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    return is_array($nids) && !empty($nids) ? reset($nids) : 0;
  }

}
