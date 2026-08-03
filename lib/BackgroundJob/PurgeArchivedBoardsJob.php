<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\BackgroundJob;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Db\SyncMapMapper;
use OCA\MoodleDeckSync\Deck\DeckClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

class PurgeArchivedBoardsJob extends TimedJob {
	public function __construct(
		private readonly SyncMapMapper $syncMapMapper,
		private readonly DeckClient $deckClient,
		private readonly IAppConfig $appConfig,
		?ITimeFactory $timeFactory = null,
	) {
		if ($timeFactory !== null) {
			parent::__construct($timeFactory);
		}
		$this->setInterval(86400);
	}

	#[\Override]
	public function run(mixed $argument): void {
		$purgeAfterDays = $this->appConfig->getValueInt(
			Application::APP_ID,
			'purge_after_days',
			0,
			true,
		);

		if ($purgeAfterDays <= 0) {
			return;
		}

		$now = time();
		$cutoff = $now - ($purgeAfterDays * 86400);

		$eligibleMaps = $this->syncMapMapper->findEligibleForPurge($cutoff, 50);

		foreach ($eligibleMaps as $map) {
			$purgeAfterDays = $this->appConfig->getValueInt(
				Application::APP_ID,
				'purge_after_days',
				0,
				true,
			);
			if ($purgeAfterDays <= 0) {
				return;
			}

			$current = $this->syncMapMapper->findById($map->getId());
			if ($current === null) {
				continue;
			}
			$map = $current;

			$boardId = $map->getBoardId();
			if ($boardId === null || $map->getStatus() !== 'archived' || $map->getPurgedAt() !== null) {
				continue;
			}

			$archivedAt = $map->getArchivedAt();
			if ($archivedAt === null || $archivedAt > (time() - ($purgeAfterDays * 86400))) {
				continue;
			}

			try {
				$this->deckClient->deleteBoard($boardId);
				$this->syncMapMapper->markPurged($map->getId(), time());
			} catch (\Throwable $e) {
				$this->syncMapMapper->markError($map->getId(), 'purge_failed');
			}
		}
	}
}
