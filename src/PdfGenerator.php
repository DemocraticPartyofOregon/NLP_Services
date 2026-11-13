<?php

namespace Drupal\nlpservices;

use Drupal\Core\File\FileExists;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Exception;
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
   * @param string $orientation
   *   The page orientation: 'L' for landscape or 'P' for portrait.
   *
   * @return string
   *   The URL of the generated PDF file, or '' if it could not be created.
   */
  public function generatePdf(string $html, string $tempFile, string $orientation = 'L'): string
  {
    // Both mPDF (MpdfException) and the file save (EntityStorageException) can throw.
    try {
      // mPDF's default scratch directory is inside vendor/, which the web
      // server user can't write to on a hardened site.  Use the system temp.
      $mpdf = new Mpdf(
        [
          'tempDir' => self::mpdfTempDir(),
          'orientation' => $orientation,
          'margin_left'   => 6.5,
          'margin_right'  => 6.5,
          'margin_top'    => 12,
          'margin_bottom' => 12,
        ]);
      $mpdf->WriteHTML($html);
      // Create a Drupal temporary file to store the PDF.  It's deleted in a few days.
      $file = $this->fileRepository->writeData('Placeholder', $tempFile, FileExists::Replace);
      $file->setTemporary();
      $file->save();
      $file_uri = $file->getFileUri();
      $mpdf->Output($file_uri, Destination::FILE);
    }
    catch (Exception $e) {
      nlp_debug_msg('PdfGenerator', $e);
      return '';
    }
    // Return a URL for a hyperlink to the PDF file.
    return $this->fileUrlGenerator->generateAbsoluteString($file_uri);
  }

  /**
   * mpdfTempDir
   *
   * The scratch directory for mPDF, in the system temp directory.  mPDF
   * creates it if it doesn't exist.
   *
   * @return string
   */
  public static function mpdfTempDir(): string
  {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mpdf';
  }
  
}