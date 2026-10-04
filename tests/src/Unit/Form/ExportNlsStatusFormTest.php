<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\ExportNlsStatusForm;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpExportNlsStatus;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\Tests\UnitTestCase;
  use Exception;

  class ExportNlsStatusFormTest extends UnitTestCase {

    private NlpSessionData $sessionDataMock;
    private NlpConfiguration $configurationMock;
    private NlpExportNlsStatus $exportNlsStatusMock;
    private DrupalUser $drupalUserMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->sessionDataMock    = $this->createMock(NlpSessionData::class);
      $this->configurationMock  = $this->createMock(NlpConfiguration::class);
      $this->exportNlsStatusMock = $this->createMock(NlpExportNlsStatus::class);
      $this->drupalUserMock     = $this->createMock(DrupalUser::class);
      $this->messengerMock      = $this->createMock(MessengerInterface::class);

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
        $this->configurationMock,
        $this->exportNlsStatusMock,
        $this->drupalUserMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    /** Builds a file mock with setTemporary() and save() and registers it with file.repository. */
    private function setupFileRepo(bool $saveThrows = false): void {
      $fileMock = $this->getMockBuilder(\stdClass::class)
        ->addMethods(['setTemporary', 'save'])
        ->getMock();
      if ($saveThrows) {
        $fileMock->method('save')->willThrowException(new Exception('save failed'));
      }

      $repo = $this->getMockBuilder(\stdClass::class)->addMethods(['writeData'])->getMock();
      $repo->method('writeData')->willReturn($fileMock);

      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/nls-status.csv');

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file.repository', $repo);
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);
    }

    private function setupFileRepoThatThrowsOnWrite(): void {
      $repo = $this->getMockBuilder(\stdClass::class)->addMethods(['writeData'])->getMock();
      $repo->method('writeData')->willThrowException(new \Exception('write failed'));

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file.repository', $repo);
      Drupal::setContainer($container);
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_export_nls_status_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, admin user (administrator role)
    // -------------------------------------------------------------------------

    public function testBuildFormAdminUserShowsSaveButtonWithoutDistrictSelect(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['administrator']]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('save', $result);
      $this->assertArrayNotHasKey('districtSelect', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, admin user (nlp_admin role)
    // -------------------------------------------------------------------------

    public function testBuildFormNlpAdminRoleShowsSaveButtonWithoutDistrictSelect(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['nlp_admin']]);

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
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['authenticated']]);
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
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['authenticated']]);
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->configurationMock->method('getHDNames')->with('Denver')
        ->willReturn([1 => 'HD 01', 2 => 'HD 02']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('districtSelect', $result['hdSelect']);
      $this->assertArrayNotHasKey('countySelect', $result);
      $this->assertArrayHasKey('save', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — first render, admin → county select defaults to all, no HD select
    // -------------------------------------------------------------------------

    public function testBuildFormAdminShowsCountySelectWithAllOption(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['administrator']]);
      $this->configurationMock->method('getCountyNames')->willReturn(['Denver', 'Boulder']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $options = $result['countySelect']['#options'];
      $this->assertSame(['all', 'Denver', 'Boulder'], array_keys($options));
      $this->assertSame('::countySelectedCallback', $result['countySelect']['#ajax']['callback']);
      $this->assertArrayNotHasKey('districtSelect', $result['hdSelect']);
    }

    // -------------------------------------------------------------------------
    // buildForm — admin rebuild after selecting one county → HD select shown
    // -------------------------------------------------------------------------

    public function testBuildFormAdminWithCountySelectedShowsDistrictSelect(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['nlp_admin']]);
      $this->configurationMock->method('getCountyNames')->willReturn(['Denver']);
      $this->configurationMock->method('getHDNames')->with('Denver')
        ->willReturn(['All' => 'All', 1 => 1]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);
      $formState->method('getValue')->with('countySelect')->willReturn('Denver');

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame(['All' => 'All', 1 => 1], $result['hdSelect']['districtSelect']['#options']);
    }

    // -------------------------------------------------------------------------
    // countySelectedCallback — returns the HD wrapper
    // -------------------------------------------------------------------------

    public function testCountySelectedCallbackReturnsHdSelect(): void {
      $form = ['hdSelect' => ['#type' => 'container', 'districtSelect' => []]];
      $formState = $this->createMock(FormStateInterface::class);
      $this->assertSame($form['hdSelect'], $this->buildForm()->countySelectedCallback($form, $formState));
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, admin → all-county heading
    // -------------------------------------------------------------------------

    public function testBuildFormReenterAdminShowsAllCountiesHeading(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   TRUE],
        ['fileName',  'public://temp/nls-status.csv'],
        ['county',    'all'],
        ['admin',     TRUE],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('result', $result);
      $this->assertStringContainsString('all counties', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, admin with one county → county/HD heading
    // -------------------------------------------------------------------------

    public function testBuildFormReenterAdminSingleCountyShowsCountyHeading(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   TRUE],
        ['fileName',  'public://temp/nls-status.csv'],
        ['county',    'Boulder'],
        ['admin',     TRUE],
        ['hd',        0],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('Boulder County, HD All', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, non-admin with specific HD → county/HD heading
    // -------------------------------------------------------------------------

    public function testBuildFormReenterNonAdminShowsCountyAndHdHeading(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   TRUE],
        ['fileName',  'public://temp/nls-status.csv'],
        ['county',    'Denver'],
        ['admin',     FALSE],
        ['hd',        '42'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('Denver', $result['result']['#markup']);
      $this->assertStringContainsString('42', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter, non-admin, hd empty → defaults to 'All'
    // -------------------------------------------------------------------------

    public function testBuildFormReenterNonAdminEmptyHdDefaultsToAll(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   TRUE],
        ['fileName',  'public://temp/nls-status.csv'],
        ['county',    'Denver'],
        ['admin',     FALSE],
        ['hd',        ''],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('All', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter → result markup includes download link
    // -------------------------------------------------------------------------

    public function testBuildFormReenterMarkupContainsDownloadLink(): void {
      $fileUrlMock = $this->createMock(FileUrlGeneratorInterface::class);
      $fileUrlMock->method('generateAbsoluteString')->willReturn('https://example.com/file.csv');
      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('file_url_generator', $fileUrlMock);
      Drupal::setContainer($container);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',   TRUE],
        ['fileName',  'public://temp/nls-status.csv'],
        ['county',    'Denver'],
        ['admin',     FALSE],
        ['hd',        '01'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('https://example.com/file.csv', $result['result']['#markup']);
      $this->assertStringContainsString('<a href=', $result['result']['#markup']);
    }

    // -------------------------------------------------------------------------
    // submitForm — admin user → hd=0, calls createNlsStatusFile with 'all', 0
    // -------------------------------------------------------------------------

    public function testSubmitFormAdminCallsCreateNlsStatusFileWithAllAndZero(): void {
      $this->setupFileRepo();

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  TRUE],
        ['county', 'all'],
      ]);

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
      $this->setupFileRepo();

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  FALSE],
        ['county', 'Denver'],
      ]);
      $formState->method('getValue')->with('districtSelect')->willReturn('3');

      $this->exportNlsStatusMock->expects($this->once())
        ->method('createNlsStatusFile')
        ->with($this->anything(), 'Denver', 3);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — admin with one county → county and HD from form values
    // -------------------------------------------------------------------------

    public function testSubmitFormAdminSingleCountyPassesCountyAndHd(): void {
      $this->setupFileRepo();

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  TRUE],
      ]);
      $formState->method('getValue')->willReturnMap([
        ['countySelect',   NULL, 'Denver'],
        ['districtSelect', NULL, '5'],
      ]);

      $this->exportNlsStatusMock->expects($this->once())
        ->method('createNlsStatusFile')
        ->with($this->anything(), 'Denver', 5);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — admin with one county and 'All' HD → hd=0
    // -------------------------------------------------------------------------

    public function testSubmitFormAdminSingleCountyAllHdPassesZero(): void {
      $this->setupFileRepo();

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  TRUE],
      ]);
      $formState->method('getValue')->willReturnMap([
        ['countySelect',   NULL, 'Denver'],
        ['districtSelect', NULL, 'All'],
      ]);

      $this->exportNlsStatusMock->expects($this->once())
        ->method('createNlsStatusFile')
        ->with($this->anything(), 'Denver', 0);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — file save() throws → addError called, createNlsStatusFile skipped
    // -------------------------------------------------------------------------

    public function testSubmitFormSaveExceptionAddsErrorAndSkipsExport(): void {
      $this->setupFileRepo(saveThrows: true);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  FALSE],
        ['county', 'Denver'],
      ]);
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
      $this->setupFileRepo();

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['admin',  TRUE],
        ['county', 'all'],
      ]);
      $formState->expects($this->once())->method('setRebuild');
      $formState->expects($this->atLeastOnce())->method('set')
        ->with($this->logicalOr(
          $this->equalTo('reenter'),
          $this->equalTo('county'),
          $this->equalTo('hd'),
          $this->equalTo('fileName'),
        ), $this->anything());

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

  }

}
