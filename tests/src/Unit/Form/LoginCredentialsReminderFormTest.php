<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\Form\LoginCredentialsReminderForm;
  use Drupal\nlpservices\MagicWord;
  use Drupal\nlpservices\NlpConfiguration;
  use Drupal\nlpservices\NlpNls;
  use Drupal\nlpservices\NlpSearchBar;
  use Drupal\nlpservices\NlpSendEmail;
  use Drupal\nlpservices\NlpSessionData;
  use Drupal\nlpservices\NlpUserDisplay;
  use Drupal\Tests\UnitTestCase;

  class LoginCredentialsReminderFormTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private NlpNls $nlsObjMock;
    private MagicWord $magicWordMock;
    private NlpSearchBar $searchBarMock;
    private NlpUserDisplay $userDisplayMock;
    private NlpSendEmail $sendEmailMock;
    private NlpConfiguration $nlpConfigurationMock;
    private NlpSessionData $nlpSessionDataMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock      = $this->createMock(DrupalUser::class);
      $this->nlsObjMock          = $this->createMock(NlpNls::class);
      $this->magicWordMock       = $this->createMock(MagicWord::class);
      $this->searchBarMock       = $this->createMock(NlpSearchBar::class);
      $this->userDisplayMock     = $this->createMock(NlpUserDisplay::class);
      $this->sendEmailMock       = $this->createMock(NlpSendEmail::class);
      $this->nlpConfigurationMock = $this->createMock(NlpConfiguration::class);
      $this->nlpSessionDataMock  = $this->createMock(NlpSessionData::class);
      $this->messengerMock       = $this->createMock(MessengerInterface::class);

      $container = new ContainerBuilder();
      $container->set('string_translation', $this->getStringTranslationStub());
      $container->set('messenger', $this->messengerMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildForm(): LoginCredentialsReminderForm {
      $form = new LoginCredentialsReminderForm(
        $this->drupalUserMock,
        $this->nlsObjMock,
        $this->magicWordMock,
        $this->searchBarMock,
        $this->userDisplayMock,
        $this->sendEmailMock,
        $this->nlpConfigurationMock,
        $this->nlpSessionDataMock,
      );
      $form->setStringTranslation($this->getStringTranslationStub());
      return $form;
    }

    private function sampleUser(int $uid = 1): array {
      return [
        'uid'       => $uid,
        'firstName' => 'Jane',
        'lastName'  => 'Doe',
        'email'     => 'jane@example.com',
        'mcid'      => 10001,
        'hd'        => '32',
        'county'    => 'Denver',
        'userName'  => 'jane.doe',
        'phone'     => '555-1234',
      ];
    }

    private function currentUserFixture(): array {
      return [
        'firstName' => 'Admin',
        'lastName'  => 'User',
        'email'     => 'admin@example.com',
        'phone'     => '555-0000',
      ];
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame(
        'nlpservices_login_credentials_reminder_form',
        $this->buildForm()->getFormId()
      );
    }

    // -------------------------------------------------------------------------
    // buildForm — first entry, no county → warning and empty array
    // -------------------------------------------------------------------------

    public function testBuildFormFirstEntryNoCountyAddsWarningAndReturnsEmptyArray(): void {
      $this->nlpSessionDataMock->method('getCounty')->willReturn('');
      $this->messengerMock->expects($this->once())->method('addWarning');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([['reenter', NULL]]);

      $result = $this->buildForm()->buildForm([], $formState);
      $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // buildForm — first entry, county set → initialises hdOptions with 'All'
    //             when user count is below USER_ALL_MAX
    // -------------------------------------------------------------------------

    public function testBuildFormFirstEntryPrependsAllWhenUserCountBelowMax(): void {
      $users = [1 => $this->sampleUser(1)];

      $this->nlpSessionDataMock->method('getCounty')->willReturn('Denver');
      $this->nlpSessionDataMock->method('getSavedHdIndex')->willReturn(0);
      $this->drupalUserMock->method('getUsers')->willReturn($users);
      $this->searchBarMock->method('searchBar')->willReturn([]);
      $this->userDisplayMock->method('displayUsers')->willReturn([]);

      $capturedHdOptions = NULL;
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',        NULL],
        ['currentHdIndex', 0],
        ['hdOptions',      ['All', '32']],
        ['county',         'Denver'],
        ['page',           'displayUsers'],
        ['search-needle',  NULL],
      ]);
      $formState->method('set')
        ->willReturnCallback(function ($key, $value) use (&$capturedHdOptions) {
          if ($key === 'hdOptions') {
            $capturedHdOptions = $value;
          }
        });

      $this->buildForm()->buildForm([], $formState);
      $this->assertSame('All', reset($capturedHdOptions));
    }

    // -------------------------------------------------------------------------
    // buildForm — displayUsers page, no users → addError
    // -------------------------------------------------------------------------

    public function testBuildFormDisplayUsersPageNoUsersAddsError(): void {
      $this->drupalUserMock->method('getUsers')->willReturn([]);
      $this->searchBarMock->method('searchBar')->willReturn([]);
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',        TRUE],
        ['currentHdIndex', 0],
        ['hdOptions',      ['All']],
        ['county',         'Denver'],
        ['page',           'displayUsers'],
        ['search-needle',  NULL],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — displayUsers page, users present → renders table and submit
    // -------------------------------------------------------------------------

    public function testBuildFormDisplayUsersPageWithUsersRendersTableAndSubmitButton(): void {
      $users = [1 => $this->sampleUser(1)];

      $this->drupalUserMock->method('getUsers')->willReturn($users);
      $this->searchBarMock->method('searchBar')->willReturn(['#type' => 'select']);
      $this->userDisplayMock->method('displayUsers')->willReturn(['#type' => 'table']);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',        TRUE],
        ['currentHdIndex', 0],
        ['hdOptions',      ['All', '32']],
        ['county',         'Denver'],
        ['page',           'displayUsers'],
        ['search-needle',  NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      $this->assertArrayHasKey('user-form', $result);
      $this->assertArrayHasKey('reminder_select', $result);
      $this->assertSame('submit', $result['reminder_select']['#type']);
      $this->assertSame('reminder_select', $result['reminder_select']['#name']);
    }

    // -------------------------------------------------------------------------
    // buildForm — needle set → calls searchInfoArray and clears needle
    // -------------------------------------------------------------------------

    public function testBuildFormWithNeedleCallsSearchInfoArray(): void {
      $users = [1 => $this->sampleUser(1)];

      $this->drupalUserMock->method('getUsers')->willReturn($users);
      $this->searchBarMock->expects($this->once())
        ->method('searchInfoArray')
        ->with('Doe', $this->isType('array'))
        ->willReturn($users);
      $this->searchBarMock->method('searchBar')->willReturn([]);
      $this->userDisplayMock->method('displayUsers')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',        TRUE],
        ['currentHdIndex', 0],
        ['hdOptions',      ['All', '32']],
        ['county',         'Denver'],
        ['page',           'displayUsers'],
        ['search-needle',  'Doe'],
      ]);

      $this->buildForm()->buildForm([], $formState);
    }

    // -------------------------------------------------------------------------
    // buildForm — always attaches required libraries
    // -------------------------------------------------------------------------

    public function testBuildFormAttachesRequiredLibraries(): void {
      $this->drupalUserMock->method('getUsers')->willReturn([]);
      $this->searchBarMock->method('searchBar')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('get')->willReturnMap([
        ['reenter',        TRUE],
        ['currentHdIndex', 0],
        ['hdOptions',      ['All']],
        ['county',         'Denver'],
        ['page',           'displayUsers'],
        ['search-needle',  NULL],
      ]);

      $result = $this->buildForm()->buildForm([], $formState);

      foreach (['nlpservices/nlp-table', 'nlpservices/info-boxes', 'nlpservices/search-boxes', 'nlpservices/hint-note', 'nlpservices/table-helper'] as $lib) {
        $this->assertContains($lib, $result['#attached']['library']);
      }
    }

    // -------------------------------------------------------------------------
    // validateForm — hd-select saves hd index
    // -------------------------------------------------------------------------

    public function testValidateFormHdSelectSavesHdIndex(): void {
      $hdOptions = ['All', '32', '44'];

      $this->nlpSessionDataMock->expects($this->once())->method('saveHdIndex')->with(2);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'hd-select']);
      $formState->method('getValue')->with('hd-select')->willReturn(2);
      $formState->method('get')->willReturnMap([['hdOptions', $hdOptions]]);

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — reminder_select with no selections sets form error
    // -------------------------------------------------------------------------

    public function testValidateFormReminderSelectNoSelectionSetsError(): void {
      $this->userDisplayMock->method('validateUserSelection')->willReturn([]);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('getValue')->with('contacts')->willReturn([]);
      $formState->expects($this->once())->method('setErrorByName')
        ->with('reminder_select', $this->isType('string'));

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // validateForm — reminder_select with valid selections stores them
    // -------------------------------------------------------------------------

    public function testValidateFormReminderSelectWithSelectionsStoresSelections(): void {
      $selections = [['uid' => 1]];
      $this->userDisplayMock->method('validateUserSelection')->willReturn($selections);

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('getValue')->with('contacts')->willReturn([1 => '1']);
      $formState->expects($this->once())->method('set')->with('userSelections', $selections);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm()->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — always sets reenter and calls setRebuild
    // -------------------------------------------------------------------------

    public function testSubmitFormAlwaysSetsReenterAndCallsSetRebuild(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('search-needle')->willReturn('');
      $formState->expects($this->atLeastOnce())->method('set')
        ->with($this->logicalOr($this->equalTo('reenter'), $this->equalTo('search-needle')), $this->anything());
      $formState->expects($this->once())->method('setRebuild');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — search_submit stores needle and returns early
    // -------------------------------------------------------------------------

    public function testSubmitFormSearchSubmitStoresNeedle(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'search_submit']);
      $formState->method('getValue')->with('search-needle')->willReturn('Smith');
      $formState->expects($this->atLeast(1))->method('set')
        ->willReturnCallback(function ($key, $value) {
          if ($key === 'search-needle') {
            $this->assertSame('Smith', $value);
          }
        });

      $this->drupalUserMock->expects($this->never())->method('getUserByUid');

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — reminder_select sends email to each selected user
    // -------------------------------------------------------------------------

    public function testSubmitFormReminderSelectSendsEmailsToSelectedUsers(): void {
      $user = $this->sampleUser(1);
      $this->drupalUserMock->method('getUserByUid')->with(1)->willReturn($user);
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->currentUserFixture());
      $this->magicWordMock->method('getMagicWord')->with(10001)->willReturn('secret123');
      $this->sendEmailMock->expects($this->once())
        ->method('sendEmail')
        ->with('account_reminder', 'jane@example.com', $this->isType('string'), $this->isType('string'), 'admin@example.com')
        ->willReturn(TRUE);
      $this->messengerMock->expects($this->once())->method('addStatus');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('get')->willReturnMap([
        ['userSelections', [['uid' => 1]]],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — reminder_select skips user with no email and adds error
    // -------------------------------------------------------------------------

    public function testSubmitFormReminderSelectSkipsUserWithNoEmail(): void {
      $user = $this->sampleUser(1);
      $user['email'] = '';

      $this->drupalUserMock->method('getUserByUid')->willReturn($user);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('get')->willReturnMap([
        ['userSelections', [['uid' => 1]]],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — reminder_select skips user with no mcid and adds error
    // -------------------------------------------------------------------------

    public function testSubmitFormReminderSelectSkipsUserWithNoMcid(): void {
      $user = $this->sampleUser(1);
      $user['mcid'] = 0;

      $this->drupalUserMock->method('getUserByUid')->willReturn($user);
      $this->sendEmailMock->expects($this->never())->method('sendEmail');
      $this->messengerMock->expects($this->once())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('get')->willReturnMap([
        ['userSelections', [['uid' => 1]]],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — reminder_select reports failure when sendEmail returns false
    // -------------------------------------------------------------------------

    public function testSubmitFormReminderSelectReportsFailureWhenSendEmailFails(): void {
      $user = $this->sampleUser(1);
      $this->drupalUserMock->method('getUserByUid')->willReturn($user);
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->currentUserFixture());
      $this->magicWordMock->method('getMagicWord')->willReturn('');
      $this->sendEmailMock->method('sendEmail')->willReturn(FALSE);
      $this->messengerMock->expects($this->atLeastOnce())->method('addError');

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('get')->willReturnMap([
        ['userSelections', [['uid' => 1]]],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // remindUserCredentials — falls back to 'unknown' password when no magic word
    // -------------------------------------------------------------------------

    public function testSubmitFormReminderSelectUsesUnknownPasswordWhenNoMagicWord(): void {
      $user = $this->sampleUser(1);
      $this->drupalUserMock->method('getUserByUid')->willReturn($user);
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->currentUserFixture());
      $this->magicWordMock->method('getMagicWord')->willReturn('');

      $capturedMessage = '';
      $this->sendEmailMock->method('sendEmail')
        ->willReturnCallback(function ($type, $to, $subject, $message, $from) use (&$capturedMessage) {
          $capturedMessage = $message;
          return TRUE;
        });

      $formState = $this->createMock(FormStateInterface::class);
      $formState->method('getTriggeringElement')->willReturn(['#name' => 'reminder_select']);
      $formState->method('get')->willReturnMap([
        ['userSelections', [['uid' => 1]]],
      ]);

      $form = [];
      $this->buildForm()->submitForm($form, $formState);
      $this->assertStringContainsString('unknown', $capturedMessage);
    }

  }

}
