<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\NlpUserDisplay;
  use Drupal\Tests\UnitTestCase;

  class NlpUserDisplayTest extends UnitTestCase {

    private NlpUserDisplay $service;

    protected function setUp(): void {
      parent::setUp();
      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      \Drupal::setContainer($container);
      $this->service = new NlpUserDisplay();
    }

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function baseUser(): array {
      return [
        'uid'       => 7,
        'hd'        => 42,
        'mcid'      => 100,
        'lastName'  => 'Doe',
        'firstName' => 'Jane',
        'userName'  => 'jdoe',
        'created'   => mktime(0, 0, 0, 3, 15, 2023),
        'access'    => mktime(0, 0, 0, 10, 1, 2024),
        'phone'     => '503-555-1234',
        'email'     => 'jane@example.com',
        'county'    => 'Multnomah',
        'roles'     => ['authenticated' => 'Authenticated', 'coordinator' => 'Coordinator'],
      ];
    }

    // -------------------------------------------------------------------------
    // displayUsers — empty
    // -------------------------------------------------------------------------

    public function testDisplayUsersEmptyReturnsNoUsersMarkup(): void {
      $result = $this->service->displayUsers([]);
      $this->assertArrayHasKey('table-form', $result);
      $this->assertStringContainsString('No users', $result['table-form']['no-users']['#markup']);
    }

    // -------------------------------------------------------------------------
    // displayUsers — standard (not login reminder)
    // -------------------------------------------------------------------------

    public function testDisplayUsersReturnsContactsKey(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertArrayHasKey('contacts', $result);
    }

    public function testDisplayUsersContactsIsTableType(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('table', $result['contacts']['#type']);
    }

    public function testDisplayUsersHasHeader(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertNotEmpty($result['contacts']['#header']);
    }

    public function testDisplayUsersCbColumnIsCheckbox(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('checkbox', $result['contacts'][1]['cb']['#type']);
    }

    public function testDisplayUsersCbReturnValueContainsRowCountAndUid(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('CB_1_7', $result['contacts'][1]['cb']['#return_value']);
    }

    public function testDisplayUsersRowNumbersStartAtOne(): void {
      $result = $this->service->displayUsers([$this->baseUser(), $this->baseUser()]);
      $this->assertArrayHasKey(1, $result['contacts']);
      $this->assertArrayHasKey(2, $result['contacts']);
    }

    public function testDisplayUsersLastNameHasMarkup(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('Doe', $result['contacts'][1]['lastName']['#markup']);
    }

    public function testDisplayUsersCreatedDateFormatted(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('2023/3/15', $result['contacts'][1]['created']['#markup']);
    }

    public function testDisplayUsersAccessDateFormatted(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('2024/10/1', $result['contacts'][1]['access']['#markup']);
    }

    public function testDisplayUsersAccessNeverWhenZero(): void {
      $user = array_merge($this->baseUser(), ['access' => 0]);
      $result = $this->service->displayUsers([$user]);
      $this->assertSame('Never', $result['contacts'][1]['access']['#markup']);
    }

    public function testDisplayUsersStandardModeHasCreatedNotPhone(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertArrayHasKey('created', $result['contacts'][1]);
      $this->assertArrayNotHasKey('phone', $result['contacts'][1]);
    }

    public function testDisplayUsersRolesSkipsAuthenticated(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertSame('Coordinator', $result['contacts'][1]['roles']['#markup']);
    }

    public function testDisplayUsersMultipleNonAuthenticatedRolesJoinedWithComma(): void {
      $user = array_merge($this->baseUser(), [
        'roles' => ['authenticated' => 'Authenticated', 'coordinator' => 'Coordinator', 'admin' => 'Admin'],
      ]);
      $result = $this->service->displayUsers([$user]);
      $this->assertStringContainsString('Coordinator', $result['contacts'][1]['roles']['#markup']);
      $this->assertStringContainsString('Admin', $result['contacts'][1]['roles']['#markup']);
      $this->assertStringContainsString(',', $result['contacts'][1]['roles']['#markup']);
    }

    public function testDisplayUsersOnlyAuthenticatedRoleShowsEmpty(): void {
      $user = array_merge($this->baseUser(), ['roles' => ['authenticated' => 'Authenticated']]);
      $result = $this->service->displayUsers([$user]);
      $this->assertSame('', $result['contacts'][1]['roles']['#markup']);
    }

    // -------------------------------------------------------------------------
    // displayUsers — login reminder mode
    // -------------------------------------------------------------------------

    public function testDisplayUsersLoginReminderModeHasPhoneNotCreated(): void {
      $result = $this->service->displayUsers([$this->baseUser()], TRUE);
      $this->assertArrayHasKey('phone', $result['contacts'][1]);
      $this->assertArrayNotHasKey('created', $result['contacts'][1]);
    }

    public function testDisplayUsersLoginReminderModeHasEmail(): void {
      $result = $this->service->displayUsers([$this->baseUser()], TRUE);
      $this->assertSame('jane@example.com', $result['contacts'][1]['email']['#markup']);
    }

    public function testDisplayUsersLoginReminderModeHasPrecinctAfterHd(): void {
      $user = $this->baseUser();
      $user['precinct'] = '1234';
      $result = $this->service->displayUsers([$user], TRUE);
      $this->assertSame('1234', $result['contacts'][1]['precinct']['#markup']);

      $keys = array_values(array_filter(array_keys($result['contacts'][1]), 'is_string'));
      $this->assertSame(['cb', 'hd', 'precinct', 'mcid'], array_slice($keys, 0, 4));
      $labels = array_map(fn($cell) => (string) $cell['data'], $result['contacts']['#header']);
      $this->assertSame('Precinct', $labels[2]);
    }

    public function testDisplayUsersLoginReminderModeMissingPrecinctIsBlank(): void {
      $result = $this->service->displayUsers([$this->baseUser()], TRUE);
      $this->assertSame('', $result['contacts'][1]['precinct']['#markup']);
    }

    public function testDisplayUsersStandardModeOmitsPrecinct(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertArrayNotHasKey('precinct', $result['contacts'][1]);
      $labels = array_map(fn($cell) => (string) $cell['data'], $result['contacts']['#header']);
      $this->assertNotContains('Precinct', $labels);
    }

    public function testGetSortFieldLoginReminderPrecinct(): void {
      $this->assertSame('precinct', $this->service->getSortField('Precinct', TRUE));
      $this->assertSame('', $this->service->getSortField('Precinct'));
    }

    public function testDisplayUsersLoginReminderModeOmitsUidAndCounty(): void {
      $result = $this->service->displayUsers([$this->baseUser()], TRUE);
      $this->assertArrayNotHasKey('uid', $result['contacts'][1]);
      $this->assertArrayNotHasKey('county', $result['contacts'][1]);

      $labels = array_map(fn($cell) => (string) $cell['data'], $result['contacts']['#header']);
      $this->assertSame('', $labels[0]);
      $this->assertNotContains('UID', $labels);
      $this->assertNotContains('County', $labels);
      // One header cell per row cell, so the columns still line up.
      $this->assertCount(count(array_filter(array_keys($result['contacts'][1]), 'is_string')), $labels);
    }

    public function testDisplayUsersStandardModeKeepsUidAndCounty(): void {
      $result = $this->service->displayUsers([$this->baseUser()]);
      $this->assertArrayHasKey('uid', $result['contacts'][1]);
      $this->assertArrayHasKey('county', $result['contacts'][1]);

      $labels = array_map(fn($cell) => (string) $cell['data'], $result['contacts']['#header']);
      $this->assertSame(['cb', 'UID'], array_slice($labels, 0, 2));
      $this->assertContains('County', $labels);
    }

    // -------------------------------------------------------------------------
    // validateUserSelection
    // -------------------------------------------------------------------------

    public function testValidateUserSelectionEmptyContactsReturnsEmptyArray(): void {
      $this->assertSame([], $this->service->validateUserSelection([]));
    }

    public function testValidateUserSelectionCheckedContactReturnsSelection(): void {
      $contacts = [['cb' => 'CB_1_7']];
      $result = $this->service->validateUserSelection($contacts);
      $this->assertArrayHasKey('1', $result);
      $this->assertSame('1', $result['1']['rowCount']);
      $this->assertSame('7', $result['1']['uid']);
    }

    public function testValidateUserSelectionUncheckedContactSkipped(): void {
      $contacts = [['cb' => ''], ['cb' => 'CB_1_7']];
      $result = $this->service->validateUserSelection($contacts);
      $this->assertCount(1, $result);
    }

    public function testValidateUserSelectionMultipleCheckedReturnsAll(): void {
      $contacts = [['cb' => 'CB_1_7'], ['cb' => 'CB_2_8']];
      $result = $this->service->validateUserSelection($contacts);
      $this->assertArrayHasKey('1', $result);
      $this->assertArrayHasKey('2', $result);
    }

    // -------------------------------------------------------------------------
    // getSortField
    // -------------------------------------------------------------------------

    public function testGetSortFieldReturnsUidForUID(): void {
      $this->assertSame('uid', $this->service->getSortField('UID'));
    }

    public function testGetSortFieldReturnsHdForHD(): void {
      $this->assertSame('hd', $this->service->getSortField('HD'));
    }

    public function testGetSortFieldReturnsMcidForMCID(): void {
      $this->assertSame('mcid', $this->service->getSortField('MCID'));
    }

    public function testGetSortFieldReturnsLastNameForLastName(): void {
      $this->assertSame('lastName', $this->service->getSortField('Last&nbsp;Name'));
    }

    public function testGetSortFieldReturnsFirstNameForFirstName(): void {
      $this->assertSame('firstName', $this->service->getSortField('First&nbsp;Name'));
    }

    public function testGetSortFieldReturnsUserNameForUsername(): void {
      $this->assertSame('userName', $this->service->getSortField('Username'));
    }

    public function testGetSortFieldReturnsCreatedForCreate(): void {
      $this->assertSame('created', $this->service->getSortField('Create'));
    }

    public function testGetSortFieldReturnsAccessForAccess(): void {
      $this->assertSame('access', $this->service->getSortField('Access'));
    }

    public function testGetSortFieldReturnsPhoneForPhoneInLoginReminderMode(): void {
      $this->assertSame('phone', $this->service->getSortField('Phone', TRUE));
    }

    public function testGetSortFieldReturnsEmailForEmailInLoginReminderMode(): void {
      $this->assertSame('email', $this->service->getSortField('Email', TRUE));
    }

    public function testGetSortFieldReturnsEmptyStringForUnknownLabel(): void {
      $this->assertSame('', $this->service->getSortField('Unknown'));
    }

    public function testGetSortFieldReturnsEmptyStringForNonSortableColumn(): void {
      $this->assertSame('', $this->service->getSortField('County'));
    }

    public function testGetSortFieldCreateNotAvailableInLoginReminderMode(): void {
      $this->assertSame('', $this->service->getSortField('Create', TRUE));
    }

    public function testGetSortFieldPhoneNotAvailableInStandardMode(): void {
      $this->assertSame('', $this->service->getSortField('Phone'));
    }

    // -------------------------------------------------------------------------
    // sortUsers
    // -------------------------------------------------------------------------

    private function buildUsers(): array {
      return [
        ['uid' => 3, 'hd' => 8, 'mcid' => 300, 'lastName' => 'Zara',  'firstName' => 'Amy', 'userName' => 'zara'],
        ['uid' => 1, 'hd' => 6, 'mcid' => 100, 'lastName' => 'Alice', 'firstName' => 'Bob', 'userName' => 'alice'],
        ['uid' => 2, 'hd' => 7, 'mcid' => 200, 'lastName' => 'Mike',  'firstName' => 'Cal', 'userName' => 'mike'],
      ];
    }

    public function testSortUsersAscendingByLastName(): void {
      $users = $this->buildUsers();
      $this->service->sortUsers($users, 'asc', 'Last&nbsp;Name');
      $values = array_column($users, 'lastName');
      $this->assertSame('Alice', $values[0]);
      $this->assertSame('Zara', $values[2]);
    }

    public function testSortUsersDescendingByLastName(): void {
      $users = $this->buildUsers();
      $this->service->sortUsers($users, 'desc', 'Last&nbsp;Name');
      $values = array_column($users, 'lastName');
      $this->assertSame('Zara', $values[0]);
      $this->assertSame('Alice', $values[2]);
    }

    public function testSortUsersAscendingByUid(): void {
      $users = $this->buildUsers();
      $this->service->sortUsers($users, 'asc', 'UID');
      $values = array_column($users, 'uid');
      $this->assertSame(1, $values[0]);
      $this->assertSame(3, $values[2]);
    }

    public function testSortUsersUnknownOrderDoesNotSort(): void {
      $users = $this->buildUsers();
      $original = $users;
      $this->service->sortUsers($users, 'asc', 'Unknown');
      $this->assertSame($original, $users);
    }

    public function testSortUsersEmptyArrayDoesNotError(): void {
      $users = [];
      $this->service->sortUsers($users, 'asc', 'Last&nbsp;Name');
      $this->assertSame([], $users);
    }

    public function testSortUsersPreservesKeysAfterSort(): void {
      $users = $this->buildUsers();
      $this->service->sortUsers($users, 'asc', 'Last&nbsp;Name');
      $keys = array_keys($users);
      $this->assertContains(0, $keys);
      $this->assertContains(1, $keys);
      $this->assertContains(2, $keys);
    }

  }

}
