<?php

namespace Drupal\Tests\nlpservices\Unit\Controller {

  use Drupal;
  use Drupal\Core\DependencyInjection\ContainerBuilder;
  use Drupal\Core\Messenger\MessengerInterface;
  use Drupal\Core\Routing\UrlGeneratorInterface;
  use Drupal\Core\Session\AccountProxyInterface;
  use Drupal\nlpservices\Controller\LeaderStartController;
  use Drupal\nlpservices\DrupalUser;
  use Drupal\nlpservices\NlpTurfs;
  use Drupal\Core\Logger\LoggerChannelFactoryInterface;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\LoggerInterface;

  class LeaderStartControllerTest extends UnitTestCase {

    private DrupalUser $drupalUserMock;
    private NlpTurfs $turfsMock;
    private MessengerInterface $messengerMock;
    /** @var array The status messages waiting to be shown. */
    private array $statusMessages = [];
    /** @var string[] The notices logged to the nlpservices channel. */
    private array $notices = [];

    protected function setUp(): void {
      parent::setUp();
      $this->drupalUserMock = $this->createMock(DrupalUser::class);
      $this->turfsMock      = $this->createMock(NlpTurfs::class);
      $this->messengerMock  = $this->createMock(MessengerInterface::class);
      $this->statusMessages = [];
      $this->messengerMock->method('messagesByType')->with('status')
        ->willReturnCallback(fn(): array => $this->statusMessages);

      $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
      $urlGenerator->method('generateFromRoute')
        ->willReturnCallback(fn($name, $parameters) => 'https://example.com/' . $name
          . (isset($parameters['user']) ? '/' . $parameters['user'] : ''));
      $currentUser = $this->createMock(AccountProxyInterface::class);
      $currentUser->method('id')->willReturn(39);

      $this->notices = [];
      $loggerMock = $this->createMock(LoggerInterface::class);
      $loggerMock->method('notice')->willReturnCallback(function ($message, array $context = []): void {
        $this->notices[] = strtr((string) $message, $context);
      });
      $loggerFactoryMock = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactoryMock->method('get')->with('nlpservices')->willReturn($loggerMock);

      $container = new ContainerBuilder();
      $container->set('url_generator', $urlGenerator);
      $container->set('current_user', $currentUser);
      $container->set('logger.factory', $loggerFactoryMock);
      Drupal::setContainer($container);
    }

    protected function tearDown(): void {
      parent::tearDown();
      Drupal::setContainer(new ContainerBuilder());
    }

    private function buildController(): LeaderStartController {
      $controller = new LeaderStartController($this->drupalUserMock, $this->turfsMock);
      $controller->setStringTranslation($this->getStringTranslationStub());
      $controller->setMessenger($this->messengerMock);
      return $controller;
    }

    private function leader(array $overrides = []): array {
      return array_merge(['userName' => 'alice', 'roles' => ['authenticated', 'nlp_leader'], 'mcid' => 42,
        'county' => 'Lane'], $overrides);
    }

    public function testALeaderWithATurfGoesToDataEntry(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->leader());
      $this->drupalUserMock->method('isLeaderOnly')->willReturn(TRUE);
      $this->turfsMock->method('turfExists')->with(42, 'Lane')->willReturn(['turfs' => [5 => []], 'turfCnt' => 1]);

      $response = $this->buildController()->start();

      $this->assertSame('https://example.com/nlpservices.data_entry_form', $response->getTargetUrl());
      $this->assertSame(['Login landing for alice: data entry.'], $this->notices);
    }

    public function testALeaderWithoutATurfGoesToTheAccountPage(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->leader());
      $this->drupalUserMock->method('isLeaderOnly')->willReturn(TRUE);
      $this->turfsMock->method('turfExists')->willReturn([]);

      $response = $this->buildController()->start();

      $this->assertSame('https://example.com/entity.user.canonical/39', $response->getTargetUrl());
      $this->assertSame(['Login landing for alice: account page, no turf for MCID 42 in Lane county.'], $this->notices);
    }

    public function testALeaderWithoutACountyGoesToTheAccountPage(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->leader(['county' => NULL]));
      $this->drupalUserMock->method('isLeaderOnly')->willReturn(TRUE);
      $this->turfsMock->expects($this->never())->method('turfExists');

      $response = $this->buildController()->start();

      $this->assertSame('https://example.com/entity.user.canonical/39', $response->getTargetUrl());
      $this->assertSame(['Login landing for alice: account page, no county on the account.'], $this->notices);
    }

    public function testACoordinatorGoesToTheAccountPageEvenWithATurf(): void {
      $this->drupalUserMock->method('getCurrentUser')
        ->willReturn($this->leader(['roles' => ['authenticated', 'nlp_leader', 'nlp_coordinator']]));
      $this->drupalUserMock->method('isLeaderOnly')->willReturn(FALSE);
      $this->turfsMock->method('turfExists')->willReturn(['turfs' => [5 => []], 'turfCnt' => 1]);

      $response = $this->buildController()->start();

      $this->assertSame('https://example.com/entity.user.canonical/39', $response->getTargetUrl());
      $this->assertSame(['Login landing for alice: account page, not only an NLP Leader (roles: authenticated, nlp_leader, nlp_coordinator).'],
        $this->notices);
    }

    public function testTheSetPasswordMessageIsRemovedAndOtherMessagesKept(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->leader());
      $this->statusMessages = [LeaderStartController::SET_PASSWORD_MSG, 'Welcome back.'];
      $this->messengerMock->expects($this->once())->method('deleteByType')->with('status');
      $this->messengerMock->expects($this->once())->method('addStatus')->with('Welcome back.');

      $this->buildController()->start();
    }

    public function testStatusMessagesAreLeftAloneWithoutTheSetPasswordMessage(): void {
      $this->drupalUserMock->method('getCurrentUser')->willReturn($this->leader());
      $this->statusMessages = ['Welcome back.'];
      $this->messengerMock->expects($this->never())->method('deleteByType');
      $this->messengerMock->expects($this->never())->method('addStatus');

      $this->buildController()->start();
    }

  }

}
