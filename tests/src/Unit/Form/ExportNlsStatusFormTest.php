<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\ExportNlsStatusForm;
  use Drupal\nlpservices\NlpExportNlsStatus;
  use Drupal\nlpservices\NlpFileFunctions;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\Tests\UnitTestCase;

  class ExportNlsStatusFormTest extends UnitTestCase {

    private NlpSessionData $sessionDataMock;
    private NlpNls $nlsMock;
    private NlpExportNlsStatus $exportNlsStatusMock;
    private DrupalUser $drupalUserMock;
    private NlpFileFunctions $fileFunctionsMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->sessionDataMock     = $this->createMock(NlpSessionData::class);
      $this->nlsMock             = $this->createMock(NlpNls::class);
      $this->exportNlsStatusMock = $this->createMock(NlpExportNlsStatus::class);
      $this->drupalUserMock      = $this->createMock(DrupalUser::class);
      $this->fileFunctionsMock   = $this->createMock(NlpFileFunctions::class);
      $this->messengerMock       = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): ExportNlsStatusForm {
      $form = new ExportNlsStatusForm(
        $this->sessionDataMock,
        $this->nlsMock,
        $this->exportNlsStatusMock,
        $this->drupalUserMock,
        $this->fileFunctionsMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /** Stubs createTempCsvFile to succeed or fail. */
    private function setupFileFunctions(bool $fails = false): void {
      if ($fails) {
        $this->fileFunctionsMock->method('createTempCsvFile')->willReturn(false);
      } else {
        $fh = fopen('php://memory', 'w');
        $this->fileFunctionsMock->method('createTempCsvFile')
          ->willReturn(['uri' => 'public://temp/nls-status-2024-01-01-00-00-00.csv', 'fh' => $fh]);
      }
    }

    /** Builds a container with file_url_generator registered (needed for reenter buildForm). */
    private function setupFileUrlContainer(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_export_nls_status_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, admin user
    // -------------------------------------------------------------------------

    public function testBuildFormAdminUserShowsSaveButtonWithoutDistrictSelect(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('save', $result);
      $this->assertArrayNotHasKey('districtSelect', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, non-admin, county missing → error, returns []
    // -------------------------------------------------------------------------

    public function testBuildFormNonAdminNoCountyAddsErrorAndReturnsEmpty(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionDataMock->method('getCounty')->willReturn('');
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, non-admin, county set → district select + save
    // -------------------------------------------------------------------------

    public function testBuildFormNonAdminWithCountyShowsDistrictSelectAndSave(): void {
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->nlsMock->method('getHdList')->with('Denver')->willReturn(['HD 01', 'HD 02']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('districtSelect', $result);
      $this->assertArrayHasKey('save', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, admin → all-county heading
    // -------------------------------------------------------------------------

    public function testBuildFormReenterAdminShowsAllCountiesHeading(): void {
      $this->setupFileUrlContainer();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',  TRUE],
        ['fileName', 'public://temp/nls-status.csv'],
        ['county',   'all'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('result', $result);
      $this->assertStringContainsString('all counties', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, non-admin with specific HD → county/HD heading
    // -------------------------------------------------------------------------

    public function testBuildFormReenterNonAdminShowsCountyAndHdHeading(): void {
      $this->setupFileUrlContainer();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',  TRUE],
        ['fileName', 'public://temp/nls-status.csv'],
        ['county',   'Denver'],
        ['hd',       '42'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('Denver', $result['result']['#markup']);
      $this->assertStringContainsString('42', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, non-admin, hd empty → defaults to 'All'
    // -------------------------------------------------------------------------

    public function testBuildFormReenterNonAdminEmptyHdDefaultsToAll(): void {
      $this->setupFileUrlContainer();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',  TRUE],
        ['fileName', 'public://temp/nls-status.csv'],
        ['county',   'Denver'],
        ['hd',       ''],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('All', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter → result markup includes download link
    // -------------------------------------------------------------------------

    public function testBuildFormReenterMarkupContainsDownloadLink(): void {
      $this->setupFileUrlContainer();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',  TRUE],
        ['fileName', 'public://temp/nls-status.csv'],
        ['county',   'Denver'],
        ['hd',       '01'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('https://example.com/file.csv', $result['result']['#markup']);
      $this->assertStringContainsString('<a href=', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // submitForm — admin user → hd=0, calls createNlsStatusFile with 'all', 0
    // -------------------------------------------------------------------------

    public function testSubmitFormAdminCallsCreateNlsStatusFileWithAllAndZero(): void {
      $this->setupFileFunctions();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('all');

      $this->exportNlsStatusMock->expects($this->once())
        ->method('createNlsStatusFile')
        ->with($this->stringContains('nls-status-'), 'all', 0);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — non-admin → hd from form value, county from form state
    // -------------------------------------------------------------------------

    public function testSubmitFormNonAdminPassesHdFromFormValue(): void {
      $this->setupFileFunctions();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getValue')->with('districtSelect')->willReturn('3');

      $this->exportNlsStatusMock->expects($this->once())
        ->method('createNlsStatusFile')
        ->with($this->anything(), 'Denver', 3);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — createTempCsvFile fails → addError, createNlsStatusFile skipped
    // -------------------------------------------------------------------------

    public function testSubmitFormFileCreateFailureAddsErrorAndSkipsExport(): void {
      $this->setupFileFunctions(fails: true);
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getValue')->willReturn('1');

      $this->messengerMock->expects($this->once())->method('addError');
      $this->exportNlsStatusMock->expects($this->never())->method('createNlsStatusFile');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — sets reenter=TRUE and calls setRebuild
    // -------------------------------------------------------------------------

    public function testSubmitFormSetsReenterAndRebuild(): void {
      $this->setupFileFunctions();
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('all');
      $formState->expects($this->once())->method('setRebuild');
      $formState->expects($this->atLeastOnce())->method('set')
        ->with($this->logicalOr(
          $this->equalTo('reenter'),
          $this->equalTo('hd'),
          $this->equalTo('fileName'),
        ), $this->anything());

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

  }

}
