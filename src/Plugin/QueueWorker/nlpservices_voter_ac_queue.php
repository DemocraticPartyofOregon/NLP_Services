<?php

namespace Drupal\nlpservices\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\nlpservices\NlpVoterAcProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A queue worker for checking and applying missing NLP Voter activist codes.
 *
 * @QueueWorker(
 *   id = "nlpservices_voter_ac_queue",
 *   title = @Translation("NLP Voter activist code check."),
 *   cron = {"time" = 1800}
 * )
 */
class nlpservices_voter_ac_queue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NlpVoterAcProcessor $voterAcProcessorObj,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('nlpservices.voter_ac_processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void
  {
    $turfIndex = (int) $data['turfIndex'];
    $this->voterAcProcessorObj->processTurf($turfIndex);
  }

}
