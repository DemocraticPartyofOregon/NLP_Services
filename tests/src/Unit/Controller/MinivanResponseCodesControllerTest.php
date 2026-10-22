<?php

namespace Drupal\Tests\nlpservices\Unit\Controller {

  use Drupal\nlpservices\ApiResponseCodes;
  use Drupal\nlpservices\Controller\MinivanResponseCodesController;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\Tests\UnitTestCase;

  class MinivanResponseCodesControllerTest extends UnitTestCase {

    private ApiResponseCodes $apiResponseCodesMock;
    private NlpConfiguration $nlpConfigurationMock;

    protected function setUp(): void {
      parent::setUp();
      $this->apiResponseCodesMock = $this->createMock(ApiResponseCodes::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
    }

    private function codeList(): array {
      $controller = new MinivanResponseCodesController($this->apiResponseCodesMock, $this->nlpConfigurationMock);
      return $controller->getResponseCodeList();
    }

    public function testUnselectedWalkCodesAreNotPermitted(): void {
      $this->apiResponseCodesMock->method('getApiContactTypes')->willReturn(['Phone' => 1, 'Walk' => 2]);
      $this->apiResponseCodesMock->method('getApiResultCodes')->with(2)
        ->willReturn(['Canvassed' => 14, 'Not Home' => 1, 'Moved' => 5, 'Refused' => 2]);
      $this->nlpConfigurationMock->method('getContactResponses')->with(['name' => 'Walk'])
        ->willReturn(['Not Home' => 1, 'Moved' => 5]);

      $this->assertSame([
        ['name' => 'Not Home', 'code' => 1, 'permitted' => TRUE],
        ['name' => 'Moved', 'code' => 5, 'permitted' => TRUE],
        ['name' => 'Canvassed', 'code' => 14, 'permitted' => FALSE],
        ['name' => 'Refused', 'code' => 2, 'permitted' => FALSE],
      ], $this->codeList());
    }

    public function testSelectedCodesStillListedWhenVanHasNoWalkType(): void {
      $this->apiResponseCodesMock->method('getApiContactTypes')->willReturn([]);
      $this->apiResponseCodesMock->expects($this->never())->method('getApiResultCodes');
      $this->nlpConfigurationMock->method('getContactResponses')->willReturn(['Not Home' => 1]);

      $this->assertSame([['name' => 'Not Home', 'code' => 1, 'permitted' => TRUE]], $this->codeList());
    }

    public function testNoSelectionMarksEveryWalkCodeNotPermitted(): void {
      $this->apiResponseCodesMock->method('getApiContactTypes')->willReturn(['Walk' => 2]);
      $this->apiResponseCodesMock->method('getApiResultCodes')->willReturn(['Not Home' => 1]);
      $this->nlpConfigurationMock->method('getContactResponses')->willReturn([]);

      $this->assertSame([['name' => 'Not Home', 'code' => 1, 'permitted' => FALSE]], $this->codeList());
    }

  }

}
