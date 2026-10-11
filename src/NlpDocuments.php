<?php

namespace Drupal\nlpservices;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpDocuments
{
  const string DOCUMENTS_TBL = "nlp_documents";

  private array $documentsList = [
    'name' => 'name',
    'weight' => 'weight',
    'docFileName' => 'docFileName',
    'pdfFileName' => 'pdfFileName',
    'description' => 'description',
    'missing' => 'missing',
    'createDate' => '',
  ];

  public array $nameList = [
    'nlInstruct' => ['description' =>'Neighborhood Leader Instructions - canvass','weight'=>10,
      'defaultFilename' => 'InstructionsCanvass'],
    'nlPostcard' => ['description' =>'Neighborhood Leader Instructions - postcard','weight'=>20,
      'defaultFilename' => 'InstructionsPostcard'],
    'nlList' => ['description' =>'Managing the list of Active NLs','weight'=>30,
      'defaultFilename' => 'ActiveNLList'],
    'cutTurf' => ['description' =>'Cutting turf','weight'=>40,
      'defaultFilename' => 'CuttingTurf'],
    'deliverTurf' => ['description' =>'Sending a turf to an NL','weight'=>50,
      'defaultFilename' => 'TurfDelivery'],
    'committeeCoordinator' => ['description' =>'Defining the coordinators','weight'=>60,
      'defaultFilename' => 'CommitteeCoordinator'],
    'editEmail' => ['description' =>'Edit turf delivery email','weight'=>70,
      'defaultFilename' => 'EditTurfEmail'],
    'nlpConfiguration' => ['description' =>'NLP Configuration','weight'=>80,
      'defaultFilename' => 'NlpConfiguration'],
  ];

  protected Connection $connection;
  protected NlpPaths $pathsObj;

  public function __construct(
    $connection,
    $pathsObj,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    $this->connection = $connection;
    $this->pathsObj = $pathsObj;
  }

  public static function create(ContainerInterface $container): NlpDocuments
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.paths'),
      $container->get('file_url_generator'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * createDocument
   *
   * Inserts or updates a document record in the database table.
   *
   * @param array $req An associative array containing the document details:
   *                    - 'name': The name of the document.
   *                    - 'weight': The weight associated with the document.
   *                    - 'docFileName': The filename of the document file.
   *                    - 'pdfFileName': The filename of the PDF file.
   *                    - 'description': A description for the document.
   *                    - 'createDate': The creation date of the document.
   *
   * @return void
   */
  public function createDocument(array $req): void
  {
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
      nlp_debug_msg('createDocument', $e);
      return;
    }
  }

  /**
   * getDocuments
   *
   * Retrieves a list of documents from the database and organizes them into an associative array.
   *
   * The method fetches records from the database table, processes them to map database keys
   * to application-friendly keys, and sorts the documents based on their weight and name in ascending order.
   *
   * @return array An associative array of documents where each key corresponds to the document's name.
   *               Each document contains its attributes such as:
   *                - 'name': The name of the document.
   *                - 'weight': The weight associated with the document.
   *                - Additional document attributes as defined in the database mapping.
   */
  public function getDocuments(): array
  {
    $dbList = array_flip($this->documentsList);
    $query = $this->connection->select(self::DOCUMENTS_TBL, 'd')
      ->fields('d');
    try {
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getDocuments', $e);
      return [];
    }

    $docs = [];
    foreach ($result as $record) {
      $doc = [];
      foreach ($record as $dbKey => $value) {
        if (isset($dbList[$dbKey]) && $dbList[$dbKey] !== '') {
          $doc[$dbList[$dbKey]] = $value;
        }
      }
      $docs[$doc['name']] = $doc;
    }
    if (empty($docs)) {return $docs;}
    $name   = array_column($docs, 'name');
    $weight = array_column($docs, 'weight');
    array_multisort($weight, SORT_ASC, $name, SORT_ASC, $docs);
    return $docs;
  }

  /**
   * displayList
   *
   * Retrieves a list of document descriptions from the nameList property.
   *
   * @return array An array containing the descriptions of documents.
   */
  public function displayList(): array
  {
    return array_map(function ($documentInfo) {
      return $documentInfo['description'];
    }, $this->nameList);
  }

  /**
   * buildDocumentDisplay
   *
   * Builds and returns a structured document display array.
   *
   * This method generates a display structure for documents, including
   * their name, description, and downloadable links for both DOCX and PDF
   * files. If no documents are available, it returns a message indicating
   * the absence of documents. The method constructs a table formatted for
   * rendering within a form or a page.
   *
   * @return array
   *   A renderable array containing the document display. The structure
   *   includes headers, rows for documents, and file download links. If no
   *   documents are found, a markup message is returned instead.
   */
  public function buildDocumentDisplay(): array
  {
    $documents = $this->getDocuments();
    if (empty($documents)) {
      $page['documents'] = [
          '#markup' => 'The documents are missing.'
      ];
      return $page;
    }

    $docPath = $this->pathsObj->getPath('DOCS');
    $page['document_form'] = [
      '#title' => 'Available NLP Documents.  (Right click the link to download the document.)',
      '#type' => 'fieldset',
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    $header = [
      'name' => 'Name',
      'description' => 'Description',
      'doc' => 'Docx',
      'pdf' => 'PDF',
    ];

    $rows = [];
    foreach ($documents as $document) {

      if (!empty($document['docFileName'])) {
        $docxUri = $docPath . $document['docFileName'];
        $docUrl = $this->fileUrlGenerator->generateAbsoluteString($docxUri);
        $doc = '(<a href="'.$docUrl.'">'.$document['docFileName'].'</a>) ';
      } else {
        $doc = '-';
      }

      if (!empty($document['pdfFileName'])) {
        $pdfUri = $docPath . $document['pdfFileName'];
        $pdfUrl = $this->fileUrlGenerator->generateAbsoluteString($pdfUri);
        $pdf = '(<a href="'.$pdfUrl.'">'.$document['pdfFileName'].'</a>) ';
      } else {
        $pdf = '-';
      }

      $name = $document['name'];
      $description = (!empty($document['description'])) ? $document['description'] :
        $this->nameList[$name]['description'];

      $row = [
        'name' => $name,
        'description' => $description,
        'doc' => ['data' => ['#markup' => $doc]],
        'pdf' => ['data' => ['#markup' => $pdf]],
      ];
      $rows[$document['name']] = $row;
    }

    $page['document_form']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => t('No documents found.'),
      '#attributes' => [
        'class' => [ 'nlp-doc-table', ],
      ],
      '#attached' => [
        'library' => [
          'nlpservices/doc-table',
        ],
      ],
    ];
    return $page;
  }

  /**
   * verifyDefaultDocuments
   *
   * Verifies the existence of default documents and identifies any that are missing.
   *
   * This method checks if the default documents specified in the configuration
   * are present in the current state. It compares the list of default expected
   * documents with the ones currently available, validates document files within
   * the module's directory, and returns a list of missing document names.
   *
   * @return array
   *   An array of missing document names. If all default documents are
   *   available, the returned array will be empty.
   */
  public function verifyDefaultDocuments(): array
  {
    $modulePath = $this->moduleExtensionList->getPath(NLP_MODULE);
    $defaultDocuments = $this->nameList;
    $currentDocuments = $this->getDocuments();
    $missingDocuments = [];
    foreach ($currentDocuments as $currentDocument) {
      if (isset($defaultDocuments[$currentDocument['name']])) {
        unset($defaultDocuments[$currentDocument['name']]);
      }
    }
    $documentFiles = scandir($modulePath.'/docs/');
    if (empty($documentFiles)) {
      return array_keys($defaultDocuments);
    }
    $defaultFiles = [];
    foreach ($documentFiles as $documentFile) {
      if (!is_dir($modulePath.'/docs/'.$documentFile)) {
        $path_parts = pathinfo($documentFile);
        if ($path_parts['extension'] === 'pdf' || $path_parts['extension'] === 'docx') {
          $filename = $path_parts['filename'];
          $root_parts = explode('_',$filename);
          $defaultFiles[$root_parts[0]] = $filename;
        }
      }
    }

    foreach ($defaultDocuments as $name => $defaultDocument) {
      $defaultFilename = $defaultDocument['defaultFilename'];
      if (!isset($defaultFiles[$defaultFilename])) {
        $missingDocuments[] = $name;
      }
    }
    return $missingDocuments;
  }

  /**
   * buildDefaultFilesInfo
   *
   * Builds a detailed array of file information based on available file names
   * and predefined name configuration.
   *
   * @param array $availableFileNames An array of available file names for processing.
   * @return array An array of file information records, where each record contains
   *               metadata such as file names, weight, description, and other details.
   */
  public function buildDefaultFilesInfo(array $availableFileNames): array
  {
    $filesInfo = [];
    foreach ($this->nameList as $name => $info) {
      $defaultRoot = $info['defaultFilename'] ?? '';
      if ($defaultRoot === '') {
        continue;
      }

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

      foreach ($matchesByRoot as $root => $files) {
        $record = [];
        foreach ($this->documentsList as $mappedKey) {
          if ($mappedKey === '') {
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
