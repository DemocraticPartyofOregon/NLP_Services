<?php

namespace Drupal\nlpservices;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class ApiEarlyVoteFields
{
  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private array $stateCommitteeKey;
  
  public function __construct($client, $nlpConfigurationObj) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->stateCommitteeKey = $this->nlpConfigurationObj->getStateKey();
  }
  
  public static function create(ContainerInterface $container): ApiEarlyVoteFields
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
    );
  }
  
  
  
  public function getEarlyVoteFields(): array
  {
    $committeeKey = $this->stateCommitteeKey;
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    /*
    curl --request GET \
     --url 'https://api.securevan.com/v4/earlyVoteFields?statuses=Active%2CInactive' \
     --header 'accept: application/json' \
     --header 'authorization: Basic c3RldmUuai5wYWNrZXJAZ21haWwuY29tOg=='
    */
    
    
    $listsURL = "https://$user:$apiKey|0@$apiURL/earlyVoteFields?statuses=Active%2CInactive";
    
    //nlp_debug_msg('$listsURL',$listsURL);
  
    try {
      $request = $this->client->get($listsURL);
      $results = json_decode($request->getBody(), true);
      nlp_debug_msg('$results',$results);
    } catch (Exception $e) {
      $statusCode = $e->getCode();
      //nlp_debug_msg('$statusCode',$statusCode);
      if ($statusCode == 404) {
        //nlp_debug_msg('getSavedLists - 400',$e->getMessage());
        return [];
      }
      nlp_debug_msg('getSavedLists',$e->getMessage());
      return [];
    }
  
    return $results;
  }
  
}