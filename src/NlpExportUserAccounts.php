<?php

namespace Drupal\nlpservices;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpExportUserAccounts
{
  protected NlpFileFunctions $fileFunctionsObj;
  protected DrupalUser $drupalUser;
  protected MagicWord $magicWord;
  protected NlpNls $nls;
  protected ApiNls $apiNlsObj;

  public function __construct(
    $fileFunctionsObj,
    $drupalUser,
    $magicWord,
    $nls,
    $apiNlsObj,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  )
  {
    $this->fileFunctionsObj = $fileFunctionsObj;
    $this->drupalUser = $drupalUser;
    $this->magicWord = $magicWord;
    $this->nls = $nls;
    $this->apiNlsObj = $apiNlsObj;
  }

  public static function create(ContainerInterface $container): NlpExportUserAccounts
  {
    return new static(
      $container->get('nlpservices.file_functions'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.api_nls'),
      $container->get('file_url_generator'),
    );
  }

  public function getUserAccountsFile(): string
  {
    $result = $this->fileFunctionsObj->createTempCsvFile('drupal_users');
    if ($result === false) {
      return 'Oops!';
    }
    ['uri' => $userUri, 'fh' => $fh] = $result;

    $counties = $this->drupalUser->getCounties();
    $first = TRUE;
    foreach ($counties as $county) {
      $users = $this->drupalUser->getUsers($county);
      foreach ($users as $user) {
        if (!empty($user['mcid'])) {
          $mcid = $user['mcid'];
          $nl = $this->nls->getNlById($mcid);
          $hdNlp = empty($nl) ? 'Unknown' : $nl['hd'];
          $newHd = $this->resolveHd($user['hd'], $hdNlp, $mcid, $user['uid']);
          if (!empty($newHd)) {
            $user['hd'] = $newHd;
          }
          $user['password'] = $this->magicWord->getMagicWord($user['mcid']);
        } else {
          $user['password'] = 'unknown';
        }
        $roleStr = '';
        foreach ($user['roles'] as $roleId => $role) {
          $roleStr .= $roleId . ':' . $role . ';';
        }
        $user['roles'] = $roleStr;
        if ($first) {
          fputcsv($fh, array_keys($user), escape: '');
          $first = FALSE;
        }
        fputcsv($fh, $user, escape: '');
      }
    }
    fclose($fh);
    $userUrl = $this->fileUrlGenerator->generateAbsoluteString($userUri);
    $output = "<p>The file is a list of NLP users in CSV format.  It is used to move user accounts and passwords
to a new site.</p>";
    $output .= '<p> <a href="' . $userUrl . '">Right-click to download the user accounts file. </a></p>';
    return $output;
  }

  public function resolveHd(string $hdDrupal, string $hdNlp, int $mcid, int $uid): string
  {
    $updateDrupal = FALSE;
    $hdNew = '';
    if ($hdDrupal === 'Unknown') {
      if ($hdNlp !== 'Unknown') {
        if (!empty($hdNlp)) {
          $updateDrupal = TRUE;
          $hdNew = $hdNlp;
        } else {
          $nlRecord = $this->apiNlsObj->getApiNls('', $mcid);
          if (empty($nlRecord) || empty($nlRecord['hd'])) {
            $hdNew = 'Unknown'; // This NL seems to be missing from VoteBuilder.
          } else {
            $hdNew = $nlRecord['hd'];
          }
          $updateDrupal = TRUE;
        }
      }
    } elseif (!empty($hdDrupal)) {
      if ($hdNlp !== 'Unknown' && !empty($hdNlp)) {
        if ($hdDrupal !== $hdNlp) {
          $hdNew = $hdNlp;
          $updateDrupal = TRUE;
        }
      }
    } else {
      // The Drupal login has an empty HD, probably created before the HD field was created.
      if ($hdNlp === 'Unknown') {
        $nlRecord = $this->apiNlsObj->getApiNls('', $mcid);
        if (empty($nlRecord) || empty($nlRecord['hd'])) {
          $hdNew = 'Unknown'; // This NL seems to be missing from VoteBuilder.
        } else {
          $hdNew = $nlRecord['hd'];
        }
      } elseif (empty($hdNlp)) {
        $hdNew = 'Unknown';
      } else {
        $hdNew = $hdNlp;
      }
      $updateDrupal = TRUE;
    }

    if ($updateDrupal) {
      $this->drupalUser->updateUser(['uid' => $uid, 'hd' => $hdNew]);
    }
    return $hdNew;
  }

}
