<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Notification;

use OCA\MoodleDeckSync\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private IManager&MockObject $notificationManager;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private Notifier $notifier;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->notificationManager = $this->createMock(IManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => str_replace('{board}', (string)($parameters['board'] ?? ''), $text),
		);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $routeName, array $parameters): string {
				self::assertSame('deck.page.indexBoard', $routeName);
				return 'https://nextcloud.local/index.php/apps/deck/board/' . $parameters['boardId'];
			},
		);
		$this->notifier = new Notifier($this->notificationManager, $this->l10n, $this->urlGenerator);
	}

	public function testNotifierImplementsNextcloudNotifier(): void {
		self::assertInstanceOf(INotifier::class, $this->notifier);
		self::assertSame('moodle_deck_sync', $this->notifier->getID());
	}

	public function testNotifierEmitsBoardReadyNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->expects(self::once())
			->method('setLink')
			->with('https://nextcloud.local/index.php/apps/deck/board/42')
			->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects(self::once())
			->method('notify');

		$this->notifier->notifyBoardReady('nc_user1', 42, 'Project Alpha Board');
	}

	public function testPrepareLocalizesBoardReadyNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('moodle_deck_sync');
		$notification->method('getSubject')->willReturn('board_ready');
		$notification->method('getSubjectParameters')->willReturn(['board' => 'Project Alpha Board']);
		$notification->expects(self::once())
			->method('setParsedSubject')
			->with('Nextcloud Deck board is ready: Project Alpha Board')
			->willReturnSelf();

		self::assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}

	public function testPrepareRejectsUnknownNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('other_app');

		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}
}
