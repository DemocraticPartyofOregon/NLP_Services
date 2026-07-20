<?php

namespace Drupal\nlpservices\Config;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpDocuments;
use Drupal\nlpservices\NlpPaths;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class DocumentConfigurationForm extends FormBase {

  protected NlpDocuments $documentsObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $filesystem;

  public function __construct($documentsObj, $pathsObj, $filesystem) {
    $this->documentsObj = $documentsObj;
    $this->pathsObj = $pathsObj;
    $this->filesystem = $filesystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.documents'),
      $container->get('nlpservices.paths'),
      $container->get('file_system'),
    );
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
      '#markup' => '<p>Click the Save Configuration button to initialize the
 training documents for the coordinators.</p>',
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
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $this->prepareDocuments();
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * prepareDocuments
   *
   * @return void
   */
  private function prepareDocuments(): void
  {
    $sourceDir = DRUPAL_ROOT . '/' . $this->pathsObj->getPath('DEFAULT_DOCS');
    $destUri = $this->pathsObj->getPath('DOCS');

    $defaultDocuments = $this->documentsObj->nameList;

    $documentFiles = scandir($sourceDir);
    if ($documentFiles === FALSE) {
      $this->messenger()->addWarning($this->t('Default documents are missing'));
      return;
    }

    $defaultFiles = [];
    foreach ($documentFiles as $documentFile) {
      if ($documentFile == '.' || $documentFile == '..') { continue; }
      if (is_dir($sourceDir . $documentFile)) { continue; }
      $pathParts = pathinfo($documentFile);
      if (strtolower($pathParts['extension'] ?? '') !== 'docx') { continue; }
      $stem = $pathParts['filename'];
      foreach ($this->documentsObj->nameList as $info) {
        $defaultRoot = $info['defaultFilename'];
        if (stripos($stem, $defaultRoot) === 0) {
          $defaultFiles[$defaultRoot] = $stem;
          break;
        }
      }
    }

    if (empty($defaultFiles)) {
      $this->messenger()->addWarning($this->t('Default documents are missing'));
      return;
    }

    $this->filesystem->prepareDirectory($destUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    foreach ($defaultDocuments as $name => $defaultDocument) {
      $defaultFilename = $defaultDocument['defaultFilename'];
      if (isset($defaultFiles[$defaultFilename])) {
        $doc = $defaultFiles[$defaultFilename].'.docx';
        $pdf = $defaultFiles[$defaultFilename].'.pdf';
        $this->filesystem->copy($sourceDir.$doc, $destUri.$doc, \Drupal\Core\File\FileExists::Replace);
        if (file_exists($sourceDir.$pdf)) {
          $this->filesystem->copy($sourceDir.$pdf, $destUri.$pdf, \Drupal\Core\File\FileExists::Replace);
        }
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
