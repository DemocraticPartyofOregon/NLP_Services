<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WelcomeController extends ControllerBase {

  protected NlpConfiguration $configuration;

  public function __construct($configuration, $entityTypeManager) {
    $this->configuration = $configuration;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
      $container->get('entity_type.manager'),
    );
  }

  public function welcome(): array
  {
    $electionName = $this->configuration->getElectionName();
    $electionDate = $this->configuration->getElectionDate(TRUE);
    $ballotDropDate = $this->configuration->getBallotDropDate(TRUE);

    $body = '<h4><strong>The Neighborhood Leader Program website.&nbsp;</strong></h4>'
      . '<p><em>By logging in, you are agreeing to keep the information secure and not to share it with any unauthorized person.&nbsp;</em></p>'
      . '<p>The site is configured for the "' . $electionName . '."<br>'
      . 'Election date: ' . $electionDate . '<br>'
      . 'The earliest date ballots can be mailed to voters: ' . $ballotDropDate . '</p>';

    /** @var NodeStorageInterface $nodeStorage */
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('WelcomeController', $e->getMessage());
      return ['#markup' => $this->t('Failed to access node storage.')];
    }

    $nids = $nodeStorage->getQuery()
      ->condition('type', 'page')
      ->condition('title', 'Welcome')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($nids)) {
      $node = $nodeStorage->load(reset($nids));
      if (!$node instanceof NodeInterface) {
        return ['#markup' => $this->t('Home page node could not be loaded.')];
      }
    } else {
      $node = Node::create([
        'type' => 'page',
        'title' => 'Welcome',
        'status' => 1,
      ]);
    }

    $node->set('body', ['value' => $body, 'format' => 'full_html']);
    $node->set('sticky', TRUE);

    try {
      $node->save();
    } catch (EntityStorageException $e) {
      nlp_debug_msg('WelcomeController', $e->getMessage());
      return ['#markup' => $this->t('Failed to save the Home page node.')];
    }

    return ['#markup' => $this->t('Home page updated.')];
  }

}
