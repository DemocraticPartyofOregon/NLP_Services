<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Truncate;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\ApiExportJobs;
use Drupal\nlpservices\Config\NlpResetForm;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpCoordinators;
use Drupal\nlpservices\NlpCrosstabCounts;
use Drupal\nlpservices\NlpInstructions;
use Drupal\nlpservices\NlpMatchbacks;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\NlpVoters;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(NlpResetForm::class)]
#[Group('nlpservices')]
class NlpResetFormTest extends UnitTestCase {

  private NlpVoters $votersObjMock;
  private NlpNls $nlsObjMock;
  private NlpTurfs $turfsObjMock;
  private NlpMatchbacks $matchbackObjMock;
  private ApiExportJobs $exportJobsObjMock;
  private NlpCrosstabCounts $crosstabCountsObjMock;
  private NlpInstructions $instructionsObjMock;
  private Connection $connectionMock;
  private NlpCoordinators $coordinatorObjMock;
  private DrupalUser $drupalUserObjMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->votersObjMock         = $this->createMock(NlpVoters::class);
    $this->nlsObjMock            = $this->createMock(NlpNls::class);
    $this->turfsObjMock          = $this->createMock(NlpTurfs::class);
    $this->matchbackObjMock      = $this->createMock(NlpMatchbacks::class);
    $this->exportJobsObjMock     = $this->createMock(ApiExportJobs::class);
    $this->crosstabCountsObjMock = $this->createMock(NlpCrosstabCounts::class);
    $this->instructionsObjMock   = $this->createMock(NlpInstructions::class);
    $this->connectionMock        = $this->createMock(Connection::class);
    $this->coordinatorObjMock    = $this->createMock(NlpCoordinators::class);
    $this->drupalUserObjMock     = $this->createMock(DrupalUser::class);
    $this->messengerMock         = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(): NlpResetForm {
    $form = new NlpResetForm(
      $this->votersObjMock,
      $this->nlsObjMock,
      $this->turfsObjMock,
      $this->matchbackObjMock,
      $this->exportJobsObjMock,
      $this->crosstabCountsObjMock,
      $this->instructionsObjMock,
      $this->connectionMock,
      $this->coordinatorObjMock,
      $this->drupalUserObjMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  /**
   * Stubs a successful truncate()->execute() for every table, so tests that
   * only care about the user-deletion logic don't have to think about it.
   */
  private function stubSuccessfulTruncate(): void {
    $truncateMock = $this->createMock(Truncate::class);
    $truncateMock->method('execute')->willReturn(0);
    $this->connectionMock->method('truncate')->willReturn($truncateMock);
  }

  private function nlUser(int $uid, array $roles): array {
    return [
      'uid' => $uid,
      'roles' => $roles,
      'firstName' => 'Test',
      'lastName' => 'User',
    ];
  }

  /**
   * Calls submitForm() with PHP warnings suppressed.
   * getDirContent() calls is_dir('public://...'), which emits a warning in
   * unit-test context (no stream wrapper registered); see NlpFileFunctionsTest
   * for the same pattern.
   */
  private function callSubmitForm(NlpResetForm $resetForm, ?FormState $formState = NULL): void {
    $form = [];
    set_error_handler(fn() => true);
    $resetForm->submitForm($form, $formState ?? new FormState());
    restore_error_handler();
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('nlp_reset_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormContainsSubmitButton(): void {
    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('submit', $form['actions']['submit']['#type']);
    $this->assertSame('add_coordinator', $form['actions']['submit']['#name']);
  }

  // ---------------------------------------------------------------------------
  // submitForm - table truncation
  // ---------------------------------------------------------------------------

  public function testSubmitFormTruncatesAllExpectedTablesAndReportsSuccess(): void {
    $truncatedTables = [];
    $truncateMock = $this->createMock(Truncate::class);
    $truncateMock->method('execute')->willReturn(0);
    $this->connectionMock->expects($this->exactly(12))
      ->method('truncate')
      ->willReturnCallback(function ($table) use (&$truncatedTables, $truncateMock) {
        $truncatedTables[] = $table;
        return $truncateMock;
      });

    $this->drupalUserObjMock->method('getUsers')->willReturn([]);

    $this->messengerMock->expects($this->exactly(12))->method('addStatus');

    $this->callSubmitForm($this->buildForm());

    $this->assertSame([
      NlpVoters::VOTER_TBL,
      NlpVoters::VOTER_STATUS_TBL,
      NlpVoters::VOTER_ADDRESS_TBL,
      NlpVoters::VOTER_TURF_TBL,
      NlpNls::NLS_TBL,
      NlpNls::NLS_STATUS_TBL,
      NlpTurfs::TURF_TBL,
      NlpCrosstabCounts::CROSSTAB_COUNTS_TBL,
      NlpMatchbacks::MATCHBACK_TBL,
      ApiExportJobs::EXPORT_JOBS_TBL,
      NlpInstructions::INSTRUCTIONS_TBL,
      NlpCoordinators::COORDINATOR_TBL,
    ], $truncatedTables);
  }

  public function testSubmitFormSwallowsExceptionAndSkipsStatusMessageOnTruncateFailure(): void {
    $this->connectionMock->method('truncate')->willThrowException(new \Exception('DB error'));
    $this->drupalUserObjMock->method('getUsers')->willReturn([]);

    $this->messengerMock->expects($this->never())->method('addStatus');

    $this->callSubmitForm($this->buildForm());
  }

  // ---------------------------------------------------------------------------
  // submitForm - NL login account cleanup
  // ---------------------------------------------------------------------------

  public function testSubmitFormDeletesUserWithOnlyNlpLeaderRole(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_leader']),
    ]);

    $this->drupalUserObjMock->expects($this->once())
      ->method('deleteUser')
      ->with(5);

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormDeletesUserWhenNlpLeaderRoleIsFirstInArray(): void {
    // Regression test: array_flip($roles) maps 'nlp_leader' to its array
    // index, so a naive `!empty($roleNames['nlp_leader'])` check is falsy
    // when nlp_leader is at index 0. The source uses isset() instead so
    // this ordering is handled correctly.
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['nlp_leader', 'authenticated']),
    ]);

    $this->drupalUserObjMock->expects($this->once())
      ->method('deleteUser')
      ->with(5);

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormSkipsUserWithoutNlpLeaderRole(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_coordinator']),
    ]);

    $this->drupalUserObjMock->expects($this->never())->method('deleteUser');

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormSkipsNlpLeaderWhoIsAlsoCoordinator(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_leader', 'nlp_coordinator']),
    ]);

    $this->drupalUserObjMock->expects($this->never())->method('deleteUser');

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormSkipsNlpLeaderWhoIsAlsoAdminOrAdministrator(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_leader', 'nlp_admin']),
      6 => $this->nlUser(6, ['authenticated', 'nlp_leader', 'administrator']),
    ]);

    $this->drupalUserObjMock->expects($this->never())->method('deleteUser');

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormSkipsNlpLeaderWithAdditionalUnrelatedRole(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_leader', 'some_other_role']),
    ]);

    $this->drupalUserObjMock->expects($this->never())->method('deleteUser');

    $this->callSubmitForm($this->buildForm());
  }

  public function testSubmitFormOnlyDeletesQualifyingUsersFromMixedSet(): void {
    $this->stubSuccessfulTruncate();
    $this->drupalUserObjMock->method('getUsers')->willReturn([
      5 => $this->nlUser(5, ['authenticated', 'nlp_leader']),
      6 => $this->nlUser(6, ['authenticated', 'nlp_leader', 'nlp_coordinator']),
      7 => $this->nlUser(7, ['authenticated', 'nlp_coordinator']),
    ]);

    $this->drupalUserObjMock->expects($this->once())
      ->method('deleteUser')
      ->with(5);

    $this->callSubmitForm($this->buildForm());
  }

}
