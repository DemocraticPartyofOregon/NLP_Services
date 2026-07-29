<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\NlpEarlyVoting;
use Drupal\nlpservices\NlpPdfParser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @noinspection PhpUnused
 */
class ImportCountyEarlyVotingForm extends FormBase
{

  protected NlpPdfParser $pdfParser;
  protected NlpEarlyVoting $earlyVotingObj;

  public function __construct($pdfParser, $earlyVotingObj, $requestStack)
  {
    $this->pdfParser = $pdfParser;
    $this->earlyVotingObj = $earlyVotingObj;
    $this->setRequestStack($requestStack);
  }

  public static function create(ContainerInterface $container): ImportCountyEarlyVotingForm
  {
    return new static(
      $container->get('nlpservices.pdf_parser'),
      $container->get('nlpservices.early_voting'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string
  {
    return 'nlpservices_import_county_early_voting_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['pdf_file'] = [
      '#type' => 'file',
      '#title' => $this->t('County early voting PDF'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import >>'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $pdfFile = $files['pdf_file'] ?? NULL;
    if (!$pdfFile) {
      $form_state->setErrorByName('pdf_file', $this->t('No file was uploaded.'));
      return;
    }
    $ext = strtolower(pathinfo($pdfFile->getClientOriginalName(), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
      $form_state->setErrorByName('pdf_file', $this->t('The uploaded file must be a PDF.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    $pdfFile = $files['pdf_file'];
    $tempPath = $pdfFile->getRealPath();

    $rows = $this->pdfParser->extractRegistrationTable($tempPath);

    if ($rows === false) {
      $this->messenger()->addError($this->t('Failed to parse the PDF. Check the debug log for details.'));
      return;
    }

    $voters = $this->earlyVotingObj->matchEarlyVoters($rows);

    nlp_debug_msg('Early voting matches (' . count($voters) . ' voters)', $voters);
    $this->messenger()->addStatus($this->t('Import complete. Matched @count voters. See debug log for details.', ['@count' => count($voters)]));
  }

}
