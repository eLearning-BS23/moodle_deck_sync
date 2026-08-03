<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\AppInfo;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\BackgroundJob\PurgeArchivedBoardsJob;
use OCA\MoodleDeckSync\Notification\Notifier;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase {
	public function testRegistersMiddlewareAndNotifier(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerServiceAlias')
			->with(\OCA\MoodleDeckSync\Deck\DeckClient::class, \OCA\MoodleDeckSync\Deck\HttpDeckClient::class);
		$context->expects(self::once())
			->method('registerMiddleware');
		$context->expects(self::once())
			->method('registerNotifierService')
			->with(Notifier::class);

		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$application->register($context);
	}

	public function testBootRegistersPurgeJobViaJobList(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('has')
			->with(PurgeArchivedBoardsJob::class, null)
			->willReturn(false);
		$jobList->expects(self::once())
			->method('add')
			->with(PurgeArchivedBoardsJob::class);
		$settingsManager = $this->createMock(ISettingsManager::class);
		$settingsManager->expects(self::once())
			->method('registerSection')
			->with(ISettingsManager::SETTINGS_ADMIN, \OCA\MoodleDeckSync\Settings\AdminSection::class);
		$settingsManager->expects(self::once())
			->method('registerSetting')
			->with(ISettingsManager::SETTINGS_ADMIN, \OCA\MoodleDeckSync\Settings\AdminSettings::class);

		$context = $this->createMock(IBootContext::class);
		$context->expects(self::once())
			->method('injectFn')
			->willReturnCallback(static function (callable $callback) use ($jobList, $settingsManager): void {
				$callback($jobList, $settingsManager);
			});

		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$application->boot($context);
	}
}
