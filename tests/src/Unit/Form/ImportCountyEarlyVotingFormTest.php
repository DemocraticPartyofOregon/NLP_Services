<?php

namespace Drupal\Tests\nlpservices\Unit\Form {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Form\FormStateInterface;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\nlpservices\Form\ImportCountyEarlyVotingForm;
  use Drupal\nlpservices\NlpEarlyVoting;
  use Drupal\nlpservices\NlpPdfParser;
  use Drupal\Tests\UnitTestCase;
  use Symfony\Component\HttpFoundation\File\UploadedFile;
  use Symfony\Component\HttpFoundation\FileBag;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;

  class ImportCountyEarlyVotingFormTest extends UnitTestCase {

    private NlpPdfParser $pdfParserMock;
    private NlpEarlyVoting $earlyVotingMock;
    private MessengerInterface $messengerMock;

    protected function setUp(): void {
      parent::setUp();
      $this->pdfParserMock   = $this->createMock(NlpPdfParser::class);
      $this->earlyVotingMock = $this->createMock(NlpEarlyVoting::class);
      $this->messengerMock   = $this->createMock(MessengerInterface::class);

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

    private function buildForm(array $files = []): ImportCountyEarlyVotingForm {
      $filesBag = $this->createMock(FileBag::class);
      $filesBag->method('get')->with('files', [])->willReturn($files);

      $request = $this->createMock(Request::class);
      $request->files = $filesBag;

      $requestStack = $this->createMock(RequestStack::class);
      $requestStack->method('getCurrentRequest')->willReturn($request);

      $form = new ImportCountyEarlyVotingForm($this->pdfParserMock, $this->earlyVotingMock, $requestStack);
      $form->setStringTranslation($this->getStringTranslationStub());
      $form->setMessenger($this->messengerMock);
      return $form;
    }

    private function makeUploadedFile(string $originalName, string $realPath = '/tmp/phpABCDEF'): UploadedFile {
      $mock = $this->createMock(UploadedFile::class);
      $mock->method('getClientOriginalName')->willReturn($originalName);
      $mock->method('getRealPath')->willReturn($realPath);
      return $mock;
    }

    // -------------------------------------------------------------------------
    // getFormId
    // -------------------------------------------------------------------------

    public function testGetFormIdReturnsExpectedId(): void {
      $this->assertSame('nlpservices_import_county_early_voting_form', $this->buildForm()->getFormId());
    }

    // -------------------------------------------------------------------------
    // buildForm
    // -------------------------------------------------------------------------

    public function testBuildFormContainsPdfFileInputAndSubmitButton(): void {
      $result = $this->buildForm()->buildForm([], $this->createMock(FormStateInterface::class));

      $this->assertSame('multipart/form-data', $result['#attributes']['enctype']);
      $this->assertSame('file', $result['pdf_file']['#type']);
      $this->assertTrue($result['pdf_file']['#required']);
      $this->assertSame('submit', $result['submit']['#type']);
    }

    // -------------------------------------------------------------------------
    // validateForm
    // -------------------------------------------------------------------------

    public function testValidateFormNoFileUploadedSetsError(): void {
      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('pdf_file', $this->anything());

      $form = [];
      $this->buildForm([])->validateForm($form, $formState);
    }

    public function testValidateFormNonPdfExtensionSetsError(): void {
      $files = ['pdf_file' => $this->makeUploadedFile('report.docx')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->once())
        ->method('setErrorByName')
        ->with('pdf_file', $this->anything());

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    public function testValidateFormPdfExtensionSetsNoError(): void {
      $files = ['pdf_file' => $this->makeUploadedFile('report.pdf')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    public function testValidateFormUppercasePdfExtensionSetsNoError(): void {
      $files = ['pdf_file' => $this->makeUploadedFile('report.PDF')];

      $formState = $this->createMock(FormStateInterface::class);
      $formState->expects($this->never())->method('setErrorByName');

      $form = [];
      $this->buildForm($files)->validateForm($form, $formState);
    }

    // -------------------------------------------------------------------------
    // submitForm — parse failure
    // -------------------------------------------------------------------------

    public function testSubmitFormParseFailureAddsErrorAndSkipsMatching(): void {
      $files = ['pdf_file' => $this->makeUploadedFile('report.pdf', '/tmp/report.pdf')];

      $this->pdfParserMock->method('extractRegistrationTable')
        ->with('/tmp/report.pdf')
        ->willReturn(FALSE);
      $this->earlyVotingMock->expects($this->never())->method('matchEarlyVoters');
      $this->messengerMock->expects($this->once())->method('addError');
      $this->messengerMock->expects($this->never())->method('addStatus');

      $form = [];
      $this->buildForm($files)->submitForm($form, $this->createMock(FormStateInterface::class));
    }

    // -------------------------------------------------------------------------
    // submitForm — successful parse and match
    // -------------------------------------------------------------------------

    public function testSubmitFormSuccessMatchesVotersAndAddsStatus(): void {
      $files = ['pdf_file' => $this->makeUploadedFile('report.pdf', '/tmp/report.pdf')];
      $rows = [['name' => 'Jane Doe']];

      $this->pdfParserMock->method('extractRegistrationTable')->willReturn($rows);
      $this->earlyVotingMock->expects($this->once())
        ->method('matchEarlyVoters')
        ->with($rows)
        ->willReturn([['vanid' => 111], ['vanid' => 222]]);

      $this->messengerMock->expects($this->never())->method('addError');
      $this->messengerMock->expects($this->once())->method('addStatus');

      $form = [];
      $this->buildForm($files)->submitForm($form, $this->createMock(FormStateInterface::class));
    }

  }

}
