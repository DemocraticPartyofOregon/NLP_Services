<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\TempStore\PrivateTempStore;
  use Drupal\Core\TempStore\PrivateTempStoreFactory;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class NlpSessionDataTest extends UnitTestCase {

    private PrivateTempStoreFactory $tempStoreFactoryMock;
    private PrivateTempStore $tempStoreMock;
    private DrupalUser $drupalUserMock;
    private NlpConfiguration $nlpConfigurationMock;
    private RequestStack $requestStackMock;
    private array $store = [];

    protected function setUp(): void {
      parent::setUp();

      $this->store = [];

      $this->tempStoreMock = $this->createMock(PrivateTempStore::class);
      $this->tempStoreMock->method('get')
        ->willReturnCallback(fn($key) => $this->store[$key] ?? NULL);
      $this->tempStoreMock->method('set')
        ->willReturnCallback(function ($key, $value): void {
          $this->store[$key] = $value;
        });

      $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
      $this->tempStoreFactoryMock->method('get')
        ->with('nlpservices.session_data')
        ->willReturn($this->tempStoreMock);

      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
      $this->requestStackMock = $this->createMock(RequestStack::class);
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    private function buildService(): NlpSessionData {
      return new NlpSessionData(
        $this->tempStoreFactoryMock,
        $this->drupalUserMock,
        $this->nlpConfigurationMock,
        $this->requestStackMock,
      );
    }

    private function mockRequest(?string $hdIndex = NULL, ?string $pctIndex = NULL): void {
      $params = [];
      if ($hdIndex !== NULL) { $params['hd_index'] = $hdIndex; }
      if ($pctIndex !== NULL) { $params['pct_index'] = $pctIndex; }
      $request = Request::create('/test', 'GET', $params);
      $this->requestStackMock->method('getCurrentRequest')->willReturn($request);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateFactoryUsesContainerServices(): void {
      $container = new ContainerBuilder();
      $container->set('tempstore.private', $this->tempStoreFactoryMock);
      $container->set('nlpservices.drupal_user', $this->drupalUserMock);
      $container->set('nlpservices.configuration', $this->nlpConfigurationMock);
      $container->set('request_stack', $this->requestStackMock);

      $service = NlpSessionData::create($container);

      $this->assertInstanceOf(NlpSessionData::class, $service);
    }

    // -------------------------------------------------------------------------
    // get() / set()
    // -------------------------------------------------------------------------

    public function testSetAndGetRoundTrip(): void {
      $service = $this->buildService();
      $service->set('myKey', 'myValue');

      $this->assertSame('myValue', $service->get('myKey'));
    }

    public function testGetReturnsNullForMissingKey(): void {
      $service = $this->buildService();

      $this->assertNull($service->get('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // getCounty()
    // -------------------------------------------------------------------------

    public function testGetCountyLoadsFromUserWhenSessionEmpty(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn(['county' => 'Multnomah']);
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $service = $this->buildService();

      $county = $service->getCounty();

      $this->assertSame('Multnomah', $county);
    }

    public function testGetCountyReturnsStoredCountyForAdmin(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn(['county' => 'Multnomah']);
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
      $service = $this->buildService();
      $service->set('County', 'Clackamas');

      $county = $service->getCounty();

      $this->assertSame('Clackamas', $county);
    }

    public function testGetCountyOverridesStoredCountyForNonAdminWhenMismatch(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn(['county' => 'Washington']);
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $service = $this->buildService();
      $service->set('County', 'Clackamas');

      $county = $service->getCounty();

      $this->assertSame('Washington', $county);
    }

    public function testGetCountyReturnsEmptyStringWhenUserHasNoCounty(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn([]);
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $service = $this->buildService();

      $county = $service->getCounty();

      $this->assertSame('', $county);
    }

    // -------------------------------------------------------------------------
    // setVoterContactMethod() / getVoterContactMethod()
    // -------------------------------------------------------------------------

    public function testGetVoterContactMethodReturnsDefaultWalkWhenNotSet(): void {
      $service = $this->buildService();

      $result = $service->getVoterContactMethod(123456);

      $this->assertSame(['vanid' => 123456, 'name' => 'Walk', 'code' => 2], $result);
    }

    public function testSetAndGetVoterContactMethod(): void {
      $service = $this->buildService();
      $method = ['vanid' => '123456', 'name' => 'Phone', 'code' => 1];
      $service->setVoterContactMethod($method);

      $result = $service->getVoterContactMethod('123456');

      $this->assertSame($method, $result);
    }

    public function testSetVoterContactMethodPreservesOtherVoters(): void {
      $service = $this->buildService();
      $methodA = ['vanid' => '111', 'name' => 'Phone', 'code' => 1];
      $methodB = ['vanid' => '222', 'name' => 'Walk', 'code' => 2];
      $service->setVoterContactMethod($methodA);
      $service->setVoterContactMethod($methodB);

      $this->assertSame($methodA, $service->getVoterContactMethod('111'));
      $this->assertSame($methodB, $service->getVoterContactMethod('222'));
    }

    public function testSetVoterContactMethodOverwritesExistingEntry(): void {
      $service = $this->buildService();
      $service->setVoterContactMethod(['vanid' => '999', 'name' => 'Walk', 'code' => 2]);
      $updated = ['vanid' => '999', 'name' => 'Phone', 'code' => 1];
      $service->setVoterContactMethod($updated);

      $this->assertSame($updated, $service->getVoterContactMethod('999'));
    }

    public function testGetVoterContactMethodReturnsSavedDefaultWhenNotSet(): void {
      $service = $this->buildService();
      $service->setDefaultVoterContactMethod(['code' => 1, 'name' => 'Phone']);

      $result = $service->getVoterContactMethod(123456);

      $this->assertSame(['vanid' => 123456, 'name' => 'Phone', 'code' => 1], $result);
    }

    public function testSetDefaultVoterContactMethodReplacesEveryVotersMethod(): void {
      $service = $this->buildService();
      $service->setVoterContactMethod(['vanid' => '111', 'name' => 'Walk', 'code' => 2]);
      $service->setVoterContactMethod(['vanid' => '222', 'name' => 'Postcard', 'code' => 3]);

      $service->setDefaultVoterContactMethod(['code' => 1, 'name' => 'Phone']);

      $this->assertSame(['vanid' => 111, 'name' => 'Phone', 'code' => 1], $service->getVoterContactMethod('111'));
      $this->assertSame(['vanid' => 222, 'name' => 'Phone', 'code' => 1], $service->getVoterContactMethod('222'));
      $this->assertSame(['code' => 1, 'name' => 'Phone'], $this->store['defaultVoterContactMethod']);
    }

    // -------------------------------------------------------------------------
    // setNewDistricts() — first call (no prior session data)
    // -------------------------------------------------------------------------

    public function testSetNewDistrictsSavesListWithDefaultIndexes(): void {
      $service = $this->buildService();
      $districtList = ['HD1', 'HD2', 'HD3'];

      $service->setNewDistricts($districtList);

      $this->assertSame($districtList, $service->getHdOptions());
    }

    public function testSetNewDistrictsDefaultHdIndexIsZero(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1', 'HD2']);

      $this->assertSame(0, $service->getSavedHdIndex());
    }

    public function testSetNewDistrictsDefaultPrecinctIndexIsZero(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2']);

      $this->assertSame(0, $service->getSavedPrecinctIndex());
    }

    public function testSetNewDistrictsSavesPctList(): void {
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2']);

      $this->assertSame(['Pct1', 'Pct2'], $service->getPrecinctList());
    }

    // -------------------------------------------------------------------------
    // setNewDistricts() — second call (restoring previous focus)
    // -------------------------------------------------------------------------

    public function testSetNewDistrictsRestoresPreviousHdWhenStillPresent(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1', 'HD2', 'HD3']);
      $service->saveHdIndex(2); // was focused on HD3

      $service->setNewDistricts(['HD0', 'HD2', 'HD3']); // HD3 is now index 2

      $this->assertSame(2, $service->getSavedHdIndex());
    }

    public function testSetNewDistrictsResetsHdIndexWhenPreviousHdGone(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1', 'HD2', 'HD3']);
      $service->saveHdIndex(2); // was on HD3

      $service->setNewDistricts(['HD1', 'HD2']); // HD3 no longer exists → reset

      $this->assertSame(0, $service->getSavedHdIndex());
    }

    public function testSetNewDistrictsRestoresPreviousPrecinctWhenStillPresent(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2', 'Pct3']);
      $service->savePrecinctIndex(2); // was on Pct3

      $service->setNewDistricts(['HD1'], ['Pct0', 'Pct2', 'Pct3']); // Pct3 now at index 2

      $this->assertSame(2, $service->getSavedPrecinctIndex());
    }

    public function testSetNewDistrictsResetsPrecinctIndexWhenPreviousPrecinctGone(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2']);
      $service->savePrecinctIndex(1); // was on Pct2

      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct3']); // Pct2 gone → reset

      $this->assertSame(0, $service->getSavedPrecinctIndex());
    }

    // -------------------------------------------------------------------------
    // saveHdIndex() / getSavedHdIndex()
    // -------------------------------------------------------------------------

    public function testSaveAndGetHdIndex(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1', 'HD2', 'HD3']);
      $service->saveHdIndex(1);

      $this->assertSame(1, $service->getSavedHdIndex());
    }

    public function testGetSavedHdIndexUsesQueryParamWhenPresent(): void {
      $this->mockRequest('2');
      $service = $this->buildService();
      $service->setNewDistricts(['HD1', 'HD2', 'HD3']);

      $index = $service->getSavedHdIndex();

      $this->assertSame(2, $index);
    }

    public function testGetSavedHdIndexReturnsZeroWhenNoDistrictsSet(): void {
      $this->mockRequest();
      $service = $this->buildService();

      $this->assertSame(0, $service->getSavedHdIndex());
    }

    // -------------------------------------------------------------------------
    // getSavedHd()
    // -------------------------------------------------------------------------

    public function testGetSavedHdReturnsSelectedDistrictAsInt(): void {
      $service = $this->buildService();
      $service->setNewDistricts(['All', '12', '14']);
      $service->saveHdIndex(2);

      $this->assertSame(14, $service->getSavedHd());
    }

    public function testGetSavedHdReturnsZeroWhenAllIsSelected(): void {
      $service = $this->buildService();
      $service->setNewDistricts(['All', '12', '14']);
      $service->saveHdIndex(0);

      $this->assertSame(0, $service->getSavedHd());
    }

    public function testGetSavedHdReturnsZeroWhenNoDistrictsSet(): void {
      $service = $this->buildService();

      $this->assertSame(0, $service->getSavedHd());
    }

    // -------------------------------------------------------------------------
    // savePrecinctIndex() / getSavedPrecinctIndex()
    // -------------------------------------------------------------------------

    public function testSaveAndGetPrecinctIndex(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2', 'Pct3']);
      $service->savePrecinctIndex(2);

      $this->assertSame(2, $service->getSavedPrecinctIndex());
    }

    public function testGetSavedPrecinctIndexReturnsZeroWhenNotSet(): void {
      $this->mockRequest();
      $service = $this->buildService();

      $this->assertSame(0, $service->getSavedPrecinctIndex());
    }

    public function testGetSavedPrecinctIndexUsesQueryParamWhenPresent(): void {
      $this->mockRequest(NULL, '2');
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['Pct1', 'Pct2', 'Pct3']);

      $this->assertSame(2, $service->getSavedPrecinctIndex());
    }

    // -------------------------------------------------------------------------
    // getSavedPct()
    // -------------------------------------------------------------------------

    public function testGetSavedPctReturnsEmptyStringWhenNoPctListSet(): void {
      $service = $this->buildService();

      $this->assertSame('', $service->getSavedPct());
    }

    public function testGetSavedPctReturnsEmptyStringWhenAllSelected(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['All', 'Pct1', 'Pct2']);
      // default index 0 → 'All'

      $this->assertSame('', $service->getSavedPct());
    }

    public function testGetSavedPctReturnsPrecinctValueWhenNonAllSelected(): void {
      $this->mockRequest();
      $service = $this->buildService();
      $service->setNewDistricts(['HD1'], ['All', 'Pct1', 'Pct2']);
      $service->savePrecinctIndex(2);

      $this->assertSame('Pct2', $service->getSavedPct());
    }

    // -------------------------------------------------------------------------
    // getHdOptions() / getPrecinctList()
    // -------------------------------------------------------------------------

    public function testGetHdOptionsReturnsEmptyArrayWhenNotSet(): void {
      $service = $this->buildService();

      $this->assertSame([], $service->getHdOptions());
    }

    public function testGetPrecinctListReturnsEmptyArrayWhenNotSet(): void {
      $service = $this->buildService();

      $this->assertSame([], $service->getPrecinctList());
    }

  }

}
