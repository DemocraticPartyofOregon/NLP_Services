<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Config\ImmutableConfig;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\TempStore\PrivateTempStore;
  use Drupal\Core\TempStore\PrivateTempStoreFactory;
  use Drupal\nlpservices\Form\CancelMovedReport;
  use Drupal\nlpservices\NlpReports;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpVoterListDisplay;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class CancelMovedReportTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpReports $reportsMock;
    private NlpSessionData $sessionMock;
    private PrivateTempStoreFactory $tempStoreFactoryMock;
    private PrivateTempStore $tempStoreMock;
    private NlpSearchBar $searchBarMock;
    private NlpVoterListDisplay $voterListDisplayMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock           = $this->createMock(NlpVoters::class);
      $this->reportsMock          = $this->createMock(NlpReports::class);
      $this->sessionMock          = $this->createMock(NlpSessionData::class);
      $this->tempStoreMock        = $this->createMock(PrivateTempStore::class);
      $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
      $this->tempStoreFactoryMock->method('get')
        ->with('nlpservices.session_data')
        ->willReturn($this->tempStoreMock);
      $this->searchBarMock        = $this->createMock(NlpSearchBar::class);
      $this->voterListDisplayMock = $this->createMock(NlpVoterListDisplay::class);
      $this->messengerMock        = $this->createMock(MessengerInterface::class);

      $configMock = $this->createMock(ImmutableConfig::class);
      $configMock->method('get')
        ->with('nlpservices-election-configuration')
        ->willReturn(['nlp_election_cycle' => '2024-11-G']);
      $configFactoryMock = $this->createMock(ConfigFactoryInterface::class);
      $configFactoryMock->method('get')->with('nlpservices.configuration')->willReturn($configMock);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      $container->set('tempstore.private', $this->tempStoreFactoryMock);
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

    private function buildForm(): CancelMovedReport {
      $form = new CancelMovedReport(
        $this->votersMock,
        $this->reportsMock,
        $this->sessionMock,
        $this->tempStoreFactoryMock,
        $this->searchBarMock,
        $this->voterListDisplayMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function baseVoter(array $overrides = []): array {
      return array_merge([
        'vanid'     => 1001,
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'moved'     => TRUE,
        'turfIndex' => 7,
      ], $overrides);
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_cancel_moved_report_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — county not set
    // -------------------------------------------------------------------------

    public function testBuildFormNoCountyAddsWarningAndReturnsEmptyArray(): void {
      $this->tempStoreMock->method('get')->with('County')->willReturn('');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        ''],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — county set, no search performed → "no voters" markup
    // -------------------------------------------------------------------------

    public function testBuildFormNoVoterArrayReturnsNoVoterMarkup(): void {
      $this->searchBarMock->method('searchBar')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('No voters to display', $result['voter-table']['turf-form']['#markup']);
      $this->assertArrayNotHasKey('chooseVoter', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — search needle present → searches voters and shows submit
    // -------------------------------------------------------------------------

    public function testBuildFormWithNeedleSearchesVotersAndShowsSubmitButton(): void {
      $voterArray = [1001 => $this->baseVoter()];

      $this->votersMock->expects($this->once())
        ->method('searchVoters')
        ->with('Denver', 'Doe')
        ->willReturn($voterArray);
      $this->searchBarMock->method('searchBar')->willReturn([]);
      $this->voterListDisplayMock->method('buildVoterTableArray')->willReturn([1001 => []]);
      $this->voterListDisplayMock->method('displayListOfVoters')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       NULL],
        ['county',        'Denver'],
        ['search-needle', 'Doe'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('chooseVoter', $result);
      $this->assertSame('chooseVoter', $result['chooseVoter']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter=TRUE skips tempstore lookup
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsTempstoreLookup(): void {
      $this->tempStoreMock->expects($this->never())->method('get');
      $this->searchBarMock->method('searchBar')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',       TRUE],
        ['county',        'Denver'],
        ['search-needle', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('voter-table', $result);
    }

    // -------------------------------------------------------------------------
    // submitForm — search_submit → stores needle
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('search-needle')->willReturn('Smith');
      $formState->expects($this->once())
        ->method('set')
        ->with('search-needle', 'Smith');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — chooseVoter, no selections → error and no status change
    // -------------------------------------------------------------------------

    public function testSubmitFormChooseVoterNoSelectionsAddsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'chooseVoter']);
      $formState->method('getValue')->with('contacts')->willReturn([]);
      $this->voterListDisplayMock->method('validateVoterSelection')->willReturn([]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — chooseVoter, selected voter was not marked moved → error
    // -------------------------------------------------------------------------

    public function testSubmitFormChooseVoterVoterNotMovedAddsError(): void {
      $voterArray = [1001 => $this->baseVoter(['moved' => FALSE])];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'chooseVoter']);
      $formState->method('getValue')->with('contacts')->willReturn(['ignored']);
      $formState->method('get')->with('voterArray')->willReturn($voterArray);
      $this->voterListDisplayMock->method('validateVoterSelection')
        ->willReturn([1001 => ['rowCount' => 0, 'vanid' => 1001]]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — chooseVoter, moved voter selected → status corrected
    // -------------------------------------------------------------------------

    public function testSubmitFormChooseVoterMovedVoterCorrectsStatus(): void {
      $voterArray = [1001 => $this->baseVoter()];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'chooseVoter']);
      $formState->method('getValue')->with('contacts')->willReturn(['ignored']);
      $formState->method('get')->with('voterArray')->willReturn($voterArray);
      $this->voterListDisplayMock->method('validateVoterSelection')
        ->willReturn([1001 => ['rowCount' => 0, 'vanid' => 1001]]);

      $this->reportsMock->method('getReport')->willReturn(['reportIndex' => 55]);
      $this->reportsMock->expects($this->once())->method('deleteReport')->with(55);
      $this->votersMock->expects($this->once())->method('setMovedStatus')->with(7, 1001, FALSE);
      $this->messengerMock->expects($this->once())->method('addMessage');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // correctMovedStatus — voter not marked moved → warning only
    // -------------------------------------------------------------------------

    public function testCorrectMovedStatusNotMovedAddsWarning(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $this->reportsMock->expects($this->never())->method('getReport');

      $this->buildForm()->correctMovedStatus($this->baseVoter(['moved' => FALSE]));
    }

    // -------------------------------------------------------------------------
    // correctMovedStatus — existing report deleted and moved flag cleared
    // -------------------------------------------------------------------------

    public function testCorrectMovedStatusDeletesExistingReportAndClearsFlag(): void {
      $this->reportsMock->method('getReport')->willReturn(['reportIndex' => 55]);
      $this->reportsMock->expects($this->once())->method('deleteReport')->with(55);
      $this->votersMock->expects($this->once())->method('setMovedStatus')->with(7, 1001, FALSE);
      $this->messengerMock->expects($this->once())->method('addMessage');

      $this->buildForm()->correctMovedStatus($this->baseVoter());
    }

    // -------------------------------------------------------------------------
    // correctMovedStatus — no existing report → delete skipped, flag cleared
    // -------------------------------------------------------------------------

    public function testCorrectMovedStatusNoExistingReportSkipsDelete(): void {
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->reportsMock->expects($this->never())->method('deleteReport');
      $this->votersMock->expects($this->once())->method('setMovedStatus')->with(7, 1001, FALSE);

      $this->buildForm()->correctMovedStatus($this->baseVoter());
    }

    // -------------------------------------------------------------------------
    // correctMovedStatus — no turf index → setMovedStatus skipped
    // -------------------------------------------------------------------------

    public function testCorrectMovedStatusNoTurfIndexSkipsSetMovedStatus(): void {
      $this->reportsMock->method('getReport')->willReturn([]);
      $this->votersMock->expects($this->never())->method('setMovedStatus');

      $this->buildForm()->correctMovedStatus($this->baseVoter(['turfIndex' => NULL]));
    }

  }

}
