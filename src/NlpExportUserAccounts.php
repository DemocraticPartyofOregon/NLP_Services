<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpExportUserAccounts
{
  protected FileSystemInterface $fileSystem;
  protected Connection $connection;
  protected DrupalUser $drupalUser;
  protected MagicWord $magicWord;
  protected NlpNls $nls;
  protected ApiNls $apiNlsObj;
  
  public function __construct( $fileSystem,$connection,$drupalUser,$magicWord,$nls, $apiNlsObj ) {
    $this->fileSystem = $fileSystem;
    $this->connection = $connection;
    $this->drupalUser = $drupalUser;
    $this->magicWord = $magicWord;
    $this->nls = $nls;
    $this->apiNlsObj = $apiNlsObj;
  }

  public static function create(ContainerInterface $container): NlpExportUserAccounts
  {
    return new static(
      $container->get('file.system'),
      $container->get('database'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.api_nls'),
    );
  }
  
  /**
   * @return string - HTML with a link to a file with information to recreate user accounts.
   */
  public function getUserAccountsFile(): string
  {
    $output = '';
    $temp_dir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s',time());
    $userUri = $temp_dir.'/'.'drupal_users'.'-'.$contactDate.'.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $userUri, FileExists::Replace);
    } catch (EntityStorageException $e) {
      nlp_debug_msg('File create failed.',$e->getMessage());
      return 'Oops!';
    }
    $file->setTemporary();
    try {
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('error', $e->getMessage() );
      return '';
    }
    $fh = fopen($userUri,"w");
    $counties = $this->drupalUser->getCounties();
    $first = TRUE;
    foreach ($counties as $county) {
      $users = $this->drupalUser->getUsers($county);
      foreach ($users as $user) {
        if(!empty($user['mcid'])) {
          $mcid = $user['mcid'];
          $nl = $this->nls->getNlById($mcid);
          $hdNlp = (empty($nl))?'Unknown':$nl['hd'];
          $newHd = $this->resolveHd($user['hd'],$hdNlp,$mcid,$user['uid']);
          if(!empty($newHd)) {
            $user['hd'] = $newHd;
          }
          $password = $this->magicWord->getMagicWord($user['mcid']);
          $user['password'] = $password;
        } else {
          //$user['hd'] = '';
          $user['password'] = 'unknown';
        }
        $roleStr = '';
        foreach ($user['roles'] as $roleId => $role) {
          $roleStr .= $roleId.':'.$role.';';
        }
        $user['roles'] = $roleStr;
        if($first) {
          $keys = array_keys($user);
          fputcsv($fh, $keys);
          $first = FALSE;
        }
        fputcsv($fh, $user);
      }
    }
    fclose($fh);
    $userUrl = Drupal::service('file_url_generator')->generateAbsoluteString($userUri);
    $output .= "<p>The file is a list of NLP users in CSV format.  It is used to move user accounts and passwords
to a new site.</p>";
    $output .= '<p> <a href="'.$userUrl.'">Right-click to download the user accounts file. </a></p>';
    return $output;
  }
  
  /**
   * @param $hdDrupal
   * @param $hdNlp
   * @param $mcid
   * @param $uid
   * @return string
   */
  function resolveHd($hdDrupal,$hdNlp,$mcid,$uid): string
  {
    $updateDrupal = FALSE;
    $hdNew = '';
    if($hdDrupal=='Unknown') {
      // The Drupal login does not have the HD, probably means the lookup of the HD failed in the past.
      if ($hdNlp != 'Unknown') {
        //  The list of active NLs does not have this NL.
        if (!empty($hdNlp)) {
          // The HD is known so update the Drupal record.
          $updateDrupal = TRUE;
          $hdNew = $hdNlp;
        } else {
          // Look up the NL in VoteBuilder to get the HD.
          
          $nlRecord = $this->apiNlsObj->getApiNls(NULL, $mcid);
          if (empty($nlRecord) or empty($nlRecord['hd'])) {
            $hdNew = 'Unknown';  // This NL seems to be missing from VoteBuilder.
          } else {
            $hdNew = $nlRecord['hd'];  // This NL seems to be missing from VoteBuilder.
          }
          $updateDrupal = TRUE;
        }
      }
    } elseif (!empty($hdDrupal)) {
      // The login record is not empty, so the HD was known in the past.
      if ($hdNlp != 'Unknown' and !empty($hdNlp)) {
          // We have a known value for the NL's HD.
          if ($hdDrupal != $hdNlp) {
            // The NL's HD is new, update Drupal login record.
            $hdNew = $hdNlp;  // The HD of this NL changed
            $updateDrupal = TRUE;
          }
        }
        
    } else {
      //  The Drupal login has an empty HD, probably created before the HD field was created.
      if($hdNlp=='Unknown') {
        //  This NL is not active this cycle.
        // API lookup.
        $nlRecord = $this->apiNlsObj->getApiNls(NULL,$mcid);
        if(empty($nlRecord) OR empty($nlRecord['hd'])) {
          $hdNew = 'Unknown';  // This NL seems to be missing from VoteBuilder.
        } else {
          $hdNew = $nlRecord['hd'];  // This NL seems to be missing from VoteBuilder.
        }
      } elseif(empty($hdNlp)) {
        $hdNew = 'Unknown';  // The HD of this NL can't be resolved from the geo data.
      } else {
        $hdNew = $hdNlp;  // The HD of this NL can't be resolved from the geo data.
      }
      $updateDrupal = TRUE;
    }
    
    if($updateDrupal) {
      $editUpdate = [
        'uid' => $uid,
        'hd' => $hdNew,
      ];
      $this->drupalUser->updateUser($editUpdate);
    }
    return $hdNew;
  }
}
