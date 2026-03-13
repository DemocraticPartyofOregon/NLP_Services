<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpPaths;
use Drupal\nlpservices\NlpDocuments;

/**
 * @noinspection PhpUnused
 */
class FoldersConfigurationForm extends ConfigFormBase {
  
  protected NlpPaths $pathsObj;
  protected NlpDocuments $documentsObj;
  
  protected FileSystemInterface $fileSystem;
  
  
  public function __construct( $config_factory, $pathsObj, $documentsObj, $fileSystem) {
    parent::__construct($config_factory);
    $this->pathsObj = $pathsObj;
    $this->documentsObj = $documentsObj;
    $this->fileSystem = $fileSystem;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ConfigFormBase|FoldersConfigurationForm|static
  {
    return new static(
      $container->get('config.factory'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.documents'),
      $container->get('file_system'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array
  {
    return ['nlpservices.configuration'];
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'folders_configuration_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['counties_names'] = [
      '#type' => 'file',
      '#title' => $this->t('YAML file with county names'),
      '#description' => $this->t('Please provide a file of county names for your state.'),
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
    
    
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $file_name = $_FILES['files']['name']['counties_names'];
    $file_name_lc = strtolower($file_name);
    $file_name_parts = explode('.', $file_name_lc);
    $file_type_extension = end($file_name_parts);
    $allowed = array('yml','yaml');
    if (!in_array($file_type_extension, $allowed)) {
      $form_state->setErrorByName('counties_names',
        $this->t('The county names file must be a yml or yaml type.'));
    }
    parent::validateForm($form, $form_state);
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $counties_names = $_FILES['files']['tmp_name']['counties_names'];
    $counties = (array) yaml_parse_file($counties_names);
    
    $this->config('nlpservices.configuration')
      ->set('nlpservices-county-names', $counties)
      ->save();
    unset($counties['State']);
    ksort($counties);
  
    $this->pathsObj->createDir('TEMP','');
    $this->pathsObj->createDir('NLP','');
    $this->pathsObj->createDir('TURF','');
    
    $this->prepareLogo();
   
    // Create the directories for the counties.
    $countyNames = array_keys($counties);
    $subFolders = array('INST','PDF','TURF');
    foreach ($countyNames as $countyName) {
      $this->pathsObj->createDir('COUNTY',$countyName);
      foreach ($subFolders as $subFolder) {
        $this->pathsObj->createDir($subFolder,$countyName);
      }
    }
    
    // Populate the magic words to be used to create simple passwords for the NLs.
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $magicWordsFile = $modulePath.'/src/Config/magic_words.yml';
    $magicWords = yaml_parse_file($magicWordsFile);
    $this->config('nlpservices.configuration')
      ->set('nlpservices-magic-words', $magicWords)
      ->save();
    
    parent::submitForm($form, $form_state);
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * prepareLogo
   *
   * Moves the logo and favicon files to the public folder.  This makes the configuration of
   * Drupal easier.
   *
   */
  function prepareLogo(): void
  {
    $messenger = Drupal::messenger();
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    
    $nlp_logo = 'nlp_logo.png';
    $nlp_favicon = 'nlp_favicon.ico';
    
    $imageFiles = scandir($modulePath.'/img/');
    if(empty($imageFiles)) {
      $messenger->addWarning( $this->t('Default image files are missing.'));
      return;
    }
    
    $source = $modulePath.'/img/'.$nlp_logo;
    $destination = 'public://'.$nlp_logo;
    $this->fileSystem->copy($source,$destination,FileSystemInterface::EXISTS_REPLACE);
    
    $source = $modulePath.'/img/'.$nlp_favicon;
    $destination = 'public://'.$nlp_favicon;
    $this->fileSystem->copy($source,$destination,FileSystemInterface::EXISTS_REPLACE);
    
  }
  
}
