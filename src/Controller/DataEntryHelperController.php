<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\NlpDataEntryHelper;
use Drupal\nlpservices\NlpDataEntryPrint;

/**
 * @noinspection PhpUnused
 */
class DataEntryHelperController extends ControllerBase
{
  protected NlpDataEntryHelper $dataEntryHelperObj;
  protected NlpDataEntryPrint $dataEntryPrintObj;
  protected PrivateTempStoreFactory $tempStore;

  public function __construct( $dataEntryHelperObj, $dataEntryPrintObj, $tempStore) {
    $this->dataEntryHelperObj = $dataEntryHelperObj;
    $this->dataEntryPrintObj = $dataEntryPrintObj;
    $this->tempStore = $tempStore;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): DataEntryHelperController|static
  {
    return new static(
      $container->get('nlpservices.data_entry_helper'),
      $container->get('nlpservices.data_entry_print'),
      $container->get('tempstore.private')
    );
  }
  
  /**
   * Data_entry_helper
   *
   * Assembles the help instructions for the data entry form.  Returns information as
   * a render array.
   *
   * @return array
   * @noinspection PhpUnused
   * used in route
   */
  public function data_entry_helper(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $output = [
      '#markup' => $this->dataEntryHelperObj->dataEntryHelp(),
    ];
    $output['#attached']['library'][] = 'nlpservices/data-entry-help';
    return $output;
  }
  
  /**
   * Data_entry_print
   *
   * Assembles the help instructions for the data entry print form.  Returns information as
   * a render array.
   *
   * @return array
   * @noinspection PhpUnused
   * used in route
   */
  public function data_entry_print(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $output = [
      '#markup' => $this->dataEntryPrintObj->dataEntryPrint(),
      '#prefix' => '<div class="link-box">',
      '#suffix' => '</div>',
    ];
    $output['#attached']['library'][] = 'nlpservices/data-entry-help';
    return $output;
  }
  
  /**
   * Printable_calling_page
   *
   * Generates a PDF GOTV call list for the given turf index.  The output is a render array with a
   * link to download the PDF.
   *
   * @param $turfIndex
   * @noinspection PhpUnused
   * used in route
   * @return array
   */
  public function printable_calling_page($turfIndex): array
  {
    $voters = $this->dataEntryPrintObj->getVoters($turfIndex);
    $output = $this->dataEntryPrintObj->buildCallList($voters);
    Drupal::service("page_cache_kill_switch")->trigger();
    $output['#attached']['library'][] = 'nlpservices/data-entry-help';
    return $output;
  }
  
  /**
   * Printable_mailing_page
   *
   * Generates a PDF mailing list for the given turf index.  The output is a render array with a
   * link to download the PDF.
   *
   * @param $turfIndex
   * @noinspection PhpUnused
   * used in route
   * @return array
   */
  public function printable_mailing_page($turfIndex): array
  {
    $voters = $this->dataEntryPrintObj->getVoters($turfIndex);
    $output = $this->dataEntryPrintObj->buildMailingList($voters);
    Drupal::service("page_cache_kill_switch")->trigger();
    $output['#attached']['library'][] = 'nlpservices/data-entry-help';
    return $output;
  }
  
  /**
   * Printable_walksheet_page
   *
   * Generates a printable walk sheet page for the given turf index.  The output is a render array, and
   * the user can print it directly from the browser.
   *
   * @param $turfIndex
   * @noinspection PhpUnused
   * used in route
   * @return array
   */
  public function printable_walksheet_page($turfIndex): array
  {
    $output = $this->dataEntryPrintObj->buildWalksheetPage($turfIndex);
    Drupal::service("page_cache_kill_switch")->trigger();
    return $output;
  }
  
}
