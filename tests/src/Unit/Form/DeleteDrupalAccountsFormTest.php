<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\DeleteDrupalAccountsForm;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpUserDisplay;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\Form\DeleteDrupalAccountsForm
 * @group nlpservices
 */
class DeleteDrupalAccountsFormTest extends UnitTestCase {

  private DrupalUser $drupalUserMock;
  private NlpUserDisplay $userDisplayMock;
  private NlpSearchBar $searchBarMock;
  private PrivateTempStoreFactory $privateTempstoreMock;
  private NlpSessionData $sessionDataMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->drupalUserMock       = $this->createMock(DrupalUser::class);
    $this->userDisplayMock      = $this->createMock(NlpUserDisplay::class);
    $this->searchBarMock        = $this->createMock(NlpSearchBar::class);
    $this->privateTempstoreMock = $this->createMock(PrivateTempStoreFactory::class);
    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->messengerMock        = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messengerMock);
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

  private function buildFormInstance(): DeleteDrupalAccountsForm {
    $form = new DeleteDrupalAccountsForm(
      $this->drupalUserMock,
      $this->userDisplayMock,
      $this->searchBarMock,
      $this->privateTempstoreMock,
      $this->sessionDataMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function baseUser(int $uid, int|string $hd = 'HD1'): array {
    return [
      'uid'       => $uid,
      'firstName' => 'Test',
      'lastName'  => 'User',
      'hd'        => $hd,
      'roles'     => [],
    ];
  }

  /**
   * Configure mocks for a successful first-render buildForm.
   */
  private function configureBuildFormMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);
  }

  /**
   * Configure mocks for buildForm when reenter is already set.
   */
  private function configureBuildFormReenterMocks(string $county = 'TestCounty'): void {
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  /**
   * @covers ::getFormId
   */
  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_delete_drupal_user_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSetsReenterAndCountyOnFirstRender(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame('TestCounty', $formState->get('county'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSetsPageToDisplayUsersOnFirstRender(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('displayUsers', $formState->get('page'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSetsCountySelectedWhenNotAdminUser(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county_selected'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormSkipsInitialisationWhenReenterAlreadySet(): void {
    $this->configureBuildFormReenterMocks();
    $this->drupalUserMock->expects($this->never())->method('isNlpAdminUser');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);

    $this->buildFormInstance()->buildForm([], $formState);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormReturnsEmptyArrayAndAddsWarningWhenCountyEmpty(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormContainsUserFormAndSubmitOnDisplayUsersPage(): void {
    $this->configureBuildFormMocks();

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('user-form', $form);
    $this->assertArrayHasKey('submit', $form);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureBuildFormMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertContains('nlpservices/info-boxes', $form['#attached']['library']);
    $this->assertContains('nlpservices/nlp-table', $form['#attached']['library']);
    $this->assertContains('nlpservices/search-boxes', $form['#attached']['library']);
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFiltersUsersBySearchNeedle(): void {
    $users = [1 => $this->baseUser(1), 2 => $this->baseUser(2)];
    $filtered = [1 => $this->baseUser(1)];

    $this->drupalUserMock->method('getUsers')->willReturn($users);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('getSavedHd')->willReturn('All');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);
    $this->searchBarMock->expects($this->once())
      ->method('searchInfoArray')
      ->with('Test', $users)
      ->willReturn($filtered);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);
    $formState->set('search-needle', 'Test');

    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertNull($formState->get('search-needle'));
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormFiltersUsersByHdWhenHdIsNotAll(): void {
    $users = [
      1 => $this->baseUser(1, 'HD1'),
      2 => $this->baseUser(2, 'HD2'),
    ];

    $this->drupalUserMock->method('getUsers')->willReturn($users);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('getSavedHd')->willReturn('HD1');
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(1);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);

    $this->buildFormInstance()->buildForm([], $formState);

    $storedUsers = $formState->get('users');
    $this->assertArrayHasKey(1, $storedUsers);
    $this->assertArrayNotHasKey(2, $storedUsers);
  }

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::validateForm
   */
  public function testValidateFormSearchSubmitReturnsWithoutSideEffects(): void {
    $this->userDisplayMock->expects($this->never())->method('validateUserSelection');
    $this->sessionDataMock->expects($this->never())->method('saveHdIndex');

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormHdSelectSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(3);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
    $formState->method('getValue')->with('hd-select')->willReturn(3);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormDeleteSubmitSetsErrorWhenNoUsersSelected(): void {
    $this->userDisplayMock->method('validateUserSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete-submit']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('reminder_select', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormDeleteSubmitStoresUserSelectionsOnValidSelection(): void {
    $selections = [['uid' => 5]];
    $this->userDisplayMock->method('validateUserSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'delete-submit']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['userSelections']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSearchSubmitStoresNeedle(): void {
    $formState = new FormState();
    $formState->setValue('search-needle', 'Smith');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Smith', $formState->get('search-needle'));
    $this->assertTrue($formState->get('reenter'));
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitDeletesNonAdminUsers(): void {
    $this->drupalUserMock->expects($this->once())->method('deleteUser')->with(7);
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 7]]);
    $formState->set('users', [
      7 => ['uid' => 7, 'firstName' => 'Jane', 'lastName' => 'Doe', 'roles' => []],
    ]);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitLoadsUserMissingFromFormState(): void {
    $this->drupalUserMock->method('getUserByUid')->with(7)
      ->willReturn(['uid' => 7, 'firstName' => 'Jane', 'lastName' => 'Doe', 'roles' => []]);
    $this->drupalUserMock->expects($this->once())->method('deleteUser')->with(7);
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 7]]);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitWarnsWhenUserNoLongerExists(): void {
    $this->drupalUserMock->method('getUserByUid')->with(7)->willReturn([]);
    $this->drupalUserMock->expects($this->never())->method('deleteUser');
    $this->messengerMock->expects($this->once())->method('addWarning');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 7]]);
    $formState->set('users', []);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitSkipsAdminUsers(): void {
    $this->drupalUserMock->expects($this->never())->method('deleteUser');
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 8]]);
    $formState->set('users', [
      8 => ['uid' => 8, 'firstName' => 'Admin', 'lastName' => 'User', 'roles' => ['administrator']],
    ]);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitSkipsNlpAdminUsers(): void {
    $this->drupalUserMock->expects($this->never())->method('deleteUser');

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 9]]);
    $formState->set('users', [
      9 => ['uid' => 9, 'firstName' => 'NlpAdmin', 'lastName' => 'User', 'roles' => ['nlp_admin']],
    ]);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDeleteSubmitDeletesOnlyNonAdminsFromMixedList(): void {
    $deleted = [];
    $this->drupalUserMock->method('deleteUser')
      ->willReturnCallback(function ($uid) use (&$deleted): void {
        $deleted[] = $uid;
      });

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('userSelections', [['uid' => 10], ['uid' => 11]]);
    $formState->set('users', [
      10 => ['uid' => 10, 'firstName' => 'Alice', 'lastName' => 'Nl', 'roles' => []],
      11 => ['uid' => 11, 'firstName' => 'Bob',   'lastName' => 'Admin', 'roles' => ['administrator']],
    ]);
    $formState->setTriggeringElement(['#name' => 'delete-submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame([10], $deleted);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSetsReenterAndCallsSetRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->setValue('search-needle', '');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertTrue($formState->get('reenter'));
  }

  // -------------------------------------------------------------------------
  // hdsWithUsers
  // -------------------------------------------------------------------------

  /**
   * @covers ::hdsWithUsers
   */
  public function testHdsWithUsersReturnsEmptyArrayForNoUsers(): void {
    $result = $this->buildFormInstance()->hdsWithUsers([]);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::hdsWithUsers
   */
  public function testHdsWithUsersSkipsUnknownAndZeroHd(): void {
    $users = [
      $this->baseUser(1, 'Unknown'),
      $this->baseUser(2, 0),
      $this->baseUser(3, 'HD1'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users);

    $this->assertNotContains('Unknown', $result);
    $this->assertNotContains(0, $result);
    $this->assertContains('HD1', $result);
  }

  /**
   * @covers ::hdsWithUsers
   */
  public function testHdsWithUsersDeduplicatesHds(): void {
    $users = [
      $this->baseUser(1, 'HD1'),
      $this->baseUser(2, 'HD1'),
      $this->baseUser(3, 'HD2'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users);

    $this->assertCount(2, $result);
  }

  // -------------------------------------------------------------------------
  // justUsersInHd
  // -------------------------------------------------------------------------

  /**
   * @covers ::justUsersInHd
   */
  public function testJustUsersInHdReturnsOnlyUsersMatchingHd(): void {
    $users = [
      1 => $this->baseUser(1, 1),
      2 => $this->baseUser(2, 2),
      3 => $this->baseUser(3, 1),
    ];

    $result = $this->buildFormInstance()->justUsersInHd($users, 1);

    $this->assertArrayHasKey(1, $result);
    $this->assertArrayHasKey(3, $result);
    $this->assertArrayNotHasKey(2, $result);
  }

  /**
   * @covers ::justUsersInHd
   */
  public function testJustUsersInHdTreatsUnknownAsZero(): void {
    $users = [
      1 => $this->baseUser(1, 'Unknown'),
      2 => $this->baseUser(2, 'HD1'),
    ];

    $result = $this->buildFormInstance()->justUsersInHd($users, 0);

    $this->assertArrayHasKey(1, $result);
    $this->assertArrayNotHasKey(2, $result);
  }

  /**
   * @covers ::justUsersInHd
   */
  public function testJustUsersInHdReturnsEmptyArrayWhenNoMatch(): void {
    $users = [1 => $this->baseUser(1, 2)];

    $result = $this->buildFormInstance()->justUsersInHd($users, 1);

    $this->assertSame([], $result);
  }

}
