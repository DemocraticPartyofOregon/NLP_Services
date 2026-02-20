<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfDeliveryMessage
{
  const string TURF_MSG_TEMPLATE = 'TurfDeliveryMessageTemplate.html';
  const string TURF_DEFAULT_TEMPLATE = 'TurfDeliveryMessageTemplateDefault.html';
  
  protected FileSystemInterface $fileSystem;
  protected NlpPaths $paths;
  
  public function __construct($fileSystem, $paths) {
    $this->fileSystem = $fileSystem;
    $this->paths = $paths;
  }
  
  public static function create(ContainerInterface $container): NlpTurfDeliveryMessage
  {
    return new static(
      $container->get('file.system'),
      $container->get('nlpservices_paths'),
    );
  }
  
  /**
   * unlinkFile
   *
   * Delete the custom email template file.
   *
   * @param string $path
   * @return void
   */
  private function unlinkFile(string $path): void
  {
    $fullName = $path . self::TURF_MSG_TEMPLATE;
    if(file_exists($fullName)) {
      $this->fileSystem->unlink($fullName);}
  }
  
  /**
   * getTurfMsg
   *
   * Return the custom email template if it has been created, or for a county, get the custom state
   * template.  If there is no state template, return the default email template.
   *
   * @param $county - County name or and empty string if state template is desired.
   * @return string
   */
  public function getTurfMsg($county=''): string
  {
    $filePath = $this->paths->getPath('TURF',$county);
    $fileName = $filePath.self::TURF_MSG_TEMPLATE;
    if(!file_exists($fileName)) {
      if(!empty($county)) {
        $filePath = $this->paths->getPath('TURF','');
        $fileName = $filePath.self::TURF_MSG_TEMPLATE;
      }
    }
    if(!file_exists($fileName)) {
      $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
      $fileName = $modulePath.'/src/Templates/'.self::TURF_DEFAULT_TEMPLATE;
      if(!file_exists($fileName)) {
        nlp_debug_msg('Turf email template missing');
        return '';
      }
    }
    
    $fh = fopen($fileName, "r");
    if(empty($fh)) {
      nlp_debug_msg('open failed', '');
      return '';
    }
    $turfMsgTemplate = fread($fh, filesize($fileName));
    if ($turfMsgTemplate === false) {
      nlp_debug_msg('read failed', '');
      return '';
    }
    return $turfMsgTemplate;
  }
  
  /**
   * putTurfMsg
   *
   * Save the custom email template.
   *
   * @param $county
   * @param $turfMsgTemplate
   * @return void
   */
  public function putTurfMsg($county,$turfMsgTemplate): void
  {
    if(empty($county)) {
      $statePath = $this->paths->getPath('TURF','');
      $this->unlinkFile($statePath);
      $stateFileName = $statePath.self::TURF_MSG_TEMPLATE;
      $fh = fopen($stateFileName, "w");
      if(empty($fh)) {
        nlp_debug_msg('open failed', '');
        return;
      }
      
      $fWrite = fwrite($fh, $turfMsgTemplate);
      if ($fWrite === false) {
        nlp_debug_msg('write failed', '');
      }
    } else {
      $countyPath = $this->paths->getPath('TURF',$county);
      $this->unlinkFile($countyPath);
      $countyFileName = $countyPath.self::TURF_MSG_TEMPLATE;
      $fh = fopen($countyFileName, "w");
      if(empty($fh)) {
        nlp_debug_msg('open failed', '');
      } else {
        $fWrite = fwrite($fh, $turfMsgTemplate);
        if ($fWrite === false) {
          nlp_debug_msg('write failed', '');
        }
      }
    }
  }
  
}
