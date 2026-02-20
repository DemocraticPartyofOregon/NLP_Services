<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpDocuments;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpPaths;
use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @noinspection PhpUnused
 */
class DocumentsForm extends FormBase
{
  
  protected NlpDocuments $documentsObj;
  protected DrupalUser $drupalUserObj;
  protected NlpPaths $pathsObj;
  protected FileSystemInterface $filesystem;
  
  public function __construct( $documentsObj, $drupalUserObj, $pathsObj,  $filesystem)
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
        '#title' => 'Upload a new set of documents',
        '#prefix' => " \n".'<div style="width:310px;">'." \n",
        '#suffix' => " \n".'</div>'." \n",
        '#type' => 'fieldset',
      ];
      $documentList = $this->documentsObj->displayList();
      
      $form['new_documents']['document_type'] = [
        '#type' => 'select',
        '#title' => t('Select the document type to upload.'),
        '#options' => $documentList,
        '#required' => TRUE,
      ];
      $form['#attributes']['enctype'] = 'multipart/form-data';
      
      $form['new_documents']['new_docx'] = [
        '#type' => 'file',
        '#title' => 'The editable document',
        '#required' => TRUE,
      ];
      
      $form['new_documents']['new_pdf'] = [
        '#type' => 'file',
        '#title' => 'The same document in PDF format',
        '#required' => TRUE,
      ];
      
      $form['new_documents']['submit'] = [
        '#type' => 'submit',
        '#name' => 'document_submit',
        '#value' => 'Submit the new documents >>'
      ];
    }
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $editableDocument = $_FILES['files']['name']['new_docx'];
    $pdfDocument = $_FILES['files']['name']['new_pdf'];
    $editableExt = $editableDocument ? strtolower(pathinfo($editableDocument, PATHINFO_EXTENSION)) : '';
    $pdfExt = $pdfDocument ? strtolower(pathinfo($pdfDocument, PATHINFO_EXTENSION)) : '';
    if($editableExt != 'docx' && $editableExt != 'doc') {
      $form_state->setErrorByName('new_docx', 'The editable document must be in docx or doc format.');
    }
    if($pdfExt != 'pdf') {
      $form_state->setErrorByName('new_pdf', 'The PDF document must be in PDF format.');
    }
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    
    $uploadFiles['docx']['name'] = $_FILES['files']['name']['new_docx'];
    $uploadFiles['docx']['tmp'] = $_FILES['files']['tmp_name']['new_docx'];
    $uploadFiles['pdf']['name'] = $_FILES['files']['name']['new_pdf'];
    $uploadFiles['pdf']['tmp'] = $_FILES['files']['tmp_name']['new_pdf'];
    $documentType = $form_state->getValue('document_type');
    $this->updateDocumentsFile($documentType, $uploadFiles);
    
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * updateDocumentsFile
   *
   * Move the files to the public directory and remember the name.
   * Delete the previous file if it exists.
   *
   * @param string $documentType
   * @param array $uploadFiles
   */
  function updateDocumentsFile(string $documentType, array $uploadFiles): void
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
    $date = '';
    $ctime = time();
    $timezone = 'UTC';
    try {
      $dt = (new DateTimeImmutable())->setTimestamp($ctime)->setTimezone(new DateTimeZone($timezone));
      $date = $dt->format('Y-m-d\TH:i:s\Z');
    } catch (DateInvalidTimeZoneException $e) {
      nlp_debug_msg('$e',$e->getMessage());
    }
    
    $req = [
      'name' => $documentType,
      'weight' => $nameList['weight'],
      'docFileName' => $docx,
      'pdfFileName' => $pdf,
      'description' => $nameList['description'],
      'createDate' => $date,
      'missing' => FALSE,
    ];
    $this->documentsObj->createDocument($req);
  }
}
