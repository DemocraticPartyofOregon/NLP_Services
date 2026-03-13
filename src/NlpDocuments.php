<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpDocuments
{
  const string DOCUMENTS_TBL = "nlp_documents";
  
  private array $documentsList = array(
    'name' => 'name',
    'weight' => 'weight',
    'docFileName' => 'docFileName',
    'pdfFileName' => 'pdfFileName',
    'description' => 'description',
    'missing' => 'missing',
    'createDate' => '',
  );
  
  public array $nameList = array(
    'nlInstruct' => array('description' =>'Neighborhood Leader Instructions - canvass','weight'=>10,
      'defaultFilename' => 'InstructionsCanvass'),
    'nlPostcard' => array('description' =>'Neighborhood Leader Instructions - postcard','weight'=>20,
      'defaultFilename' => 'InstructionsPostcard'),
    'nlList' => array('description' =>'Managing the list of Active NLs','weight'=>30,
      'defaultFilename' => 'ActiveNLList'),
    'cutTurf' => array('description' =>'Cutting turf','weight'=>40,
      'defaultFilename' => 'CuttingTurf'),
    'deliverTurf' => array('description' =>'Sending a turf to an NL','weight'=>50,
      'defaultFilename' => 'TurfDelivery'),
    'committeeCoordinator' => array('description' =>'Defining the coordinators','weight'=>60,
      'defaultFilename' => 'CommitteeCoordinator'),
    'editEmail' => array('description' =>'Edit turf delivery email','weight'=>70,
      'defaultFilename' => 'EditTurfEmail'),
  );
  
  protected Connection $connection;
  protected NlpPaths $pathsObj;
  
  public function __construct( $connection, $pathsObj ) {
    $this->connection = $connection;
    $this->pathsObj = $pathsObj;
  }
  
  public static function create(ContainerInterface $container): NlpDocuments
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.paths'),
    );
  }
  
  /**
   * createDocument
   *
   *
   * @param array $req
   * @return void
   */
  public function createDocument(array $req): void
  {
    nlp_debug_msg('$req',$req);
    try {
      $this->connection->merge(self::DOCUMENTS_TBL)
        ->keys([
          'name' => $req['name'],])
        ->fields([
          'weight' => $req['weight'],
          'docFileName' => $req['docFileName'],
          'pdfFileName' => $req['pdfFileName'],
          'description' => $req['description'],
          'missing' => 0,
          'createDate' => $req['createDate']])
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createDocument',$e->getMessage());
      return;
    }
  }
  
  /**
   * getDocuments
   *
   * @return array
   */
  public function getDocuments(): array
  {
    $dbList = array_flip($this->documentsList);
    $query = $this->connection->select(self::DOCUMENTS_TBL, 'd')
      ->fields('d');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDocuments',$e->getMessage());
      return [];
    }
    
    $docs =  [];
    do {
      $record = $result->fetchAssoc();
      if(empty($record)) {break;}
      $doc = [];
      foreach ($record as $dbKey => $value) {
        if(!empty($dbList[$dbKey])) {
          $doc[$dbList[$dbKey]] = $value;
        }
      }
      $docs[$doc['name']] = $doc;
    } while (TRUE);
    if(empty($docs)) {return $docs;}
    $name  = array_column($docs, 'name');
    $weight = array_column($docs, 'weight');
    array_multisort($weight, SORT_ASC, $name, SORT_ASC, $docs);
    return $docs;
  }
  
  /**
   * @return array
   */
  public function  displayList(): array
  {
    return array_map(function ($documentInfo) {
      return $documentInfo['description'];
    }, $this->nameList);
  }
  
  /**
   * buildDocumentDisplay
   *
   *
   * @return array
   */
  function buildDocumentDisplay(): array
  {
    $documents = $this->getDocuments();
    if(empty($documents)) {
      $page['documents'] = [
          '#markup' => 'The documents are missing.'
      ];
      return $page;
    }
    
    $docPath = $this->pathsObj->getPath('DOCS');
    $page['document_form'] = array(
      '#title' => 'Available NLP Documents.  (Right click the link to download the document.)',
      '#type' => 'fieldset',
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    );
    
    $header = [
      'name' => 'Name',
      'description' => 'Description',
      'doc' => 'Docx',
      'pdf' => 'PDF',
    ];
    
    $rows = [];
    foreach ($documents as $document) {
      
      if(!empty($document['docFileName'])) {
        $docxUri = $docPath . $document['docFileName'];
        $docUrl = Drupal::service('file_url_generator')->generateAbsoluteString($docxUri);
        $doc = '(<a href="'.$docUrl.'">'.$document['docFileName'].'</a>) ';
      } else {
        $doc = '-';
      }
      
      if(!empty($document['pdfFileName'])) {
        $pdfUri = $docPath . $document['pdfFileName'];
        //$pdfUrl = file_create_url($pdfUri);
        $pdfUrl = Drupal::service('file_url_generator')->generateAbsoluteString($pdfUri);
        $pdf = '(<a href="'.$pdfUrl.'">'.$document['pdfFileName'].'</a>) ';
      } else {
        $pdf = '-';
      }
      
      $name = $document['name'];
      $description =(!empty($document['description']))?$document['description']:
        $this->nameList[$name]['description'];
      
      $row = [
        'name' => $name,
        'description' => $description,
        'doc' => t($doc),
        'pdf' => t($pdf),
      ];
      $rows[$document['name']] = $row;
    }
    
    $page['document_form']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => t('No documents found.'),
      // Add CSS classes to the table element.
      '#attributes' => [
        'class' => [ 'nlp-doc-table', ],
      ],
      // Attach a Drupal library that contains your CSS (recommended).
      '#attached' => [
        'library' => [
          'nlpservices/doc-table',
        ],
      ],
    ];
    return $page;
  }
  
  public function verifyDefaultDocuments(array $defaultDocuments): array
  {
    
    //$documentsDir = $this->pathsObj->getPath('DEFAULT_DOCS','');
    
    
    
    
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $defaultDocuments = $this->nameList;
    $currentDocuments = $this->getDocuments();
    $missingDocuments = array();
    foreach ($currentDocuments as $currentDocument) {
      if(isset($defaultDocuments[$currentDocument['name']])) {
        unset($defaultDocuments[$currentDocument['name']]);
      }
    }
    $documentFiles = scandir($modulePath.'/docs/');
    if(empty($documentFiles)) {
      return array_keys($defaultDocuments);
    }
    $defaultFiles = array();
    foreach ($documentFiles as $documentFile) {
      if(!is_dir($modulePath.'/docs/'.$documentFile)) {
        $path_parts = pathinfo($documentFile);
        if($path_parts['extension']=='pdf' OR $path_parts['extension']=='docx') {
          $filename = $path_parts['filename'];
          $root_parts = explode('_',$filename);
          $defaultFiles[$root_parts[0]] = $filename;
        }
      }
    }
    
    foreach ($defaultDocuments as $name => $defaultDocument) {
      $defaultFilename = $defaultDocument['defaultFilename'];
      if(!isset($defaultFiles[$defaultFilename])) {
        $missingDocuments[] = $name;
      }
    }
    return $missingDocuments;
  }
  
  
  
  public function buildDefaultFilesInfo(array $availableFileNames): array
  {
    $filesInfo = [];
    // For each defined document type look for files that contain its defaultFilename.
    foreach ($this->nameList as $name => $info) {
      $defaultRoot = $info['defaultFilename'] ?? '';
      if ($defaultRoot === '') {
        continue;
      }
      
      // Group matching filenames by their filename root (filename without extension).
      $matchesByRoot = [];
      foreach ($availableFileNames as $file) {
        if (stripos($file, $defaultRoot) === false) {continue;}
        $root = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($matchesByRoot[$root])) {
          $matchesByRoot[$root] = ['docx' => '', 'pdf' => ''];
        }
        if ($ext === 'docx') {
          $matchesByRoot[$root]['docx'] = $file;
        } elseif ($ext === 'pdf') {
          $matchesByRoot[$root]['pdf'] = $file;
        }
      }
      
      // Convert each root group into a record using keys from the documentsList.
      foreach ($matchesByRoot as $root => $files) {
        $record = [];
        // Initialize keys from the documentsList (use mapped values as keys).
        foreach ($this->documentsList as $mappedKey) {
          if ($mappedKey === '') {
            // If you want to include an empty mapped key name (like createDate), set it explicitly.
            continue;
          }
          $record[$mappedKey] = '';
        }
        
        $record['name'] = $name;
        $record['weight'] = $info['weight'] ?? 0;
        $record['docFileName'] = $files['docx'] ?? '';
        $record['pdfFileName'] = $files['pdf'] ?? '';
        $record['description'] = $info['description'] ?? '';
        $record['missing'] = false;
        $record['createDate'] = '';
        
        // Ensure the top-level key (prefix) is unique if duplicates of the root exist.
        $prefix = $root;
        $unique = $prefix;
        $i = 1;
        while (isset($filesInfo[$unique])) {
          $unique = $prefix . '_' . $i++;
        }
        $filesInfo[$unique] = $record;
      }
    }
    
    return $filesInfo;
  }
}
