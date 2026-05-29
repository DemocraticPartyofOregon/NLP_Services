<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpDocuments;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpPaths;

/**
 * @noinspection PhpUnused
 */
class DocumentsForm extends FormBase
{

  protected NlpDocuments $documentsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $filesystem;

  public function __construct( $documentsObj, $drupalUserObj, $pathsObj, $filesystem)
  {
    $this->documentsObj = $documentsObj;
    $this->drupalUserObj = $drupalUserObj;
    $this->pathsObj = $pathsObj;
    $this->filesystem = $filesystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DocumentsForm
  {
    return new static(
      $container->get('nlpservices.documents'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.paths'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_documents_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $adminUser = $this->drupalUserObj->isNlpAdminUser();
    if($adminUser) {

      $form['new_documents'] = [
        '#title' => $this->t('Upload a new set of documents'),
        '#prefix' => " \n".'<div style="width:310px;">'." \n",
        '#suffix' => " \n".'</div>'." \n",
        '#type' => 'fieldset',
      ];
      $documentList = $this->documentsObj->displayList();

      $form['new_documents']['document_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Select the document type to upload.'),
        '#options' => $documentList,
        '#required' => TRUE,
      ];
      $form['#attributes']['enctype'] = 'multipart/form-data';

      $form['new_documents']['new_docx'] = [
        '#type' => 'file',
        '#title' => $this->t('The editable document'),
        '#required' => TRUE,
      ];

      $form['new_documents']['new_pdf'] = [
        '#type' => 'file',
        '#title' => $this->t('The same document in PDF format'),
        '#required' => TRUE,
      ];

      $form['new_documents']['submit'] = [
        '#type' => 'submit',
        '#name' => 'document_submit',
        '#value' => $this->t('Submit the new documents >>'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $editableFile = $files['new_docx'] ?? NULL;
    $pdfFile = $files['new_pdf'] ?? NULL;
    $editableExt = $editableFile ? strtolower(pathinfo($editableFile->getClientOriginalName(), PATHINFO_EXTENSION)) : '';
    $pdfExt = $pdfFile ? strtolower(pathinfo($pdfFile->getClientOriginalName(), PATHINFO_EXTENSION)) : '';
    if($editableExt != 'docx' && $editableExt != 'doc') {
      $form_state->setErrorByName('new_docx', $this->t('The editable document must be in docx or doc format.'));
    }
    if($pdfExt != 'pdf') {
      $form_state->setErrorByName('new_pdf', $this->t('The PDF document must be in PDF format.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $uploadFiles = [
      'docx' => [
        'name' => $files['new_docx']->getClientOriginalName(),
        'tmp'  => $files['new_docx']->getRealPath(),
      ],
      'pdf' => [
        'name' => $files['new_pdf']->getClientOriginalName(),
        'tmp'  => $files['new_pdf']->getRealPath(),
      ],
    ];
    $documentType = $form_state->getValue('document_type');
    $this->updateDocumentsFile($documentType, $uploadFiles);
  }

  /**
   * updateDocumentsFile
   *
   * Move the files to the public directory and remember the name.
   * Delete the previous file if it exists.
   *
   * @param string $documentType
   * @param array $uploadFiles
   */
  private function updateDocumentsFile(string $documentType, array $uploadFiles): void
  {
    $documents = $this->documentsObj->getDocuments();
    $replacedDocuments = $documents[$documentType];
    $replacedDocx = $replacedDocuments['docFileName'];
    $replacedPdf = $replacedDocuments['pdfFileName'];

    $localPath = $this->pathsObj->getPath('DOCS');
    $this->filesystem->unlink($localPath.$replacedDocx);
    $this->filesystem->unlink($localPath.$replacedPdf);

    $docx = $uploadFiles['docx']['name'];
    $docxTmp = $uploadFiles['docx']['tmp'];
    $pdf = $uploadFiles['pdf']['name'];
    $pdfTmp = $uploadFiles['pdf']['tmp'];

    $this->filesystem->moveUploadedFile($docxTmp, $localPath.$docx);
    $this->filesystem->moveUploadedFile($pdfTmp, $localPath.$pdf);

    $nameList = $this->documentsObj->nameList[$documentType];
    $req = [
      'name' => $documentType,
      'weight' => $nameList['weight'],
      'docFileName' => $docx,
      'pdfFileName' => $pdf,
      'description' => $nameList['description'],
      'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
      'missing' => FALSE,
    ];
    $this->documentsObj->createDocument($req);
  }
}
