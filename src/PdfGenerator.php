<?php

namespace Drupal\nlpservices;

require_once DRUPAL_ROOT . '/../vendor/autoload.php';

use Drupal;
use Drupal\Core\File\FileExists;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class PdfGenerator {
  
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
    $file_repository = Drupal::service('file.repository');
    $file = $file_repository->writeData('Placeholder', $tempFile, FileExists::Replace);
    if ($file) {
      $file_uri = $file->getFileUri();
    }
    else {
      nlp_debug_msg('ExportNlsParticipationForm','Failed to create temporary file: ');
      return '';
    }
    $mpdf->Output($file_uri, Destination::FILE);
    // Return a URL for a hyperlink to the PDF file.
    return Drupal::service('file_url_generator')->generateAbsoluteString($file_uri);
  }
  
}