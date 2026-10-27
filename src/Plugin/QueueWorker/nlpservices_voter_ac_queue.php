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
 * No cron time is set, so core cron doesn't run this queue.  It is run by
 * nlpservices_cron() through NlpCronRunner, in priority order.
 *
 * @QueueWorker(
 *   id = "nlpservices_voter_ac_queue",
 *   title = @Translation("NLP Voter activist code check.")
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
