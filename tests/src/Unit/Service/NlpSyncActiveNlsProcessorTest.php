<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\StringTranslation\TranslatableMarkup;
  use Drupal\nlpservices\ApiActivistCodes;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpLegislativeFixes;
  use Drupal\nlpservices\NlpMcidReassignment;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSyncActiveNlsProcessor;
  use Drupal\Tests\UnitTestCase;
  use PHPUnit\Framework\Constraint\Callback;

  class NlpSyncActiveNlsProcessorTest extends UnitTestCase {

    private ApiNls $apiNlsMock;
    private NlpLegislativeFixes $legFixesMock;
    private NlpConfiguration $configMock;
    private MagicWord $magicWordMock;
    private DrupalUser $drupalUserMock;
    private NlpNls $nlsMock;
    private NlpAwards $awardsMock;
    private MessengerInterface $messengerMock;
    private ApiActivistCodes $activistCodesMock;
    private NlpMcidReassignment $reassignmentMock;

    /** Temp files created during a test — cleaned up in tearDown. */
    private array $tempFiles = [];

    /** Controls what getLegFixes() returns; override per-test before calling processBatch. */
    private array $legFixesData = [];

    protected function setUp(): void {
      parent::setUp();
      $this->apiNlsMock     = $this->createMock(ApiNls::class);
      $this->legFixesMock   = $this->createMock(NlpLegislativeFixes::class);
      $this->configMock     = $this->createMock(NlpConfiguration::class);
      $this->magicWordMock  = $this->createMock(MagicWord::class);
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->nlsMock        = $this->createMock(NlpNls::class);
      $this->awardsMock     = $this->createMock(NlpAwards::class);
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->activistCodesMock = $this->createMock(ApiActivistCodes::class);
      $this->reassignmentMock  = $this->createMock(NlpMcidReassignment::class);

      // Default stubs so tests only need to override what they care about.
      $this->legFixesMock->method('getLegFixes')->willReturnCallback(fn() => $this->legFixesData);
      $this->configMock->method('getActivistCode')->willReturnMap([
        ['NLPNotRegisteredAC', 11],
        ['NLPDropoutAC',       22],
      ]);
      $this->configMock->method('getStateName')->willReturn('Colorado');
    }

    protected function tearDown(): void {
      parent::tearDown();
      foreach ($this->tempFiles as $path) {
        if (file_exists($path)) { unlink($path); }
      }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildProcessor(): NlpSyncActiveNlsProcessor {
      return new NlpSyncActiveNlsProcessor(
        $this->apiNlsMock,
        $this->legFixesMock,
        $this->configMock,
        $this->magicWordMock,
        $this->drupalUserMock,
        $this->nlsMock,
        $this->awardsMock,
        $this->messengerMock,
        $this->activistCodesMock,
        $this->reassignmentMock,
      );
    }

    /**
     * Write rows to a temp CSV and return its path.
     * First element of $rows must be the header.
     */
    private function writeTempCsv(array $rows): string {
      $path = tempnam(sys_get_temp_dir(), 'nlp_test_');
      $fh   = fopen($path, 'w');
      foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    /** Standard header matching the activist-code names returned by configMock. */
    private function csvHeader(): array {
      return ['VanID', 'ActivistCode_11', 'ActivistCode_22'];
    }

    /** A data row with all flags clear. */
    private function csvRow(int $mcid, bool $notRegistered = FALSE, bool $dropout = FALSE): array {
      return [$mcid, $notRegistered ? '1' : '', $dropout ? '1' : ''];
    }

    /** Matches a messenger message (string or TranslatableMarkup) by its untranslated text. */
    private function messageContains(string $needle): Callback {
      return $this->callback(function ($message) use ($needle) {
        $text = $message instanceof TranslatableMarkup ? $message->getUntranslatedString() : (string) $message;
        return str_contains($text, $needle);
      });
    }

    private function baseArg(string $uri): array {
      return ['uri' => $uri, 'county' => 'Denver'];
    }

    private function baseNlRecord(array $overrides = []): array {
      return array_merge([
        'mcid'      => 101,
        'firstName' => 'Alice',
        'lastName'  => 'Smith',
        'nickname'  => '',
        'email'     => 'alice@example.com',
        'phone'     => '555-1234',
        'address'   => '123 Main St',
        'city'      => 'Denver',
        'zip'       => '80201',
        'county'    => 'Denver',
        'hd'        => '06',
        'precinct'  => '6.1',
        'vfid'      => 50,
      ], $overrides);
    }

    private function baseDrupalUser(array $overrides = []): array {
      return array_merge([
        'uid'       => 1,
        'mcid'      => 101,
        'userName'  => 'asmith',
        'email'     => 'alice@example.com',
        'firstName' => 'Alice',
        'lastName'  => 'Smith',
        'phone'     => '555-1234',
        'county'    => 'Denver',
        'hd'        => '6',
        'roles'     => ['nlp_leader'],
        'created'   => 0,
        'access'    => 0,
      ], $overrides);
    }

    // -------------------------------------------------------------------------
    // processBatch — file I/O errors
    // -------------------------------------------------------------------------

    public function testProcessBatchFileNotOpenableAddsWarningAndSetsFinished(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');

      $context = [];
      $this->buildProcessor()->processBatch(
        $this->baseArg('/nonexistent/missing_file.csv'),
        $context
      );

      $this->assertTrue($context['finished']);
    }

    // -------------------------------------------------------------------------
    // processBatch — header-only CSV
    // -------------------------------------------------------------------------

    public function testProcessBatchHeaderOnlyCsvFinishesWithZeroRecords(): void {
      $uri = $this->writeTempCsv([$this->csvHeader()]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame(1, $context['finished']);
      $this->assertSame(0, $context['results']['recordCount']);
    }

    public function testProcessBatchRefreshesActiveNlsSyncMarkAndReportsCounty(): void {
      $uri = $this->writeTempCsv([$this->csvHeader()]);
      $county = $this->baseArg($uri)['county'];
      $this->nlsMock->expects($this->once())->method('markActiveNlsSync')->with($county);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame($county, $context['results']['county']);
    }

    // -------------------------------------------------------------------------
    // processBatch — deleting the old list of active NLs
    // -------------------------------------------------------------------------

    public function testProcessBatchFirstStepDeletesTheOldNls(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([]);
      $this->nlsMock->expects($this->once())->method('deleteNls')->with('Denver');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    public function testProcessBatchLaterStepDoesNotDeleteTheNlsAgain(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101), $this->csvRow(102)]);
      $this->apiNlsMock->method('getNls')->willReturn([]);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      // The next step runs in a new request with a fresh processor.
      $this->nlsMock = $this->createMock(NlpNls::class);
      $this->nlsMock->expects($this->never())->method('deleteNls');
      $context['finished'] = 0;
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    public function testProcessBatchUnreadableFileKeepsTheOldNls(): void {
      $this->nlsMock->expects($this->never())->method('deleteNls');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg('/nonexistent/missing_file.csv'), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — NL not returned by API (unknown mcid)
    // -------------------------------------------------------------------------

    public function testProcessBatchUnknownMcidFromApiAddsError(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([]);  // API knows nothing about mcid 101

      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('did not return a record'));
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame(1, $context['results']['recordCount']);
    }

    // -------------------------------------------------------------------------
    // processBatch — API returned a different MCID (merged record)
    // -------------------------------------------------------------------------

    public function testProcessBatchMismatchedMcidFromApiAddsErrorAndDoesNotCreateRecord(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')
        ->willReturn([101 => $this->baseNlRecord(['mcid' => 456])]);

      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('probably merged'));
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — dropout flag set
    // -------------------------------------------------------------------------

    public function testProcessBatchDropoutNlAddsErrorAndDoesNotCreateRecord(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101, FALSE, TRUE)]);
      $this->apiNlsMock->method('getNls')->willReturn([101 => $this->baseNlRecord()]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — dropout activist code column missing from the file
    // -------------------------------------------------------------------------

    /**
     * Regression test: when the uploaded file has no ActivistCode_<code>
     * column for the configured Dropout activist code (e.g., the code isn't
     * included in the VAN export, or isn't configured at all), the missing
     * column used to be silently treated as column 0 (VanID), which is
     * always non-empty — falsely marking every NL as a dropout.
     */
    public function testProcessBatchMissingDropoutColumnDoesNotFalselyRejectNl(): void {
      // Header has no ActivistCode_22 (the configured dropout code) column.
      $uri = $this->writeTempCsv([
        ['VanID', 'ActivistCode_11'],
        [101, ''],
      ]);
      $nl = $this->baseNlRecord();
      $this->apiNlsMock->method('getNls')->willReturn([101 => $nl]);

      // Full success path (same as testProcessBatchNewNlSuccessPathCreatesNlRecord).
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->method('uniqueUserName')->willReturn('asmith');
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->magicWordMock->method('createMagicWord')->willReturn('secret123');
      $this->drupalUserMock->method('createDrupalAccount')
        ->willReturn($this->baseDrupalUser());
      $this->magicWordMock->method('createNewCredentials')
        ->willReturn(['mcid' => 101, 'userName' => 'asmith', 'email' => 'alice@example.com',
                      'vfid' => 50, 'county' => 'Denver', 'hd' => '6',
                      'roles' => [], 'created' => 0, 'access' => 0]);
      $this->nlsMock->method('createNl')->willReturn(TRUE);
      $this->nlsMock->method('getNlsStatus')->willReturn([]);

      $this->nlsMock->expects($this->once())->method('createNl');
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->atLeastOnce())->method('addWarning');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — NL has no email address
    // -------------------------------------------------------------------------

    public function testProcessBatchNlWithNoEmailAddsErrorAndDoesNotCreateRecord(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')
        ->willReturn([101 => $this->baseNlRecord(['email' => ''])]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — missing HD/precinct with no legislative fix
    // -------------------------------------------------------------------------

    public function testProcessBatchMissingHdWithNoFixAddsErrorAndDoesNotCreateRecord(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')
        ->willReturn([101 => $this->baseNlRecord(['hd' => '', 'precinct' => ''])]);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — missing HD repaired by legislative fix
    // -------------------------------------------------------------------------

    public function testProcessBatchMissingHdRepairedByLegislativeFixAddsWarning(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->legFixesData = [101 => ['hd' => '6', 'precinct' => '6.1']];
      $this->apiNlsMock->method('getNls')
        ->willReturn([101 => $this->baseNlRecord(['hd' => '', 'precinct' => ''])]);

      // addWarning from checkDistricts repair; further processing continues so
      // verifyUniqueEmail etc. are invoked — stub the minimum to avoid errors.
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->method('uniqueUserName')->willReturn('');

      $this->messengerMock->expects($this->atLeastOnce())->method('addWarning');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — email already in use by another NL
    // -------------------------------------------------------------------------

    public function testProcessBatchEmailInUseByAnotherNlAddsError(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')
        ->willReturn([101 => $this->baseNlRecord()]);

      // No existing credentials for this mcid, but email is claimed by someone else.
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')
        ->willReturn(['mcid' => 999, 'userName' => 'other']);

      $this->messengerMock->expects($this->once())->method('addError');
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — record count increments across multiple rows
    // -------------------------------------------------------------------------

    public function testProcessBatchRecordCountReflectsAllRowsRead(): void {
      $uri = $this->writeTempCsv([
        $this->csvHeader(),
        $this->csvRow(101),
        $this->csvRow(102),
        $this->csvRow(103),
      ]);
      $this->apiNlsMock->method('getNls')->willReturn([]);  // all skipped

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame(3, $context['results']['recordCount']);
    }

    // -------------------------------------------------------------------------
    // processBatch — full new-NL success path
    // -------------------------------------------------------------------------

    public function testProcessBatchNewNlSuccessPathCreatesNlRecord(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $nl  = $this->baseNlRecord();
      $this->apiNlsMock->method('getNls')->willReturn([101 => $nl]);

      // verifyUniqueEmail: no prior credentials, email not in use.
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')->willReturn([]);

      // verifyDrupalAccount (newNl path): no existing drupal account.
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      // createDrupalLogin: find a unique username and create the account.
      $this->magicWordMock->method('uniqueUserName')->willReturn('asmith');
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->magicWordMock->method('createMagicWord')->willReturn('secret123');
      $this->drupalUserMock->method('createDrupalAccount')
        ->willReturn($this->baseDrupalUser());

      // createNewCredentials: return a minimal credentials record.
      $this->magicWordMock->method('createNewCredentials')
        ->willReturn(['mcid' => 101, 'userName' => 'asmith', 'email' => 'alice@example.com',
                      'vfid' => 50, 'county' => 'Denver', 'hd' => '6',
                      'roles' => [], 'created' => 0, 'access' => 0]);

      // createNlsRecord dependencies.
      $this->nlsMock->method('createNl')->willReturn(TRUE);
      $this->nlsMock->method('getNlsStatus')->willReturn([]);

      $this->nlsMock->expects($this->once())->method('createNl');
      $this->messengerMock->expects($this->never())->method('addError');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame(1, $context['finished']);
    }

    // -------------------------------------------------------------------------
    // processBatch — database insert fails
    // -------------------------------------------------------------------------

    public function testProcessBatchInsertFailureAddsError(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([101 => $this->baseNlRecord()]);

      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->method('uniqueUserName')->willReturn('asmith');
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->magicWordMock->method('createMagicWord')->willReturn('secret123');
      $this->drupalUserMock->method('createDrupalAccount')
        ->willReturn($this->baseDrupalUser());
      $this->magicWordMock->method('createNewCredentials')
        ->willReturn(['mcid' => 101, 'userName' => 'asmith', 'email' => 'alice@example.com',
                      'vfid' => 50, 'county' => 'Denver', 'hd' => '6',
                      'roles' => [], 'created' => 0, 'access' => 0]);

      $this->nlsMock->method('createNl')->willReturn(FALSE);
      $this->nlsMock->expects($this->never())->method('setNlsStatus');
      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('could not be saved'));

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    // -------------------------------------------------------------------------
    // processBatch — restoring a returning NL's account
    // -------------------------------------------------------------------------

    /**
     * Restores a returning NL whose saved credentials are given, and returns
     * the account info createDrupalAccount() was given and the credentials
     * saved afterwards.
     */
    private function restoreReturningNl(array $savedCredentials, string $newUserName): array {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([101 => $this->baseNlRecord()]);

      $this->magicWordMock->method('getCredentials')->willReturn($savedCredentials);
      $this->magicWordMock->method('getMagicWord')->willReturn($savedCredentials['magicWord']);
      // The NL's account was deleted after the last election.
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->method('uniqueUserName')->willReturn($newUserName);
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->magicWordMock->method('createMagicWord')->willReturn('newpw5');

      $result = ['accountInfo' => [], 'savedCredentials' => []];
      $this->drupalUserMock->method('createDrupalAccount')
        ->willReturnCallback(function (array $userInfo) use (&$result): array {
          $result['accountInfo'] = $userInfo;
          return $this->baseDrupalUser(['userName' => $userInfo['userName']]);
        });
      $this->magicWordMock->method('updateCredentials')
        ->willReturnCallback(function (array $credentials) use (&$result): bool {
          $result['savedCredentials'] = $credentials;
          return TRUE;
        });
      $this->nlsMock->method('createNl')->willReturn(TRUE);
      $this->messengerMock->expects($this->never())->method('addError');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
      return $result;
    }

    private function savedCredentials(array $overrides = []): array {
      return array_merge([
        'mcid' => 101, 'userName' => 'asmith', 'email' => 'alice@example.com', 'magicWord' => 'planet7',
        'vfid' => 50, 'county' => 'Denver', 'hd' => '6', 'roles' => [], 'created' => 0, 'access' => 0,
      ], $overrides);
    }

    public function testProcessBatchRestoresTheSavedUsernameAndPassword(): void {
      $this->magicWordMock->expects($this->never())->method('createMagicWord');
      $result = $this->restoreReturningNl($this->savedCredentials(), 'unused');
      $this->assertSame('asmith', $result['accountInfo']['userName']);
      $this->assertSame('planet7', $result['accountInfo']['magicWord']);
    }

    public function testProcessBatchKeepsTheSavedPasswordWhenTheSavedUsernameIsBlank(): void {
      $this->magicWordMock->expects($this->never())->method('createMagicWord');
      $this->magicWordMock->expects($this->never())->method('setMagicWord');

      $result = $this->restoreReturningNl($this->savedCredentials(['userName' => '']), 'alice.smith');

      $this->assertSame('alice.smith', $result['accountInfo']['userName']);
      $this->assertSame('planet7', $result['accountInfo']['magicWord']);
      // The new username is saved too.
      $this->assertSame('alice.smith', $result['savedCredentials']['userName']);
    }

    public function testProcessBatchSavesANewPasswordForABlankOneWithTheAccount(): void {
      // Drupal and the saved copy must get the same new password.
      $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(101, 'newpw5');

      $result = $this->restoreReturningNl($this->savedCredentials(['magicWord' => '']), 'alice.smith');

      $this->assertSame('newpw5', $result['accountInfo']['magicWord']);
    }

    // -------------------------------------------------------------------------
    // processBatch — second call (sandbox already initialised) resumes seek
    // -------------------------------------------------------------------------

    public function testProcessBatchSecondCallResumesSandboxWithoutRereadingHeader(): void {
      // Write two rows; first call consumes them all (BATCH_LIMIT is 50).
      $uri = $this->writeTempCsv([
        $this->csvHeader(),
        $this->csvRow(101),
        $this->csvRow(102),
      ]);
      $this->apiNlsMock->method('getNls')->willReturn([]);

      // Simulate a first completed call that stored sandbox state.
      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
      $this->assertSame(1, $context['finished']);

      // Reset finished and simulate a second call using existing sandbox.
      $context['finished'] = 0;
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      // Second call starts at EOF so reads zero records; total stays at 2.
      $this->assertSame(2, $context['results']['recordCount']);
    }

      // -------------------------------------------------------------------------
    // checkVoterId — vfid, duplicates and the Not Registered code
    // -------------------------------------------------------------------------

    private function checkVoterId(array $nlRecord, bool &$notRegistered): bool {
      return $this->buildProcessor()->checkVoterId($nlRecord, $notRegistered, 'Denver');
    }

    public function testMissingVfidWithoutNotRegisteredCodeIsRejected(): void {
      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('vfid is missing'));

      $notRegistered = FALSE;
      $this->assertFalse($this->checkVoterId($this->baseNlRecord(['vfid' => 0]), $notRegistered));
    }

    public function testMissingVfidWithNotRegisteredCodeIsAcceptedWithWarning(): void {
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->once())->method('addWarning')
        ->with($this->messageContains('accepted without a vfid'));
      $this->activistCodesMock->expects($this->never())->method('setApiActivistCode');

      $notRegistered = TRUE;
      $this->assertTrue($this->checkVoterId($this->baseNlRecord(['vfid' => 0]), $notRegistered));
      $this->assertTrue($notRegistered);
    }

    public function testVfidWithNotRegisteredCodeRemovesTheCode(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn([]);
      $this->activistCodesMock->expects($this->once())->method('setApiActivistCode')
        ->with(['codeName' => 'NLPNotRegisteredAC', 'vanid' => 101, 'action' => 'Remove', 'committee' => 'Denver'])
        ->willReturn(TRUE);
      $this->messengerMock->expects($this->once())->method('addWarning')
        ->with($this->messageContains('activist code was removed'));

      $notRegistered = TRUE;
      $this->assertTrue($this->checkVoterId($this->baseNlRecord(), $notRegistered));
      $this->assertFalse($notRegistered);
    }

    public function testFailedActivistCodeRemovalStillAcceptsWithWarning(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn([]);
      $this->activistCodesMock->method('setApiActivistCode')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addWarning')
        ->with($this->messageContains('could not be removed'));

      $notRegistered = TRUE;
      $this->assertTrue($this->checkVoterId($this->baseNlRecord(), $notRegistered));
      $this->assertFalse($notRegistered);
    }

    public function testVfidKnownUnderSameMcidIsAccepted(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 101, 'vfid' => 50]);
      $this->apiNlsMock->expects($this->never())->method('getMcidStatus');
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->never())->method('addWarning');

      $notRegistered = FALSE;
      $this->assertTrue($this->checkVoterId($this->baseNlRecord(), $notRegistered));
    }

    public function testDuplicateIsRejectedWhenOriginalMcidStillExists(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 900, 'vfid' => 50]);
      $this->apiNlsMock->expects($this->once())->method('getMcidStatus')->with('Denver', 900)
        ->willReturn(ApiNls::MCID_EXISTS);
      $this->reassignmentMock->expects($this->never())->method('reassign');
      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('is a duplicate'));

      $notRegistered = FALSE;
      $this->assertFalse($this->checkVoterId($this->baseNlRecord(), $notRegistered));
    }

    public function testDuplicateIsRejectedWhenMyCampaignCannotBeReached(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 900, 'vfid' => 50]);
      $this->apiNlsMock->method('getMcidStatus')->willReturn(ApiNls::MCID_UNKNOWN);
      $this->reassignmentMock->expects($this->never())->method('reassign');
      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('could not be reached'));

      $notRegistered = FALSE;
      $this->assertFalse($this->checkVoterId($this->baseNlRecord(), $notRegistered));
    }

    public static function destroyedStatuses(): array {
      return [[ApiNls::MCID_MISSING], [ApiNls::MCID_MERGED]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('destroyedStatuses')]
    public function testDestroyedOriginalMcidIsReassignedToTheNewMcid(string $status): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 900, 'vfid' => 50]);
      $this->apiNlsMock->method('getMcidStatus')->willReturn($status);
      $this->reassignmentMock->expects($this->once())->method('reassign')->with(900, 101)
        ->willReturn(['ok' => TRUE]);
      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->once())->method('addWarning')
        ->with($this->messageContains('was merged into'));

      $notRegistered = FALSE;
      $this->assertTrue($this->checkVoterId($this->baseNlRecord(), $notRegistered));
    }

    public function testFailedReassignmentRejectsTheNl(): void {
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 900, 'vfid' => 50]);
      $this->apiNlsMock->method('getMcidStatus')->willReturn(ApiNls::MCID_MISSING);
      $this->reassignmentMock->method('reassign')
        ->willReturn(['ok' => FALSE, 'message' => 'MCID 101 already has a login.']);
      $this->messengerMock->expects($this->once())->method('addError')
        ->with($this->messageContains('Reassign MCID'));

      $notRegistered = FALSE;
      $this->assertFalse($this->checkVoterId($this->baseNlRecord(), $notRegistered));
    }

    public function testProcessBatchRejectsDuplicateBeforeCreatingAnAccount(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([101 => $this->baseNlRecord()]);
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn(['mcid' => 900, 'vfid' => 50]);
      $this->apiNlsMock->method('getMcidStatus')->willReturn(ApiNls::MCID_EXISTS);

      $this->drupalUserMock->expects($this->never())->method('createDrupalAccount');
      $this->magicWordMock->expects($this->never())->method('createCredentials');
      $this->nlsMock->expects($this->never())->method('createNl');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

    public function testProcessBatchSavesNotRegisteredClearedAfterCodeRemoved(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101, TRUE)]);
      $this->apiNlsMock->method('getNls')->willReturn([101 => $this->baseNlRecord()]);
      $this->magicWordMock->method('getCredentialsByVfid')->willReturn([]);
      $this->activistCodesMock->method('setApiActivistCode')->willReturn(TRUE);
      $this->magicWordMock->method('getCredentials')->willReturn([]);
      $this->magicWordMock->method('getCredentialsByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByEmail')->willReturn([]);
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->magicWordMock->method('uniqueUserName')->willReturn('asmith');
      $this->drupalUserMock->method('getUserByName')->willReturn([]);
      $this->magicWordMock->method('createMagicWord')->willReturn('secret123');
      $this->drupalUserMock->method('createDrupalAccount')->willReturn($this->baseDrupalUser());
      $this->magicWordMock->method('createNewCredentials')
        ->willReturn(['mcid' => 101, 'userName' => 'asmith', 'email' => 'alice@example.com',
                      'vfid' => 50, 'county' => 'Denver', 'hd' => '6',
                      'roles' => [], 'created' => 0, 'access' => 0]);
      $this->nlsMock->method('getNlsStatus')->willReturn([]);

      $this->nlsMock->expects($this->once())->method('createNl')
        ->with($this->callback(fn($nl) => $nl['notRegistered'] === FALSE))
        ->willReturn(TRUE);

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);
    }

  }

}
