<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpPaths {
  
  const string NLP_FILES = 'nlp_files';
  const string NLP_DOCUMENTS = 'nlp_documents';
  const string TURF_PDF_DIR = 'turf_pdf';
  const string INSTRUCTIONS_DIR = 'instructions';
  const string TURF_MESSAGE_DIR = 'turf_delivery_msg';
  
  protected FileSystemInterface $fileSystem;
  
  public function __construct( $fileSystem) {
    $this->fileSystem = $fileSystem;
  }
  
  public static function create(ContainerInterface $container): NlpPaths
  {
    return new static(
      $container->get('file.system'),
    );
  }
  
  /**
   * getPath
   *
   * Get the path for the specified type and committee.  The type can have the
   * following values: PDF, INST, DOCS, TURF.  The committee can be 'ALL' or a
   * specific committee name.  If the committee is 'ALL', the path is the root
   * directory for the specified type.  If the committee is a specific committee,
   * the path is the root directory for the specified type and committee.  If full
   * is TRUE, the path is the full URL to the file.  If full is FALSE, the path is
   * the path to the file relative to the Drupal root directory.
   *
   * @param string $type
   * @param string $county
   * @param bool $full
   * @return string
   */
  public function getPath(string $type, string $county='', bool $full=FALSE): string
  {
    $baseUrl = Drupal::request()->getSchemeAndHttpHost();
    $dir = $baseUrl.'/sites/default/files/'.self::NLP_FILES."/";
    if(!$full) {
      $dir = 'public://'.self::NLP_FILES."/";
    }
    if($county === 'ALL') {return $dir;}
    if(!empty($county)) {
      $dir .= $county.'/';
    }
    switch ($type) {
      case 'PDF':
        $dir .= self::TURF_PDF_DIR.'/';
        break;
      case 'INST':
        $dir .= self::INSTRUCTIONS_DIR.'/';
        break;
      case 'DOCS':
        $dir .= "docs/";
        break;
      case 'DEFAULT_DOCS':
        $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
        $dir = $modulePath.'/docs/';
        break;
      case 'TURF':
        $dir .= self::TURF_MESSAGE_DIR.'/';
        break;
      default:
        Drupal::logger('nlpservices')->notice('getPath: unknown type @type', ['@type' => $type]);
        break;
    }
    return $dir;
  }
  
  /**
   * createDir
   *
   * Create the specified directory.  The type can have the following values: TEMP,
   * NLP, DOCS, COMMITTEE, PDF, INST, TURF.  The committee can be 'ALL' or a specific
   * committee name.  If the committee is 'ALL', the directory is created in the
   * specified type directory.  If the committee is a specific committee, the directory
   * is created in the specified type and committee directory.
   *
   * @param string $type
   * @param string $county
   * @return void
   */
  public function createDir(string $type,string $county): void
  {
    switch ($type) {
      case 'TEMP':
        $dir = 'public://temp';
        break;
      case 'NLP':
        $dir = 'public://'.self::NLP_FILES;
        break;
      case 'DOCS':
        $dir = 'public://'.self::NLP_DOCUMENTS;
        break;
      case 'COUNTY':
        $dir = 'public://'.self::NLP_FILES."/".$county;
        break;
      case 'PDF':
        $dir = 'public://'.self::NLP_FILES."/".$county."/".self::TURF_PDF_DIR;
        break;
      case 'INST':
        $dir = 'public://'.self::NLP_FILES."/".$county."/".self::INSTRUCTIONS_DIR;
        break;
      case 'TURF':
        $dir = 'public://'.self::NLP_FILES."/".$county."/".self::TURF_MESSAGE_DIR;
        break;
      default:
        Drupal::logger('nlpservices')->notice('createDir: unknown type @type', ['@type' => $type]);
        return;
    }
    $this->fileSystem->prepareDirectory($dir,FileSystemInterface::CREATE_DIRECTORY |
      FileSystemInterface::MODIFY_PERMISSIONS);
  }
}
