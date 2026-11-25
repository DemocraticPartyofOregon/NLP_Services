<?php

namespace Drupal\Tests\nlpservices\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks nlpservices_update_10009(), which adds field_committee to sites
 * that have field_county, against real field storage.
 */
#[Group('nlpservices')]
#[RunTestsInSeparateProcesses]
class CommitteeFieldUpdateTest extends KernelTestBase
{
  protected static $modules = ['system', 'user', 'field', 'file', 'nlpservices'];

  protected function setUp(): void
  {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'field']);
    $this->container->get('module_handler')->loadInclude('nlpservices', 'install');

    // A site upgraded from V7_2_1 has field_county on its user form.
    FieldStorageConfig::create([
      'field_name' => 'field_county', 'entity_type' => 'user', 'type' => 'string',
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_county', 'entity_type' => 'user', 'bundle' => 'user', 'label' => 'County',
    ])->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'user', 'bundle' => 'user', 'mode' => 'default', 'status' => TRUE,
    ])->setComponent('field_county', ['type' => 'string_textfield', 'weight' => 7])->save();
  }

  public function testTheUpdateAddsTheFieldLikeTheInstallConfig(): void
  {
    $message = nlpservices_update_10009();

    $storage = FieldStorageConfig::loadByName('user', 'field_committee');
    $this->assertNotNull($storage);
    $this->assertSame('string', $storage->getType());
    $this->assertSame(255, $storage->getSetting('max_length'));
    $this->assertSame(1, $storage->getCardinality());
    $field = FieldConfig::loadByName('user', 'user', 'field_committee');
    $this->assertSame('Committee', $field->getLabel());
    $this->assertStringContainsString('Created the field_committee storage.', $message);

    // It's on the user form where field_county is, and field_county is left alone.
    $display = EntityFormDisplay::load('user.user.default');
    $this->assertSame(['type' => 'string_textfield', 'weight' => 7],
      array_intersect_key($display->getComponent('field_committee'), ['type' => 1, 'weight' => 1]));
    $this->assertNotNull($display->getComponent('field_county'));
    $this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_county'));
  }

  public function testAFieldAddedByHandIsLeftAsItIs(): void
  {
    FieldStorageConfig::create([
      'field_name' => 'field_committee', 'entity_type' => 'user', 'type' => 'string',
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_committee', 'entity_type' => 'user', 'bundle' => 'user', 'label' => 'VB committee',
    ])->save();

    $message = nlpservices_update_10009();

    $this->assertStringContainsString('field_committee was already there.', $message);
    $this->assertSame('VB committee', FieldConfig::loadByName('user', 'user', 'field_committee')->getLabel());
  }

  public function testRunningItTwiceChangesNothing(): void
  {
    nlpservices_update_10009();
    $message = nlpservices_update_10009();

    $this->assertSame('field_committee was already there.', $message);
  }

  public function testAnAccountKeepsItsCommitteeInTheNewField(): void
  {
    nlpservices_update_10009();
    $account = $this->container->get('entity_type.manager')->getStorage('user')
      ->create(['name' => 'nl', 'field_committee' => 'Region 1']);
    $account->save();

    $reloaded = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($account->id());
    $this->assertSame('Region 1', $reloaded->get('field_committee')->value);
  }
}
