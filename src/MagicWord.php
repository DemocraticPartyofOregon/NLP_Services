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
    //nlp_debug_msg('>>> setMagicWord',$magicWord);
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
    //nlp_debug_msg('$credentials', $credentials );
    if(empty($credentials)) {return [];}
    //nlp_debug_msg('$decrypted', $decrypted );
    if($decrypted) {
      if(!empty($credentials['magicWordEncrypted'])) {
        $key = hash('sha256', self::KEY);
        $iv = substr(hash('sha256', self::IV), 0, 16);
        $pw = openssl_decrypt(base64_decode($credentials['magicWordEncrypted']), "AES-256-CBC", $key, 0, $iv);
        
        $credentials['magicWord'] = $pw;
      }
      unset($credentials['magicWordEncrypted']);
    }
    
    $credentials['roles'] = (!empty($credentials['roles']))?json_decode($credentials['roles'], true):'';
    //nlp_debug_msg('$credentials', $credentials );
    return $credentials;
  }
  
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
  
  public function getCredentialsByUsername($username): array
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
  
  public function updateCredentials($credentials): bool
  {
    /*
    //nlp_debug_msg('>>> updateCredentials', $credentials );
    if(!empty($credentials['roles'])) {
      $credentials['roles'] = json_encode($credentials['roles']);
    }
    */
    if(!empty($credentials['magicWord'])) {
      $key = hash('sha256', self::KEY);
      $iv = substr(hash('sha256', self::IV), 0, 16);
      $output = openssl_encrypt($credentials['magicWord'], "AES-256-CBC", $key, 0, $iv);
      $credentials['magicWordEncrypted'] = base64_encode($output);
      unset($credentials['magicWord']);
    }
    $mcid = $credentials['mcid'];
    //unset($credentials['mcid']);
    
    //if(empty($credentials['access'])) {$credentials['access']=0;}
    //if(empty($credentials['created'])) {$credentials['created']=0;}
    //nlp_debug_msg('>>> updateCredentials, mcid:'.$mcid, $credentials );
    
    $current = $this->getCredentials($mcid,FALSE);
    /*
    $key = hash('sha256', self::KEY);
    $iv = substr(hash('sha256', self::IV), 0, 16);
    $output = openssl_encrypt($current['magicWord'], "AES-256-CBC", $key, 0, $iv);
    $current['magicWordEncrypted'] = base64_encode($output);
    unset($current['magicWord']);
    */
    //nlp_debug_msg('current',$current);
    foreach($credentials as $key => $value) {
      $current[$key] = $value;
    }
    
    if(!empty($current['roles'])) {
      $current['roles'] = json_encode($current['roles']);
    }
    //nlp_debug_msg('current',$current);
    
    $this->deleteCredentials($mcid);
    
    return $this->createCredentials($current);
  }
  
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
  
  public function createCredentials($credentials): bool
  {
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
  
  public function isUnique($userName): bool
  {
    try {
      $query = $this->connection->select(self::MAGIC_WORD_TBL, 'm');
      $query->addField('m', 'userName');
      $query->condition('userName',$userName);
      $result = $query->execute();
    }
    catch (Exception $e) {
      nlp_debug_msg('updateCredentials', $e->getMessage() );
      return FALSE;
    }
    $inUse = $result->fetchAssoc();
    if(empty($inUse)) {return TRUE;}
    return FALSE;
  }
  
  /**
   * @param $firstName
   * @param $middleName
   * @param $lastName
   * @param $nickName
   * Is used in SyncActiveNlsBatch.php
   * @return string
   */
  public function defaultUserName($firstName,$middleName,$lastName,$nickName): string
  {
    //nlp_debug_msg('$middleName', $middleName );
    $loginName = (!empty($nickName)) ? $nickName: $firstName;
    $lcUsrName = strtolower("$loginName.$lastName");
    $userName = preg_replace('/-|\s+|&#0*39;|\'/', '', $lcUsrName);
    if($this->isUnique($userName)) {return $userName;}
    //nlp_debug_msg('$userName',$userName);
    $middleInitial = (!empty($middleName)) ? substr($middleName,0,1): '';
    //nlp_debug_msg('$middleInitial',$middleInitial);
    $altLoginName = substr($firstName, 0, 1).$middleInitial.$lastName;
    //nlp_debug_msg('$altLoginName',$altLoginName);
    $altLoginName = strtolower($altLoginName);
    $userName = preg_replace('/-|\s+|&#0*39;|\'/', '', $altLoginName);
    if($this->isUnique($userName)) {return $userName;}
    for ($i=1;$i<100;$i++) {
      $userNameI = $userName.$i;
      if($this->isUnique($userNameI)) {return $userNameI;}
    }
    return '';
  }
  
  public function uniqueUserName($nlRecord,$namesTried): string
  {
    //nlp_debug_msg('$nlRecord',$nlRecord);
    $middleInitial = (!empty($nlRecord['middleName'])) ? substr($nlRecord['middleName'],1): '';
    $altLoginName = substr($nlRecord['firstName'], 0, 1).$middleInitial.$nlRecord['lastName'];
    $altLoginName = strtolower($altLoginName);
    $userName = preg_replace('/-|\s+|&#0*39;|\'/', '', $altLoginName);
    //if($this->isUnique($userName)) {return $userName;}
    for ($i=1;$i<100;$i++) {
      $userNameI = $userName.$i;
      if(in_array($userName,$namesTried) ) {continue;}
      if($this->isUnique($userNameI)) {return $userNameI;}
    }
    return '';
  }
  
  public function emailIsUnique($email): bool
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
    //nlp_debug_msg('$credentials',$credentials);
    unset($credentials['roles'][NLP_COORDINATOR_ROLE_ID]);
    $this->createCredentials($credentials);
  }

}
