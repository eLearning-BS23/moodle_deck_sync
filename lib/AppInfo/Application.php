<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\AppInfo;

use OCA\MoodleDeckSync\BackgroundJob\PurgeArchivedBoardsJob;
use OCA\MoodleDeckSync\Deck\DeckClient;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCA\MoodleDeckSync\Middleware\SignatureMiddleware;
use OCA\MoodleDeckSync\Notification\Notifier;
use OCA\MoodleDeckSync\Settings\AdminSection;
use OCA\MoodleDeckSync\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Settings\IManager as ISettingsManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'moodle_deck_sync';

	public function __construct() {
		parent::__construct(self::APP_ID);
		$container = $this->getContainer();
		if (method_exists($container, 'registerAlias')) {
			$container->registerAlias(DeckClient::class, HttpDeckClient::class);
		}
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(DeckClient::class, HttpDeckClient::class);
		$context->registerMiddleware(SignatureMiddleware::class, false);
		$context->registerNotifierService(Notifier::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
		$context->injectFn(static function (IJobList $jobList, ISettingsManager $settingsManager): void {
			if (!$jobList->has(PurgeArchivedBoardsJob::class, null)) {
				$jobList->add(PurgeArchivedBoardsJob::class);
			}
			$settingsManager->registerSection(ISettingsManager::SETTINGS_ADMIN, AdminSection::class);
			$settingsManager->registerSetting(ISettingsManager::SETTINGS_ADMIN, AdminSettings::class);
		});
	}
}
