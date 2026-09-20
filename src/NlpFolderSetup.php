<?php

namespace Drupal\nlpservices;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

class NlpFolderSetup
{
  use StringTranslationTrait;

  protected NlpConfiguration $nlpConfigurationObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $fileSystem;
  protected MessengerInterface $messenger;
  protected ModuleExtensionList $moduleExtensionList;

  public function __construct($nlpConfigurationObj, $pathsObj, $fileSystem, $messenger, $moduleExtensionList, $stringTranslation) {
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->pathsObj = $pathsObj;
    $this->fileSystem = $fileSystem;
    $this->messenger = $messenger;
    $this->moduleExtensionList = $moduleExtensionList;
    $this->stringTranslation = $stringTranslation;
  }

  public function initializeFolders(array $countyNames): void
  {
    $this->pathsObj->createDir('TEMP', '');
    $this->pathsObj->createDir('NLP', '');
    $this->pathsObj->createDir('TURF', '');

    $this->prepareLogo();

    $subFolders = ['INST', 'PDF', 'TURF'];
    foreach ($countyNames as $countyName) {
      $this->pathsObj->createDir('COUNTY', $countyName);
      foreach ($subFolders as $subFolder) {
        $this->pathsObj->createDir($subFolder, $countyName);
      }
    }

    $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);
    $magicWordsFile = $modulePath . '/src/Config/magic_words.yml';
    $magicWords = Yaml::decode(file_get_contents($magicWordsFile));
    $this->nlpConfigurationObj->set('nlpservices-magic-words', $magicWords);

    $this->messenger->addStatus($this->t('The folders have been initialized.'));
  }

  private function prepareLogo(): void
  {
    $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);

    $nlpLogo = 'nlp_logo.png';
    $nlpFavicon = 'nlp_favicon.ico';

    $imageFiles = scandir($modulePath . '/img/');
    if (empty($imageFiles)) {
      $this->messenger->addWarning($this->t('Default image files are missing.'));
      return;
    }

    try {
      $source = $modulePath . '/img/' . $nlpLogo;
      $destination = 'public://' . $nlpLogo;
      $this->fileSystem->copy($source, $destination, FileExists::Replace);

      $source = $modulePath . '/img/' . $nlpFavicon;
      $destination = 'public://' . $nlpFavicon;
      $this->fileSystem->copy($source, $destination, FileExists::Replace);
    } catch (FileException $e) {
      nlp_debug_msg('prepareLogo', $e->getMessage());
      $this->messenger->addWarning($this->t('The default logo and favicon could not be copied to the public files directory; it may not be configured. Folder setup will continue.'));
    }
  }
}
