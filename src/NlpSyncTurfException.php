<?php

namespace Drupal\nlpservices;

use Exception;

/**
 * A turf sync was refused.  The message is meant for the coordinator.
 *
 * Thrown inside the turf sync's database transaction so the transaction is
 * rolled back and nothing from the sync is left in the database.
 */
class NlpSyncTurfException extends Exception {

}
