<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpDocuments;
use Drupal\nlpservices\NlpPaths;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class FoldersConfigurationForm extends FormBase {

  protected NlpPaths $pathsObj;
  protected NlpDocuments $documentsObj;
  protected FileSystemInterface $fileSystem;

  public function __construct($configFactory, $pathsObj, $documentsObj, $fileSystem) {
    $this->configFactory = $configFactory;
    $this->pathsObj = $pathsObj;
    $this->documentsObj = $documentsObj;
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
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

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $uploadedFile = ($this->getRequest()->files->get('files', []))['counties_names'] ?? NULL;
    if ($uploadedFile === NULL || !$uploadedFile->isValid()) {
      $form_state->setErrorByName('counties_names',
        $this->t('Please select a valid file to upload.'));
      return;
    }
    $extension = strtolower($uploadedFile->getClientOriginalExtension());
    if (!in_array($extension, ['yml', 'yaml'])) {
      $form_state->setErrorByName('counties_names',
        $this->t('The county names file must be a yml or yaml type.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $uploadedFile = ($this->getRequest()->files->get('files', []))['counties_names'] ?? NULL;
    if ($uploadedFile === NULL || !$uploadedFile->isValid()) {
      $this->messenger()->addError($this->t('No valid file was uploaded.'));
      return;
    }

    $counties = Yaml::decode(file_get_contents($uploadedFile->getRealPath()));
    if (!is_array($counties)) {
      $this->messenger()->addError($this->t('The county names file could not be parsed.'));
      return;
    }

    $this->configFactory->getEditable('nlpservices.configuration')
      ->set('nlpservices-county-names', $counties)
      ->save();

    unset($counties['State']);
    ksort($counties);

    $this->pathsObj->createDir('TEMP', '');
    $this->pathsObj->createDir('NLP', '');
    $this->pathsObj->createDir('TURF', '');

    $this->prepareLogo();

    $countyNames = array_keys($counties);
    $subFolders = ['INST', 'PDF', 'TURF'];
    foreach ($countyNames as $countyName) {
      $this->pathsObj->createDir('COUNTY', $countyName);
      foreach ($subFolders as $subFolder) {
        $this->pathsObj->createDir($subFolder, $countyName);
      }
    }

    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $magicWordsFile = $modulePath . '/src/Config/magic_words.yml';
    $magicWords = Yaml::decode(file_get_contents($magicWordsFile));
    $this->configFactory->getEditable('nlpservices.configuration')
      ->set('nlpservices-magic-words', $magicWords)
      ->save();

    $this->messenger()->addStatus($this->t('The configuration has been saved.'));
  }

  private function prepareLogo(): void
  {
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);

    $nlp_logo = 'nlp_logo.png';
    $nlp_favicon = 'nlp_favicon.ico';

    $imageFiles = scandir($modulePath . '/img/');
    if (empty($imageFiles)) {
      $this->messenger()->addWarning($this->t('Default image files are missing.'));
      return;
    }

    $source = $modulePath . '/img/' . $nlp_logo;
    $destination = 'public://' . $nlp_logo;
    $this->fileSystem->copy($source, $destination, FileExists::Replace);

    $source = $modulePath . '/img/' . $nlp_favicon;
    $destination = 'public://' . $nlp_favicon;
    $this->fileSystem->copy($source, $destination, FileExists::Replace);
  }

}
