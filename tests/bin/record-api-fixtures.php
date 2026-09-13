<?php

/**
 * @file
 * Records real sandbox API responses as test fixtures for ApiVoter/ApiNls.
 *
 * Run this ON A LIVE SITE that has sandbox-mode API credentials configured
 * (via the Key module, same as production) - NEVER against a real voter
 * file/database. It reuses the exact same nlpservices.api_key_management /
 * http_client services the real ApiVoter/ApiNls classes use, so the
 * plaintext API key is decrypted and used entirely inside this process on
 * the live site - it is never printed, logged, or returned to the caller.
 *
 * Usage (via drush scr, so Drupal's container is already bootstrapped):
 *   drush scr web/modules/custom/nlpservices/tests/bin/record-api-fixtures.php -- voter <vanid> [committee]
 *   drush scr web/modules/custom/nlpservices/tests/bin/record-api-fixtures.php -- nl <mcid> [committee]
 *
 * committee defaults to '' (ApiKeyManagement treats that as 'State
 * Committee'). Writes two files per capture into tests/fixtures/api/:
 *   {mode}/{committee}_{id}.json           - the raw sandbox response body
 *   {mode}/{committee}_{id}.expected.json  - this module's own parsed output
 *                                            for that response (a frozen
 *                                            snapshot the replay tests diff
 *                                            against)
 *
 * Both output files are safe to commit: sandbox data is not real voter/NL
 * data, and neither file contains the API key or the request URL.
 */

use Drupal\nlpservices\ApiNls;
use Drupal\nlpservices\ApiVoter;

// $extra is populated by `drush scr script.php -- a b c` with ['a', 'b', 'c'].
$args = $extra ?? array_slice($_SERVER['argv'] ?? [], 1);
[$mode, $id, $committee] = array_pad($args, 3, '');

if (!in_array($mode, ['voter', 'nl'], TRUE) || empty($id)) {
  fwrite(STDERR, "Usage: drush scr web/modules/custom/nlpservices/tests/bin/record-api-fixtures.php -- voter|nl <id> [committee]\n");
  exit(1);
}

$httpClient = \Drupal::service('http_client');
$apiKeyManagement = \Drupal::service('nlpservices.api_key_management');
$configuration = \Drupal::service('nlpservices.configuration');

$committeeKey = $apiKeyManagement->getKeyValues($committee);
if (empty($committeeKey['API Key'])) {
  fwrite(STDERR, "No API key found for committee '$committee' - check the Key module configuration on this site.\n");
  exit(1);
}
$apiKey = $committeeKey['API Key'];
$apiURL = $committeeKey['Url'];
$user = $committeeKey['App Name'];

if ($mode === 'voter') {
  $dbMode = 0;
  $expandOptions = '?$expand=phones,addresses,districts,electionRecords,externalIds';
  $processor = ApiVoter::create(\Drupal::getContainer());
  $processMethod = 'processVoterResult';
}
else {
  $dbMode = 1;
  $expandOptions = '?$expand=phones,emails,addresses,districts,preferences,externalIds';
  $processor = ApiNls::create(\Drupal::getContainer());
  $processMethod = 'processNlResult';
}

$url = "https://$user:$apiKey|$dbMode@$apiURL/people/$id$expandOptions";

try {
  $response = $httpClient->request('GET', $url);
  $rawBody = (string) $response->getBody();
  $decoded = json_decode($rawBody, TRUE);
  if (json_last_error() !== JSON_ERROR_NONE) {
    throw new \RuntimeException('Response was not valid JSON.');
  }
}
catch (\Throwable $e) {
  // Deliberately do not print $e->getMessage() - Guzzle exception messages
  // for connection/auth errors include the full request URI, which embeds
  // the API key as HTTP Basic Auth credentials.
  fwrite(STDERR, 'Request failed: ' . get_class($e) . ($e->getCode() ? ' (HTTP ' . $e->getCode() . ')' : '') . "\n");
  exit(1);
}

$safeCommittee = preg_replace('/[^A-Za-z0-9_-]/', '_', $committee ?: 'default');
$baseName = "{$safeCommittee}_{$id}";
$dir = __DIR__ . "/../fixtures/api/$mode";
if (!is_dir($dir)) {
  mkdir($dir, 0755, TRUE);
}

file_put_contents("$dir/$baseName.json", json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$reflection = new \ReflectionMethod($processor, $processMethod);
$reflection->setAccessible(TRUE);
$expected = $reflection->invoke($processor, $decoded);
file_put_contents("$dir/$baseName.expected.json", json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Wrote $dir/$baseName.json and $baseName.expected.json\n";
