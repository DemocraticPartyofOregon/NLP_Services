<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\Form\FormState;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Form\ImportMinivanForm;
  use Drupal\nlpservices\NlpMinivan;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Attributes\CoversClass;
  use PHPUnit\Framework\Attributes\CoversMethod;
  use PHPUnit\Framework\Attributes\Group;

  #[CoversClass(\Drupal\nlpservices\Form\ImportMinivanForm::class)]
  #[CoversMethod(\Drupal\nlpservices\Form\ImportMinivanForm::class, 'getFormId')]
  #[CoversMethod(\Drupal\nlpservices\Form\ImportMinivanForm::class, 'buildForm')]
  #[CoversMethod(\Drupal\nlpservices\Form\ImportMinivanForm::class, 'submitForm')]
  #[Group('nlpservices')]
  class ImportMinivanFormTest extends UnitTestCase {

    private NlpMinivan $minivanMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();

      $this->minivanMock   = $this->createMock(NlpMinivan::class);
      $this->messengerMock = $this->createMock(MessengerInterface::class);

      $moduleRoot = realpath(__DIR__ . '/../../../../');

      $extListMock = $this->createMock(ModuleExtensionList::class);
      $extListMock->method('getPath')->willReturn($moduleRoot);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      $container->set('extension.list.module', $extListMock);
      Drupal::setContainer($container);

      $GLOBALS['batch_set_calls'] = [];
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
      unset($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildFormInstance(): ImportMinivanForm {
      $form = new ImportMinivanForm($this->minivanMock);
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    private function baseQueueItem(int $reportCount = 2): array {
      return [
        'reportCount' => $reportCount,
        'reports'     => [['van_id' => 1], ['van_id' => 2]],
        'fileType'    => 'csv',
        'subject'     => 'MiniVAN Report',
        'month'       => 6,
        'day'         => 15,
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedString(): void {
      $this->assertSame(
        'nlpservices_import_minivan_form',
        $this->buildFormInstance()->getFormId(),
      );
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsSubmitElement(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertArrayHasKey('submit', $form);
    }

    public function testBuildFormSubmitElementHasCorrectType(): void {
      $form = $this->buildFormInstance()->buildForm([], new FormState());

      $this->assertSame('submit', $form['submit']['#type']);
    }

    // -------------------------------------------------------------------------
    // submitForm — empty queue breaks the loop immediately
    // -------------------------------------------------------------------------

    public function testSubmitFormCallsMinivanEmailCheck(): void {
      $this->minivanMock->expects($this->atLeastOnce())->method('minivanEmailCheck')->willReturn([]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());
    }

    public function testSubmitFormDoesNotSetBatchWhenQueueIsEmpty(): void {
      $this->minivanMock->method('minivanEmailCheck')->willReturn([]);

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());

      $this->assertEmpty($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // submitForm — items with empty reportCount are skipped
    // -------------------------------------------------------------------------

    /**
     * Items whose reportCount is empty/zero must not trigger batch_set; the
     * second call to minivanEmailCheck returns [] to exit the do-while loop.
     */
    public function testSubmitFormSkipsQueueItemsWithZeroReportCount(): void {
      $this->minivanMock->method('minivanEmailCheck')
        ->willReturnOnConsecutiveCalls(
          [$this->baseQueueItem(0)],
          [],
        );

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());

      $this->assertEmpty($GLOBALS['batch_set_calls']);
    }

    // -------------------------------------------------------------------------
    // submitForm — items with reports trigger batch_set
    // -------------------------------------------------------------------------

    public function testSubmitFormCallsBatchSetForItemsWithReports(): void {
      $this->minivanMock->method('minivanEmailCheck')
        ->willReturnOnConsecutiveCalls(
          [$this->baseQueueItem(2)],
          [],
        );

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());

      $this->assertCount(1, $GLOBALS['batch_set_calls']);
    }

    public function testSubmitFormSetsOneBatchPerQueueItemWithReports(): void {
      $this->minivanMock->method('minivanEmailCheck')
        ->willReturnOnConsecutiveCalls(
          [$this->baseQueueItem(2), $this->baseQueueItem(3)],
          [],
        );

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());

      $this->assertCount(2, $GLOBALS['batch_set_calls']);
    }

    /**
     * The batch operation must pass the expected keys from the queue item
     * through to importMinivanBatch.
     */
    public function testSubmitFormBatchOperationPassesExpectedArgs(): void {
      $this->minivanMock->method('minivanEmailCheck')
        ->willReturnOnConsecutiveCalls(
          [$this->baseQueueItem(2)],
          [],
        );

      $form = [];
      $this->buildFormInstance()->submitForm($form, new FormState());

      $args = $GLOBALS['batch_set_calls'][0]['operations'][0][1][0];
      $this->assertArrayHasKey('records', $args);
      $this->assertArrayHasKey('fileType', $args);
      $this->assertArrayHasKey('subject', $args);
      $this->assertArrayHasKey('month', $args);
      $this->assertArrayHasKey('day', $args);
    }

  }

}

// Stub batch_set() in the form's namespace so unit tests do not require
// a full Drupal bootstrap. Guarded because other test files (e.g.
// ImportUserAccountsFormTest) declare the same namespaced stub, and PHP
// fatals on redeclaring a function when both files load in the same test run.
namespace Drupal\nlpservices\Form {
  if (!function_exists(__NAMESPACE__ . '\batch_set')) {
    function batch_set(array $batch_definition): void {
      $GLOBALS['batch_set_calls'][] = $batch_definition;
    }
  }
}
