<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MagicWord {
  
  const string MAGIC_WORD_TBL = 'nlp_magic_word';

  const string KEY = 'In order to create a more perfect union';
  const string IV = 'Kamala Harris';

  // These fields are used to detect and update credentials when a change is made.
  const array CREDENTIAL_FIELDS = ['userName','email','magicWord','roles','access','created','vfid'];

  protected ConfigFactoryInterface $config;
  protected Connection $connection;
  
  public function __construct( $config,  $connection) {
    $this->config = $config;
    $this->connection = $connection;
  }
  
  public static function create(ContainerInterface $container): MagicWord
  {
    return new static(
      $container->get('config.factory'),
      $container->get('database'),
    );
  }
  
  /**
   * @return string - A manageable password for the NL.
   *
   * A random word from the list of 6-letter scrabble words with a random single digit number
   * suffix.  The number will not be 0 or 1 to avoid confusion with a letter.
   */
  public function createMagicWord(): string
  {
    $config = $this->config->get('nlpservices.configuration');
    $magicWords = $config->get('nlpservices-magic-words');
    $index = rand(1, count($magicWords))-1;
    $word = $magicWords[$index];
    $number = substr(str_shuffle('23456789'), 0, 1);
    return $word.$number;
  }
  
  /**
   * @param $mcid
   * @param $magicWord
   * @return bool - the NL's password is saved in an encrypted form.
   */
  public function setMagicWord($mcid,$magicWord): bool
  {
    $key = hash('sha256', self::KEY);
    $iv = substr(hash('sha256', self::IV), 0, 16);
    $output = openssl_encrypt($magicWord, "AES-256-CBC", $key, 0, $iv);
    $magicWordEncrypted = base64_encode($output);
    try {
      $this->connection->merge(self::MAGIC_WORD_TBL)
        ->keys(array('mcid' => $mcid))
        ->fields(array(
          //'magicWord' => $magicWord,
          'magicWordEncrypted' => $magicWordEncrypted,
        ))
        ->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('setMagicWord', $error );
      return FALSE;
    }
    return TRUE;
  }
  
  /**
   * @param $mcid
   * @return void
   */
  public function deleteMagicWord($mcid): void
  {
    try{
      $this->connection->delete(self::MAGIC_WORD_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('deleteMagicWord', $error );
    }
  }
  
  /**
   * @param $mcid
   * @return string - the decrypted password for an NL.
   */
  public function getMagicWord($mcid): string
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->addField('m', 'magicWordEncrypted');
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('getMagicWord', $error );
      return '';
    }
    $magicWordRecord = $result->fetchAssoc();
    if(empty($magicWordRecord)) {return '';}
    if(empty($magicWordRecord['magicWordEncrypted'])) {return '';}
    $key = hash('sha256', self::KEY);
    $iv = substr(hash('sha256', self::IV), 0, 16);
    return openssl_decrypt(base64_decode($magicWordRecord['magicWordEncrypted']), "AES-256-CBC", $key, 0, $iv);
  }
  
  /**
   * @return array
   */
  public function getKeys(): array
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->fields('m');
      $result = $query->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('getKeys', $error );
      return [];
    }
    $mcids = [];
    do {
      $mcid = $result->fetchAssoc();
      if(empty($mcid)) {break;}
      $mcids[] = $mcid['mcid'];
    } while (TRUE);
    return $mcids;
  }

  /**
   * createNewCredentials
   *
   * Create a new NL's credentials array.'
   *
   * @param int $mcid
   * @param string $userName
   * @param string $email
   * @return array
   */
  function createNewCredentials(int $mcid, string $userName, string $email): array
  {
    return [
      'mcid' => $mcid,
      'userName' => $userName,
      'email' => $email,
      'county' => '',
      'hd' => 0,
      'roles' => '',
      'access' => 0,
      'created' => 0,
      'vfid' => 0,
    ];

  }
  
  /**
   * @param $mcid
   * @param bool $decrypted
   * @return array
   */
  public function getCredentials($mcid, bool $decrypted=TRUE): array
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->fields('m');
      $query->condition('mcid',$mcid);
      $result = $query->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('getCredentials', $error );
      return [];
    }
    $credentials = $result->fetchAssoc();
    if(empty($credentials)) {return [];}
    if($decrypted) {
      if(!empty($credentials['magicWordEncrypted'])) {
        $key = hash('sha256', self::KEY);
        $iv = substr(hash('sha256', self::IV), 0, 16);
        $pw = openssl_decrypt(base64_decode($credentials['magicWordEncrypted']), "AES-256-CBC", $key, 0, $iv);
        $credentials['magicWord'] = $pw;
      } else {
        $credentials['magicWord'] = '';  // The password has been forgotten, probably a symptom of a past bug.
      }
      unset($credentials['magicWordEncrypted']);
    }
    
    $credentials['roles'] = (!empty($credentials['roles']))?json_decode($credentials['roles'], true):[];
    return $credentials;
  }

  /**
   * getCredentialsByEmail
   *
   * Retrieves user credentials by their email address.
   *
   * @param string $email The email address to search for in the database.
   * @return array An associative array of user credentials. Returns an empty array if no credentials are found or an error occurs.
   */
  public function getCredentialsByEmail(string $email): array
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->fields('m');
      $query->condition('email',$email);
      $result = $query->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('getCredentialsByEmail', $error );
      return [];
    }
    $credentials = $result->fetchAssoc();
    if(empty($credentials)) {return [];}
    unset($credentials['magicWordEncrypted']);
    $credentials['roles'] = json_decode($credentials['roles'], true);
    return $credentials;
  }

  /**
   * getCredentialsByUsername
   *
   * Retrieves user credentials by their MCID.
   *
   * @param string $username
   * @return array An associative array of user credentials. Returns an empty array if no credentials
   * are found or an error occurs.
   */
  public function getCredentialsByUsername(string $username): array
  {
    if(empty($username)) {return [];}
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->fields('m');
      $query->condition('username',$username);
      $result = $query->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('getCredentialsByUsername', $error );
      return [];
    }
    $credentials = $result->fetchAssoc();
    if(empty($credentials)) {return [];}
    unset($credentials['magicWordEncrypted']);
    $credentials['roles'] = json_decode($credentials['roles'], true);
    return $credentials;
  }


  /**
   * updateCredentials
   *
   * Update NL credentials in the database.
   * 1. If the NL has a nickname, use that. Otherwise, use the first name.
   * 2. Add the last name, separated by a period.
   * 3. If that username is already in use, use the first initial, middle initial, and last name.
   * 4. If that username is already in use, add a number suffix, starting with 2 and incrementing
   *      until a unique username is found or we reach 10 attempts.
   * 5. If the email is already in use, add a number suffix, starting with 2 and incrementing
   *      until a unique email is found or we reach 10 attempts.
   *
   * @param $credentials
   * @return bool
   */
  public function updateCredentials($credentials): bool
  {
    if(isset($credentials['magicWord']) && !empty($credentials['magicWord'])) {
      if(!empty($credentials['magicWord'])) {
        $key = hash('sha256', self::KEY);
        $iv = substr(hash('sha256', self::IV), 0, 16);
        $output = openssl_encrypt($credentials['magicWord'], "AES-256-CBC", $key, 0, $iv);
        $credentials['magicWordEncrypted'] = base64_encode($output);
      }
      unset($credentials['magicWord']);
    }
    $mcid = $credentials['mcid'];
    $current = $this->getCredentials($mcid,FALSE);
    foreach($credentials as $key => $value) {
      $current[$key] = $value;
    }
    
    if(!empty($current['roles'])) {
      $current['roles'] = json_encode($current['roles']);
    }
    $this->deleteCredentials($mcid);
    return $this->createCredentials($current);
  }

  /**
   * deleteCredentials
   *
   * Delete NL credentials from the database.
   *
   * @param $mcid
   * @return void
   */
  public function deleteCredentials($mcid): void
  {
    try{
      $this->connection->delete(self::MAGIC_WORD_TBL)
        ->condition('mcid', $mcid)
        ->execute();
    }
    catch (Exception $e) {
      $error = $e->getMessage();
      nlp_debug_msg('deleteCredentials', $error );
    }
  }

  /**
   * createCredentials
   *
   * Create NL credentials in the database.
   *
   * @param $credentials
   * @return bool
   */
  public function createCredentials($credentials): bool
  {
    if(isset($credentials['magicWord'])) {
      if(!empty($credentials['magicWord'])) {
        $key = hash('sha256', self::KEY);
        $iv = substr(hash('sha256', self::IV), 0, 16);
        $output = openssl_encrypt($credentials['magicWord'], "AES-256-CBC", $key, 0, $iv);
        $credentials['magicWordEncrypted'] = base64_encode($output);
      }
      unset($credentials['magicWord']);
    }
    if(!empty($credentials['roles'])) {
      $credentials['roles'] = json_encode($credentials['roles']);
    }
    $mcid = $credentials['mcid'];
    //unset($credentials['mcid']);
    try {
      $this->connection->merge(self::MAGIC_WORD_TBL)
        ->key('mcid', $mcid)
        ->fields($credentials)
        ->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('createCredentials', $e->getMessage() );
      return FALSE;
    }
    return TRUE;
  }

  /**
   * isUnique
   *
   * Check if a username is unique.
   *
   * @param string $userName
   * @param int $mcid
   * @return bool
   */
  private function isUnique( string $userName, int $mcid): bool
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->addField('m', 'userName');
      $query->addField('m', 'mcid');
      $query->condition('userName',$userName);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateCredentials', $e->getMessage() );
      return FALSE;
    }
    $inUse = $result->fetchAssoc();
    if(empty($inUse)) {return TRUE;}
    if($inUse['mcid'] == $mcid) {return TRUE;}
    return FALSE;
  }

  /**
   * uniqueUserName
   *
   * Create a unique username for the NL.
   * 1. If the NL has a nickname, use that. Otherwise, use the first name.
   * 2. Add the last name, separated by a period.
   * 3. If that username is already in use, use the first initial, middle initial, and last name.
   * 4. If that username is already in use, add a number suffix, starting with 2 and incrementing
   *      until a unique username is found or we reach 10 attempts.
   *
   * @param $nlRecord
   * @param $namesTried
   * @return string
   */
  public function uniqueUserName($nlRecord,&$namesTried): string
  {
    $userName = '';
    do {
      $tryCount = count($namesTried);
      switch ($tryCount) {
        case 0:
          $firstName = (!empty($nlRecord['nickName'])) ? $nlRecord['nickName'] : $nlRecord['firstName'];
          $loginName = $firstName . '.' . $nlRecord['lastName'];
          break;
        case 1:
          $middleInitial = (!empty($nlRecord['middleName'])) ? substr($nlRecord['middleName'], 0, 1) : '';
          $firstInitial = (!empty($nlRecord['firstName'])) ? substr($nlRecord['firstName'], 0, 1) : '';
          $loginName = $firstInitial . $middleInitial . $nlRecord['lastName'];
          break;
        default:
          if ($tryCount > 9) {
            $loginName = '';
            break;
          }
          $middleInitial = (!empty($nlRecord['middleName'])) ? substr($nlRecord['middleName'], 0, 1) : '';
          $firstInitial = (!empty($nlRecord['firstName'])) ? substr($nlRecord['firstName'], 0, 1) : '';
          $loginName = $firstInitial . $middleInitial . $nlRecord['lastName'] . $tryCount;
          break;
      }
      if (empty($loginName)) {
        break;
      }
      $lcUsrName = strtolower($loginName);
      $userName = preg_replace('/-|\s+|&#0*39;|\'/', '', $lcUsrName);
      $namesTried[] = $userName;
      if ($this->isUnique($userName, $nlRecord['mcid'])) {
        break;
      }

    } while (TRUE);

    return $userName;
  }

  /**
   * emailIsUnique
   *
   * Checks if the given email is unique in the database.
   *
   * @param string $email The email address to check for uniqueness.
   * @return bool Returns TRUE if the email is unique, FALSE otherwise.
   */
  public function emailIsUnique(string $email): bool
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->addField('m', 'email');
      $query->condition('email',$email);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('emailInUser', $e->getMessage() );
      return FALSE;
    }
    $inUse = $result->fetchAssoc();
    if(empty($inUse)) {return TRUE;}
    return FALSE;
  }
  
  public function getColumnNames(): array
  {
    try {
      $select = "SHOW COLUMNS FROM  {".self::MAGIC_WORD_TBL.'}';
      $result = $this->connection->query($select);
    }
    catch (Exception $e) {
      nlp_debug_msg('getColumnNames', $e->getMessage() );
      return [];
    }
    $colNames = [];
    do {
      $name = $result->fetchAssoc();
      if(empty($name)) {break;}
      $colNames[] = $name['Field'];
    } while (TRUE);
    return $colNames;
  }
  
  public function removeCoordinatorRole($mcid): void
  {
    $credentials = $this->getCredentials($mcid,FALSE);
    if(is_array($credentials['roles'])) {
      if(isset($credentials['roles'][NLP_COORDINATOR_ROLE_ID])) {
        unset($credentials['roles'][NLP_COORDINATOR_ROLE_ID]);
        $this->createCredentials($credentials);
      }
    }
  }

}
