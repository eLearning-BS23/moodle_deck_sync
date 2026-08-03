<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Notification;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier {
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Moodle Deck Sync');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'board_ready') {
			throw new UnknownNotificationException();
		}

		$parameters = $notification->getSubjectParameters();
		$boardTitle = isset($parameters['board']) ? (string)$parameters['board'] : '';

		return $notification->setParsedSubject(
			$this->l10n->t('Nextcloud Deck board is ready: {board}', ['board' => $boardTitle])
		);
	}

	public function notifyBoardReady(string $userUid, int $boardId, string $boardTitle): void {
		$link = $this->urlGenerator->linkToRouteAbsolute('deck.page.indexBoard', ['boardId' => $boardId]);
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userUid)
			->setDateTime(new \DateTime())
			->setObject('board', (string)$boardId)
			->setSubject('board_ready', [
				'board' => $boardTitle,
			])
			->setLink($link);

		$this->notificationManager->notify($notification);
	}
}
