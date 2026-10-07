<?php

namespace Drupal\nlpservices;

use Drupal;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Exception;

class ApiDistrictFields {

  protected ClientInterface $client;
  protected ApiKeyManagement $apiKeyManagementObj;
  public function __construct($client, $apiKeyManagementObj)
  {
    $this->client = $client;
    $this->apiKeyManagementObj = $apiKeyManagementObj;
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getDistrictFields
   *
   * Get the district fields (State House, State Senate, County, Precinct, ...)
   * visible to the committee's API key.  If a districtFieldId is provided,
   * only that field is requested; otherwise all fields are.
   *
   * Either way the result has the same shape, keyed by districtFieldId:
   *   [districtFieldId => [
   *     'districtFieldId' => int,
   *     'name' => string,
   *     'parentFieldId' => int|null,
   *     'isCustomDistrict' => bool,
   *     'districtFieldValues' => [id => ['id' => string, 'name' => string, 'parentId' => string|null]],
   *   ]]
   * An empty array is returned on any API error.
   *
   * @param string $committee
   * @param int $districtFieldId - if provided, get a specific district field by ID
   * @return array
   */
  public function getDistrictFields(string $committee, int $districtFieldId=0): array
  {
    $committeeKey = $this->apiKeyManagementObj->getKeyValues($committee);
    $apiKey = $committeeKey['API Key'];
    $apiURL = $committeeKey['Url'];
    $user = $committeeKey['App Name'];

    $districtFieldsURL = 'https://'.$user.':'.$apiKey.'|0@'.$apiURL.'/districtFields';
    if(!empty($districtFieldId)) {
      $districtFieldsURL .= '/'.$districtFieldId;
    }

    // Exception messages are not displayed because Guzzle embeds the request
    // URI in them, and the URI carries the API key.
    try {
      $request = $this->client->request('GET', $districtFieldsURL);
    } catch (RequestException $e) {
      $code = $e->getCode();
      if ($code == 401) {
        nlp_debug_msg('getDistrictFields', "Invalid API Key for $committee");
        return [];
      }
      if ($code == 404) {
        $msg = "District field not found, County=$committee, DistrictFieldId=$districtFieldId";
        nlp_debug_msg('getDistrictFields', $msg);
        Drupal::logger('nlpservices')->notice($msg);
        return [];
      }
      nlp_debug_msg('getDistrictFields', "Request failed for $committee, HTTP code=$code");
      return [];
    } catch (Exception | GuzzleException $e) {
      nlp_debug_msg('getDistrictFields', "Request failed for $committee: " . get_class($e));
      return [];
    }

    $results = json_decode($request->getBody(), true);
    if(empty($results) || !is_array($results)) {
      return [];
    }
    if(!empty($districtFieldId)) {
      $fields = [$results];
    } else {
      // Accept either a bare list or a paged {items: [...]} wrapper.
      $fields = $results['items'] ?? $results;
    }

    $districtFields = [];
    foreach ($fields as $field) {
      if(!is_array($field) || !isset($field['districtFieldId'])) {continue;}
      $values = [];
      foreach ($field['districtFieldValues'] ?? [] as $value) {
        if(!isset($value['id'])) {continue;}
        $values[$value['id']] = [
          'id' => $value['id'],
          'name' => $value['name'] ?? '',
          'parentId' => $value['parentId'] ?? NULL,
        ];
      }
      $districtFields[$field['districtFieldId']] = [
        'districtFieldId' => $field['districtFieldId'],
        'name' => $field['name'] ?? '',
        'parentFieldId' => $field['parentFieldId'] ?? NULL,
        'isCustomDistrict' => (bool) ($field['isCustomDistrict'] ?? FALSE),
        'districtFieldValues' => $values,
      ];
    }
    return $districtFields;
  }

}
