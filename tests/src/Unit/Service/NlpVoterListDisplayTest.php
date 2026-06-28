<?php

declare(strict_types=1);

namespace Drupal\Tests\nlpservices\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\nlpservices\NlpVoterListDisplay;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\nlpservices\NlpVoterListDisplay
 * @group nlpservices
 */
class NlpVoterListDisplayTest extends UnitTestCase {

  protected NlpVoterListDisplay $service;

  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->service = new NlpVoterListDisplay();
  }

  protected function tearDown(): void {
    parent::tearDown();
    \Drupal::setContainer(new ContainerBuilder());
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  protected function baseVoter(array $overrides = []): array {
    return array_merge([
      'vanid'     => '99999',
      'firstName' => 'Bob',
      'lastName'  => 'Smith',
      'age'       => '55',
      'sex'       => 'M',
      'party'     => 'D',
      'address'   => '123 Main St',
      'city'      => 'Portland',
      'moved'     => FALSE,
    ], $overrides);
  }

  // ---------------------------------------------------------------------------
  // buildVoterTableArray
  // ---------------------------------------------------------------------------

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayReturnsEmptyForEmptyInput(): void {
    $result = $this->service->buildVoterTableArray([]);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayKeyedByVanid(): void {
    $result = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $this->assertArrayHasKey('99999', $result);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayCopiesScalarFields(): void {
    $result = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $row = $result['99999'];

    $this->assertSame('99999', $row['vanid']);
    $this->assertSame('Smith', $row['lastName']);
    $this->assertSame('Bob', $row['firstName']);
    $this->assertSame('55', $row['age']);
    $this->assertSame('M', $row['sex']);
    $this->assertSame('D', $row['party']);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayNoMoveMarkWhenNotMoved(): void {
    $result = $this->service->buildVoterTableArray([$this->baseVoter(['moved' => FALSE])]);
    $row = $result['99999'];

    $this->assertSame('123 Main St', $row['address']);
    $this->assertSame('Portland', $row['city']);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayAppendsMoveMarkWhenMoved(): void {
    $result = $this->service->buildVoterTableArray([$this->baseVoter(['moved' => TRUE])]);
    $row = $result['99999'];

    $this->assertStringContainsString('123 Main St', $row['address']);
    $this->assertStringContainsString('<b>', $row['address']);
    $this->assertStringContainsString('<b>', $row['city']);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayCheckboxColumnHasVanid(): void {
    $result = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $cb = $result['99999']['cb'];

    $this->assertSame('cd', $cb['type']);
    $this->assertSame('99999', $cb['vanid']);
  }

  /**
   * @covers ::buildVoterTableArray
   */
  public function testBuildVoterTableArrayHandlesMultipleVoters(): void {
    $voters = [
      $this->baseVoter(['vanid' => '11111']),
      $this->baseVoter(['vanid' => '22222']),
    ];
    $result = $this->service->buildVoterTableArray($voters);

    $this->assertCount(2, $result);
    $this->assertArrayHasKey('11111', $result);
    $this->assertArrayHasKey('22222', $result);
  }

  // ---------------------------------------------------------------------------
  // displayListOfVoters
  // ---------------------------------------------------------------------------

  /**
   * @covers ::displayListOfVoters
   */
  public function testDisplayListOfVotersReturnsContactsTable(): void {
    $rows = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $form = $this->service->displayListOfVoters($rows);

    $this->assertArrayHasKey('contacts', $form);
    $this->assertSame('table', $form['contacts']['#type']);
  }

  /**
   * @covers ::displayListOfVoters
   */
  public function testDisplayListOfVotersHasNineHeaderColumns(): void {
    $rows = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $form = $this->service->displayListOfVoters($rows);

    $this->assertCount(9, $form['contacts']['#header']);
  }

  /**
   * @covers ::displayListOfVoters
   */
  public function testDisplayListOfVotersCheckboxColumnIsCheckboxType(): void {
    $rows = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $form = $this->service->displayListOfVoters($rows);

    $cb = $form['contacts']['99999']['cb'];
    $this->assertSame('checkbox', $cb['#type']);
    $this->assertStringContainsString('99999', $cb['#return_value']);
  }

  /**
   * @covers ::displayListOfVoters
   */
  public function testDisplayListOfVotersNonCheckboxColumnsAreMarkup(): void {
    $rows = $this->service->buildVoterTableArray([$this->baseVoter()]);
    $form = $this->service->displayListOfVoters($rows);

    $this->assertArrayHasKey('#markup', $form['contacts']['99999']['vanid']);
    $this->assertSame('99999', $form['contacts']['99999']['vanid']['#markup']);
  }

  /**
   * @covers ::displayListOfVoters
   */
  public function testDisplayListOfVotersReturnsEmptyTableForEmptyRows(): void {
    $form = $this->service->displayListOfVoters([]);

    $this->assertArrayHasKey('contacts', $form);
    // Only #type and #header — no voter rows.
    $this->assertCount(2, $form['contacts']);
  }

  // ---------------------------------------------------------------------------
  // validateVoterSelection
  // ---------------------------------------------------------------------------

  /**
   * @covers ::validateVoterSelection
   */
  public function testValidateVoterSelectionReturnsEmptyForEmptyInput(): void {
    $result = $this->service->validateVoterSelection([]);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::validateVoterSelection
   */
  public function testValidateVoterSelectionIgnoresUncheckedContacts(): void {
    $contacts = [
      ['cb' => ''],
    ];
    $result = $this->service->validateVoterSelection($contacts);
    $this->assertSame([], $result);
  }

  /**
   * @covers ::validateVoterSelection
   */
  public function testValidateVoterSelectionExtractsVanidFromCheckedContact(): void {
    $contacts = [
      ['cb' => 'CB_checkbox_99999'],
    ];
    $result = $this->service->validateVoterSelection($contacts);

    $this->assertArrayHasKey('99999', $result);
    $this->assertSame('99999', $result['99999']['vanid']);
  }

  /**
   * @covers ::validateVoterSelection
   */
  public function testValidateVoterSelectionExtractsRowCount(): void {
    $contacts = [
      ['cb' => 'CB_checkbox_99999'],
    ];
    $result = $this->service->validateVoterSelection($contacts);

    $this->assertSame('checkbox', $result['99999']['rowCount']);
  }

  /**
   * @covers ::validateVoterSelection
   */
  public function testValidateVoterSelectionHandlesMultipleCheckedContacts(): void {
    $contacts = [
      ['cb' => 'CB_checkbox_11111'],
      ['cb' => ''],
      ['cb' => 'CB_checkbox_22222'],
    ];
    $result = $this->service->validateVoterSelection($contacts);

    $this->assertCount(2, $result);
    $this->assertArrayHasKey('11111', $result);
    $this->assertArrayHasKey('22222', $result);
  }

}
