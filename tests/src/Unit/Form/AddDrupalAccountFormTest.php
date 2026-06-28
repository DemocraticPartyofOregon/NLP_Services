<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\AddDrupalAccountForm;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class AddDrupalAccountFormTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private NlpNls $nlsMock;
    private MagicWord $magicWordMock;
    private MessengerInterface $messengerMock;
    private NlpSessionData $sessionDataMock;

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock  = $this->createMock(DrupalUser::class);
      $this->nlsMock         = $this->createMock(NlpNls::class);
      $this->magicWordMock   = $this->createMock(MagicWord::class);
      $this->messengerMock   = $this->createMock(MessengerInterface::class);
      $this->sessionDataMock = $this->createMock(NlpSessionData::class);

      $container = new ContainerBuilder();
      $container->set('messenger', $this->messengerMock);
      $container->set('nlpservices.session_data', $this->sessionDataMock);
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

    private function buildForm(): AddDrupalAccountForm {
      return new AddDrupalAccountForm(
        $this->drupalUserMock,
        $this->nlsMock,
        $this->magicWordMock,
      );
    }

    private function baseNl(): array {
      return [
        'nickname'  => 'Alice',
        'lastName'  => 'Smith',
        'firstName' => 'Alice',
        'email'     => 'alice@example.com',
        'phone'     => '555-1234',
        'mcid'      => 42,
        'county'    => 'Denver',
        'hd'        => '06',
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_add_drupal_account_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm — addUser page, no county → warning, needle absent
    // -------------------------------------------------------------------------

    public function testBuildFormAddUserNoCountyAddsWarningAndOmitsNeedle(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('');
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', NULL],
        ['county',  ''],
        ['page',    'addUser'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayNotHasKey('needle', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — addUser page, county set → needle and submit present
    // -------------------------------------------------------------------------

    public function testBuildFormAddUserWithCountyIncludesNeedleAndSubmit(): void {
      $this->sessionDataMock->method('getCounty')->willReturn('Denver');
      $this->drupalUserMock->method('isNlpAdminUser')->willReturn(FALSE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', NULL],
        ['county',  'Denver'],
        ['page',    'addUser'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('needle', $result);
      $this->assertArrayHasKey('add_submit', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — reenter=TRUE skips sessionData county lookup
    // -------------------------------------------------------------------------

    public function testBuildFormReenterSkipsSessionDataCountyCall(): void {
      $this->sessionDataMock->expects($this->never())->method('getCounty');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter', TRUE],
        ['county',  'Denver'],
        ['page',    'addUser'],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('needle', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — userSelect page, no existing login → no *** in option label
    // -------------------------------------------------------------------------

    public function testBuildFormUserSelectNoExistingLoginOmitsMarker(): void {
      $nlList = [42 => ['firstName' => 'Alice', 'lastName' => 'Smith', 'email' => 'alice@example.com', 'address' => '123 Main St']];
      $this->drupalUserMock->method('getUserByMcid')->with(42)->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'userSelect'],
        ['nlList',       $nlList],
        ['selectedMcid', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('user_select', $result);
      $this->assertStringNotContainsString('***', $result['user_select']['#options'][42]);
    }

    // -------------------------------------------------------------------------
    // buildForm — userSelect page, user has login → *** appended to option label
    // -------------------------------------------------------------------------

    public function testBuildFormUserSelectExistingLoginAppendsMarker(): void {
      $nlList = [42 => ['firstName' => 'Alice', 'lastName' => 'Smith', 'email' => 'alice@example.com', 'address' => '123 Main St']];
      $this->drupalUserMock->method('getUserByMcid')->with(42)->willReturn(['uid' => 10]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'userSelect'],
        ['nlList',       $nlList],
        ['selectedMcid', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('***', $result['user_select']['#options'][42]);
    }

    // -------------------------------------------------------------------------
    // buildForm — userSelect page, nl has no email → placeholder shown
    // -------------------------------------------------------------------------

    public function testBuildFormUserSelectNlWithNoEmailShowsPlaceholder(): void {
      $nlList = [42 => ['firstName' => 'Alice', 'lastName' => 'Smith', 'email' => '', 'address' => '123 Main St']];
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'userSelect'],
        ['nlList',       $nlList],
        ['selectedMcid', NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertStringContainsString('No email', $result['user_select']['#options'][42]);
    }

    // -------------------------------------------------------------------------
    // buildForm — userSelect page, selectedMcid set → credentials markup shown
    // -------------------------------------------------------------------------

    public function testBuildFormUserSelectWithSelectedMcidShowsCredentials(): void {
      $nlList = [42 => ['firstName' => 'Alice', 'lastName' => 'Smith', 'email' => 'alice@example.com', 'address' => '123 Main St']];
      $this->drupalUserMock->method('getUserByMcid')->willReturn([]);
      $this->drupalUserMock->method('displayCredentials')->with(42)->willReturn('<p>Login: alice.smith</p>');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'userSelect'],
        ['nlList',       $nlList],
        ['selectedMcid', 42],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('credentials', $result);
      $this->assertStringContainsString('Login: alice.smith', $result['credentials']['#markup']);
    }

    // -------------------------------------------------------------------------
    // buildForm — displayUserAdd page, nl has email → adduser element, no email_note
    // -------------------------------------------------------------------------

    public function testBuildFormDisplayUserAddWithEmailOmitsEmailNote(): void {
      $this->nlsMock->method('getNlById')->with(42)->willReturn($this->baseNl());
      $this->magicWordMock->method('createMagicWord')->willReturn('magic5');
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader', 'nlp_admin' => 'NLP Admin']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'displayUserAdd'],
        ['selectedMcid', 42],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('adduser', $result);
      $this->assertArrayNotHasKey('email_note', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — displayUserAdd page, nl has no email → email_note present
    // -------------------------------------------------------------------------

    public function testBuildFormDisplayUserAddNoEmailIncludesEmailNote(): void {
      $nl = array_merge($this->baseNl(), ['email' => '']);
      $this->nlsMock->method('getNlById')->with(42)->willReturn($nl);
      $this->magicWordMock->method('createMagicWord')->willReturn('magic5');
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader']);

      $requestStackMock = $this->createMock(RequestStack::class);
      $requestStackMock->method('getCurrentRequest')
        ->willReturn(Request::create('https://nlp.example.org/'));
      Drupal::getContainer()->set('request_stack', $requestStackMock);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'displayUserAdd'],
        ['selectedMcid', 42],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertArrayHasKey('email_note', $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — displayUserAdd page, nlp_admin removed from roles checkboxes
    // -------------------------------------------------------------------------

    public function testBuildFormDisplayUserAddExcludesNlpAdminFromRoles(): void {
      $this->nlsMock->method('getNlById')->willReturn($this->baseNl());
      $this->magicWordMock->method('createMagicWord')->willReturn('magic5');
      $this->drupalUserMock->method('getNlpRoles')
        ->willReturn(['nlp_leader' => 'NLP Leader', 'nlp_admin' => 'NLP Admin']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',      TRUE],
        ['county',       'Denver'],
        ['page',         'displayUserAdd'],
        ['selectedMcid', 42],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);
      $rolesOptions = $result['adduser']['add']['roles']['#options'];
      $this->assertArrayNotHasKey('nlp_admin', $rolesOptions);
      $this->assertArrayHasKey('nlp_leader', $rolesOptions);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-submit': no NLs found → addStatus, no page change
    // -------------------------------------------------------------------------

    public function testSubmitFormAddSubmitNoResultsAddsStatusMessage(): void {
      $this->nlsMock->method('searchNls')->with('Smith', 'Denver')->willReturn([]);
      $this->messengerMock->expects($this->once())->method('addStatus');

      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-submit']);
      $formState->method('getValue')->with('needle')->willReturn('Smith');
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $pageChanges = array_filter($captured, fn($item) => $item[0] === 'page');
      $this->assertEmpty($pageChanges);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-submit': NLs found → stores nlList, advances to userSelect
    // -------------------------------------------------------------------------

    public function testSubmitFormAddSubmitWithResultsSetsNlListAndPage(): void {
      $nlList = [42 => ['firstName' => 'Alice', 'lastName' => 'Smith']];
      $this->nlsMock->method('searchNls')->willReturn($nlList);

      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-submit']);
      $formState->method('getValue')->with('needle')->willReturn('Smith');
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['nlList', $nlList], $captured);
      $this->assertContains(['page', 'userSelect'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'go-back' → sets page to addUser
    // -------------------------------------------------------------------------

    public function testSubmitFormGoBackSetsPageToAddUser(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'go-back']);
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'addUser'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'go-back2' → sets page to addUser
    // -------------------------------------------------------------------------

    public function testSubmitFormGoBack2SetsPageToAddUser(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'go-back2']);
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'addUser'], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-selected': user already has login → warning, no page advance
    // -------------------------------------------------------------------------

    public function testSubmitFormAddSelectedExistingLoginAddsWarningAndStaysOnPage(): void {
      $this->messengerMock->expects($this->once())->method('addWarning');

      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['county',      'Denver'],
        ['loginExists', [42 => TRUE]],
      ]);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-selected']);
      $formState->method('getValue')->with('user_select')->willReturn(42);
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $pageChanges = array_filter($captured, fn($item) => $item[0] === 'page');
      $this->assertEmpty($pageChanges);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-selected': no existing login → advances to displayUserAdd, stores mcid
    // -------------------------------------------------------------------------

    public function testSubmitFormAddSelectedNoLoginAdvancesToDisplayUserAdd(): void {
      $captured = [];
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['county',      'Denver'],
        ['loginExists', [42 => FALSE]],
      ]);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-selected']);
      $formState->method('getValue')->with('user_select')->willReturn(42);
      $formState->method('set')->willReturnCallback(
        function ($key, $value) use (&$captured): void { $captured[] = [$key, $value]; }
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertContains(['page', 'displayUserAdd'], $captured);
      $this->assertContains(['selectedMcid', 42], $captured);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-user-submit': status=complete → setMagicWord called, addStatus
    // -------------------------------------------------------------------------

    public function testSubmitFormAddUserSubmitCompleteCallsSetMagicWordAndAddsStatus(): void {
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader']);
      $this->drupalUserMock->method('addUser')->willReturn(['status' => 'complete', 'userName' => 'alice.smith']);
      $this->magicWordMock->expects($this->once())->method('setMagicWord')->with(42, 'magic5');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-user-submit']);
      $formState->method('getValue')->willReturnCallback(
        fn($key) => ['firstName' => 'Alice', 'lastName' => 'Smith', 'password' => 'magic5',
                     'mcid' => 42, 'roles' => ['nlp_leader' => 'nlp_leader'],
                     'username' => 'alice.smith', 'email' => 'alice@example.com',
                     'phone' => '555-1234', 'hd' => '06'][$key] ?? NULL
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-user-submit': status=error → addError, setMagicWord not called
    // -------------------------------------------------------------------------

    public function testSubmitFormAddUserSubmitErrorAddsErrorAndSkipsMagicWord(): void {
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader']);
      $this->drupalUserMock->method('addUser')->willReturn(['status' => 'error']);
      $this->magicWordMock->expects($this->never())->method('setMagicWord');
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-user-submit']);
      $formState->method('getValue')->willReturnCallback(
        fn($key) => ['firstName' => 'Alice', 'lastName' => 'Smith', 'password' => 'magic5',
                     'mcid' => 42, 'roles' => [],
                     'username' => 'alice.smith', 'email' => 'alice@example.com',
                     'phone' => '555-1234', 'hd' => '06'][$key] ?? NULL
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-user-submit': status=exists → addError
    // -------------------------------------------------------------------------

    public function testSubmitFormAddUserSubmitExistsAddsError(): void {
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader']);
      $this->drupalUserMock->method('addUser')->willReturn(['status' => 'exists']);
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-user-submit']);
      $formState->method('getValue')->willReturnCallback(
        fn($key) => ['firstName' => 'Alice', 'lastName' => 'Smith', 'password' => 'magic5',
                     'mcid' => 42, 'roles' => [],
                     'username' => 'alice.smith', 'email' => 'alice@example.com',
                     'phone' => '555-1234', 'hd' => '06'][$key] ?? NULL
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-user-submit': status=no email → addError
    // -------------------------------------------------------------------------

    public function testSubmitFormAddUserSubmitNoEmailAddsError(): void {
      $this->drupalUserMock->method('getNlpRoles')->willReturn(['nlp_leader' => 'NLP Leader']);
      $this->drupalUserMock->method('addUser')->willReturn(['status' => 'no email']);
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-user-submit']);
      $formState->method('getValue')->willReturnCallback(
        fn($key) => ['firstName' => 'Alice', 'lastName' => 'Smith', 'password' => 'magic5',
                     'mcid' => 42, 'roles' => [],
                     'username' => 'alice.smith', 'email' => '',
                     'phone' => '555-1234', 'hd' => '06'][$key] ?? NULL
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — 'add-user-submit': empty role values are filtered out
    // -------------------------------------------------------------------------

    public function testSubmitFormAddUserSubmitFiltersEmptyRoleSelections(): void {
      $allowedRoles = ['nlp_leader' => 'NLP Leader', 'nlp_coordinator' => 'NLP Coordinator'];
      $this->drupalUserMock->method('getNlpRoles')->willReturn($allowedRoles);

      $capturedAccount = NULL;
      $this->drupalUserMock->method('addUser')
        ->willReturnCallback(function ($account) use (&$capturedAccount) {
          $capturedAccount = $account;
          return ['status' => 'complete', 'userName' => 'alice.smith'];
        });
      $this->magicWordMock->method('setMagicWord')->willReturn(TRUE);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->with('county')->willReturn('Denver');
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'add-user-submit']);
      $formState->method('getValue')->willReturnCallback(
        fn($key) => ['firstName' => 'Alice', 'lastName' => 'Smith', 'password' => 'magic5',
                     'mcid' => 42, 'roles' => ['nlp_leader' => 'nlp_leader', 'nlp_coordinator' => 0],
                     'username' => 'alice.smith', 'email' => 'alice@example.com',
                     'phone' => '555-1234', 'hd' => '06'][$key] ?? NULL
      );

      $form = [];
      $this->buildForm()->submitForm($form, $formState);

      $this->assertArrayHasKey('nlp_leader', $capturedAccount['roles']);
      $this->assertArrayNotHasKey('nlp_coordinator', $capturedAccount['roles']);
    }

  }

}
