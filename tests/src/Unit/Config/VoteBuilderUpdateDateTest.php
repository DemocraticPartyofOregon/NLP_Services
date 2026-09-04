<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Config;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\nlpservices\Config\VoteBuilderUpdateDate;
use Drupal\nlpservices\NlpConfiguration;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(VoteBuilderUpdateDate::class)]
#[Group('nlpservices')]
class VoteBuilderUpdateDateTest extends UnitTestCase {

  private NlpConfiguration $nlpConfigurationObjMock;
  private MessengerInterface $messengerMock;

  protected function setUp(): void {
    parent::setUp();
    $this->nlpConfigurationObjMock = $this->createMock(NlpConfiguration::class);
    $this->messengerMock           = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messengerMock);
    Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    parent::tearDown();
    Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private function buildForm(): VoteBuilderUpdateDate {
    $form = new VoteBuilderUpdateDate($this->nlpConfigurationObjMock);
    $form->setStringTranslation($this->getStringTranslationStub());
    $form->setMessenger($this->messengerMock);
    return $form;
  }

  // ---------------------------------------------------------------------------
  // getFormId
  // ---------------------------------------------------------------------------

  public function testGetFormIdReturnsExpectedId(): void {
    $this->assertSame('votebuilder_update_date_form', $this->buildForm()->getFormId());
  }

  // ---------------------------------------------------------------------------
  // buildForm
  // ---------------------------------------------------------------------------

  public function testBuildFormPopulatesDefaultValueFromStoredDate(): void {
    $this->nlpConfigurationObjMock->method('getVoterBuilderUpdateDate')->willReturn('2026-09-02');

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('2026-09-02', $form['votebuilderUpdateDate']['#default_value']);
    $this->assertTrue($form['votebuilderUpdateDate']['#required']);
  }

  public function testBuildFormContainsBackLink(): void {
    $this->nlpConfigurationObjMock->method('getVoterBuilderUpdateDate')->willReturn('');

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertStringContainsString('/admin/config/nlp_menu/nlp_conf', $form['button_link']['#markup']);
  }

  public function testBuildFormContainsSubmitButton(): void {
    $this->nlpConfigurationObjMock->method('getVoterBuilderUpdateDate')->willReturn('');

    $form = $this->buildForm()->buildForm([], new FormState());

    $this->assertSame('submit', $form['actions']['submit']['#type']);
  }

  // ---------------------------------------------------------------------------
  // validateForm
  // ---------------------------------------------------------------------------

  public function testValidateFormPassesWithValidDate(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-09-02']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testValidateFormPassesWithValidLeapDayDate(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2024-02-29']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenDateHasWrongNumberOfFields(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-09']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('votebuilderUpdateDate', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenYearIsNotFourDigits(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '26-09-02']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('votebuilderUpdateDate', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenMonthIsNotTwoDigits(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-9-02']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('votebuilderUpdateDate', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenDayIsNotTwoDigits(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-09-2']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('votebuilderUpdateDate', $formState->getErrors());
  }

  public function testValidateFormAddsErrorWhenDateDoesNotExist(): void {
    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-02-30']);

    $form = [];
    $this->buildForm()->validateForm($form, $formState);

    $this->assertArrayHasKey('votebuilderUpdateDate', $formState->getErrors());
  }

  // ---------------------------------------------------------------------------
  // submitForm
  // ---------------------------------------------------------------------------

  public function testSubmitFormSavesDateAndReportsSuccess(): void {
    $this->nlpConfigurationObjMock->expects($this->once())
      ->method('setVotebuilderUpdateDate')
      ->with('2026-09-02');

    $this->messengerMock->expects($this->once())->method('addStatus');

    $formState = new FormState();
    $formState->setValues(['votebuilderUpdateDate' => '2026-09-02']);

    $form = [];
    $this->buildForm()->submitForm($form, $formState);
  }

}
