<?php

namespace Drupal\Tests\nlpservices\Unit\Service {

  use Drupal\Core\Entity\EntityStorageException;
  use Drupal\file\FileInterface;
  use Drupal\file\FileRepositoryInterface;
  use Drupal\nlpservices\NlpFileFunctions;
  use Drupal\Tests\UnitTestCase;
  use Exception;
  use Symfony\Component\DependencyInjection\ContainerBuilder;

  class NlpFileFunctionsTest extends UnitTestCase {

    private FileRepositoryInterface $repoMock;

    protected function tearDown(): void {
      parent::tearDown();
      \Drupal::setContainer(new ContainerBuilder());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildService(): NlpFileFunctions {
      return new NlpFileFunctions($this->repoMock);
    }

    /**
     * Calls createTempCsvFile() with PHP warnings suppressed.
     * fopen('public://...', 'w') emits a warning in unit-test context (no stream
     * wrapper registered); the Symfony DeprecationErrorHandler would promote that
     * warning to an exception without suppression.
     */
    private function callService(string $prefix): mixed {
      set_error_handler(fn() => true);
      $result = $this->buildService()->createTempCsvFile($prefix);
      restore_error_handler();
      return $result;
    }

    /** Returns a mock with setTemporary() and save() methods. */
    private function makeFileMock(bool $saveThrows = false): object {
      $mock = $this->createMock(FileInterface::class);
      if ($saveThrows) {
        $mock->method('save')->willThrowException(new Exception('save failed'));
      }
      return $mock;
    }

    /** Sets up a file.repository mock whose writeData() returns $fileMock. */
    private function setupRepo(object $fileMock): void {
      $repo = $this->createMock(FileRepositoryInterface::class);
      $repo->method('writeData')->willReturn($fileMock);
      $this->repoMock = $repo;
    }

    /** Sets up a file.repository mock whose writeData() throws EntityStorageException. */
    private function setupRepoThatThrows(): void {
      $repo = $this->createMock(FileRepositoryInterface::class);
      $repo->method('writeData')->willThrowException(new EntityStorageException(''));
      $this->repoMock = $repo;
    }

    /**
     * Sets up a file.repository mock that captures the URI passed to writeData().
     * $capturedUri is set by reference after callService() runs.
     */
    private function setupRepoCapturingUri(object $fileMock, ?string &$capturedUri): void {
      $repo = $this->createMock(FileRepositoryInterface::class);
      $repo->method('writeData')->willReturnCallback(
        function($data, $uri, $mode) use ($fileMock, &$capturedUri) {
          $capturedUri = $uri;
          return $fileMock;
        }
      );
      $this->repoMock = $repo;
    }

    // -------------------------------------------------------------------------
    // createTempCsvFile — error paths
    // -------------------------------------------------------------------------

    public function testCreateTempCsvFileReturnsFalseWhenEntityStorageExceptionThrown(): void {
      $this->setupRepoThatThrows();
      // Exception is thrown before fopen, so no warning suppression needed.
      $this->assertFalse($this->buildService()->createTempCsvFile('prefix'));
    }

    public function testCreateTempCsvFileReturnsFalseWhenSaveThrows(): void {
      $this->setupRepo($this->makeFileMock(saveThrows: true));
      // Exception is thrown before fopen, so no warning suppression needed.
      $this->assertFalse($this->buildService()->createTempCsvFile('prefix'));
    }

    public function testCreateTempCsvFileReturnsFalseWhenFopenFails(): void {
      // public:// stream wrapper is not registered in unit-test context,
      // so fopen('public://...', 'w') returns false and the method returns false.
      $this->setupRepo($this->makeFileMock());
      $this->assertFalse($this->callService('prefix'));
    }

    // -------------------------------------------------------------------------
    // createTempCsvFile — URI structure
    // -------------------------------------------------------------------------

    public function testCreateTempCsvFileUriContainsPrefix(): void {
      $capturedUri = null;
      $this->setupRepoCapturingUri($this->makeFileMock(), $capturedUri);
      $this->callService('myprefix');
      $this->assertStringContainsString('myprefix', $capturedUri);
    }

    public function testCreateTempCsvFileUriStartsWithPublicTemp(): void {
      $capturedUri = null;
      $this->setupRepoCapturingUri($this->makeFileMock(), $capturedUri);
      $this->callService('prefix');
      $this->assertStringStartsWith('public://temp/', $capturedUri);
    }

    public function testCreateTempCsvFileUriEndsWithCsvExtension(): void {
      $capturedUri = null;
      $this->setupRepoCapturingUri($this->makeFileMock(), $capturedUri);
      $this->callService('prefix');
      $this->assertStringEndsWith('.csv', $capturedUri);
    }

    public function testCreateTempCsvFileUriContainsTodaysDate(): void {
      $capturedUri = null;
      $this->setupRepoCapturingUri($this->makeFileMock(), $capturedUri);
      $this->callService('prefix');
      $this->assertStringContainsString(date('Y-m-d'), $capturedUri);
    }

    // -------------------------------------------------------------------------
    // createTempCsvFile — file entity interactions
    // -------------------------------------------------------------------------

    public function testCreateTempCsvFileCallsSetTemporaryOnFileEntity(): void {
      $fileMock = $this->makeFileMock();
      $fileMock->expects($this->once())->method('setTemporary');
      $this->setupRepo($fileMock);
      $this->callService('prefix');
    }

    public function testCreateTempCsvFileSavesFileEntity(): void {
      $fileMock = $this->makeFileMock();
      $fileMock->expects($this->once())->method('save');
      $this->setupRepo($fileMock);
      $this->callService('prefix');
    }

    public function testCreateTempCsvFileWritesEmptyStringToRepository(): void {
      $fileMock = $this->makeFileMock();
      $repo = $this->createMock(FileRepositoryInterface::class);
      $repo->expects($this->once())->method('writeData')
        ->with('', $this->anything(), $this->anything())
        ->willReturn($fileMock);
      $this->repoMock = $repo;
      $this->callService('prefix');
    }

  }

}
