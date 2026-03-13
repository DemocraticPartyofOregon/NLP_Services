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
class DocumentConfigurationForm extends ConfigFormBase {

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
    return 'document_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['counties_names'] = [
        '#markup' => '<p>Click the Save Configuration button to initialized the 
 training documents for the coordinators.</p>',
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
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {

    // Populate the documents folders with the default documents.
    $this->prepareDocuments();

    parent::submitForm($form, $form_state);
  }

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * prepareDocuments
   *
   * Moves the default documents from the nlpservices module to a location in the
   * public files and adds the new location in the database.  This makes
   * the documents easier to access by coordinators.
   */
  function prepareDocuments(): void
  {
    $messenger = Drupal::messenger();
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    // The default documents are the ones that are expected to be delivered with the
    // module and can be found in a folder with the module.
    $defaultDocuments = $this->documentsObj->nameList;
    // The current are the ones that have already been moved from the module to the public files.
    $currentDocuments = $this->documentsObj->getDocuments();
    // Remove from the list of default documents those that are not already in the database.
    // What remains is anything that is missing.  Note that the actual filenames may have
    // changed if the admin made updates after installation.  These updates should
    // not be overwritten.
    foreach ($currentDocuments as $currentDocument) {
      if(isset($defaultDocuments[$currentDocument['name']])) {
        unset($defaultDocuments[$currentDocument['name']]);
      }
    }
    // Check if the docs folder in the module is empty.  This should never happen.
    $documentFiles = scandir($modulePath.'/docs/');

    foreach ($documentFiles as $index => $documentFile) {
      if($documentFile == '.' || $documentFile == '..') {
        unset($documentFiles[$index]);
      }
    }
    if(empty($documentFiles)) {
      $messenger->addWarning( $this->t('Default documents are missing'));
      return;
    }


    // Find the docx files in the modules docs folder and remember the root names.
    // The PDF files have the same root name as the docx files.
    $defaultFiles = [];
    foreach ($documentFiles as $documentFile) {
      if(!is_dir($modulePath.'/docs/'.$documentFile)) {
        $pathParts = pathinfo($documentFile);
        if($pathParts['extension'] == 'docx') {
          $filename = $pathParts['filename'];
          $filenameParts = explode('-', $filename);
          $fileNameIndex = $filenameParts[0];
          $defaultFiles[$fileNameIndex] = $filename;
        }
      }
    }

    foreach ($defaultDocuments as $name => $defaultDocument) {
      $defaultFilename = $defaultDocument['defaultFilename'];
      if(isset($defaultFiles[$defaultFilename])) {
        $doc = $defaultFiles[$defaultFilename].'.docx';
        $pdf = $defaultFiles[$defaultFilename].'.pdf';
        $document = [
          'name' => $name,
          'weight' => $defaultDocument['weight'],
          'docFileName' => $doc,
          'pdfFileName' => $pdf,
          'description' => $defaultDocument['description'],
          'createDate' => date('Y-m-d'),
        ];
        $this->documentsObj->createDocument($document);
      }
    }
  }

}
