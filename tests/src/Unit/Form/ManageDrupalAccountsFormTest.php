<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\ManageDrupalAccountsForm;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSearchBar;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpUserDisplay;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'validateForm')]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'submitForm')]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'hdsWithUsers')]
#[CoversMethod(\Drupal\nlpservices\Form\ManageDrupalAccountsForm::class, 'displayUser')]
#[Group('nlpservices')]
class ManageDrupalAccountsFormTest extends UnitTestCase {

  private DrupalUser $drupalUserMock;
  private NlpNls $nlsMock;
  private MagicWord $magicWordMock;
  private NlpSearchBar $searchBarMock;
  private NlpUserDisplay $userDisplayMock;
  private NlpSessionData $sessionDataMock;
  private NlpConfiguration $nlpConfigurationMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->drupalUserMock       = $this->createMock(DrupalUser::class);
    $this->nlsMock              = $this->createMock(NlpNls::class);
    $this->magicWordMock        = $this->createMock(MagicWord::class);
    $this->searchBarMock        = $this->createMock(NlpSearchBar::class);
    $this->userDisplayMock      = $this->createMock(NlpUserDisplay::class);
    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
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

  private function buildFormInstance(): ManageDrupalAccountsForm {
    $form = new ManageDrupalAccountsForm(
      $this->drupalUserMock,
      $this->nlsMock,
      $this->magicWordMock,
      $this->searchBarMock,
      $this->userDisplayMock,
      $this->sessionDataMock,
      $this->nlpConfigurationMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  private function baseUser(int $uid, string $hd = '1'): array {
    return [
      'uid'       => $uid,
      'firstName' => 'Test',
      'lastName'  => 'User',
      'hd'        => $hd,
      'roles'     => [],
      'mcid'      => 100 + $uid,
      'email'     => 'test@example.com',
      'phone'     => '555-1234',
      'county'    => 'TestCounty',
      'userName'  => 'test.user',
    ];
  }

  private function configureDisplayUsersMocks(string $county = 'TestCounty'): void {
    $this->sessionDataMock->method('getCounty')->willReturn($county);
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);
  }

  private function editSubmitCurrentValue(): array {
    return [
      'firstName' => 'Alice',
      'lastName'  => 'Smith',
      'mcid'      => 42,
      'county'    => 0,
      'hd'        => '1',
      'phone'     => '555-1234',
      'uname'     => 'alice.smith',
      'magic-word' => 'pass',
      'mail'      => 'alice@example.com',
      'roles'     => [
        'roleDefaults' => ['nlp_leader' => 'nlp_leader', 'nlp_coordinator' => ''],
        'allowedRoles' => ['nlp_leader' => 'NLP Leader'],
      ],
    ];
  }

  private function editSubmitValues(array $overrides = []): array {
    return array_merge([
      'firstName'      => 'Alice',
      'lastName'       => 'Smith',
      'mcid'           => 42,
      'county'         => 0,
      'hd'             => '1',
      'phone'          => '555-1234',
      'uname'          => 'alice.smith',
      'magic-word'     => 'pass',
      'mail'           => 'alice@example.com',
      'selected_roles' => ['nlp_leader' => 'nlp_leader', 'nlp_coordinator' => 0],
    ], $overrides);
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame('nlpservices_manage_drupal_accounts_form', $this->buildFormInstance()->getFormId());
  }

  // -------------------------------------------------------------------------
  // buildForm
  // -------------------------------------------------------------------------

  public function testBuildFormSetsReenterCountyAndPageOnFirstRender(): void {
    $this->configureDisplayUsersMocks();

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertTrue($formState->get('reenter'));
    $this->assertSame('TestCounty', $formState->get('county'));
    $this->assertSame('displayUsers', $formState->get('page'));
  }

  public function testBuildFormSkipsInitialisationWhenReenterAlreadySet(): void {
    $this->sessionDataMock->expects($this->never())->method('getCounty');
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->searchBarMock->method('searchBar')->willReturn([]);
    $this->userDisplayMock->method('displayUsers')->willReturn([]);

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);

    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormReturnsEmptyArrayAndAddsWarningWhenCountyEmpty(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('');
    $this->drupalUserMock->method('getUsers')->willReturn([]);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addWarning');

    $result = $this->buildFormInstance()->buildForm([], new FormState());

    $this->assertSame([], $result);
  }

  public function testBuildFormContainsExpectedElementsOnDisplayUsersPage(): void {
    $this->configureDisplayUsersMocks();

    $formState = new FormState();
    $formState->set('reenter', TRUE);
    $formState->set('county', 'TestCounty');
    $formState->set('page', 'displayUsers');
    $formState->set('hdOptions', []);

    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('options_display', $form);
    $this->assertArrayHasKey('user-form', $form);
    $this->assertArrayHasKey('submit', $form);
  }

  public function testBuildFormAttachesRequiredLibraries(): void {
    $this->configureDisplayUsersMocks();

    $form = $this->buildFormInstance()->buildForm([], new FormState());

    $libs = $form['#attached']['library'];
    $this->assertContains('nlpservices/nlp-table', $libs);
    $this->assertContains('nlpservices/info-boxes', $libs);
    $this->assertContains('nlpservices/search-boxes', $libs);
    $this->assertContains('nlpservices/edit-user', $libs);
  }

  public function testBuildFormFiltersUsersBySearchNeedleAndClearsNeedle(): void {
    $users   = [1 => $this->baseUser(1), 2 => $this->baseUser(2)];
    $filtered = [1 => $this->baseUser(1)];

    $this->drupalUserMock->method('getUsers')->willReturn($users);
    $this->drupalUserMock->method('isNlpAdminUser')->willReturn(TRUE);
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('getSavedHd')->willReturn(0);
    $this->sessionDataMock->method('getSavedHdIndex')->willReturn(0);
    $this->sessionDataMock->method('getHdOptions')->willReturn([]);
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

  // -------------------------------------------------------------------------
  // validateForm
  // -------------------------------------------------------------------------

  public function testValidateFormHdSelectSavesHdIndex(): void {
    $this->sessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
    $formState->method('getValue')->with('hd-select')->willReturn(2);

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormUserSelectSetsErrorWhenNoUsersSelected(): void {
    $this->userDisplayMock->method('validateUserSelection')->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'user_select']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->expects($this->once())->method('setErrorByName')
      ->with('contacts', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormUserSelectStoresSelectionsWhenValid(): void {
    $selections = [['uid' => 5]];
    $this->userDisplayMock->method('validateUserSelection')->willReturn($selections);

    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'user_select']);
    $formState->method('getValue')->with('contacts')->willReturn([]);
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertSame($selections, $captured['userSelections']);
  }

  public function testValidateFormEditSubmitSetsErrorForEmptyFirstName(): void {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['firstName' => '']));
    $formState->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('firstName', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitSetsErrorForDuplicateMcid(): void {
    $this->drupalUserMock->method('getUserByMcid')->with(99)->willReturn(['uid' => 7]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['mcid' => 99]));
    $formState->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('mcid', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitSetsErrorWhenMcidNotInNlList(): void {
    $this->drupalUserMock->method('getUserByMcid')->with(99)->willReturn([]);
    $this->nlsMock->method('getNlById')->with(99)->willReturn([]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['mcid' => 99]));
    $formState->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('mcid', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitSetsErrorForEmptyMail(): void {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['mail' => '']));
    $formState->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('mail', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitSetsErrorForDuplicateEmail(): void {
    $this->drupalUserMock->method('getUserByEmail')->with('other@example.com')->willReturn(['uid' => 9]);

    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['mail' => 'other@example.com']));
    $formState->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('mail', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitSetsErrorWhenNoRoleSelected(): void {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues([
      'selected_roles' => ['nlp_leader' => 0, 'nlp_coordinator' => 0],
    ]));
    $formState->expects($this->once())->method('setErrorByName')
      ->with('selected_roles', $this->anything());

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);
  }

  public function testValidateFormEditSubmitStoresChangedFieldsWhenValid(): void {
    $captured = [];
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getTriggeringElement')->willReturn(['#name' => 'edit_submit']);
    $formState->method('get')->with('currentValue')->willReturn($this->editSubmitCurrentValue());
    $formState->method('getValues')->willReturn($this->editSubmitValues(['firstName' => 'Alicia']));
    $formState->method('set')->willReturnCallback(
      function ($key, $value) use (&$captured): void { $captured[$key] = $value; }
    );

    $form = [];
    $this->buildFormInstance()->validateForm($form, $formState);

    $this->assertArrayHasKey('changed', $captured);
    $this->assertSame('Alicia', $captured['changed']['firstName']);
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormSearchSubmitStoresNeedleAndSetsReenter(): void {
    $formState = new FormState();
    $formState->setValue('search-needle', 'Jones');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('Jones', $formState->get('search-needle'));
    $this->assertTrue($formState->get('reenter'));
  }

  public function testSubmitFormCallsSetRebuild(): void {
    $formState = $this->getMockBuilder(FormState::class)
      ->onlyMethods(['setRebuild'])
      ->getMock();
    $formState->expects($this->once())->method('setRebuild');
    $formState->setValue('search-needle', '');
    $formState->setTriggeringElement(['#name' => 'search_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormUserSelectSetsPageAndBuildsUidList(): void {
    $formState = new FormState();
    $formState->set('userSelections', [['uid' => 3], ['uid' => 7]]);
    $formState->setTriggeringElement(['#name' => 'user_select']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('editUsers', $formState->get('page'));
    $this->assertSame([3, 7], $formState->get('editUsers'));
    $this->assertSame(2, $formState->get('currentEditIndex'));
  }

  public function testSubmitFormEditDoneSetsPageToDisplayUsersAndResetsEditState(): void {
    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'edit_done']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('displayUsers', $formState->get('page'));
    $this->assertNull($formState->get('editUsers'));
    $this->assertSame(0, $formState->get('currentEditIndex'));
  }

  public function testSubmitFormEditSubmitUpdatesUserAndDecrementsIndex(): void {
    $this->drupalUserMock->expects($this->once())->method('updateUser');

    $formState = new FormState();
    $formState->set('changed', ['firstName' => 'Bob']);
    $formState->set('currentValue', ['uid' => 5]);
    $formState->set('currentEditIndex', 2);
    $formState->set('mcid', NULL);
    $formState->setTriggeringElement(['#name' => 'edit_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame(1, $formState->get('currentEditIndex'));
  }

  public function testSubmitFormEditSubmitReturnsToDisplayUsersWhenLastUser(): void {
    $this->drupalUserMock->method('updateUser')->willReturn([]);

    $formState = new FormState();
    $formState->set('changed', []);
    $formState->set('currentValue', ['uid' => 5]);
    $formState->set('currentEditIndex', 1);
    $formState->set('mcid', NULL);
    $formState->setTriggeringElement(['#name' => 'edit_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);

    $this->assertSame('displayUsers', $formState->get('page'));
    $this->assertNull($formState->get('editUsers'));
  }

  public function testSubmitFormEditSubmitCallsSetMagicWordWhenPasswordChangedAndMcidSet(): void {
    $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(42, 'newpass');
    $this->drupalUserMock->method('updateUser')->willReturn([]);

    $formState = new FormState();
    $formState->set('changed', ['magic-word' => 'newpass']);
    $formState->set('currentValue', ['uid' => 5]);
    $formState->set('currentEditIndex', 2);
    $formState->set('mcid', 42);
    $formState->setTriggeringElement(['#name' => 'edit_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormEditSubmitSkipsMagicWordWhenMcidIsEmpty(): void {
    $this->magicWordMock->expects($this->never())->method('setMagicWord');
    $this->drupalUserMock->method('updateUser')->willReturn([]);

    $formState = new FormState();
    $formState->set('changed', ['magic-word' => 'newpass']);
    $formState->set('currentValue', ['uid' => 5]);
    $formState->set('currentEditIndex', 2);
    $formState->set('mcid', NULL);
    $formState->setTriggeringElement(['#name' => 'edit_submit']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  // -------------------------------------------------------------------------
  // hdsWithUsers
  // -------------------------------------------------------------------------

  public function testHdsWithUsersReturnsEmptyForNoUsers(): void {
    $users = [];
    $result = $this->buildFormInstance()->hdsWithUsers($users, 0);
    $this->assertSame([], $result);
  }

  public function testHdsWithUsersSkipsUnknownHd(): void {
    $users = [
      1 => $this->baseUser(1, 'Unknown'),
      2 => $this->baseUser(2, '1'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users, 0);

    $this->assertSame([1], $result);
  }

  public function testHdsWithUsersSkipsEmptyHd(): void {
    $users = [
      1 => $this->baseUser(1, ''),
      2 => $this->baseUser(2, '2'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users, 0);

    $this->assertSame([2], $result);
  }

  public function testHdsWithUsersDeduplicatesHds(): void {
    $users = [
      1 => $this->baseUser(1, '1'),
      2 => $this->baseUser(2, '1'),
      3 => $this->baseUser(3, '2'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users, 0);

    $this->assertCount(2, $result);
  }

  public function testHdsWithUsersFiltersToSpecificHd(): void {
    $users = [
      1 => $this->baseUser(1, '1'),
      2 => $this->baseUser(2, '2'),
    ];

    $result = $this->buildFormInstance()->hdsWithUsers($users, 1);

    $this->assertSame([1], $result);
  }

  public function testHdsWithUsersModifiesUsersArrayByReference(): void {
    $users = [
      1 => $this->baseUser(1, '1'),
      2 => $this->baseUser(2, '2'),
    ];

    $this->buildFormInstance()->hdsWithUsers($users, 1);

    $this->assertArrayHasKey(1, $users);
    $this->assertArrayNotHasKey(2, $users);
  }

  // -------------------------------------------------------------------------
  // displayUser
  // -------------------------------------------------------------------------

  public function testDisplayUserReturnsFormElementsWithExpectedKeys(): void {
    $this->drupalUserMock->method('getNlpRoles')->willReturn([
      'nlp_leader'      => 'NLP Leader',
      'nlp_coordinator' => 'NLP Coordinator',
      'nlp_admin'       => 'NLP Admin',
    ]);

    $user           = $this->baseUser(1);
    $user['roles']  = [0 => 'authenticated', 'nlp_leader' => 'nlp_leader'];
    $user['password'] = 'secret';

    $countyNames  = [0 => 'TestCounty'];
    $currentValue = [];

    $result = $this->buildFormInstance()->displayUser($user, $currentValue, $countyNames);

    $this->assertArrayHasKey('edit', $result);
    $this->assertArrayHasKey('firstName', $result['edit']);
    $this->assertArrayHasKey('lastName', $result['edit']);
    $this->assertArrayHasKey('mcid', $result['edit']);
    $this->assertArrayHasKey('mail', $result['edit']);
    $this->assertArrayHasKey('selected_roles', $result['edit']);
  }

  public function testDisplayUserExcludesNlpAdminFromRoleOptions(): void {
    $this->drupalUserMock->method('getNlpRoles')->willReturn([
      'nlp_leader' => 'NLP Leader',
      'nlp_admin'  => 'NLP Admin',
    ]);

    $user           = $this->baseUser(1);
    $user['roles']  = [0 => 'authenticated'];
    $user['password'] = 'secret';

    $countyNames  = [0 => 'TestCounty'];
    $currentValue = [];

    $result = $this->buildFormInstance()->displayUser($user, $currentValue, $countyNames);

    $this->assertArrayNotHasKey('nlp_admin', $result['edit']['selected_roles']['#options']);
    $this->assertArrayHasKey('nlp_leader', $result['edit']['selected_roles']['#options']);
  }

  public function testDisplayUserPopulatesCurrentValueWithUserFields(): void {
    $this->drupalUserMock->method('getNlpRoles')->willReturn([
      'nlp_leader' => 'NLP Leader',
    ]);

    $user             = $this->baseUser(1);
    $user['roles']    = [0 => 'authenticated'];
    $user['password'] = 'thepassword';
    $user['firstName'] = 'Jane';
    $user['lastName']  = 'Doe';
    $user['email']     = 'jane@example.com';

    $countyNames  = [0 => 'TestCounty'];
    $currentValue = [];

    $this->buildFormInstance()->displayUser($user, $currentValue, $countyNames);

    $this->assertSame('Jane', $currentValue['firstName']);
    $this->assertSame('Doe', $currentValue['lastName']);
    $this->assertSame('jane@example.com', $currentValue['mail']);
    $this->assertSame('thepassword', $currentValue['magic-word']);
  }

}
