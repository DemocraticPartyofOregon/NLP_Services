<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Extension\ModuleExtensionList;
  use Drupal\Core\File\FileUrlGeneratorInterface;
  use Drupal\file\FileRepositoryInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpCoordinators;
  use Drupal\nlpservices\NlpCreateWalksheet;
  use Drupal\nlpservices\NlpDataEntryPrint;
  use Drupal\nlpservices\NlpInstructions;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryPrintTest extends UnitTestCase {

    private NlpNls $nlsMock;
    private NlpSessionData $sessionDataMock;
    private NlpCoordinators $coordinatorsMock;
    private NlpVoters $votersMock;
    private NlpTurfs $turfsMock;
    private NlpPaths $pathsMock;
    private NlpInstructions $instructionsMock;
    private DrupalUser $drupalUserMock;
    private NlpCreateWalksheet $createWalksheetMock;
    private FileUrlGeneratorInterface $fileUrlGeneratorMock;
    private FileRepositoryInterface $fileRepositoryMock;
    private ModuleExtensionList $moduleListMock;

    protected function setUp(): void {
      parent::setUp();
      $this->nlsMock             = $this->createMock(NlpNls::class);
      $this->sessionDataMock     = $this->createMock(NlpSessionData::class);
      $this->coordinatorsMock    = $this->createMock(NlpCoordinators::class);
      $this->votersMock          = $this->createMock(NlpVoters::class);
      $this->turfsMock           = $this->createMock(NlpTurfs::class);
      $this->pathsMock           = $this->createMock(NlpPaths::class);
      $this->instructionsMock    = $this->createMock(NlpInstructions::class);
      $this->drupalUserMock      = $this->createMock(DrupalUser::class);
      $this->createWalksheetMock = $this->createMock(NlpCreateWalksheet::class);
      $this->fileUrlGeneratorMock = $this->createMock(FileUrlGeneratorInterface::class);
      $this->fileRepositoryMock  = $this->createMock(FileRepositoryInterface::class);
      $this->moduleListMock      = $this->createMock(ModuleExtensionList::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpDataEntryPrint {
      return new NlpDataEntryPrint(
        $this->nlsMock,
        $this->sessionDataMock,
        $this->coordinatorsMock,
        $this->votersMock,
        $this->turfsMock,
        $this->pathsMock,
        $this->instructionsMock,
        $this->drupalUserMock,
        $this->createWalksheetMock,
        $this->fileUrlGeneratorMock,
        $this->fileRepositoryMock,
        $this->moduleListMock,
      );
    }

    private function baseCallVoter(array $overrides = []): array {
      return array_replace_recursive([
        'firstName'      => 'Bob',
        'lastName'       => 'Smith',
        'nickname'       => 'Bob',
        'age'            => '45',
        'sex'            => 'M',
        'votingHistory'  => 'VV',
        'homePhone'      => '',
        'cellPhone'      => '',
        'smsOptInStatus' => '',
        'status'         => [
          'deceased' => FALSE,
          'hostile'  => FALSE,
          'voted'    => 'X',
        ],
        'address'        => [
          'streetNo'     => '123',
          'streetPrefix' => '',
          'streetName'   => 'Main',
          'streetType'   => 'St',
          'aptType'      => '',
          'aptNo'        => '',
          'city'         => 'Portland',
        ],
      ], $overrides);
    }

    private function baseMailVoter(array $overrides = []): array {
      return array_replace_recursive([
        'firstName'     => 'Bob',
        'lastName'      => 'Smith',
        'nickname'      => 'Bob',
        'age'           => '45',
        'votingHistory' => 'VV',
        'status'        => ['deceased' => FALSE, 'hostile' => FALSE],
        'address'       => [
          'mAddress' => '123 Main St',
          'mCity'    => 'Portland',
          'mState'   => 'OR',
          'mZip'     => '97201',
        ],
      ], $overrides);
    }

    // -------------------------------------------------------------------------
    // formatTelephone
    // -------------------------------------------------------------------------

    public function testFormatTelephoneFormatsStandardTenDigitNumber(): void {
      $result = $this->buildService()->formatTelephone('2065551234');
      $this->assertSame('(206) 555-1234', $result);
    }

    public function testFormatTelephoneStripsNonDigitsBeforeFormatting(): void {
      $result = $this->buildService()->formatTelephone('(206) 555-1234');
      $this->assertSame('(206) 555-1234', $result);
    }

    public function testFormatTelephoneStripsDashes(): void {
      $result = $this->buildService()->formatTelephone('206-555-1234');
      $this->assertSame('(206) 555-1234', $result);
    }

    public function testFormatTelephoneReturnsEmptyForEmptyInput(): void {
      $this->assertSame('', $this->buildService()->formatTelephone(''));
    }

    // -------------------------------------------------------------------------
    // buildCallTableArray
    // -------------------------------------------------------------------------

    public function testBuildCallTableArraySingleVotedVoterReturnsSingleEntry(): void {
      $result = $this->buildService()->buildCallTableArray([$this->baseCallVoter()]);
      $this->assertCount(1, $result);
    }

    public function testBuildCallTableArrayVotedVoterNameNotInRedSpan(): void {
      $result = $this->buildService()->buildCallTableArray([$this->baseCallVoter()]);
      $this->assertStringNotContainsString('help-not-voted', $result[0]['names']);
    }

    public function testBuildCallTableArrayNotVotedVoterNameInRedSpan(): void {
      $voter = $this->baseCallVoter(['status' => ['voted' => '']]);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertStringContainsString('help-not-voted', $result[0]['names']);
      $this->assertStringContainsString('*', $result[0]['names']);
    }

    public function testBuildCallTableArrayNotVotedWithPhoneSetsCallFlag(): void {
      $voter = $this->baseCallVoter(['status' => ['voted' => ''], 'homePhone' => '2065551234']);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertTrue($result[0]['someOneToCall']);
    }

    public function testBuildCallTableArrayNotVotedWithoutPhoneCallFlagIsFalse(): void {
      $voter = $this->baseCallVoter(['status' => ['voted' => '']]);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertFalse($result[0]['someOneToCall']);
    }

    public function testBuildCallTableArrayVotedVoterCallFlagIsFalse(): void {
      $voter = $this->baseCallVoter(['homePhone' => '2065551234']);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertFalse($result[0]['someOneToCall']);
    }

    public function testBuildCallTableArrayTwoVotersSameAddressGroupedIntoOneEntry(): void {
      $voter1 = $this->baseCallVoter(['firstName' => 'Bob']);
      $voter2 = $this->baseCallVoter(['firstName' => 'Jane', 'lastName' => 'Smith']);
      $result = $this->buildService()->buildCallTableArray([$voter1, $voter2]);
      $this->assertCount(1, $result);
      $this->assertStringContainsString('Bob', $result[0]['names']);
      $this->assertStringContainsString('Jane', $result[0]['names']);
    }

    public function testBuildCallTableArrayTwoVotersDifferentAddressesReturnTwoEntries(): void {
      $voter1 = $this->baseCallVoter(['firstName' => 'Bob']);
      $voter2 = $this->baseCallVoter(['firstName' => 'Jane', 'address' => ['streetName' => 'Oak']]);
      $result = $this->buildService()->buildCallTableArray([$voter1, $voter2]);
      $this->assertCount(2, $result);
    }

    public function testBuildCallTableArrayDeceasedVoterShowsDelTagAndLabel(): void {
      $voter = $this->baseCallVoter(['status' => ['deceased' => TRUE, 'hostile' => FALSE, 'voted' => 'X']]);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertStringContainsString('<del>', $result[0]['names']);
      $this->assertStringContainsString('DECEASED', $result[0]['names']);
    }

    public function testBuildCallTableArrayHostileVoterShowsDelTagAndLabel(): void {
      $voter = $this->baseCallVoter(['status' => ['deceased' => FALSE, 'hostile' => TRUE, 'voted' => 'X']]);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertStringContainsString('<del>', $result[0]['names']);
      $this->assertStringContainsString('HOSTILE', $result[0]['names']);
    }

    public function testBuildCallTableArrayVotingHistoryAtCodesReplaced(): void {
      $voter = $this->baseCallVoter(['votingHistory' => '@Y@N']);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertStringContainsString('Yes', $result[0]['names']);
      $this->assertStringContainsString('No', $result[0]['names']);
      $this->assertStringNotContainsString('@Y', $result[0]['names']);
      $this->assertStringNotContainsString('@N', $result[0]['names']);
    }

    public function testBuildCallTableArrayCellPhoneWithSmsStatusDisplayed(): void {
      $voter = $this->baseCallVoter(['cellPhone' => '2065551234', 'smsOptInStatus' => 'OptIn']);
      $result = $this->buildService()->buildCallTableArray([$voter]);
      $this->assertStringContainsString('OptIn', $result[0]['phoneNumbers']);
    }

    public function testBuildCallTableArrayEmptyVotersReturnsEmptyArray(): void {
      $this->assertSame([], $this->buildService()->buildCallTableArray([]));
    }

    // -------------------------------------------------------------------------
    // buildCallListDisplay
    // -------------------------------------------------------------------------

    public function testBuildCallListDisplayEmptyInputReturnsTableShell(): void {
      $result = $this->buildService()->buildCallListDisplay([]);
      $joined = implode('', $result);
      $this->assertStringContainsString('<table', $joined);
      $this->assertStringContainsString('</table>', $joined);
    }

    public function testBuildCallListDisplaySingleEntryContainsNameAndAddress(): void {
      $voterArrays = [['names' => 'Bob Smith', 'phoneNumbers' => '(206) 555-1234', 'address' => '123 Main', 'someOneToCall' => FALSE]];
      $result = $this->buildService()->buildCallListDisplay($voterArrays);
      $joined = implode('', $result);
      $this->assertStringContainsString('Bob Smith', $joined);
      $this->assertStringContainsString('123 Main', $joined);
    }

    public function testBuildCallListDisplayCallFlagTrueShowsCallSpan(): void {
      $voterArrays = [['names' => 'Bob Smith', 'phoneNumbers' => '', 'address' => '123 Main', 'someOneToCall' => TRUE]];
      $result = $this->buildService()->buildCallListDisplay($voterArrays);
      $joined = implode('', $result);
      $this->assertStringContainsString('help-call', $joined);
    }

    public function testBuildCallListDisplayCallFlagFalseNoCallSpan(): void {
      $voterArrays = [['names' => 'Bob Smith', 'phoneNumbers' => '', 'address' => '123 Main', 'someOneToCall' => FALSE]];
      $result = $this->buildService()->buildCallListDisplay($voterArrays);
      $joined = implode('', $result);
      $this->assertStringNotContainsString('help-call', $joined);
    }

    // -------------------------------------------------------------------------
    // buildMailListArray
    // -------------------------------------------------------------------------

    public function testBuildMailListArraySingleVoterReturnsSingleEntry(): void {
      $result = $this->buildService()->buildMailListArray([$this->baseMailVoter()]);
      $this->assertCount(1, $result);
    }

    public function testBuildMailListArrayFormatsMailingAddress(): void {
      $result = $this->buildService()->buildMailListArray([$this->baseMailVoter()]);
      $this->assertStringContainsString('123 Main St', $result[0]['address']);
      $this->assertStringContainsString('Portland', $result[0]['address']);
      $this->assertStringContainsString('OR', $result[0]['address']);
      $this->assertStringContainsString('97201', $result[0]['address']);
    }

    public function testBuildMailListArrayEmptyMailingAddressShowsNotAvailable(): void {
      $voter = $this->baseMailVoter(['address' => ['mAddress' => '']]);
      $result = $this->buildService()->buildMailListArray([$voter]);
      $this->assertStringContainsString('Not available', $result[0]['address']);
    }

    public function testBuildMailListArrayTwoVotersSameAddressGroupedIntoOneEntry(): void {
      $voter1 = $this->baseMailVoter(['firstName' => 'Bob']);
      $voter2 = $this->baseMailVoter(['firstName' => 'Jane']);
      $result = $this->buildService()->buildMailListArray([$voter1, $voter2]);
      $this->assertCount(1, $result);
      $this->assertStringContainsString('Bob', $result[0]['names']);
      $this->assertStringContainsString('Jane', $result[0]['names']);
    }

    public function testBuildMailListArrayTwoVotersDifferentAddressesReturnTwoEntries(): void {
      $voter1 = $this->baseMailVoter(['firstName' => 'Bob']);
      $voter2 = $this->baseMailVoter(['firstName' => 'Jane', 'address' => ['mAddress' => '456 Oak Ave']]);
      $result = $this->buildService()->buildMailListArray([$voter1, $voter2]);
      $this->assertCount(2, $result);
    }

    public function testBuildMailListArrayDeceasedVoterShowsDelTagAndLabel(): void {
      $voter = $this->baseMailVoter(['status' => ['deceased' => TRUE, 'hostile' => FALSE]]);
      $result = $this->buildService()->buildMailListArray([$voter]);
      $this->assertStringContainsString('<del>', $result[0]['names']);
      $this->assertStringContainsString('DECEASED', $result[0]['names']);
    }

    public function testBuildMailListArrayVotingHistoryAtCodesReplaced(): void {
      $voter = $this->baseMailVoter(['votingHistory' => '@Y@N']);
      $result = $this->buildService()->buildMailListArray([$voter]);
      $this->assertStringContainsString('Yes', $result[0]['names']);
      $this->assertStringContainsString('No', $result[0]['names']);
    }

    // -------------------------------------------------------------------------
    // buildMailListDisplay
    // -------------------------------------------------------------------------

    public function testBuildMailListDisplayEmptyInputReturnsTableWithHeader(): void {
      $result = $this->buildService()->buildMailListDisplay([]);
      $joined = implode('', $result);
      $this->assertStringContainsString('<table', $joined);
      $this->assertStringContainsString('Name(s)', $joined);
      $this->assertStringContainsString('Mailing Address', $joined);
    }

    public function testBuildMailListDisplaySingleEntryContainsNameAndAddress(): void {
      $tableArrays = [['names' => 'Bob Smith', 'address' => '123 Main St<br>Portland, OR 97201']];
      $result = $this->buildService()->buildMailListDisplay($tableArrays);
      $joined = implode('', $result);
      $this->assertStringContainsString('Bob Smith', $joined);
      $this->assertStringContainsString('123 Main St', $joined);
    }

    public function testBuildMailListDisplayReturnsArray(): void {
      $result = $this->buildService()->buildMailListDisplay([]);
      $this->assertIsArray($result);
      $this->assertNotEmpty($result);
    }

    // -------------------------------------------------------------------------
    // getVoters
    // -------------------------------------------------------------------------

    public function testGetVotersEmptyResultReturnsEmptyArray(): void {
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $this->assertSame([], $this->buildService()->getVoters(7));
    }

    public function testGetVotersAddsStatusFieldToEachVoter(): void {
      $voters = [99999 => ['firstName' => 'Bob']];
      $this->votersMock->method('fetchVotersByTurf')->willReturn($voters);
      $this->votersMock->method('getVoterStatus')->willReturn(['voted' => 'X', 'deceased' => FALSE]);
      $result = $this->buildService()->getVoters(7);
      $this->assertArrayHasKey('status', $result[99999]);
      $this->assertSame('X', $result[99999]['status']['voted']);
    }

    public function testGetVotersCallsGetVoterStatusForEachVoter(): void {
      $voters = [11111 => ['firstName' => 'Bob'], 22222 => ['firstName' => 'Jane']];
      $this->votersMock->method('fetchVotersByTurf')->willReturn($voters);
      $this->votersMock->expects($this->exactly(2))->method('getVoterStatus')
        ->willReturn(['voted' => '', 'deceased' => FALSE]);
      $this->buildService()->getVoters(7);
    }

    // -------------------------------------------------------------------------
    // buildWalksheetPage
    // -------------------------------------------------------------------------

    public function testBuildWalksheetPageEmptyDataReturnsNoDataMarkup(): void {
      $this->createWalksheetMock->method('createWalksheetArray')->willReturn([]);
      $result = $this->buildService()->buildWalksheetPage(7);
      $this->assertStringContainsString('No walksheet data available', $result['#markup']);
    }

    public function testBuildWalksheetPageWithDataReturnsPdfLink(): void {
      $this->createWalksheetMock->method('createWalksheetArray')->willReturn([['householdLineCount' => 2]]);
      $this->createWalksheetMock->method('createWalksheetPdf')->willReturn('public://temp/walksheet.pdf');
      $result = $this->buildService()->buildWalksheetPage(7);
      $this->assertStringContainsString('public://temp/walksheet.pdf', $result['#markup']);
      $this->assertStringContainsString('<a href=', $result['#markup']);
    }

  }

}
