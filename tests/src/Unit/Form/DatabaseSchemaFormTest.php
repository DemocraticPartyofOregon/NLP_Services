<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal\Core\Form\FormStateInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\Form\DatabaseSchemaForm;
  use Drupal\Tests\UnitTestCase;

  class DatabaseSchemaFormTest extends UnitTestCase {

    private DatabaseFunctions $databaseFunctionsMock;

    protected function setUp(): void {
      parent::setUp();
      $this->databaseFunctionsMock = $this->createMock(DatabaseFunctions::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): DatabaseSchemaForm {
      $form = new DatabaseSchemaForm($this->databaseFunctionsMock);
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_database_schema_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — no prior action → no results section, both buttons present
    // -------------------------------------------------------------------------

    public function testBuildFormNoResultsOmitsResultsSection(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['results', NULL],
        ['action',  NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayNotHasKey('results', $result);
      $this->assertSame(['::verifySubmit'], $result['verify']['#submit']);
      $this->assertSame(['::repairSubmit'], $result['repair']['#submit']);
    }

    // -------------------------------------------------------------------------
    // buildForm — verify with no issues → "all match" message
    // -------------------------------------------------------------------------

    public function testBuildFormEmptyResultsAfterVerifyShowsAllMatchMessage(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['results', []],
        ['action',  'verify'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('All tables and fields match the schema.', $result['results']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — repair with no issues → "no repairs needed" message
    // -------------------------------------------------------------------------

    public function testBuildFormEmptyResultsAfterRepairShowsNoRepairsMessage(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['results', []],
        ['action',  'repair'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('No repairs were needed.', $result['results']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — non-empty results rendered as an escaped list
    // -------------------------------------------------------------------------

    public function testBuildFormWithResultsRendersEscapedList(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['results', ['Missing column <foo>', 'Extra table & stuff']],
        ['action',  'verify'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $markup = $result['results']['#markup'];
      $this->assertStringContainsString('<li>Missing column &lt;foo&gt;</li>', $markup);
      $this->assertStringContainsString('<li>Extra table &amp; stuff</li>', $markup);
      $this->assertStringStartsWith('<ul>', $markup);
    }

    // -------------------------------------------------------------------------
    // submitForm — no-op
    // -------------------------------------------------------------------------

    public function testSubmitFormIsNoOp(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('set');
      $formState->expects($this->never())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // verifySubmit
    // -------------------------------------------------------------------------

    public function testVerifySubmitStoresResultsAndAction(): void {
      $this->databaseFunctionsMock->expects($this->once())
        ->method('verifySchema')
        ->willReturn(['Missing table foo']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->exactly(2))
        ->method('set')
        ->with($this->logicalOr(
          $this->equalTo('results'),
          $this->equalTo('action'),
        ), $this->anything());
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->verifySubmit($form, $formState);
    }

    // -------------------------------------------------------------------------
    // repairSubmit
    // -------------------------------------------------------------------------

    public function testRepairSubmitStoresResultsAndAction(): void {
      $this->databaseFunctionsMock->expects($this->once())
        ->method('repairSchema')
        ->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->exactly(2))
        ->method('set')
        ->with($this->logicalOr(
          $this->equalTo('results'),
          $this->equalTo('action'),
        ), $this->anything());
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->repairSubmit($form, $formState);
    }

  }

}
