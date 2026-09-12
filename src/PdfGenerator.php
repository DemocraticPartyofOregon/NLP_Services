<?php

namespace Drupal\nlpservices;

use Drupal\Core\File\FileExists;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\DependencyInjection\ContainerInterface;

class PdfGenerator {

  public function __construct(
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('file.repository'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * generatePdf
   *
   * Generates a PDF from the provided HTML content and saves it to a temporary file.
   *
   * @param string $html
   *   The HTML content to convert to PDF.
   * @param string $tempFile
   *   The path to the temporary file where the PDF will be saved.
   *
   * @return string
   *   The URL of the generated PDF file.
   */
  public function generatePdf(string $html, string $tempFile): string
  {
    $mpdf = new Mpdf(
      [
        'orientation' => 'L',
        'margin_left'   => 6.5,
        'margin_right'  => 6.5,
        'margin_top'    => 12,
        'margin_bottom' => 12,
      ]);
    $mpdf->WriteHTML($html);
    // Create a Drupal temporary file to store the PDF.  It's deleted in a few days.
    $file = $this->fileRepository->writeData('Placeholder', $tempFile, FileExists::Replace);
    if ($file) {
      $file->setTemporary();
      $file->save();
      $file_uri = $file->getFileUri();
    }
    else {
      nlp_debug_msg('PdfGenerator','Failed to create temporary file: ');
      return '';
    }
    $mpdf->Output($file_uri, Destination::FILE);
    // Return a URL for a hyperlink to the PDF file.
    return $this->fileUrlGenerator->generateAbsoluteString($file_uri);
  }
  
}