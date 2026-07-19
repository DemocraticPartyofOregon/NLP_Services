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
      $nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('WelcomeController', $e->getMessage());
      return ['#markup' => $this->t('Failed to access node storage.')];
    }

    // D10 standard profile uses machine name 'page'; D11 has no defaults so
    // sites typically create 'basic_page'. Fall back in that order.
    $contentType = NULL;
    foreach (['page', 'basic_page'] as $candidate) {
      if ($nodeTypeStorage->load($candidate)) {
        $contentType = $candidate;
        break;
      }
    }
    if ($contentType === NULL) {
      return ['#markup' => $this->t('No suitable content type found. Create a content type with machine name "page" or "basic_page".')];
    }

    $nids = $nodeStorage->getQuery()
      ->condition('type', $contentType)
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
        'type' => $contentType,
        'title' => 'Welcome',
        'status' => 1,
        'promote' => 1,
        'sticky' => 1,
      ]);
    }

    // D10 built-in body base field is 'body'; user-created fields get 'field_body'.
    if ($node->hasField('body')) {
      $bodyField = 'body';
    } elseif ($node->hasField('field_body')) {
      $bodyField = 'field_body';
    } else {
      return ['#markup' => $this->t('No body field found on the "@type" content type. Add one via Structure > Content types > Manage fields.', ['@type' => $contentType])];
    }

    $node->set($bodyField, ['value' => $body, 'format' => 'full_html']);
    $node->set('promote', TRUE);
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
