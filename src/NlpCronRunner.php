<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Utility\Error;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs the NLP Services queues on cron in priority order under one time budget.
 *
 * The queue workers have no cron time of their own, so core doesn't run them.
 * nlpservices_cron() calls runQueue() for each queue in priority order, and
 * stops starting new work when the budget is used.  The budget stays well
 * under the 900-second core cron lock, so one cron run is finished before
 * the lock expires and another can start.
 */
class NlpCronRunner
{
  /**
   * The seconds of queue work in one cron run.  Automated cron runs inside a
   * page request, so keep it short.
   */
  const int BUDGET_SECS = 180;

  /**
   * How long a claimed item is held.  Longer than any one item takes, so an
   * item isn't claimed again while it is still being processed.
   */
  const int LEASE_SECS = 3600;

  protected QueueFactory $queueFactory;
  protected QueueWorkerManagerInterface $workerManager;
  protected TimeInterface $time;

  private int $deadline = 0;

  public function __construct($queueFactory, $workerManager, $time)
  {
    $this->queueFactory = $queueFactory;
    $this->workerManager = $workerManager;
    $this->time = $time;
  }

  public static function create(ContainerInterface $container): NlpCronRunner
  {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('datetime.time'),
    );
  }

  /**
   * startBudget
   *
   * Start the time budget for this cron run.
   *
   * @return void
   */
  public function startBudget(): void
  {
    $this->deadline = $this->time->getCurrentTime() + self::BUDGET_SECS;
  }

  /**
   * hasTimeLeft
   *
   * @return bool  TRUE while the budget for this cron run isn't used up.
   */
  public function hasTimeLeft(): bool
  {
    return $this->time->getCurrentTime() < $this->deadline;
  }

  /**
   * runQueue
   *
   * Process the items in a queue until it is empty or the budget is used.
   * Exceptions are handled the way core cron handles them.
   *
   * @param string $queueName
   * @return bool  TRUE if no item is left that can be processed now.
   * @throws PluginException  If no queue worker has this name.
   */
  public function runQueue(string $queueName): bool
  {
    $queue = $this->queueFactory->get($queueName);
    $worker = $this->workerManager->createInstance($queueName);
    while ($this->hasTimeLeft()) {
      $item = $queue->claimItem(self::LEASE_SECS);
      if (!$item) { return TRUE; }
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      } catch (DelayedRequeueException $e) {
        if ($queue instanceof DelayableQueueInterface) {
          $queue->delayItem($item, $e->getDelay());
        }
      } catch (RequeueException) {
        $queue->releaseItem($item);
      } catch (SuspendQueueException) {
        $queue->releaseItem($item);
        return FALSE;
      } catch (Exception $e) {
        // Leave the item in the queue to be processed again later.
        Error::logException(Drupal::logger('nlpservices'), $e);
      }
    }
    return $queue->numberOfItems() == 0;
  }

  /**
   * queueIsEmpty
   *
   * @param string $queueName
   * @return bool
   */
  public function queueIsEmpty(string $queueName): bool
  {
    return $this->queueFactory->get($queueName)->numberOfItems() == 0;
  }

}
