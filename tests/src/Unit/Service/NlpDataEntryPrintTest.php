<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\File\FileUrlGeneratorInterface;
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
  use Drupal\nlpservices\PdfGenerator;
  use Drupal\Tests\nlpservices\Traits\PdfPaginationTrait;
  use Drupal\Tests\UnitTestCase;

  class NlpDataEntryPrintTest extends UnitTestCase {

    use PdfPaginationTrait;

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
    private PdfGenerator $pdfGeneratorMock;

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
      $this->pdfGeneratorMock    = $this->createMock(PdfGenerator::class);
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
        $this->pdfGeneratorMock,
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

    /**
     * Household sizes for the rendered pagination tests, keyed by the household tag used in
     * the voters' last names.  Mostly small households, several large enough that a page
     * often can't fit them after other households, and HH10, too large for any one page.
     */
    private function paginationHouseholdSizes(): array {
      $pattern = [1, 2, 3, 1, 4, 2, 1, 1, 3, 2, 5, 1, 2, 2, 1, 3, 1, 2, 4, 1, 1, 2, 8, 2, 1,
        1, 2, 1, 3, 2, 1, 4, 2, 1, 1, 2, 3, 1, 2, 2, 6, 1, 2, 3, 1, 1, 2, 7, 1, 2];
      $pattern[10] = 60;
      $sizes = [];
      foreach ($pattern as $h => $size) {
        $sizes[sprintf('HH%02d', $h)] = $size;
      }
      return $sizes;
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

    public function testBuildCallTableArrayListsEachVoterInHousehold(): void {
      $voter1 = $this->baseCallVoter(['firstName' => 'Bob', 'homePhone' => '2065551234']);
      $voter2 = $this->baseCallVoter(['firstName' => 'Jane', 'cellPhone' => '2065559876']);
      $voter3 = $this->baseCallVoter(['firstName' => 'Ann', 'address' => ['streetName' => 'Oak']]);
      $result = $this->buildService()->buildCallTableArray([$voter1, $voter2, $voter3]);
      $this->assertCount(2, $result[0]['voters']);
      $this->assertStringContainsString('Bob', $result[0]['voters'][0]['name']);
      $this->assertStringContainsString('(206) 555-1234', $result[0]['voters'][0]['phoneNumbers']);
      $this->assertStringContainsString('Jane', $result[0]['voters'][1]['name']);
      $this->assertStringContainsString('(206) 555-9876', $result[0]['voters'][1]['phoneNumbers']);
      $this->assertCount(1, $result[1]['voters']);
      $this->assertStringContainsString('Ann', $result[1]['voters'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // buildCallListPdfHtml
    // -------------------------------------------------------------------------

    public function testBuildCallListPdfHtmlHasRepeatingColumnHeader(): void {
      $html = $this->buildService()->buildCallListPdfHtml([]);
      $this->assertStringContainsString('<htmlpageheader name="callListHeader">', $html);
      $this->assertStringContainsString('header: html_callListHeader', $html);
      foreach (['Name(s)', 'Phone Number(s)', 'Address', 'Call'] as $heading) {
        $this->assertStringContainsString("<b>$heading</b>", $html);
      }
    }

    public function testBuildCallListPdfHtmlKeepsEachHouseholdInItsOwnUnbreakableTable(): void {
      $voterArrays = [
        ['voters' => [['name' => 'Bob Smith', 'phoneNumbers' => 'H:(206) 555-1234'], ['name' => 'Jane Smith', 'phoneNumbers' => 'H:']],
         'address' => '123 Main', 'someOneToCall' => TRUE],
        ['voters' => [['name' => 'Ann Jones', 'phoneNumbers' => 'H:']], 'address' => '456 Oak', 'someOneToCall' => FALSE],
      ];
      $html = $this->buildService()->buildCallListPdfHtml($voterArrays);
      $this->assertSame(2, substr_count($html, 'page-break-inside: avoid'));
      $this->assertSame(2, substr_count($html, 'autosize="1"'));
      // One row per voter, with the address and call flag only on the household's first row.
      $this->assertSame(1, substr_count($html, '123 Main'));
      $this->assertSame(1, substr_count($html, '<span class="help-call">call</span>'));
      $this->assertMatchesRegularExpression('/Bob Smith.*555-1234.*123 Main.*help-call.*Jane Smith.*Ann Jones.*456 Oak/s', $html);
    }

    // -------------------------------------------------------------------------
    // buildCallList
    // -------------------------------------------------------------------------

    public function testBuildCallListPdfRenderedPagesKeepEachHouseholdTogether(): void {
      $sizes = $this->paginationHouseholdSizes();
      $voters = [];
      foreach (array_keys($sizes) as $h => $hh) {
        for ($i = 0; $i < $sizes[$hh]; $i++) {
          $voters[] = $this->baseCallVoter([
            'firstName' => 'Person' . $i,
            'lastName' => $hh . 'Johnson',
            'cellPhone' => '5035559876',
            'status' => ['voted' => ($i % 2) ? 'X' : ''],
            // Every third household has an apartment, making its address three lines.
            'address' => ['streetNo' => (string) (100 + $h), 'aptType' => ($h % 3) ? '' : 'Apt', 'aptNo' => ($h % 3) ? '' : (string) $h],
          ]);
        }
      }
      $service = $this->buildService();
      $html = $service->buildCallListPdfHtml($service->buildCallTableArray($voters));

      $pageTexts = $this->pageTexts($this->renderPdf($html, 'P'));
      $households = $this->householdPages($pageTexts, '/Person\d+\s+(?<hh>HH\d\d)Johnson/');
      $this->assertHouseholdsKeptTogether($households, $sizes, 'HH10');
      foreach ($pageTexts as $pageNumber => $text) {
        $this->assertStringContainsString('Phone Number(s)', $text, "Page $pageNumber is missing the column headings.");
      }
    }

    public function testBuildCallListEmptyVotersReturnsNoVotersMarkup(): void {
      $this->pdfGeneratorMock->expects($this->never())->method('generatePdf');
      $result = $this->buildService()->buildCallList([]);
      $this->assertStringContainsString('No voters are available', $result['#markup']);
    }

    public function testBuildCallListGeneratesPortraitPdfAndReturnsLink(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 12345]);
      $this->pdfGeneratorMock->expects($this->once())->method('generatePdf')
        ->with(
          $this->stringContains('Bob'),
          $this->matchesRegularExpression('#^public://temp/' . NlpDataEntryPrint::DD_CALL_FILE . '-12345-.*\.pdf$#'),
          'P'
        )
        ->willReturn('https://example.org/files/temp/calls.pdf');
      $result = $this->buildService()->buildCallList([$this->baseCallVoter()]);
      $this->assertStringContainsString('<a href="https://example.org/files/temp/calls.pdf"', $result['#markup']);
      $this->assertStringContainsString('GOTV call list', $result['#markup']);
    }

    public function testBuildCallListPdfFailureReturnsErrorMarkup(): void {
      $this->pdfGeneratorMock->method('generatePdf')->willReturn('');
      $result = $this->buildService()->buildCallList([$this->baseCallVoter()]);
      $this->assertStringContainsString('could not be created', $result['#markup']);
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

    public function testBuildMailListArrayListsEachVoterInHousehold(): void {
      $voter1 = $this->baseMailVoter(['firstName' => 'Bob']);
      $voter2 = $this->baseMailVoter(['firstName' => 'Jane']);
      $voter3 = $this->baseMailVoter(['firstName' => 'Ann', 'address' => ['mAddress' => '456 Oak Ave']]);
      $result = $this->buildService()->buildMailListArray([$voter1, $voter2, $voter3]);
      $this->assertCount(2, $result[0]['voters']);
      $this->assertStringContainsString('Bob', $result[0]['voters'][0]);
      $this->assertStringContainsString('Jane', $result[0]['voters'][1]);
      $this->assertCount(1, $result[1]['voters']);
      $this->assertStringContainsString('Ann', $result[1]['voters'][0]);
    }

    // -------------------------------------------------------------------------
    // buildMailListPdfHtml
    // -------------------------------------------------------------------------

    public function testBuildMailListPdfHtmlHasRepeatingColumnHeader(): void {
      $html = $this->buildService()->buildMailListPdfHtml([]);
      $this->assertStringContainsString('<htmlpageheader name="mailListHeader">', $html);
      $this->assertStringContainsString('header: html_mailListHeader', $html);
      $this->assertStringContainsString('Name(s)', $html);
      $this->assertStringContainsString('Mailing Address', $html);
    }

    public function testBuildMailListPdfHtmlKeepsEachHouseholdInItsOwnUnbreakableTable(): void {
      $tableArrays = [
        ['voters' => ['Bob Smith', 'Jane Smith'], 'address' => '123 Main St<br>Portland, OR 97201'],
        ['voters' => ['Ann Jones'], 'address' => '456 Oak Ave<br>Portland, OR 97201'],
      ];
      $html = $this->buildService()->buildMailListPdfHtml($tableArrays);
      $this->assertSame(2, substr_count($html, 'page-break-inside: avoid'));
      $this->assertSame(2, substr_count($html, 'autosize="1"'));
      // One row per voter, with the address only on the household's first row.
      $this->assertSame(1, substr_count($html, '123 Main St'));
      $this->assertMatchesRegularExpression('/Bob Smith.*123 Main St.*Jane Smith.*Ann Jones.*456 Oak Ave/s', $html);
    }

    // -------------------------------------------------------------------------
    // buildMailingList
    // -------------------------------------------------------------------------

    public function testBuildMailListPdfRenderedPagesKeepEachHouseholdTogether(): void {
      $sizes = $this->paginationHouseholdSizes();
      $voters = [];
      foreach (array_keys($sizes) as $h => $hh) {
        for ($i = 0; $i < $sizes[$hh]; $i++) {
          $voters[] = $this->baseMailVoter([
            'firstName' => 'Person' . $i,
            'lastName' => $hh . 'Johnson',
            'address' => ['mAddress' => (100 + $h) . ' NE Longername Street Apt ' . $h],
          ]);
        }
      }
      $service = $this->buildService();
      $html = $service->buildMailListPdfHtml($service->buildMailListArray($voters));

      $pageTexts = $this->pageTexts($this->renderPdf($html, 'P'));
      $households = $this->householdPages($pageTexts, '/Person\d+\s+(?<hh>HH\d\d)Johnson/');
      $this->assertHouseholdsKeptTogether($households, $sizes, 'HH10');
      foreach ($pageTexts as $pageNumber => $text) {
        $this->assertStringContainsString('Mailing Address', $text, "Page $pageNumber is missing the column headings.");
      }
    }

    public function testBuildMailingListEmptyVotersReturnsNoVotersMarkup(): void {
      $this->pdfGeneratorMock->expects($this->never())->method('generatePdf');
      $result = $this->buildService()->buildMailingList([]);
      $this->assertStringContainsString('No voters are available', $result['#markup']);
    }

    public function testBuildMailingListGeneratesPortraitPdfAndReturnsLink(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 12345]);
      $this->pdfGeneratorMock->expects($this->once())->method('generatePdf')
        ->with(
          $this->stringContains('Bob'),
          $this->matchesRegularExpression('#^public://temp/' . NlpDataEntryPrint::DD_POSTCARD_FILE . '-12345-.*\.pdf$#'),
          'P'
        )
        ->willReturn('https://example.org/files/temp/list.pdf');
      $result = $this->buildService()->buildMailingList([$this->baseMailVoter()]);
      $this->assertStringContainsString('<a href="https://example.org/files/temp/list.pdf"', $result['#markup']);
    }

    public function testBuildMailingListPdfFailureReturnsErrorMarkup(): void {
      $this->pdfGeneratorMock->method('generatePdf')->willReturn('');
      $result = $this->buildService()->buildMailingList([$this->baseMailVoter()]);
      $this->assertStringContainsString('could not be created', $result['#markup']);
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
      $this->assertStringContainsString('No walksheet data is available', $result['#markup']);
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
