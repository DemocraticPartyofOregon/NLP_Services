<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class ApiEarlyVoteFields
{
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiKeyManagement $apiKeyManagementObj;

  public function __construct($client, $nlpConfigurationObj, $apiKeyManagementObj) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }
  
  public static function create(ContainerInterface $container): ApiEarlyVoteFields
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }

  public function getEarlyVoteFields(): array
  {
    $stateCommitteeKey = $this->apiKeyManagementObj->getKeyValues('State Committee');
    $apiKey = $stateCommitteeKey['API Key'];
    $apiURL = $stateCommitteeKey['Url'];
    $user = $stateCommitteeKey['App Name'];
    $listsURL = "https://$user:$apiKey|0@$apiURL/earlyVoteFields?statuses=Active%2CInactive";
    try {
      $request = $this->client->get($listsURL);
      $results = json_decode($request->getBody(), TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
      }
      return $results ?? [];
    } catch (GuzzleException $e) {
      nlp_debug_msg('getEarlyVoteFields', $e->getMessage());
      return [];
    } catch (Exception $e) {
      nlp_debug_msg('getEarlyVoteFields', $e->getMessage());
      return [];
    }
  }
  
}