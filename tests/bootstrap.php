<?php

// Drupal's bootstrap (relative from this file: tests/ -> ../../../../ = Drupal root).
$drupalBootstrap = __DIR__ . '/../../../../core/tests/bootstrap.php';
if (file_exists($drupalBootstrap)) {
  require_once $drupalBootstrap;
}

// Static holder so user_load_by_* stubs can return test-controlled values.
if (!class_exists('NlpTestGlobals')) {
  class NlpTestGlobals {
    public static mixed $stubUserByName = FALSE;
    public static mixed $stubUserByMail = FALSE;
  }
}

if (!function_exists('nlp_debug_msg')) {
  function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
}
if (!function_exists('t')) {
  function t($string, array $args = [], array $options = []): string { return $string; }
}
if (!function_exists('batch_set')) {
  function batch_set(array $batch): void {}
}
if (!function_exists('user_load_by_name')) {
  function user_load_by_name(string $name): mixed {
    return NlpTestGlobals::$stubUserByName;
  }
}
if (!function_exists('user_load_by_mail')) {
  function user_load_by_mail(string $mail): mixed {
    return NlpTestGlobals::$stubUserByMail;
  }
}
if (!defined('NLP_MODULE'))                { define('NLP_MODULE',                'nlpservices'); }
if (!defined('NLP_ADMIN_ROLE_ID'))         { define('NLP_ADMIN_ROLE_ID',         'nlp_admin'); }
if (!defined('NLP_COORDINATOR_ROLE_ID'))   { define('NLP_COORDINATOR_ROLE_ID',   'nlp_coordinator'); }
if (!defined('NLP_LEADER_ROLE_ID'))        { define('NLP_LEADER_ROLE_ID',        'nlp_leader'); }
if (!defined('NLP_ADMIN_ROLE_LABEL'))      { define('NLP_ADMIN_ROLE_LABEL',      'NLP Admin'); }
if (!defined('NLP_COORDINATOR_ROLE_LABEL')){ define('NLP_COORDINATOR_ROLE_LABEL','NLP Coordinator'); }
if (!defined('NLP_LEADER_ROLE_LABEL'))     { define('NLP_LEADER_ROLE_LABEL',     'NLP Leader'); }
