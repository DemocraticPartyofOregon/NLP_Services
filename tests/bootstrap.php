<?php

// Drupal's bootstrap (relative from this file: tests/ -> ../../../../ = Drupal root).
$drupalBootstrap = __DIR__ . '/../../../../core/tests/bootstrap.php';
if (file_exists($drupalBootstrap)) {
  require_once $drupalBootstrap;
}

// Kernel tests boot the real Drupal kernel, which loads the module's own
// nlpservices.module (real nlp_debug_msg()/nlp_debug_msg_safe()) and core's
// includes/form.inc (real batch_set()) - defining stand-ins for those here
// would fatal with "Cannot redeclare" once the real ones load. Unit tests
// never boot the kernel, so they still need these procedural stand-ins.
if (getenv('NLPSERVICES_KERNEL_BOOTSTRAP') !== '1') {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('nlp_debug_msg_safe')) {
    function nlp_debug_msg_safe($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('t')) {
    function t($string, array $args = [], array $options = []): string { return $string; }
  }
  if (!function_exists('batch_set')) {
    function batch_set(array $batch): void {}
  }
}
// Stand-ins for the Key module's entity/plugin shapes (drupal/key is not a
// composer dependency here), so tests can mock a real interface instead of
// using MockBuilder::addMethods() on \stdClass.
if (!interface_exists('NlpTestKeyEntityInterface')) {
  interface NlpTestKeyEntityInterface {
    public function getKeyValue();
    public function getKeyProvider();
    public function delete();
    public function save();
  }
}
if (!interface_exists('NlpTestKeyProviderInterface')) {
  interface NlpTestKeyProviderInterface {
    public function getConfiguration();
  }
}

// Same story as above: nlpservices.module defines these same constants for
// real, so Kernel tests (which load that module) must skip these too.
if (getenv('NLPSERVICES_KERNEL_BOOTSTRAP') !== '1') {
  if (!defined('NLP_MODULE'))                { define('NLP_MODULE',                'nlpservices'); }
  if (!defined('NLP_ADMIN_ROLE_ID'))         { define('NLP_ADMIN_ROLE_ID',         'nlp_admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_ID'))   { define('NLP_COORDINATOR_ROLE_ID',   'nlp_coordinator'); }
  if (!defined('NLP_LEADER_ROLE_ID'))        { define('NLP_LEADER_ROLE_ID',        'nlp_leader'); }
  if (!defined('NLP_ADMIN_ROLE_LABEL'))      { define('NLP_ADMIN_ROLE_LABEL',      'NLP Admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_LABEL')){ define('NLP_COORDINATOR_ROLE_LABEL','NLP Coordinator'); }
  if (!defined('NLP_LEADER_ROLE_LABEL'))     { define('NLP_LEADER_ROLE_LABEL',     'NLP Leader'); }
}
