<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Form;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\Form\TurfSelectForm;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\NlpTurfs;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\Drupal\nlpservices\Form\TurfSelectForm::class)]
#[CoversMethod(\Drupal\nlpservices\Form\TurfSelectForm::class, 'getFormId')]
#[CoversMethod(\Drupal\nlpservices\Form\TurfSelectForm::class, 'buildForm')]
#[CoversMethod(\Drupal\nlpservices\Form\TurfSelectForm::class, 'submitForm')]
#[Group('nlpservices')]
class TurfSelectFormTest extends UnitTestCase {

  private PrivateTempStoreFactory $tempStoreFactoryMock;
  private NlpTurfs $turfObjMock;
  private NlpSessionData $sessionDataMock;
  private DrupalUser $drupalUserMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();

    $this->tempStoreFactoryMock = $this->createMock(PrivateTempStoreFactory::class);
    $this->turfObjMock          = $this->createMock(NlpTurfs::class);
    $this->sessionDataMock      = $this->createMock(NlpSessionData::class);
    $this->drupalUserMock       = $this->createMock(DrupalUser::class);
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

  private function buildFormInstance(): TurfSelectForm {
    $form = new TurfSelectForm(
      $this->tempStoreFactoryMock,
      $this->turfObjMock,
      $this->sessionDataMock,
      $this->drupalUserMock,
    );
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  // -------------------------------------------------------------------------
  // getFormId
  // -------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedString(): void {
    $this->assertSame(
      'nlpservices_turf_select_form',
      $this->buildFormInstance()->getFormId()
    );
  }

  // -------------------------------------------------------------------------
  // buildForm — no MCID
  // -------------------------------------------------------------------------

  public function testBuildFormNoMcidAddsError(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 0]);
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormNoMcidReturnsEmptyForm(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 0]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertEmpty($form);
  }

  // -------------------------------------------------------------------------
  // buildForm — no turf
  // -------------------------------------------------------------------------

  public function testBuildFormNoTurfAddsError(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn([]);
    $this->messengerMock->expects($this->once())->method('addError');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormNoTurfReturnsEmptyForm(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn([]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertEmpty($form);
  }

  public function testBuildFormNoTurfCallsTurfExistsWithCorrectArgs(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->expects($this->once())
      ->method('turfExists')
      ->with(42, 'TestCounty')
      ->willReturn([]);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  // -------------------------------------------------------------------------
  // buildForm — exactly one turf
  // -------------------------------------------------------------------------

  public function testBuildFormOneTurfAddsStatusMessage(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn(['turfCnt' => 1]);
    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);
  }

  public function testBuildFormOneTurfReturnsFormWithNoSubmit(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn(['turfCnt' => 1]);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayNotHasKey('submit', $form);
  }

  // -------------------------------------------------------------------------
  // buildForm — multiple turfs
  // -------------------------------------------------------------------------

  public function testBuildFormMultipleTurfsContainsTurfSelectRadios(): void {
    $turfArray = ['turfCnt' => 2];
    $turfNames = [10 => 'Turf Alpha', 20 => 'Turf Beta'];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->with('turfIndex')->willReturn(10);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn($turfArray);
    $this->turfObjMock->method('createTurfNames')->willReturn($turfNames);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('turf-select', $form);
    $this->assertSame('radios', $form['turf-select']['#type']);
    $this->assertSame($turfNames, $form['turf-select']['#options']);
  }

  public function testBuildFormMultipleTurfsContainsSubmitButton(): void {
    $turfArray = ['turfCnt' => 2];
    $turfNames = [10 => 'Turf Alpha', 20 => 'Turf Beta'];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->with('turfIndex')->willReturn(10);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn($turfArray);
    $this->turfObjMock->method('createTurfNames')->willReturn($turfNames);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertArrayHasKey('submit', $form);
    $this->assertSame('submit', $form['submit']['#type']);
  }

  public function testBuildFormMultipleTurfsDefaultValueUsesStoredTurfIndex(): void {
    $turfArray = ['turfCnt' => 2];
    $turfNames = [10 => 'Turf Alpha', 20 => 'Turf Beta'];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->with('turfIndex')->willReturn(20);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn($turfArray);
    $this->turfObjMock->method('createTurfNames')->willReturn($turfNames);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame(20, $form['turf-select']['#default_value']);
  }

  public function testBuildFormMultipleTurfsDefaultsToFirstWhenStoredIndexInvalid(): void {
    $turfArray = ['turfCnt' => 2];
    $turfNames = [10 => 'Turf Alpha', 20 => 'Turf Beta'];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->with('turfIndex')->willReturn(NULL);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn($turfArray);
    $this->turfObjMock->method('createTurfNames')->willReturn($turfNames);

    $formState = new FormState();
    $form = $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame(10, $form['turf-select']['#default_value']);
  }

  public function testBuildFormMultipleTurfsStoresTurfArrayInFormState(): void {
    $turfArray = ['turfCnt' => 2];
    $turfNames = [10 => 'Turf Alpha', 20 => 'Turf Beta'];

    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->sessionDataMock->method('get')->with('turfIndex')->willReturn(10);
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 42]);
    $this->turfObjMock->method('turfExists')->willReturn($turfArray);
    $this->turfObjMock->method('createTurfNames')->willReturn($turfNames);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame($turfArray, $formState->get('turfArray'));
  }

  public function testBuildFormStoresCountyInFormState(): void {
    $this->sessionDataMock->method('getCounty')->willReturn('TestCounty');
    $this->drupalUserMock->method('getCurrentUser')->willReturn(['mcid' => 0]);

    $formState = new FormState();
    $this->buildFormInstance()->buildForm([], $formState);

    $this->assertSame('TestCounty', $formState->get('county'));
  }

  // -------------------------------------------------------------------------
  // submitForm
  // -------------------------------------------------------------------------

  public function testSubmitFormStoresTurfIndexInSession(): void {
    $this->sessionDataMock->expects($this->once())
      ->method('set')
      ->with('turfIndex', 20);

    $formState = new FormState();
    $formState->setValue('turf-select', 20);
    $formState->set('$turfNames', [10 => 'Turf Alpha', 20 => 'Turf Beta']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

  public function testSubmitFormAddsStatusWithSelectedTurfName(): void {
    $this->sessionDataMock->method('set');
    $this->messengerMock->expects($this->atLeastOnce())->method('addStatus');

    $formState = new FormState();
    $formState->setValue('turf-select', 10);
    $formState->set('$turfNames', [10 => 'Turf Alpha', 20 => 'Turf Beta']);

    $form = [];
    $this->buildFormInstance()->submitForm($form, $formState);
  }

}
