<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\nlpservices\NlpContactDate;
use PHPUnit\Framework\Attributes\Group;

/**
 * The date of a voter contact as Drupal really renders it.
 *
 * The unit tests only see the render array NlpContactDate::dateElement()
 * returns.  This renders it through the form builder, so it fails if Drupal
 * drops the attributes js/contact-date.js needs, or can't find the
 * nlpservices/contact-date library that loads the script.
 */
#[Group('nlpservices')]
class ContactDateElementTest extends KernelTestBase implements FormInterface {

  protected static $modules = ['system', 'user', 'file', 'nlpservices'];

  public function getFormId(): string {
    return 'nlpservices_contact_date_element_test';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['canvassDate'] = NlpContactDate::dateElement([
      '#type' => 'date',
      '#title' => 'Date of the voter contact',
      '#attributes' => ['class' => ['nlp-not-an-edit']],
    ]);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {}

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function testTheDateBoxHasWhatTheScriptNeeds(): void {
    $form = $this->container->get('form_builder')->getForm($this);
    $html = (string) $this->container->get('renderer')->renderRoot($form);

    $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*>/', $html);
    preg_match('/<input[^>]*type="date"[^>]*>/', $html, $input);
    $this->assertStringContainsString('max="' . NlpContactDate::today() . '"', $input[0]);
    $this->assertMatchesRegularExpression('/class="[^"]*\bnlp-contact-date\b/', $input[0]);
    $this->assertStringContainsString('data-late-days="3"', $input[0]);
    $this->assertStringContainsString('data-future-msg=', $input[0]);
    $this->assertStringContainsString('data-late-msg=', $input[0]);
    $this->assertContains('nlpservices/contact-date', $form['#attached']['library']);
  }

  public function testTheContactDateLibraryLoadsTheScript(): void {
    $library = $this->container->get('library.discovery')->getLibraryByName('nlpservices', 'contact-date');

    $this->assertNotEmpty($library, 'nlpservices/contact-date is not a known library.');
    $scripts = array_column($library['js'], 'data');
    $this->assertContains($this->container->get('extension.list.module')->getPath('nlpservices')
      . '/js/contact-date.js', $scripts);
  }

}
