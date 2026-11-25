<?php

namespace Drupal\Tests\nlpservices\Unit\Controller {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\nlpservices\Controller\ExportTableController;
  use Drupal\nlpservices\DatabaseFunctions;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\Tests\UnitTestCase;

  /**
   * The checks ExportTableController::exportTable() makes before it exports.
   */
  class ExportTableControllerTest extends UnitTestCase {

    private DatabaseFunctions $databaseFunctionsMock;
    private DrupalUser $drupalUserMock;

    protected function setUp(): void {
      parent::setUp();
      $this->databaseFunctionsMock = $this->createMock(DatabaseFunctions::class);
      $this->drupalUserMock        = $this->createMock(DrupalUser::class);
      Drupal::setContainer(new ContainerBuilder());
      $_GET = ['tableName' => 'nlp_turf'];
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
      $_GET = [];
    }

    private function buildController(): ExportTableController {
      return new ExportTableController($this->databaseFunctionsMock, $this->drupalUserMock);
    }

    public function testACoordinatorWithNoCommitteeCantExportEveryCommittee(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['authenticated', 'nlp_coordinator'], 'county' => '']);
      $this->databaseFunctionsMock->expects($this->never())->method('exportTable');

      $result = $this->buildController()->exportTable();

      $this->assertStringContainsString('has no committee', $result['#markup']);
    }

    public function testACoordinatorWithANullCommitteeCantExportEveryCommittee(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['nlp_coordinator'], 'county' => NULL]);
      $this->databaseFunctionsMock->expects($this->never())->method('exportTable');

      $result = $this->buildController()->exportTable();

      $this->assertStringContainsString('has no committee', $result['#markup']);
    }

    public function testACoordinatorCantExportTheActivistCodeHistory(): void {
      $_GET = ['tableName' => 'nlp_ac_history'];
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn(['roles' => ['nlp_coordinator'], 'county' => 'Denver']);
      $this->databaseFunctionsMock->expects($this->never())->method('exportTable');

      $this->assertSame('Invalid table name.', $this->buildController()->exportTable()['#markup']);
    }

    public function testAnUnknownTableIsRefused(): void {
      $_GET = ['tableName' => 'users'];
      $this->databaseFunctionsMock->expects($this->never())->method('exportTable');

      $this->assertSame('Invalid table name.', $this->buildController()->exportTable()['#markup']);
    }

  }

}
