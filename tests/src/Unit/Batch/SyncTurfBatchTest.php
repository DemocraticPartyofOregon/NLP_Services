<?php

namespace Drupal\Tests\nlpservices\Unit\Batch {

  use Drupal;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Core\Logger\LoggerChannelInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Batch\SyncTurfBatch;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class SyncTurfBatchTest extends UnitTestCase {

    private MessengerInterface $messengerMock;
    private NlpTurfs $turfsMock;
    private DrupalUser $drupalUserMock;
    private MagicWord $magicWordMock;
    private NlpNls $nlsMock;
    private NlpSendEmail $sendEmailMock;

    protected function setUp(): void {
      parent::setUp();
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->turfsMock      = $this->createMock(NlpTurfs::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->magicWordMock  = $this->createMock(MagicWord::class);
      $this->nlsMock        = $this->createMock(NlpNls::class);
      $this->sendEmailMock  = $this->createMock(NlpSendEmail::class);

      $this->buildContainer();
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildContainer(): void {
      $container = new ContainerBuilder();
      $container->set('string_translation',       $this->getStringTranslationStub());
      $container->set('messenger',                $this->messengerMock);
      $container->set('nlpservices.turfs',        $this->turfsMock);
      $container->set('nlpservices.drupal_user',  $this->drupalUserMock);
      $container->set('nlpservices.magic_word',   $this->magicWordMock);
      $container->set('nlpservices.nls',          $this->nlsMock);
      $container->set('nlpservices.send_email',   $this->sendEmailMock);
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
      $container->set('logger.factory',           $loggerFactory);
      Drupal::setContainer($container);
    }

    private function baseSuccessResults(array $overrides = []): array {
      return array_merge([
        'result'      => 'success',
        'turfName'    => 'Elm Street',
        'mcid'        => 42,
        'county'      => 'Denver',
        'voterCount'  => 10,
        'turfCnt'     => 10,
        'nickname'    => 'Alice',
        'turfIndex'   => 7,
        'movedVoters' => [],
      ], $overrides);
    }

    private function baseTurfExists(): array {
      return [
        'turfCnt' => 1,
        'turfs'   => [['turfIndex' => 7, 'turfName' => 'Elm Street', 'syncDate' => '2025-01-01']],
      ];
    }

    private function movedVoterRecord(int $mcid = 99): array {
      return [
        'firstName'   => 'Bob',
        'lastName'    => 'Jones',
        'oldTurfName' => 'Oak Ave',
        'oldMcid'     => $mcid,
        'oldNlName'   => 'Carol Davis',
      ];
    }

    // -------------------------------------------------------------------------
    // finished — $success = false
    // -------------------------------------------------------------------------

    public function testFinishedBatchFailureAddsError(): void {
      $this->messengerMock->expects($this->once())->method('addError');
      SyncTurfBatch::finished(FALSE, [], []);
    }

    public function testFinishedBatchFailureAfterCommitWarnsInsteadOfError(): void {
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->once())->method('addWarning');
      SyncTurfBatch::finished(FALSE, ['committed' => TRUE, 'turfName' => 'Elm Street'], []);
    }

    // -------------------------------------------------------------------------
    // finished — result = error
    // -------------------------------------------------------------------------

    public function testFinishedErrorResultNeverDeletesATurf(): void {
      // A failed sync leaves nothing in the database, so even a stale turfIndex
      // in the results must not trigger a delete.
      $this->messengerMock->expects($this->once())->method('addError');
      $this->turfsMock->expects($this->never())->method('deleteTurf');

      SyncTurfBatch::finished(TRUE, [
        'result'     => 'error',
        'message'    => ['Something went wrong.'],
        'turfIndex'  => 7,
        'mcid'       => 42,
        'county'     => 'Denver',
      ], []);
    }

    public function testFinishedErrorResultAddsOneMessagePerEntry(): void {
      $this->messengerMock->expects($this->exactly(3))->method('addError');

      SyncTurfBatch::finished(TRUE, [
        'result'  => 'error',
        'message' => ['Error one.', 'Error two.', 'Error three.'],
      ], []);
    }

    // -------------------------------------------------------------------------
    // finished — success with no results recorded
    // -------------------------------------------------------------------------

    public function testFinishedSuccessWithEmptyResultsWarnsWithoutLookingUpTurfs(): void {
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->once())->method('addWarning');
      $this->turfsMock->expects($this->never())->method('turfExists');

      SyncTurfBatch::finished(TRUE, [], []);
    }

    public function testFinishedSuccessWithNullMcidWarnsWithoutLookingUpTurfs(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');
      $this->turfsMock->expects($this->never())->method('turfExists');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults(['mcid' => NULL]), []);
    }

    public function testFinishedSuccessWhenTurfNoLongerExistsReportsZeroTurfs(): void {
      $this->turfsMock->method('turfExists')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->messengerMock->expects($this->never())->method('addWarning');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults(), []);
    }

    // -------------------------------------------------------------------------
    // finished — result = success, no drupal user
    // -------------------------------------------------------------------------

    public function testFinishedSuccessWithNoDrupalUserSkipsStatusMessage(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      $this->messengerMock->expects($this->never())->method('addStatus');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults(), []);
    }

    // -------------------------------------------------------------------------
    // finished — result = success, with drupal user
    // -------------------------------------------------------------------------

    public function testFinishedSuccessWithDrupalUserAddsStatusMessage(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')
        ->willReturn(['userName' => 'asmith', 'mcid' => 42]);
      $this->magicWordMock->method('getMagicWord')->willReturn('secret');

      $this->messengerMock->expects($this->once())->method('addStatus');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults(), []);
    }

    // -------------------------------------------------------------------------
    // finished — result = success, no moved voters
    // -------------------------------------------------------------------------

    public function testFinishedSuccessWithNoMovedVotersSkipsWarningAndEmail(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      $this->messengerMock->expects($this->never())->method('addWarning');
      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults(['movedVoters' => []]), []);
    }

    // -------------------------------------------------------------------------
    // finished — result = success, with moved voters
    // -------------------------------------------------------------------------

    public function testFinishedSuccessWithMovedVotersAddsWarning(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn(['email' => '']);

      $this->messengerMock->expects($this->once())->method('addWarning');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults([
        'movedVoters' => [101 => $this->movedVoterRecord()],
      ]), []);
    }

    public function testFinishedSuccessMovedVoterWithEmailSendsEmail(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn(['email' => 'carol@example.com']);

      $this->sendEmailMock->expects($this->once())->method('sendEmail')
        ->with('deliver_turf', 'carol@example.com', $this->anything(), $this->anything());

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults([
        'movedVoters' => [101 => $this->movedVoterRecord(99)],
      ]), []);
    }

    public function testFinishedSuccessMovedVoterWithNoEmailSkipsEmail(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn(['email' => '']);

      $this->sendEmailMock->expects($this->never())->method('sendEmail');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults([
        'movedVoters' => [101 => $this->movedVoterRecord(99)],
      ]), []);
    }

    public function testFinishedSuccessMovedVotersTwoNlsSendsTwoEmails(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturnMap([
        [99,  ['email' => 'carol@example.com']],
        [100, ['email' => 'dave@example.com']],
      ]);

      $this->sendEmailMock->expects($this->exactly(2))->method('sendEmail');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults([
        'movedVoters' => [
          101 => $this->movedVoterRecord(99),
          102 => array_merge($this->movedVoterRecord(100), ['firstName' => 'Sue', 'lastName' => 'Lee']),
        ],
      ]), []);
    }

    public function testFinishedSuccessMultipleMovedVotersSameNlSendsOneEmail(): void {
      $this->turfsMock->method('turfExists')->willReturn($this->baseTurfExists());
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->nlsMock->method('getNlById')->willReturn(['email' => 'carol@example.com']);

      $this->sendEmailMock->expects($this->once())->method('sendEmail');

      SyncTurfBatch::finished(TRUE, $this->baseSuccessResults([
        'movedVoters' => [
          101 => $this->movedVoterRecord(99),
          102 => array_merge($this->movedVoterRecord(99), ['firstName' => 'Sue', 'lastName' => 'Lee']),
        ],
      ]), []);
    }

  }

}
