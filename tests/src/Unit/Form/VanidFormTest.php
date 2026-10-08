<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\nlpservices\ApiNls;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\VanidForm;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpAwards;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpLegislativeFixes;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpReports;
use Drupal\nlpservices\NlpTurfAssignments;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\nlpservices\NlpEncryption;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\VanidForm
 * @group nlpservices
 */
class VanidFormTest extends UnitTestCase {

  private const PREV_ID = 111111111;
  private const NEW_ID  = 222222222;

  private DrupalUser $drupalUserMock;
  private ApiNls $apiNlsMock;
  private NlpEncryption $nlpEncryptMock;
  private NlpAwards $awardsMock;
  private NlpCoordinators $coordinatorMock;
  private NlpLegislativeFixes $legFixesMock;
  private NlpReports $reportsMock;
  private NlpTurfAssignments $turfAssignmentsMock;
  private NlpTurfs $turfsMock;
  private NlpVoters $votersMock;
  private NlpNls $nlsMock;
  private MagicWord $magicWordMock;

  protected function setUp(): void {
    parent::setUp();

    $this->drupalUserMock      = $this->createMock(DrupalUser::class);
    $this->apiNlsMock          = $this->createMock(ApiNls::class);
    $this->nlpEncryptMock      = $this->createMock(NlpEncryption::class);
    $this->awardsMock          = $this->createMock(NlpAwards::class);
    $this->coordinatorMock     = $this->createMock(NlpCoordinators::class);
    $this->legFixesMock        = $this->createMock(NlpLegislativeFixes::class);
    $this->reportsMock         = $this->createMock(NlpReports::class);
    $this->turfAssignmentsMock = $this->createMock(NlpTurfAssignments::class);
    $this->turfsMock           = $this->createMock(NlpTurfs::class);
    $this->votersMock          = $this->createMock(NlpVoters::class);
    $this->nlsMock             = $this->createMock(NlpNls::class);
    $this->magicWordMock       = $this->createMock(MagicWord::class);

    $container = new ContainerBuilder();
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

  private function buildFormInstance(): VanidForm {
    $form = new VanidForm(
      $this->drupalUserMock,
      $this->apiNlsMock,
      $this->nlpEncryptMock,
      $this->awardsMock,
      $this->coordinatorMock,
      $this->legFixesMock,
      $this->reportsMock,
      $this->turfsMock,
      $this->votersMock,
      $this->nlsMock,
      $this->magicWordMock,
      $this->turfAssignmentsMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  private function submitState(): FormState {
    $state = new FormState();
    $state->set('previousId', self::PREV_ID);
    $state->set('newVanid', self::NEW_ID);
    return $state;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('vanid_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsPreviousIdNumberInput(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('previousId', $form);
    $this->assertSame('number', $form['previousId']['#type']);
    $this->assertTrue($form['previousId']['#required']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsNewIdNumberInput(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('newId', $form);
    $this->assertSame('number', $form['newId']['#type']);
    $this->assertTrue($form['newId']['#required']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsSubmitButton(): void {
    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertArrayHasKey('repairVanid', $form);
    $this->assertSame('submit', $form['repairVanid']['#type']);
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::validateForm
   */
  public function testValidateFormStoresPreviousIdInFormState(): void {
    $formState = new FormState();
    $formState->setValue('previousId', '123456789');
    $formState->setValue('newId', '000000000');

    $this->apiNlsMock->method('getApiNls')->willReturn([]);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame('123456789', $formState->get('previousId'));
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormStoresNewVanidInFormState(): void {
    $formState = new FormState();
    $formState->setValue('previousId', '123456789');
    $formState->setValue('newId', '987654321');

    $this->apiNlsMock->method('getApiNls')->willReturn(['vanid' => 987654321]);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame('987654321', $formState->get('newVanid'));
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormPreviousIdWrongLengthSetsError(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->setValue('previousId', '12345');
    $formState->setValue('newId', '987654321');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('previousId', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormPreviousIdWrongLengthSkipsNewIdCheck(): void {
    $this->apiNlsMock->expects($this->never())->method('getApiNls');

    $formState = new FormState();
    $formState->setValue('previousId', '12345');
    $formState->setValue('newId', '987654321');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormNewIdWrongLengthSetsError(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->setValue('previousId', '123456789');
    $formState->setValue('newId', '12345');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('newId', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormNewIdNotInVoteBuilderSetsError(): void {
    $this->apiNlsMock->method('getApiNls')->willReturn([]);

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->setValue('previousId', '123456789');
    $formState->setValue('newId', '987654321');
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('newId', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormBothValidSetsNoError(): void {
    $this->apiNlsMock->method('getApiNls')->willReturn(['vanid' => 987654321]);

    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setErrorByName'])
      ->getMock();
    $formState->setValue('previousId', '123456789');
    $formState->setValue('newId', '987654321');
    $formState->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // submitForm — user login record
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoPreviousUserDeletesMagicWordOnly(): void {
    $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
    $this->magicWordMock->expects($this->once())
      ->method('deleteMagicWord')
      ->with(self::PREV_ID);
    $this->drupalUserMock->expects($this->never())->method('deleteUser');
    $this->drupalUserMock->expects($this->never())->method('updateUser');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousAndNewUserBothExistDeletesOldUser(): void {
    $this->drupalUserMock->method('getUserByMcid')
      ->willReturnMap([
        [self::PREV_ID, ['uid' => 10, 'mcid' => self::PREV_ID]],
        [self::NEW_ID,  ['uid' => 20, 'mcid' => self::NEW_ID]],
      ]);
    $this->drupalUserMock->expects($this->once())
      ->method('deleteUser')
      ->with(10);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousAndNewUserBothExistDeletesMagicWord(): void {
    $this->drupalUserMock->method('getUserByMcid')
      ->willReturnMap([
        [self::PREV_ID, ['uid' => 10, 'mcid' => self::PREV_ID]],
        [self::NEW_ID,  ['uid' => 20, 'mcid' => self::NEW_ID]],
      ]);
    $this->magicWordMock->expects($this->once())
      ->method('deleteMagicWord')
      ->with(self::PREV_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousUserFoundNewUserMissingUpdatesUserRecord(): void {
    $this->drupalUserMock->method('getUserByMcid')
      ->willReturnMap([
        [self::PREV_ID, ['uid' => 10, 'mcid' => self::PREV_ID]],
        [self::NEW_ID,  []],
      ]);
    $this->magicWordMock->method('getMagicWord')->willReturn('secret');
    $this->drupalUserMock->expects($this->once())
      ->method('updateUser')
      ->with(['uid' => 10, 'mcid' => self::NEW_ID]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousUserFoundNewUserMissingTransfersCredentials(): void {
    $this->drupalUserMock->method('getUserByMcid')
      ->willReturnMap([
        [self::PREV_ID, ['uid' => 10, 'mcid' => self::PREV_ID]],
        [self::NEW_ID,  []],
      ]);
    $credentials = ['mcid' => self::PREV_ID, 'magicWordEncrypted' => 'enc', 'userName' => 'asmith',
      'email' => 'a@example.com', 'roles' => ['nlp_leader' => 'NL'], 'vfid' => 50];
    $this->magicWordMock->method('getCredentials')
      ->with(self::PREV_ID, FALSE)
      ->willReturn($credentials);
    $this->magicWordMock->expects($this->once())
      ->method('createCredentials')
      ->with(['mcid' => self::NEW_ID] + $credentials);
    $this->magicWordMock->expects($this->never())->method('setMagicWord');
    $this->magicWordMock->expects($this->once())
      ->method('deleteMagicWord')
      ->with(self::PREV_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — awards
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoAwardsSkipsAwardTransfer(): void {
    $this->awardsMock->method('getAward')->willReturn([]);
    $this->awardsMock->expects($this->never())->method('deleteAwards');
    $this->awardsMock->expects($this->never())->method('mergeAward');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormAwardsFoundDeletesOldAndMergesWithNewId(): void {
    $award = ['mcid' => self::PREV_ID, 'level' => 3];
    $this->awardsMock->method('getAward')->with(self::PREV_ID)->willReturn($award);
    $this->awardsMock->expects($this->once())->method('deleteAwards')->with(self::PREV_ID);
    $this->awardsMock->expects($this->once())
      ->method('mergeAward')
      ->with(['mcid' => self::NEW_ID, 'level' => 3]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — coordinator assignments
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoCoordinatorAssignmentsSkipsTransfer(): void {
    $this->coordinatorMock->method('getCoordinatorById')->willReturn([]);
    $this->coordinatorMock->expects($this->never())->method('deleteCoordinator');
    $this->coordinatorMock->expects($this->never())->method('createCoordinator');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormCoordinatorAssignmentsReassignedToNewId(): void {
    $assignments = [
      ['cIndex' => 5, 'mcid' => self::PREV_ID, 'county' => 'TestCounty'],
      ['cIndex' => 6, 'mcid' => self::PREV_ID, 'county' => 'OtherCounty'],
    ];
    $this->coordinatorMock->method('getCoordinatorById')->willReturn($assignments);
    $this->coordinatorMock->expects($this->exactly(2))->method('deleteCoordinator');
    $this->coordinatorMock->expects($this->exactly(2))
      ->method('createCoordinator')
      ->with($this->callback(fn(array $a): bool => $a['mcid'] === self::NEW_ID && !isset($a['cIndex'])));

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — legislative fixes
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoLegFixSkipsTransfer(): void {
    $this->legFixesMock->method('getLegFix')->willReturn([]);
    $this->legFixesMock->expects($this->never())->method('deleteLegFix');
    $this->legFixesMock->expects($this->never())->method('createLegFix');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormLegFixFoundDeletesAndRecreatWithNewId(): void {
    $fix = ['mcid' => self::PREV_ID, 'district' => 12];
    $this->legFixesMock->method('getLegFix')->with(self::PREV_ID)->willReturn($fix);
    $this->legFixesMock->expects($this->once())->method('deleteLegFix')->with(self::PREV_ID);
    $this->legFixesMock->expects($this->once())
      ->method('createLegFix')
      ->with(['mcid' => self::NEW_ID, 'district' => 12]);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — reports
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoReportsSkipsTransfer(): void {
    $this->reportsMock->method('getReportsById')->willReturn([]);
    $this->reportsMock->expects($this->never())->method('mergeReport');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormReportsMergedWithNewId(): void {
    $this->reportsMock->method('getReportsById')->with(self::PREV_ID)->willReturn([7, 8]);
    $this->reportsMock->expects($this->exactly(2))
      ->method('mergeReport')
      ->with($this->callback(fn(array $r): bool => $r['mcid'] === self::NEW_ID));

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — turfs
  // -------------------------------------------------------------------------

  /**
   * The turfs move by MCID alone, so they still move after an NLs sync has
   * removed the old MCID's nlp_nls record.
   *
   * @covers ::submitForm
   */
  public function testSubmitFormTurfsReassignedToNewId(): void {
    $this->turfsMock->expects($this->never())->method('getTurfsById');
    $this->turfsMock->expects($this->once())
      ->method('reassignNlTurfs')
      ->with(self::PREV_ID, self::NEW_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — turf assignments
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNoTurfAssignmentsSkipsUpdate(): void {
    $this->turfAssignmentsMock->method('getTurfAssignmentsById')->willReturn([]);
    $this->turfAssignmentsMock->expects($this->never())->method('setTurfAssignment');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormTurfAssignmentsReassignedToNewId(): void {
    $this->turfAssignmentsMock->method('getTurfAssignmentsById')
      ->with(self::PREV_ID)
      ->willReturn([['turfId' => 9], ['turfId' => 10]]);
    $updated = [];
    $this->turfAssignmentsMock->expects($this->exactly(2))
      ->method('setTurfAssignment')
      ->willReturnCallback(function (array $assignment) use (&$updated) {
        $updated[] = $assignment;
      });

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());

    $this->assertSame([
      ['turfId' => 9, 'mcid' => self::NEW_ID],
      ['turfId' => 10, 'mcid' => self::NEW_ID],
    ], $updated);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormTurfVotersReassignedToNewId(): void {
    $this->votersMock->expects($this->once())
      ->method('reassignNlVoters')
      ->with(self::PREV_ID, self::NEW_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — NL records: new NL exists in VoteBuilder
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNewNlExistsWithCountyDeletesPreviousNl(): void {
    $this->nlsMock->method('getNlById')
      ->willReturnMap([
        [self::NEW_ID,  ['mcid' => self::NEW_ID, 'county' => 'TestCounty']],
        [self::PREV_ID, []],
      ]);
    $this->nlsMock->method('getNlsStatus')->willReturn([]);
    $this->nlsMock->expects($this->once())->method('deleteNl')->with(self::PREV_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNewNlExistsNoPreviousStatusSkipsStatusMerge(): void {
    $this->nlsMock->method('getNlById')
      ->willReturnMap([
        [self::NEW_ID,  ['mcid' => self::NEW_ID, 'county' => 'TestCounty']],
        [self::PREV_ID, []],
      ]);
    $this->nlsMock->method('getNlsStatus')->willReturn([]);
    $this->nlsMock->expects($this->never())->method('setNlsStatus');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNewNlExistsMergesStatusFlagsFromPrevious(): void {
    $this->nlsMock->method('getNlById')
      ->willReturnMap([
        [self::NEW_ID,  ['mcid' => self::NEW_ID, 'county' => 'TestCounty']],
        [self::PREV_ID, []],
      ]);
    $previousStatus = [
      'mcid' => self::PREV_ID, 'county' => 'TestCounty',
      'signedUp' => TRUE, 'turfCut' => FALSE,
      'turfDelivered' => TRUE, 'resultsReported' => FALSE,
      'asked' => 'Y', 'notes' => 'previous notes',
    ];
    $newStatus = [
      'mcid' => self::NEW_ID, 'county' => 'TestCounty',
      'signedUp' => FALSE, 'turfCut' => FALSE,
      'turfDelivered' => FALSE, 'resultsReported' => FALSE,
      'asked' => NlpNls::DASH, 'notes' => '',
    ];
    $this->nlsMock->method('getNlsStatus')
      ->willReturnMap([
        [self::PREV_ID, '', $previousStatus],
        [self::NEW_ID,  '', $newStatus],
      ]);
    $this->nlsMock->expects($this->once())
      ->method('setNlsStatus')
      ->with($this->callback(function (array $s): bool {
        return $s['signedUp'] === TRUE
          && $s['turfDelivered'] === TRUE
          && $s['asked'] === 'Y'
          && $s['notes'] === 'previous notes';
      }));
    $this->nlsMock->expects($this->once())->method('deleteNlsStatus')->with(self::PREV_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  // -------------------------------------------------------------------------
  // submitForm — NL records: new NL not in VoteBuilder, previous NL exists
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousNlOnlyCreatesNlWithNewId(): void {
    $prevNl = ['mcid' => self::PREV_ID, 'county' => 'TestCounty', 'firstName' => 'Jane'];
    $this->nlsMock->method('getNlById')
      ->willReturnMap([
        [self::NEW_ID,  []],
        [self::PREV_ID, $prevNl],
      ]);
    $this->nlsMock->method('getNlsStatus')->willReturn(['mcid' => self::PREV_ID, 'county' => 'TestCounty']);
    $this->nlsMock->expects($this->once())->method('deleteNl')->with(self::PREV_ID);
    $this->nlsMock->expects($this->once())
      ->method('createNl')
      ->with($this->callback(fn(array $nl): bool => $nl['mcid'] === self::NEW_ID));

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormPreviousNlOnlyTransfersStatusToNewId(): void {
    $prevNl = ['mcid' => self::PREV_ID, 'county' => 'TestCounty', 'firstName' => 'Jane'];
    $prevStatus = ['mcid' => self::PREV_ID, 'county' => 'TestCounty'];
    $this->nlsMock->method('getNlById')
      ->willReturnMap([
        [self::NEW_ID,  []],
        [self::PREV_ID, $prevNl],
      ]);
    $this->nlsMock->method('getNlsStatus')->with(self::PREV_ID)->willReturn($prevStatus);
    $this->nlsMock->expects($this->once())
      ->method('setNlsStatus')
      ->with($this->callback(fn(array $s): bool => $s['mcid'] === self::NEW_ID));
    $this->nlsMock->expects($this->once())->method('deleteNlsStatus')->with(self::PREV_ID);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormNeitherNlExistsSkipsAllNlOperations(): void {
    $this->nlsMock->method('getNlById')->willReturn([]);
    $this->nlsMock->expects($this->never())->method('deleteNl');
    $this->nlsMock->expects($this->never())->method('createNl');
    $this->nlsMock->expects($this->never())->method('setNlsStatus');

    $form = [];
    $this->buildFormInstance()->submitForm($form, $this->submitState());
  }

}
