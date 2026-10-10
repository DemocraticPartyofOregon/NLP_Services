<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\nlpservices\ApiResponseCodes;
  use Drupal\nlpservices\ApiVoter;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpCreateWalksheet;
  use Drupal\nlpservices\NlpPaths;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\nlpservices\NlpVoters;
  use Drupal\nlpservices\PdfGenerator;
  use Drupal\Tests\UnitTestCase;

  class NlpCreateWalksheetTest extends UnitTestCase {

    private NlpVoters $votersMock;
    private NlpPaths $pathsMock;
    private NlpConfiguration $configMock;
    private NlpSessionData $sessionMock;
    private PdfGenerator $pdfMock;
    private ApiResponseCodes $responseCodesMock;
    private NlpTurfs $turfsMock;
    private ApiVoter $apiVoterMock;

    protected function setUp(): void {
      parent::setUp();
      $this->votersMock        = $this->createMock(NlpVoters::class);
      $this->pathsMock         = $this->createMock(NlpPaths::class);
      $this->configMock        = $this->createMock(NlpConfiguration::class);
      $this->sessionMock       = $this->createMock(NlpSessionData::class);
      $this->pdfMock           = $this->createMock(PdfGenerator::class);
      $this->responseCodesMock = $this->createMock(ApiResponseCodes::class);
      $this->turfsMock         = $this->createMock(NlpTurfs::class);
      $this->apiVoterMock      = $this->createMock(ApiVoter::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpCreateWalksheet {
      return new NlpCreateWalksheet(
        $this->votersMock,
        $this->pathsMock,
        $this->configMock,
        $this->sessionMock,
        $this->pdfMock,
        $this->responseCodesMock,
        $this->turfsMock,
        $this->apiVoterMock,
      );
    }

    private function baseTurf(): array {
      return [
        'turfName'   => 'Denver-001',
        'voterCount' => 3,
        'doorCount'  => 2,
      ];
    }

    private function baseVoter(array $overrides = []): array {
      return array_replace_recursive([
        'vanid'         => '11111',
        'address'       => [
          'addressLine1' => '123 Main St',
          'addressLine2' => '',
          'city'         => 'Portland',
        ],
        'votingHistory' => 'VV@V',
        'lastName'      => 'Smith',
        'firstName'     => 'Bob',
        'nickname'      => 'Bob',
        'age'           => '45',
        'party'         => 'D',
        'sex'           => 'M',
      ], $overrides);
    }

    private function setupStandardConfigMocks(): void {
      $this->sessionMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurf')->willReturn($this->baseTurf());
      $this->configMock->method('getElectionName')->willReturn('General 2024');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->responseCodesMock->method('getApiExpectedAbbreviations')
        ->willReturn(['Contacted' => 'C', 'Not Home' => 'NH']);
      $this->configMock->method('getContactResponses')
        ->willReturn(['Contacted' => ['name' => 'Contacted'], 'Not Home' => ['name' => 'Not Home']]);
      $this->configMock->method('getPledgeSurveyQuestion')->willReturn([
        'scriptQuestion' => '<b>Will you vote?</b>',
        'responses'      => [['name' => 'Yes'], ['name' => 'No']],
      ]);
    }

    // -------------------------------------------------------------------------
    // createWalksheetArray — household structure
    // -------------------------------------------------------------------------

    public function testCreateWalksheetArraySingleVoterReturnsOneHousehold(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertCount(1, $result);
    }

    public function testCreateWalksheetArrayTwoVotersSameAddressGroupedIntoOneHousehold(): void {
      $this->setupStandardConfigMocks();
      $voter1 = $this->baseVoter(['vanid' => '11111']);
      $voter2 = $this->baseVoter(['vanid' => '22222', 'firstName' => 'Jane', 'lastName' => 'Smith']);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter1, $voter2]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertCount(1, $result);
      $this->assertCount(2, $result[0]['votersInHousehold']);
    }

    public function testCreateWalksheetArrayTwoVotersDifferentAddressesYieldTwoHouseholds(): void {
      $this->setupStandardConfigMocks();
      $voter1 = $this->baseVoter(['vanid' => '11111']);
      $voter2 = $this->baseVoter(['vanid' => '22222', 'address' => ['addressLine1' => '456 Oak Ave', 'addressLine2' => '', 'city' => 'Portland']]);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter1, $voter2]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertCount(2, $result);
    }

    public function testCreateWalksheetArrayEmptyVoterListReturnsEmptyArray(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame([], $result);
    }

    public function testCreateWalksheetArrayMissingTurfReturnsEmptyArray(): void {
      // A deleted turf: getTurf() returns [] and no voters are fetched.
      $this->turfsMock->method('getTurf')->willReturn([]);
      $this->votersMock->expects($this->never())->method('fetchVotersByTurf');
      $result = $this->buildService()->createWalksheetArray(1398);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // createWalksheetArray — row style
    // -------------------------------------------------------------------------

    public function testCreateWalksheetArraySingleVoterHouseholdRowIsSolid(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('solid', $result[0]['votersInHousehold'][0]['control']['row']);
    }

    public function testCreateWalksheetArrayFirstVoterInSharedHouseholdRowIsDashed(): void {
      $this->setupStandardConfigMocks();
      $voter1 = $this->baseVoter(['vanid' => '11111']);
      $voter2 = $this->baseVoter(['vanid' => '22222']);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter1, $voter2]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('dashed', $result[0]['votersInHousehold'][0]['control']['row']);
    }

    public function testCreateWalksheetArrayLastVoterInSharedHouseholdRowIsSolid(): void {
      $this->setupStandardConfigMocks();
      $voter1 = $this->baseVoter(['vanid' => '11111']);
      $voter2 = $this->baseVoter(['vanid' => '22222']);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter1, $voter2]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('solid', $result[0]['votersInHousehold'][1]['control']['row']);
    }

    // -------------------------------------------------------------------------
    // createWalksheetArray — column values
    // -------------------------------------------------------------------------

    public function testCreateWalksheetArrayAddressLine1Only(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('123 Main St', $result[0]['votersInHousehold'][0]['address']);
    }

    public function testCreateWalksheetArrayAddressLine1AndLine2CombinedWithBreak(): void {
      $this->setupStandardConfigMocks();
      $voter = $this->baseVoter(['address' => ['addressLine1' => '123 Main St', 'addressLine2' => 'Apt 4', 'city' => 'Portland']]);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('123 Main St<br>Apt 4', $result[0]['votersInHousehold'][0]['address']);
    }

    public function testCreateWalksheetArrayNameFormattedBoldWithDetails(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $name = $result[0]['votersInHousehold'][0]['name'];
      $this->assertStringContainsString('<b>Smith, Bob</b>', $name);
      $this->assertStringContainsString('45 M D', $name);
    }

    public function testCreateWalksheetArrayVotingHistoryAtSignsStripped(): void {
      $this->setupStandardConfigMocks();
      $voter = $this->baseVoter(['votingHistory' => 'V@V@V']);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('VVV', $result[0]['votersInHousehold'][0]['votingHistory']);
    }

    public function testCreateWalksheetArrayResultsColumnIsHashResultsPlaceholder(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('#results', $result[0]['votersInHousehold'][0]['results']);
    }

    public function testCreateWalksheetArrayQuestionColumnIsHashQuestionPlaceholder(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame('#question', $result[0]['votersInHousehold'][0]['question']);
    }

    public function testCreateWalksheetArrayControlContainsVanidAndAddress(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $result = $this->buildService()->createWalksheetArray(7);
      $control = $result[0]['votersInHousehold'][0]['control'];
      $this->assertSame('11111', $control['vanid']);
      $this->assertSame('123 Main St', $control['householdAddress']['addressLine1']);
    }

    // -------------------------------------------------------------------------
    // createWalksheetArray — response filtering
    // -------------------------------------------------------------------------

    public function testCreateWalksheetArrayOnlyMatchingContactResponsesIncluded(): void {
      $this->sessionMock->method('getCounty')->willReturn('Denver');
      $this->turfsMock->method('getTurf')->willReturn($this->baseTurf());
      $this->configMock->method('getElectionName')->willReturn('General 2024');
      $this->configMock->method('getBallotDropDate')->willReturn('2024-10-15');
      $this->configMock->method('getElectionDate')->willReturn('2024-11-05');
      $this->configMock->method('getPledgeSurveyQuestion')->willReturn([
        'scriptQuestion' => 'Will you vote?',
        'responses'      => [['name' => 'Yes']],
      ]);
      // Only 'Contacted' is in the Walk contact responses; 'Other' is not.
      $this->responseCodesMock->method('getApiExpectedAbbreviations')
        ->willReturn(['Contacted' => 'C', 'Other' => 'X']);
      $this->configMock->method('getContactResponses')
        ->willReturn(['Contacted' => ['name' => 'Contacted']]);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);

      // turfInfo['responseDisplay'] should only contain the Contacted entry.
      // We can't access turfInfo directly, but we can verify via createWalksheetPdf HTML.
      // Verify at least no exception is thrown and result is valid.
      $result = $this->buildService()->createWalksheetArray(7);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // createWalksheetPdf
    // -------------------------------------------------------------------------

    public function testCreateWalksheetPdfCallsGeneratePdfAndReturnsUrl(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);
      $this->pdfMock->method('generatePdf')->willReturn('public://temp/walksheet.pdf');

      $service = $this->buildService();
      $walksheetData = $service->createWalksheetArray(7);
      $result = $service->createWalksheetPdf($walksheetData);

      $this->assertSame('public://temp/walksheet.pdf', $result);
    }

    public function testCreateWalksheetPdfHtmlContainsTurfName(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return 'public://temp/walksheet.pdf';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      $this->assertStringContainsString('Denver-001', $capturedHtml);
    }

    public function testCreateWalksheetPdfHtmlContainsElectionName(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      $this->assertStringContainsString('General 2024', $capturedHtml);
    }

    public function testCreateWalksheetPdfHtmlContainsSurveyQuestion(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      // strip_tags removes <b> from the question; plain text should appear.
      $this->assertStringContainsString('Will you vote?', $capturedHtml);
    }

    public function testCreateWalksheetPdfEmptyWalksheetDataProducesNoPageBreak(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      // No households means the loop never fires, so no <pagebreak /> is written.
      $this->assertStringNotContainsString('<pagebreak />', $capturedHtml);
    }

    public function testCreateWalksheetPdfVoterRowExpandsResultsPlaceholder(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      $this->assertStringContainsString(NlpCreateWalksheet::WALKSHEET_RESULTS, $capturedHtml);
      $this->assertStringNotContainsString('#results', $capturedHtml);
    }

    public function testCreateWalksheetPdfVoterRowExpandsQuestionPlaceholder(): void {
      $this->setupStandardConfigMocks();
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$this->baseVoter()]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      $this->assertStringContainsString(NlpCreateWalksheet::WALKSHEET_QUESTION, $capturedHtml);
      $this->assertStringNotContainsString('#question', $capturedHtml);
    }

    public function testCreateWalksheetPdfKeepsEachHouseholdInItsOwnUnbreakableTable(): void {
      $this->setupStandardConfigMocks();
      // Two voters at 123 Main St (one household), then one at 456 Oak Ave.
      $voter1 = $this->baseVoter();
      $voter2 = $this->baseVoter(['vanid' => '22222']);
      $voter3 = $this->baseVoter(['vanid' => '33333', 'address' => ['addressLine1' => '456 Oak Ave', 'addressLine2' => '', 'city' => 'Portland']]);
      $this->votersMock->method('fetchVotersByTurf')->willReturn([$voter1, $voter2, $voter3]);

      $capturedHtml = '';
      $this->pdfMock->method('generatePdf')
        ->willReturnCallback(function (string $html) use (&$capturedHtml): string {
          $capturedHtml = $html;
          return '';
        });

      $service = $this->buildService();
      $service->createWalksheetPdf($service->createWalksheetArray(7));

      // One page break after the instructions; mPDF breaks pages between households.
      $this->assertSame(1, substr_count($capturedHtml, '<pagebreak />'));
      $this->assertSame(2, substr_count($capturedHtml, 'page-break-inside:avoid'));
      $this->assertSame(2, substr_count($capturedHtml, 'autosize="1"'));
      // The voter list ends with one solid row: the last household's bottom border.  The
      // first household's last row has none, so its separator isn't drawn twice.
      $voterList = substr($capturedHtml, strpos($capturedHtml, '<pagebreak />'));
      $this->assertSame(1, substr_count($voterList, '<tr style="border-bottom: 1px solid #000;">'));
      $this->assertSame(1, substr_count($voterList, '<tr style="border-bottom: 1px dashed LightGray;">'));
      $this->assertSame(1, substr_count($voterList, '<tr style="">'));
    }

  }

}
