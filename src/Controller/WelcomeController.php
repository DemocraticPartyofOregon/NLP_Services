<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\nlpservices\NlpConfiguration;
use Symfony\Component\DependencyInjection\ContainerInterface;

class WelcomeController extends ControllerBase {

  protected NlpConfiguration $configuration;

  public function __construct($configuration) {
    $this->configuration = $configuration;
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.configuration'),
    );
  }

  public function welcome(): array
  {
    $electionName = $this->configuration->getElectionName();
    $electionDate = $this->configuration->getElectionDate(TRUE);
    $earlyVotingStartDate = $this->configuration->getBallotDropDate(TRUE);

    $body = '<h4><strong>The Neighborhood Leader Program website.&nbsp;</strong></h4>'
      . '<p><em>By logging in, you are agreeing to keep the information secure and not to share it with any unauthorized person.&nbsp;</em></p>'
      . '<p>The site is configured for the "' . $electionName . '."<br>'
      . 'Election date: ' . $electionDate . '<br>'
      . 'Start date of early voting: ' . $earlyVotingStartDate . '</p>';

    return [
      '#markup' => $body,
      '#allowed_tags' => ['h4', 'strong', 'p', 'em', 'br'],
      '#cache' => [
        'tags' => ['config:nlpservices.configuration'],
      ],
    ];
  }

}
