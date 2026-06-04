<?php

namespace {
  if (!function_exists('nlp_debug_msg')) {
    function nlp_debug_msg($msg, $values = 'EMPTY'): void {}
  }
  if (!function_exists('t')) {
    function t($string, array $args = [], array $options = []): string { return $string; }
  }
  if (!defined('NLP_MODULE'))                { define('NLP_MODULE',                'nlpservices'); }
  if (!defined('NLP_ADMIN_ROLE_ID'))         { define('NLP_ADMIN_ROLE_ID',         'nlp_admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_ID'))   { define('NLP_COORDINATOR_ROLE_ID',   'nlp_coordinator'); }
  if (!defined('NLP_LEADER_ROLE_ID'))        { define('NLP_LEADER_ROLE_ID',        'nlp_leader'); }
  if (!defined('NLP_ADMIN_ROLE_LABEL'))      { define('NLP_ADMIN_ROLE_LABEL',      'NLP Admin'); }
  if (!defined('NLP_COORDINATOR_ROLE_LABEL')){ define('NLP_COORDINATOR_ROLE_LABEL','NLP Coordinator'); }
  if (!defined('NLP_LEADER_ROLE_LABEL'))     { define('NLP_LEADER_ROLE_LABEL',     'NLP Leader'); }
}

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\ApiNls;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpAwards;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpLegislativeFixes;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSyncActiveNlsProcessor;
  use Drupal\Tests\UnitTestCase;

  class NlpSyncActiveNlsProcessorTest extends UnitTestCase {

    private ApiNls $apiNlsMock;
    private NlpLegislativeFixes $legFixesMock;
    private NlpConfiguration $configMock;
    private MagicWord $magicWordMock;
    private DrupalUser $drupalUserMock;
    private NlpNls $nlsMock;
    private NlpAwards $awardsMock;
    private MessengerInterface $messengerMock;

    /** Temp files created during a test — cleaned up in tearDown. */
    private array $tempFiles = [];

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

      // Default stubs so tests only need to override what they care about.
      $this->legFixesMock->method('getLegFixes')->willReturn([]);
      $this->configMock->method('getActivistCode')->willReturnMap([
        ['NLPNotRegisteredAC', 'NR'],
        ['NLPDropoutAC',       'DO'],
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
        fputcsv($fh, $row);
      }
      fclose($fh);
      $this->tempFiles[] = $path;
      return $path;
    }

    /** Standard header matching the activist-code names returned by configMock. */
    private function csvHeader(): array {
      return ['VanID', 'ActivistCode_NR', 'ActivistCode_DO'];
    }

    /** A data row with all flags clear. */
    private function csvRow(int $mcid, bool $notRegistered = FALSE, bool $dropout = FALSE): array {
      return [$mcid, $notRegistered ? '1' : '', $dropout ? '1' : ''];
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

    // -------------------------------------------------------------------------
    // processBatch — NL not returned by API (unknown mcid)
    // -------------------------------------------------------------------------

    public function testProcessBatchUnknownMcidFromApiIsSkippedQuietly(): void {
      $uri = $this->writeTempCsv([$this->csvHeader(), $this->csvRow(101)]);
      $this->apiNlsMock->method('getNls')->willReturn([]);  // API knows nothing about mcid 101

      $this->messengerMock->expects($this->never())->method('addError');

      $context = [];
      $this->buildProcessor()->processBatch($this->baseArg($uri), $context);

      $this->assertSame(1, $context['results']['recordCount']);
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
      $this->legFixesMock->method('getLegFixes')
        ->willReturn([101 => ['hd' => '6', 'precinct' => '6.1']]);
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

  }

}
