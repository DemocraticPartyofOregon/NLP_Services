<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\TempStore\PrivateTempStore;
  use Drupal\Core\TempStore\PrivateTempStoreFactory;
  use Drupal\nlpservices\Form\LegislativeFixesForm;
  use Drupal\nlpservices\NlpLegislativeFixes;
  use Drupal\Tests\UnitTestCase;

  class LegislativeFixesFormTest extends UnitTestCase {

    private NlpLegislativeFixes $legFixesMock;
    private MessengerInterface $messengerMock;
    private PrivateTempStoreFactory $tempStoreFactoryMock;
    private PrivateTempStore $tempStoreMock;

    protected function setUp(): void {
      parent::setUp();
      $this->legFixesMock         = $this->createMock(NlpLegislativeFixes::class);
      $this->messengerMock        = $this->createMock(MessengerInterface::class);
      $this->tempStoreMock        = $this->createMock(PrivateTempStore::class);
      $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
      $this->tempStoreFactoryMock->method('get')
        ->with('nlpservices.session_data')
        ->willReturn($this->tempStoreMock);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      $container->set('tempstore.private', $this->tempStoreFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): LegislativeFixesForm {
      $form = new LegislativeFixesForm($this->legFixesMock);
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function sampleFix(string $mcid = '10001'): array {
      return [
        'mcid'      => $mcid,
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'cd'        => '7',
        'hd'        => '32',
        'precinct'  => '042',
        'county'    => 'Denver',
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_legislative_fixes_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — first entry loads county from tempstore
    // -------------------------------------------------------------------------

    public function testBuildFormFirstEntryLoadsCountyFromTempstore(): void {
      $this->tempStoreMock->expects($this->once())
        ->method('get')
        ->with('County')
        ->willReturn('Denver');

      $this->legFixesMock->method('getLegFixes')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', NULL],
        ['county',  'Denver'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('new_fix', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — no county adds warning and returns empty array
    // -------------------------------------------------------------------------

    public function testBuildFormNoCountyAddsWarningAndReturnsEmptyArray(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  ''],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — with county renders new_fix fieldset and all text fields
    // -------------------------------------------------------------------------

    public function testBuildFormWithCountyRendersNewFixFieldset(): void {
      $this->legFixesMock->method('getLegFixes')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('new_fix', $result);
      $this->assertSame('fieldset', $result['new_fix']['#type']);
      foreach (['fix_mcid', 'fix_first_name', 'fix_last_name', 'fix_cd', 'fix_hd', 'fix_precinct'] as $field) {
        $this->assertArrayHasKey($field, $result['new_fix'], "Expected field '$field' in new_fix");
        $this->assertSame('textfield', $result['new_fix'][$field]['#type']);
      }
      $this->assertSame('submit', $result['new_fix']['fix_add_submit']['#type']);
      $this->assertSame('fix_add_submit', $result['new_fix']['fix_add_submit']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — existing fixes renders old_fix fieldset with checkboxes
    // -------------------------------------------------------------------------

    public function testBuildFormWithExistingFixesRendersOldFixFieldset(): void {
      $fix = $this->sampleFix('10001');
      $this->legFixesMock->method('getLegFixes')->willReturn(['10001' => $fix]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('old_fix', $result);
      $this->assertSame('fieldset', $result['old_fix']['#type']);
      $this->assertSame('checkboxes', $result['old_fix']['fix_delete']['#type']);
      $this->assertArrayHasKey('10001', $result['old_fix']['fix_delete']['#options']);
      $this->assertSame('submit', $result['old_fix']['fix_delete_submit']['#type']);
      $this->assertSame('fix_delete_submit', $result['old_fix']['fix_delete_submit']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — no existing fixes omits old_fix fieldset
    // -------------------------------------------------------------------------

    public function testBuildFormWithNoExistingFixesOmitsOldFixFieldset(): void {
      $this->legFixesMock->method('getLegFixes')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayNotHasKey('old_fix', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — attaches libraries
    // -------------------------------------------------------------------------

    public function testBuildFormAttachesLibraries(): void {
      $this->legFixesMock->method('getLegFixes')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertContains('nlpservices/form-helper', $result['#attached']['library']);
      $this->assertContains('nlpservices/info-boxes', $result['#attached']['library']);
    }

    // -------------------------------------------------------------------------
    // submitForm — fix_add_submit calls createLegFix with sanitized values
    // -------------------------------------------------------------------------

    public function testSubmitFormFixAddSubmitCallsCreateLegFix(): void {
      $this->legFixesMock->expects($this->once())
        ->method('createLegFix')
        ->with($this->callback(function (array $fix): bool {
          return $fix['county']    === 'Denver'
            && $fix['mcid']       === '10001'
            && $fix['firstName']  === 'Jane'
            && $fix['lastName']   === 'Doe'
            && $fix['cd']         === '7'
            && $fix['hd']         === '32'
            && $fix['precinct']   === '042';
        }));

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'fix_add_submit']);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getValues')->willReturn([
        'fix_mcid'       => '10001',
        'fix_first_name' => 'Jane',
        'fix_last_name'  => 'Doe',
        'fix_cd'         => '7',
        'fix_hd'         => '32',
        'fix_precinct'   => '042',
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — fix_add_submit strips spaces from precinct
    // -------------------------------------------------------------------------

    public function testSubmitFormFixAddSubmitStripsSpacesFromPrecinct(): void {
      $captured = [];
      $this->legFixesMock->expects($this->once())
        ->method('createLegFix')
        ->with($this->callback(function (array $fix) use (&$captured): bool {
          $captured = $fix;
          return TRUE;
        }));

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'fix_add_submit']);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getValues')->willReturn([
        'fix_mcid'       => '',
        'fix_first_name' => '',
        'fix_last_name'  => '',
        'fix_cd'         => '',
        'fix_hd'         => '',
        'fix_precinct'   => '0 4 2',
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
      $this->assertSame('042', $captured['precinct']);
    }

    // -------------------------------------------------------------------------
    // submitForm — fix_delete_submit calls deleteLegFix for checked items
    // -------------------------------------------------------------------------

    public function testSubmitFormFixDeleteSubmitCallsDeleteLegFixForSelectedItems(): void {
      $existingFixes = [
        '10001' => ['mcid' => 10001, 'county' => 'Denver'],
        '10002' => ['mcid' => 10002, 'county' => 'Denver'],
      ];

      $this->legFixesMock->expects($this->once())
        ->method('deleteLegFix')
        ->with(10001);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'fix_delete_submit']);
      $formState->method('get')->with('fixes')->willReturn($existingFixes);
      $formState->method('getValues')->willReturn([
        'fix_delete' => [
          '10001' => '10001',
          '10002' => '',
        ],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — fix_delete_submit deletes multiple selected items
    // -------------------------------------------------------------------------

    public function testSubmitFormFixDeleteSubmitDeletesMultipleSelectedItems(): void {
      $existingFixes = [
        '10001' => ['mcid' => 10001, 'county' => 'Denver'],
        '10002' => ['mcid' => 10002, 'county' => 'Denver'],
      ];

      $this->legFixesMock->expects($this->exactly(2))->method('deleteLegFix');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'fix_delete_submit']);
      $formState->method('get')->with('fixes')->willReturn($existingFixes);
      $formState->method('getValues')->willReturn([
        'fix_delete' => [
          '10001' => '10001',
          '10002' => '10002',
        ],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

  }

}
