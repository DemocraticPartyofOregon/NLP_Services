<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handles a file uploaded through a plain '#type' => 'file' form field.
 *
 * The import forms use a plain file field, not managed_file, so no file
 * entity is saved.  On a site with the file_entity module, saving one fails
 * with "The referenced entity (file_type: undefined) does not exist."
 *
 * A form using this trait must add
 * $form['#attributes']['enctype'] = 'multipart/form-data' in buildForm().
 */
trait PlainFileUploadTrait
{

  /**
   * Nullable with a container fallback, so a form object cached before this
   * property existed still works after a deploy.
   */
  protected ?FileSystemInterface $fileObj = NULL;

  /**
   * Returns the file uploaded in the named file field, or NULL if none.
   */
  protected function getUploadedFile(string $fieldName): ?UploadedFile
  {
    $files = $this->getRequest()->files->get('files', []);
    $file = $files[$fieldName] ?? NULL;
    return ($file instanceof UploadedFile) ? $file : NULL;
  }

  /**
   * Returns the lower-case extension of the uploaded file's original name.
   */
  protected function uploadedFileType(UploadedFile $file): string
  {
    return strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
  }

  /**
   * Moves the uploaded file into public://temp so a batch can read it.
   *
   * PHP deletes the uploaded file when the request ends, so a batch, which
   * runs in later requests, needs its own copy.  The batch deletes it when
   * it finishes.
   *
   * @return string|null
   *   The new URI, or NULL if the move failed.
   */
  protected function moveUploadToTemp(UploadedFile $file): ?string
  {
    $fileSystem = $this->fileObj ?? Drupal::service('file_system');
    $tempDir = 'public://temp';
    $fileSystem->prepareDirectory($tempDir, FileSystemInterface::CREATE_DIRECTORY);
    $fileUri = $tempDir . '/' . basename($file->getClientOriginalName());
    if (!$fileSystem->moveUploadedFile($file->getRealPath(), $fileUri)) {
      return NULL;
    }
    return $fileUri;
  }

}
