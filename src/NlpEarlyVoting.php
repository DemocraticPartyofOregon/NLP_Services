<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpEarlyVoting
{

  const string VOTER_TBL = 'nlp_voter';
  const string VOTER_ADDRESS_TBL = 'nlp_voter_address';

  private array $stateEarlyVotingHdr = [
    'stateFileId' => ['name' => 'AssignedVoterID', 'err' => 'Assigned Voter ID missing.'],
    'date' => ['name' => 'BallotReceivedDate', 'err' => 'Ballot Received Date missing.'],
  ];

  protected Connection $connection;

  public function __construct($connection)
  {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container): NlpEarlyVoting
  {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * For each row in the early voting array, parse the Name field, search the
   * voter database by precinct and lastName, refine by firstName, and return
   * an array of matched records.
   *
   * Each input row must have keys: 'name', 'precinct', 'receiveDate'.
   *
   * Name format: "lastName, firstName [optional additional]"
   *
   * Output rows have keys: 'vanid', 'name', 'precinct', 'date'.
   * - vanid is 0 and date is '' when the match is ambiguous (multiple voters).
   * - Rows with no match are omitted.
   *
   * @param array $earlyVotingRows
   * @return array
   */
  public function matchEarlyVoters(array $earlyVotingRows): array
  {
    $matched = [];

    foreach ($earlyVotingRows as $row) {
      $name = $row['name'] ?? '';
      $precinct = $row['precinct'] ?? '';
      $receiveDate = $row['receiveDate'] ?? '';

      // Parse "lastName, firstName [rest ignored]"
      $commaParts = explode(',', $name, 2);
      if (count($commaParts) < 2) {
        continue;
      }
      $lastName = trim($commaParts[0]);
      $firstNameParts = preg_split('/\s+/', trim($commaParts[1]));
      $firstName = $firstNameParts[0] ?? '';

      if ($lastName === '' || $firstName === '' || $precinct === '') {
        continue;
      }

      // Step 1: match on precinct + lastName.
      $candidates = $this->fetchByPrecinctAndLastName($precinct, $lastName);
      if (empty($candidates)) {
        continue;
      }

      // Step 2: narrow by firstName (case-insensitive contains).
      $firstNameLower = strtolower($firstName);
      $refined = array_filter($candidates, static function (array $voter) use ($firstNameLower): bool {
        return str_contains(strtolower($voter['firstName']), $firstNameLower);
      });

      $count = count($refined);
      if ($count === 0) {
        continue;
      }

      if ($count === 1) {
        $voter = reset($refined);
        $matched[] = [
          'vanid' => $voter['vanid'],
          'name' => $name,
          'precinct' => $precinct,
          'date' => $receiveDate,
        ];
      } else {
        // Ambiguous — flag the row but leave vanid and date blank.
        $matched[] = [
          'vanid' => 0,
          'name' => $name,
          'precinct' => $precinct,
          'date' => '',
        ];
      }
    }

    return $matched;
  }

  /**
   * decodeStateEarlyVotingHdr
   *
   * Verify that the "Assigned Voter ID" and "Ballot Received Date" columns needed
   * for the state early voting file are present and save the column number.
   *
   * Returns an array for finding the column value in the following records. The keys are:
   *   pos - array with the column number for each field
   *   err - array of error messages for the fields not present
   *   ok - true if all fields are present
   *
   * @param array $fileHdr
   * @return array
   */
  public function decodeStateEarlyVotingHdr(array $fileHdr): array
  {
    $hdrErr = $hdrPos = [];
    foreach ($this->stateEarlyVotingHdr as $nlpKey => $vanField) {
      foreach ($fileHdr as $fileCol => $fileColName) {
        $fileColName = preg_replace('/[^A-Za-z0-9\-]/', '', $fileColName);
        if (trim($fileColName) == $vanField['name']) {
          $hdrPos[$nlpKey] = $fileCol;
          break;
        }
      }
      if (!isset($hdrPos[$nlpKey])) {
        $hdrErr[$nlpKey] = $this->stateEarlyVotingHdr[$nlpKey]['err'];
      }
    }
    return ['pos' => $hdrPos, 'err' => $hdrErr, 'ok' => empty($hdrErr)];
  }

  /**
   * Returns all voters matching precinct and lastName, with vanid and firstName.
   *
   * @param string $precinct
   * @param string $lastName
   * @return array
   */
  private function fetchByPrecinctAndLastName(string $precinct, string $lastName): array
  {
    try {
      $query = $this->connection->select(self::VOTER_TBL, 'v');
      $query->join(self::VOTER_ADDRESS_TBL, 'a', 'a.vanid = v.vanid');
      $query->addField('v', 'vanid');
      $query->addField('v', 'firstName');
      $query->condition('v.lastName', $lastName);
      $query->condition('a.precinct', $precinct);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('NlpEarlyVoting fetchByPrecinctAndLastName', $e->getMessage());
      return [];
    }

    $voters = [];
    while ($voter = $result->fetchAssoc()) {
      $voters[] = $voter;
    }
    return $voters;
  }

}
