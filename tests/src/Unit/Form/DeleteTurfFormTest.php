<?php

// Global stubs for functions not available in the unit-test autoloader.
namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('t')) {
    function t($string, array $args = [], array $options = []): string { return $string; }
  }
}

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiFolders;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfDisplay;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\Form\DeleteTurfForm;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class DeleteTurfFormTest extends UnitTestCase {

    private NlpTurfs $turfsMock;
    private NlpAwards $awardsMock;
    private NlpSearchBar $searchBarMock;
    private NlpTurfDisplay $turfDisplayMock;
    private NlpSessionData $sessionDataMock;
    private NlpConfiguration $configurationMock;
    private ApiFolders $foldersMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->turfsMock        = $this->createMock(NlpTurfs::class);
      $this->awardsMock       = $this->createMock(NlpAwards::class);
      $this->searchBarMock    = $this->createMock(NlpSearchBar::class);
      $this->turfDisplayMock  = $this->createMock(NlpTurfDisplay::class);
      $this->sessionDataMock  = $this->createMock(NlpSessionData::class);
      $this->configurationMock = $this->createMock(NlpConfiguration::class);
      $this->foldersMock      = $this->createMock(ApiFolders::class);
      $this->messengerMock    = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): DeleteTurfForm {
      return new DeleteTurfForm(
        $this->turfsMock,
        $this->awardsMock,
        $this->searchBarMock,
        $this->turfDisplayMock,
        $this->sessionDataMock,
        $this->configurationMock,
        $this->foldersMock,
      );
    }

    private function baseTurfRecord(): array {
      return [
        'turfIndex'  => 7,
        'syncDate'   => '2025-01-01',
        'nickname'   => 'Alice',
        'nlLastName' => 'Smith',
        'turfName'   => '123rd Ave',
        'mcid'       => 42,
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_delete_turf_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — county not set
    // -------------------------------------------------------------------------

    public function testBuildFormNoCountyAddsWarningAndReturnsEmptyArray(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('reenter')->willReturn(NULL);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set but no turfs for the selected HD
    // -------------------------------------------------------------------------

    public function testBuildFormNoTurfsReturnsNoTurfMarkup(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01', 2 => '02']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(10); // < TURF_ALL_MAX → prepends 'All'
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('no turf to display', $result['turf-table']['turf-form']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set with turfs → full form with submit button
    // -------------------------------------------------------------------------

    public function testBuildFormWithTurfsIncludesSubmitButton(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->sessionDataMock->method('getHdOptions')->willReturn([1 => '01']);
      $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->turfDisplayMock->method('buildTurfTableArray')->willReturn([1 => []]);
      $this->turfDisplayMock->method('displayTurfs')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('submit', $result);
      $this->assertSame('delete_submit', $result['submit']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter=TRUE skips county/HD initialisation
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsCountySetup(): void {
      $this->sessionDataMock->expects($this->never())->method('getCounty');
      $this->sessionDataMock->method('getSavedHd')->willReturn('');
      // No turfs → no-turf markup returned.
      $this->turfsMock->method('getTurfs')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       TRUE],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('turf-table', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — search needle causes searchInfoArray to be called
    // -------------------------------------------------------------------------

    public function testBuildFormWithNeedleCallsSearchInfoArray(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurfHD')->willReturn([1 => '01']);
      $this->turfsMock->method('getCountyTurfCount')->willReturn(1);
      $this->sessionDataMock->method('getSavedHd')->willReturn('01');
      $this->turfsMock->method('getTurfs')->willReturn([7 => $this->baseTurfRecord()]);
      $this->searchBarMock->expects($this->once())
        ->method('searchInfoArray')
        ->with('Smith', $this->isType('array'))
        ->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', 'Smith'],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — hd-select clicked → saves index, skips selection check
    // -------------------------------------------------------------------------

    public function testValidateFormHdSelectSavesHdIndexAndReturns(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
      $formState->method('getValue')->with('hd-select')->willReturn(2);

      $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);
      $this->turfDisplayMock->expects($this->never())->method('validateTurfSelection');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — delete clicked, no selection → form error
    // -------------------------------------------------------------------------

    public function testValidateFormNoSelectionSetsFormError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => []]);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('submit', $this->stringContains('select at least one'));

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — delete clicked, valid selection → no error set
    // -------------------------------------------------------------------------

    public function testValidateFormWithValidSelectionDoesNotSetError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('getValues')->willReturn(['contacts' => [7 => 1]]);
      $formState->expects($this->never())->method('setErrorByName');

      $this->turfDisplayMock->method('validateTurfSelection')->willReturn([7 => 7]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — search_submit → stores needle
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('nl-search-needle')->willReturn('Smith');
      $formState->expects($this->atLeast(1))
        ->method('set')
        ->with($this->logicalOr(
          $this->equalTo('reenter'),
          $this->equalTo('search-needle'),
        ), $this->anything());

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — delete succeeds → nlParticipation called, status added
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteSuccessCallsNlParticipationAndAddsStatus(): void {
      $turfArray = [7 => $this->baseTurfRecord()];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7]],
      ]);

      $this->turfsMock->expects($this->once())->method('deleteTurf')->willReturn(TRUE);
      $this->awardsMock->expects($this->once())->method('nlParticipation')->with(42, 'Yes');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — delete fails → error added, nlParticipation NOT called
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteFailureAddsErrorAndSkipsAwards(): void {
      $turfArray = [7 => $this->baseTurfRecord()];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7]],
      ]);

      $this->turfsMock->expects($this->once())->method('deleteTurf')->willReturn(FALSE);
      $this->awardsMock->expects($this->never())->method('nlParticipation');
      $this->messengerMock->expects($this->once())->method('addError');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — multiple turfs deleted → nlParticipation called once per turf
    // -------------------------------------------------------------------------

    public function testSubmitFormDeleteMultipleTurfsCallsNlParticipationForEach(): void {
      $turfArray = [
        7 => $this->baseTurfRecord(),
        8 => array_merge($this->baseTurfRecord(), ['turfIndex' => 8, 'mcid' => 99]),
      ];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete_submit']);
      $formState->method('get')->willReturnMap([
        ['county',     'Denver'],
        ['turfArray',  $turfArray],
        ['selections', [7 => 7, 8 => 8]],
      ]);

      $this->turfsMock->method('deleteTurf')->willReturn(TRUE);
      $this->awardsMock->expects($this->exactly(2))->method('nlParticipation');
      $this->messengerMock->expects($this->exactly(2))->method('addStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // selectboxCallback — returns form['hd-change']
    // -------------------------------------------------------------------------

    public function testSelectboxCallbackReturnsHdChangeElement(): void {
      $form = ['hd-change' => ['#type' => 'fieldset', '#title' => 'Select HD']];
      $formState = $this->createMock(FormStateInterface::class);
      $result = $this->buildForm()->selectboxCallback($form, $formState);
      $this->assertSame($form['hd-change'], $result);
    }

  }

}
