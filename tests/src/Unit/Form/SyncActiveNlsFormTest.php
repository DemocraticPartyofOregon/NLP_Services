<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiExportJobs;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\ApiSavedLists;
  use Drupal\nlpservices\NlpEncryption;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\Form\SyncActiveNlsForm;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\MockObject\MockObject;
  use ReflectionMethod;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class SyncActiveNlsFormTest extends UnitTestCase {

    private ApiExportJobs $exportJobsMock;
    private ApiSavedLists $savedListsMock;
    private ApiFolders $foldersMock;
    private NlpNls $nlsMock;
    private NlpEncryption $encryptMock;
    private NlpSessionData $sessionDataMock;

    /** What startActiveNlsSync returns; FALSE simulates a rebuild already running. */
    private bool $activeNlsSyncStarts = TRUE;

    protected function setUp(): void {
      parent::setUp();
      $this->exportJobsMock  = $this->createMock(ApiExportJobs::class);
      $this->savedListsMock  = $this->createMock(ApiSavedLists::class);
      $this->foldersMock     = $this->createMock(ApiFolders::class);
      $this->nlsMock         = $this->createMock(NlpNls::class);
      $this->nlsMock->method('startActiveNlsSync')
        ->willReturnCallback(fn() => $this->activeNlsSyncStarts);
      $this->encryptMock     = $this->createMock(NlpEncryption::class);
      $this->sessionDataMock = $this->createMock(NlpSessionData::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): SyncActiveNlsForm {
      return new SyncActiveNlsForm(
        $this->exportJobsMock,
        $this->savedListsMock,
        $this->foldersMock,
        $this->nlsMock,
        $this->encryptMock,
        $this->sessionDataMock,
      );
    }

    private function buildFormStateMock(): FormStateInterface&MockObject {
      $mock = $this->createMock(FormStateInterface::class);
      $mock->method('set')->willReturnSelf();
      return $mock;
    }

    private function setupContainerWithMessenger(): MessengerInterface&MockObject {
      $messengerMock = $this->createMock(MessengerInterface::class);
      $container = new ContainerBuilder();
      $container->set('messenger', $messengerMock);
      Drupal::setContainer($container);
      return $messengerMock;
    }

    private function baseList(): array {
      return [
        ['savedListId' => 100, 'name' => 'List A', 'description' => 'Active NLs', 'listCount' => 15],
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_sync_active_nls_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — no county
    // -------------------------------------------------------------------------

    public function testBuildFormNoCountyAddsWarningAndReturnsEmpty(): void {
      $messengerMock = $this->setupContainerWithMessenger();
      $messengerMock->expects($this->once())->method('addWarning');
      $this->sessionDataMock->method('getCounty')->willReturn('');
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — county present, folder not found
    // -------------------------------------------------------------------------

    public function testBuildFormFolderNotFoundReturnsErrorMarkup(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([
        10 => 'Boulder_NLs',
        11 => 'Jefferson_NLs',
      ]);
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertArrayHasKey('done1', $result);
      $this->assertStringContainsString('county folder', $result['done1']['#markup']);
    }

    public function testBuildFormFolderSuffixMustMatchExactly(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      // 'Denver_NLs_Extra' does not match 'Denver_NLs' exactly.
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs_Extra']);
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertArrayHasKey('done1', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — folder found, no lists
    // -------------------------------------------------------------------------

    public function testBuildFormNoListsReturnsNoListsMarkup(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn([]);
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertArrayHasKey('no-lists', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — folder found, lists available
    // -------------------------------------------------------------------------

    public function testBuildFormWithListsBuildsRadiosAndSubmit(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertSame('radios', $result['file_select']['#type']);
      $this->assertSame('submit', $result['submit']['#type']);
    }

    public function testBuildFormOptionsIncludeListNameAndCount(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $option = $result['file_select']['#options'][100];
      $this->assertStringContainsString('List A', $option);
      $this->assertStringContainsString('15', $option);
    }

    public function testBuildFormListSelectorHasNoDefaultOutsideTheOptions(): void {
      // A default that isn't a saved list ID is what Drupal submits when no
      // list is chosen, and it then rejects it as "The submitted value 0 ...
      // is not allowed." instead of saying the field is required.
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $element = $result['file_select'];
      $this->assertTrue($element['#required']);
      if (isset($element['#default_value'])) {
        $this->assertArrayHasKey($element['#default_value'], $element['#options']);
      }
      else {
        $this->assertArrayNotHasKey('#default_value', $element);
      }
    }

    public function testBuildFormPassesCorrectFolderIdToSavedLists(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([
        3 => 'Boulder_NLs',
        7 => 'Denver_NLs',
        9 => 'Jefferson_NLs',
      ]);
      // getSavedLists must be called with folderId=7, not 3 or 9.
      $this->savedListsMock->expects($this->once())
        ->method('getSavedLists')
        ->with('Denver', 1, 7)
        ->willReturn($this->baseList());
      $this->buildForm()->buildForm([], $this->buildFormStateMock());
    }

    public function testBuildFormStopsAtFirstMatchingFolder(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      // Two folders with the correct name — should stop at the first one (id=5).
      $this->foldersMock->method('getApiFolders')->willReturn([
        5 => 'Denver_NLs',
        6 => 'Denver_NLs',
      ]);
      $this->savedListsMock->expects($this->once())
        ->method('getSavedLists')
        ->with('Denver', 1, 5)
        ->willReturn([]);
      $this->buildForm()->buildForm([], $this->buildFormStateMock());
    }

    public function testBuildFormAttachesRequiredLibraries(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertContains('nlpservices/info-boxes', $result['#attached']['library']);
      $this->assertContains('nlpservices/button', $result['#attached']['library']);
    }

    public function testBuildFormAttachesCoreSingleSubmitGuard(): void {
      // core/drupal.form ignores a second submit with the same values, so a
      // double click on Synchronize can't hide the redirect to the batch page.
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $this->assertContains('core/drupal.form', $result['#attached']['library']);
    }

    public function testAttachedNlpLibrariesAreDefined(): void {
      // An undefined library is skipped silently, so its CSS never loads.
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn($this->baseList());
      $result = $this->buildForm()->buildForm([], $this->buildFormStateMock());
      $defined = file_get_contents(dirname(__DIR__, 4) . '/nlpservices.libraries.yml');
      foreach ($result['#attached']['library'] as $library) {
        if (!str_starts_with($library, 'nlpservices/')) { continue; }
        $name = substr($library, strlen('nlpservices/'));
        $this->assertMatchesRegularExpression('/^' . preg_quote($name, '/') . ':\s*$/m', $defined,
          "Library $library is not defined in nlpservices.libraries.yml.");
      }
    }

    public function testBuildFormStoresListCountsForSubmit(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->foldersMock->method('getApiFolders')->willReturn([5 => 'Denver_NLs']);
      $this->savedListsMock->method('getSavedLists')->willReturn([
        100 => ['savedListId' => 100, 'name' => 'A', 'description' => '', 'listCount' => 9],
        512428 => ['savedListId' => 512428, 'name' => 'B', 'description' => '', 'listCount' => 14],
      ]);
      $formStateMock = $this->createMock(FormStateInterface::class);
      $stored = [];
      $formStateMock->method('set')->willReturnCallback(
        function ($key, $value) use (&$stored, $formStateMock) {
          $stored[$key] = $value;
          return $formStateMock;
        });
      $this->buildForm()->buildForm([], $formStateMock);
      $this->assertSame([100 => 9, 512428 => 14], $stored['listCounts']);
    }

    // -------------------------------------------------------------------------
    // shortExportWarning
    // -------------------------------------------------------------------------

    private function shortExportWarning(?int $expected, int|string $rowCount): ?string {
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      Drupal::setContainer($container);
      $method = new ReflectionMethod(SyncActiveNlsForm::class, 'shortExportWarning');
      $warning = $method->invoke($this->buildForm(), $expected, $rowCount);
      return $warning === NULL ? NULL : (string) $warning;
    }

    public function testShortExportWarningWhenFewerRowsThanListCount(): void {
      $warning = $this->shortExportWarning(14, 7);
      $this->assertNotNull($warning);
      $this->assertStringContainsString('7 of the 14', $warning);
    }

    public function testShortExportWarningNullWhenCountsMatch(): void {
      $this->assertNull($this->shortExportWarning(14, 14));
    }

    public function testShortExportWarningNullWhenMoreRowsThanListCount(): void {
      $this->assertNull($this->shortExportWarning(14, 15));
    }

    public function testShortExportWarningNullWhenListCountUnknown(): void {
      $this->assertNull($this->shortExportWarning(NULL, 7));
    }

    public function testShortExportWarningNullWhenFileUnreadable(): void {
      $this->assertNull($this->shortExportWarning(14, 'unreadable'));
    }

    // -------------------------------------------------------------------------
    // submitForm — export job failure paths
    // -------------------------------------------------------------------------

    public function testSubmitFormBlockedWhileAnotherActiveNlsSyncRuns(): void {
      $messengerMock = $this->setupContainerWithMessenger();
      $messengerMock->expects($this->once())->method('addError');
      $this->activeNlsSyncStarts = FALSE;
      $this->exportJobsMock->expects($this->never())->method('getExportJobs');
      $this->nlsMock->expects($this->never())->method('deleteNls');
      $this->nlsMock->expects($this->never())->method('endActiveNlsSync');
      $formStateMock = $this->buildFormStateMock();
      $formStateMock->method('getValue')->willReturn('100');
      $formStateMock->method('get')->willReturn('Denver');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

    public function testSubmitFormExportFailureReleasesActiveNlsSync(): void {
      $this->setupContainerWithMessenger();
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $this->nlsMock->expects($this->never())->method('deleteNls');
      $this->nlsMock->expects($this->once())->method('endActiveNlsSync')->with('Denver');
      $formStateMock = $this->buildFormStateMock();
      $formStateMock->method('getValue')->willReturn('100');
      $formStateMock->method('get')->willReturn('Denver');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

    public function testSubmitFormEmptyExportJobAddsError(): void {
      $messengerMock = $this->setupContainerWithMessenger();
      $messengerMock->expects($this->once())->method('addError');
      $this->exportJobsMock->method('getExportJobs')->willReturn([]);
      $formStateMock = $this->buildFormStateMock();
      $formStateMock->method('getValue')->willReturn('100');
      $formStateMock->method('get')->willReturn('Denver');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

    public function testSubmitFormFailedResultAddsError(): void {
      $messengerMock = $this->setupContainerWithMessenger();
      $messengerMock->expects($this->once())->method('addError');
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['result' => 'fail', 'downloadUrl' => '']);
      $formStateMock = $this->buildFormStateMock();
      $formStateMock->method('getValue')->willReturn('100');
      $formStateMock->method('get')->willReturn('Denver');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

    public function testSubmitFormMissingDownloadUrlAddsError(): void {
      $messengerMock = $this->setupContainerWithMessenger();
      $messengerMock->expects($this->once())->method('addError');
      $this->exportJobsMock->method('getExportJobs')
        ->willReturn(['result' => 'ok', 'downloadUrl' => '']);
      $formStateMock = $this->buildFormStateMock();
      $formStateMock->method('getValue')->willReturn('100');
      $formStateMock->method('get')->willReturn('Denver');
      $form = [];
      $this->buildForm()->submitForm($form, $formStateMock);
    }

  }

}
