<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\ImmutableConfig;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\file\FileInterface;
  use Drupal\file\FileRepositoryInterface;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\Form\ExportVoterContactReportsForm;
  use Drupal\nlpservices\NlpReports;
  use Drupal\Tests\UnitTestCase;

  class ExportVoterContactReportsFormTest extends UnitTestCase {

    private NlpReports $reportsMock;
    private DatabaseFunctions $dbFunctionsMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->reportsMock     = $this->createMock(NlpReports::class);
      $this->dbFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->messengerMock   = $this->createMock(MessengerInterface::class);

      $configMock = $this->createMock(ImmutableConfig::class);
      $configMock->method('get')
        ->with('nlpservices-election-configuration')
        ->willReturn(['nlp_election_cycle' => '2024-11-G']);
      $configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $configFactoryMock->method('get')->with('nlpservices.configuration')->willReturn($configMock);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      $container->set('config.factory', $configFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): ExportVoterContactReportsForm {
      $form = new ExportVoterContactReportsForm($this->reportsMock, $this->dbFunctionsMock);
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    /** Captures every $form_state->set(key, value) call into the given array. */
    private function captureSetCalls(FormStateInterface $formState, array &$calls): void {
      $formState->method('set')->willReturnCallback(function ($key, $value) use (&$calls) {
        $calls[$key] = $value;
      });
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_export_reports_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, no reports found
    // -------------------------------------------------------------------------

    public function testBuildFormReenterEmptyArgsShowsNoReportsMessage(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['args',    []],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame('The current cycle has no reports.', (string) $result['empty']['#markup']);
      $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $result['button_link']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, reports found → count and download link
    // -------------------------------------------------------------------------

    public function testBuildFormReenterWithArgsShowsCountAndDownloadLink(): void {
      $urlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $urlGeneratorMock->method('generateAbsoluteString')
        ->with('public://temp/reports.csv')
        ->willReturn('https://example.org/temp/reports.csv');
      Drupal::getContainer()->set('file_url_generator', $urlGeneratorMock);

      $args = ['countOfRecords' => 42, 'uri' => 'public://temp/reports.csv'];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['args',    $args],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('42', $result['count']['#markup']);
      $this->assertStringContainsString('https://example.org/temp/reports.csv', $result['file']['#markup']);
      $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $result['button_link']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — initial state
    // -------------------------------------------------------------------------

    public function testBuildFormInitialStateBuildsCycleOptionsAndControls(): void {
      $this->reportsMock->method('getKnownCycles')->willReturn([1 => '2024-11-G', 2 => '2022-11-G']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertSame('checkbox', $result['currentCycle']['#type']);
      $this->assertSame('checkbox', $result['removeInactive']['#type']);
      $this->assertSame('select', $result['startCycle']['#type']);
      $this->assertSame('select', $result['endCycle']['#type']);
      $this->assertSame(
        [0 => 'Select starting cycle', 1 => '2024-11-G', 2 => '2022-11-G'],
        $result['startCycle']['#options'],
      );
      $this->assertSame(
        [0 => 'Select ending cycle', 1 => '2024-11-G', 2 => '2022-11-G'],
        $result['endCycle']['#options'],
      );
      $this->assertSame('export-file', $result['upload_file']['#id']);
    }

    // -------------------------------------------------------------------------
    // validateForm — current cycle only
    // -------------------------------------------------------------------------

    public function testValidateFormCurrentCycleOnlySetsSingleCycleOptions(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle', TRUE],
        ['startCycle',   0],
        ['endCycle',     0],
      ]);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame([1 => '2024-11-G'], $calls['cycleOptions']);
      $this->assertSame(1, $calls['startCycle']);
      $this->assertSame(1, $calls['endCycle']);
    }

    // -------------------------------------------------------------------------
    // validateForm — start cycle not selected
    // -------------------------------------------------------------------------

    public function testValidateFormStartCycleZeroSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle', FALSE],
        ['startCycle',   0],
        ['endCycle',     0],
      ]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('startCycle', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — end cycle defaults to start cycle
    // -------------------------------------------------------------------------

    public function testValidateFormEndCycleZeroDefaultsToStartCycle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle', FALSE],
        ['startCycle',   2],
        ['endCycle',     0],
      ]);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame(2, $calls['startCycle']);
      $this->assertSame(2, $calls['endCycle']);
    }

    // -------------------------------------------------------------------------
    // validateForm — end cycle before start cycle
    // -------------------------------------------------------------------------

    public function testValidateFormEndCycleBeforeStartCycleSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle', FALSE],
        ['startCycle',   3],
        ['endCycle',     2],
      ]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('endCycle', $this->anything());

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — valid explicit range
    // -------------------------------------------------------------------------

    public function testValidateFormValidRangeStoresStartAndEndCycle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle', FALSE],
        ['startCycle',   1],
        ['endCycle',     3],
      ]);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);

      $this->assertSame(1, $calls['startCycle']);
      $this->assertSame(3, $calls['endCycle']);
    }

    // -------------------------------------------------------------------------
    // submitForm
    // -------------------------------------------------------------------------

    /**
     * The private export helper writes to a "public://" stream, which is not
     * registered in a plain unit-test container. fopen() therefore always
     * fails, and the helper returns [] before ever touching NlpReports or
     * DatabaseFunctions — a deterministic outcome we can assert on.
     */
    public function testSubmitFormSetsReenterAndStoresEmptyArgsWhenFileCannotBeOpened(): void {
      $fileMock = $this->createMock(FileInterface::class);
      $repoMock = $this->createMock(FileRepositoryInterface::class);
      $repoMock->expects($this->once())->method('writeData')->willReturn($fileMock);
      Drupal::getContainer()->set('file.repository', $repoMock);

      $this->dbFunctionsMock->expects($this->never())->method('getColumnNames');
      $this->reportsMock->expects($this->never())->method('getReportCount');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['startCycle',   1],
        ['endCycle',     1],
        ['cycleOptions', [1 => '2024-11-G']],
      ]);
      $formState->method('getValue')->willReturnMap([
        ['currentCycle',   FALSE],
        ['removeInactive', FALSE],
      ]);
      $calls = [];
      $this->captureSetCalls($formState, $calls);
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      @$this->buildForm()->submitForm($form, $formState);

      $this->assertTrue($calls['reenter']);
      $this->assertSame([], $calls['args']);
    }

  }

}
