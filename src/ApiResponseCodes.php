<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class ApiResponseCodes
{
  // The list of recommended contact types and associated response code expected by NLP.
  private  array $expectedContactTypes = [
    'Walk' => [
      ['text'=>'Left Message/Lit','weight'=>1,'abbreviation'=>'LM/L'],
      ['text'=>'Not Home','weight'=>2,'abbreviation'=>'NH'],
      ['text'=>'Refused','weight'=>3,'abbreviation'=>'RF'],
      ['text'=>'Inaccessible','weight'=>4,'abbreviation'=>'IN'],
      ['text'=>'Moved','weight'=>5,'abbreviation'=>'MV'],
      ['text'=>'Deceased','weight'=>6,'abbreviation'=>'DC'],
      ['text'=>'Hostile','weight'=>7,'abbreviation'=>'HO'],
      ['text'=>'Left Message','weight'=>9,'abbreviation'=>'LM'],
      ['text'=>'Deceased','weight'=>12,'abbreviation'=>'DC'],
      ['text'=>'Hostile','weight'=>13,'abbreviation'=>'HS'],
      ['text'=>'Wrong Address','weight'=>17,'abbreviation'=>'WA'],
    ],
    'Phone'=>[
      ['text'=>'Left Message','weight'=>8],
      ['text'=>'Deceased','weight'=>7],
      ['text'=>'Hostile','weight'=>6],
      ['text'=>'Moved','weight'=>5],
      ['text'=>'Wrong Number','weight'=>9],
      ['text'=>'Not Home','weight'=>11],
    ],
    'Postcard'=>[
      ['text'=>'Mailed','weight'=>5],
      ['text'=>'Deceased','weight'=>7],
      ['text'=>'Hostile','weight'=>6],
      ['text'=>'Moved','weight'=>5],
    ],
    'Personal Email'=>[
      ['text'=>'Sent Email','weight'=>5],
      ['text'=>'Deceased','weight'=>7],
      ['text'=>'Moved','weight'=>5],
      ['text'=>'Hostile','weight'=>6],
      ['text'=>'Do Not Email','weight'=>9],
    ],
    'SMS Text'=>[
      ['text'=>'Texted','weight'=>5],
      ['text'=>'Deceased','weight'=>7],
      ['text'=>'Moved','weight'=>5],
      ['text'=>'Hostile','weight'=>6],
      ['text'=>'Do Not Text','weight'=>9],
    ],
    'Social Media'=>[
      ['text'=>'Left Message','weight'=>5],
    ],
  ];

  protected ClientInterface $client;
  protected NlpConfiguration $nlpConfigurationObj;
  private ApiKeyManagement $apiKeyManagementObj;

  public function __construct( $client, $nlpConfigurationObj, $apiKeyManagementObj  ) {
    $this->client = $client;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }

  public static function create(ContainerInterface $container): ApiResponseCodes
  {
    return new static(
      $container->get('http_client'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.api_key_management'),
    );
  }

  /**
   * getApiContactTypes
   *
   *  This is used to get the contact types for the canvass responses.
   *  The contact types are used to determine the type of contact made with the voter.
   *
   * @return array - array of contact types and their ids from the API.
   *
   */
  public function getApiContactTypes(): array
  {
    $messenger = Drupal::messenger();
    $committeeKey = $this->apiKeyManagementObj->getKeyValues('');
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $post_url = "https://$user:$apiKey|0@$apiURL/canvassResponses/contactTypes?inputTypeId=11";
    try {
      $request = $this->client->request('GET', $post_url);
      $result = json_decode($request->getBody(), true);
    } catch (Exception $e)  {
      $error = $e->getMessage();
      $messenger->addStatus( t('An error occurred. Please contact the Administrator. '.$error));
      return [];
    } catch (GuzzleException $e) {
      $error = $e->getMessage();
      $messenger->addStatus( t('An error occurred. Please contact the Administrator. '.$error));
      return [];
    }
    $contactTypes = [];
    foreach ($result as $contactType) {
      $contactTypes[$contactType['name']] = $contactType['contactTypeId'];
    }
    return $contactTypes;
  }

  /**
   * getApiResultCodes
   *
   * @param int $contactTypeId - The contact type id to get the result codes for.
   * @return array - array of result codes and their ids for the given contact type.
   *
   * This is used to get the result codes for the canvass responses.
   * The result codes are used to determine the outcome of the contact made with the voter.
   */
  public function getApiResultCodes(int $contactTypeId ): array
  {
    $messenger = Drupal::messenger();
    $committeeKey = $this->apiKeyManagementObj->getKeyValues('');
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];
    $url = "https://$apiURL/canvassResponses/resultCodes?contactTypeId=$contactTypeId";
    try {
      $request = $this->client->request('GET', $url, [
        'auth' => [$user, "$apiKey|0"],
      ]);
      $resultArray = json_decode($request->getBody(), true);
    } catch (Exception $e) {
      $error = $e->getMessage();
      $messenger->addStatus(t('An error occurred. Please contact the Administrator. '.$error));
      return [];
    } catch (GuzzleException $e) {
      $error = $e->getMessage();
      $messenger->addStatus(t('An error occurred. Please contact the Administrator. '.$error));
      return [];
    }
    $resultCodes = [];
    foreach ($resultArray as $resultCodeObj) {
      $resultCodes[$resultCodeObj['name']] = $resultCodeObj['resultCodeId'];
    }
    return $resultCodes;
  }

  /**
   * getApiKnownResultCodes
   *
   * @return array - Array of the known contact types and their result codes.
   *
   * This is used to get the known contact types and their result codes for the canvass responses.
   * The result codes are used to determine the outcome of the contact made with the voter.
   */
  public function getApiKnownResultCodes(): array
  {
    $contactTypes = $this->getApiContactTypes();
    $knownResultCodes = [];
    foreach ($contactTypes as $contactTypeName => $contactTypeId) {
      $knownResultCodes[$contactTypeName]['code'] = $contactTypeId;
      $resultCodes = $this->getApiResultCodes($contactTypeId);
      foreach ($resultCodes as $resultName => $resultCodeId) {
        $knownResultCodes[$contactTypeName]['responses'][$resultName] = $resultCodeId;
      }
    }
    return $knownResultCodes;
  }

  /**
   * @return array - Array of the recommended contact types and response codes.
   *
   * This list seems to be the right list for the best use of NLP.
   * Others can be added during the configuration of the site.
   * If some are missing, the NLs may object to missing options for reporting voter contacts.
   */
  public function getApiExpectedResultCodes(): array
  {
    $expectedResultCodes = [];
    foreach ($this->expectedContactTypes as $contactType=>$eResultArray) {
      foreach ($eResultArray as $expectedResultArray) {
        $text = $expectedResultArray['text'];
        $expectedResultCodes[$contactType]['responses'][$text] = $text;
      }
    }
    return $expectedResultCodes;
  }

  /**
   * getApiExpectedAbbreviations
   *
   * @param string $contactType - The contact type to get the expected abbreviations for.
   * @return array - Array of expected abbreviations for the given contact type.
   *
   * This is used to get the expected abbreviations for the contact types.
   * The abbreviations are used to determine the short form of the contact type.
   */
  public function getApiExpectedAbbreviations( string $contactType='Walk'): array
  {
    $expectedAbbreviations = [];
    $eResultArray = $this->expectedContactTypes[$contactType];
    usort($eResultArray, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });
    foreach ($eResultArray as $expectedResultArray) {
      $text = $expectedResultArray['text'];
      $expectedAbbreviations[$text] = $expectedResultArray['abbreviation'] ?? '';
    }

    return $expectedAbbreviations;
  }

}
