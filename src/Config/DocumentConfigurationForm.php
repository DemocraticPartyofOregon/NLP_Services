<?php

namespace Drupal\nlpservices\Config;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpDocuments;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class DocumentConfigurationForm extends FormBase {

  protected NlpDocuments $documentsObj;

  public function __construct($documentsObj) {
    $this->documentsObj = $documentsObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('nlpservices.documents'),
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

  private function prepareDocuments(): void
  {
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $defaultDocuments = $this->documentsObj->nameList;
    $currentDocuments = $this->documentsObj->getDocuments();
    foreach ($currentDocuments as $currentDocument) {
      if (isset($defaultDocuments[$currentDocument['name']])) {
        unset($defaultDocuments[$currentDocument['name']]);
      }
    }
    $documentFiles = scandir($modulePath . '/docs/');

    if ($documentFiles === FALSE) {
      $this->messenger()->addWarning($this->t('Default documents are missing'));
      return;
    }

    foreach ($documentFiles as $index => $documentFile) {
      if ($documentFile == '.' || $documentFile == '..') {
        unset($documentFiles[$index]);
      }
    }
    if (empty($documentFiles)) {
      $this->messenger()->addWarning($this->t('Default documents are missing'));
      return;
    }

    $defaultFiles = [];
    foreach ($documentFiles as $documentFile) {
      if (!is_dir($modulePath . '/docs/' . $documentFile)) {
        $pathParts = pathinfo($documentFile);
        if ($pathParts['extension'] == 'docx') {
          $filename = $pathParts['filename'];
          $filenameParts = explode('-', $filename);
          $fileNameIndex = $filenameParts[0];
          $defaultFiles[$fileNameIndex] = $filename;
        }
      }
    }

    foreach ($defaultDocuments as $name => $defaultDocument) {
      $defaultFilename = $defaultDocument['defaultFilename'];
      if (isset($defaultFiles[$defaultFilename])) {
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
