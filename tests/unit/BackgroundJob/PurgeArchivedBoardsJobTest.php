<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\BackgroundJob;

use OCA\MoodleDeckSync\BackgroundJob\PurgeArchivedBoardsJob;
use OCA\MoodleDeckSync\Db\SyncMap;
use OCA\MoodleDeckSync\Db\SyncMapMapper;
use OCA\MoodleDeckSync\Deck\DeckClient;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PurgeArchivedBoardsJobTest extends TestCase {
	private SyncMapMapper&MockObject $mapMapper;
	private DeckClient&MockObject $deckClient;
	private IAppConfig&MockObject $appConfig;
	private PurgeArchivedBoardsJob $job;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->mapMapper = $this->createMock(SyncMapMapper::class);
		$this->deckClient = $this->createMock(DeckClient::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->job = new PurgeArchivedBoardsJob(
			$this->mapMapper,
			$this->deckClient,
			$this->appConfig,
		);
	}

	public function testPurgeAfterDaysZeroDisablesDeletion(): void {
		$this->appConfig->method('getValueInt')->willReturn(0);

		$this->mapMapper->expects(self::never())->method('findEligibleForPurge');
		$this->deckClient->expects(self::never())->method('deleteBoard');

		$this->job->run(null);
	}

	public function testPurgesEligibleArchivedBoard(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);

		$map = new SyncMap();
		$map->setId(100);
		$map->setStatus('archived');
		$map->setBoardId(501);
		$map->setArchivedAt(time() - (40 * 86400));

		$this->mapMapper->method('findEligibleForPurge')->willReturn([$map]);
		$this->mapMapper->method('findById')->with(100)->willReturn($map);

		$this->deckClient->expects(self::once())
			->method('deleteBoard')
			->with(501);

		$this->mapMapper->expects(self::once())
			->method('markPurged')
			->with(100, self::anything());

		$this->job->run(null);
	}

	public function testDoesNotPurgeActiveOrYoungBoards(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);

		// Active map
		$activeMap = new SyncMap();
		$activeMap->setId(101);
		$activeMap->setStatus('active');
		$activeMap->setBoardId(502);

		// Too-young archived map
		$recentArchived = new SyncMap();
		$recentArchived->setId(102);
		$recentArchived->setStatus('archived');
		$recentArchived->setBoardId(503);
		$recentArchived->setArchivedAt(time() - (5 * 86400));

		$this->mapMapper->method('findEligibleForPurge')->willReturn([]);
		$this->deckClient->expects(self::never())->method('deleteBoard');

		$this->job->run(null);
	}

	public function testRechecksMapStatusBeforeDeleting(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);

		$candidate = new SyncMap();
		$candidate->setId(100);
		$candidate->setStatus('archived');
		$candidate->setBoardId(501);
		$candidate->setArchivedAt(time() - (40 * 86400));

		$current = new SyncMap();
		$current->setId(100);
		$current->setStatus('active');
		$current->setBoardId(501);
		$current->setArchivedAt(time() - (40 * 86400));

		$this->mapMapper->method('findEligibleForPurge')->with(self::anything(), 50)->willReturn([$candidate]);
		$this->mapMapper->method('findById')->with(100)->willReturn($current);
		$this->deckClient->expects(self::never())->method('deleteBoard');

		$this->job->run(null);
	}

	public function testRechecksPurgeSettingBeforeEachDelete(): void {
		$this->appConfig->expects(self::exactly(2))
			->method('getValueInt')
			->willReturnOnConsecutiveCalls(30, 0);

		$candidate = new SyncMap();
		$candidate->setId(100);
		$candidate->setStatus('archived');
		$candidate->setBoardId(501);
		$candidate->setArchivedAt(time() - (40 * 86400));

		$this->mapMapper->method('findEligibleForPurge')->with(self::anything(), 50)->willReturn([$candidate]);
		$this->mapMapper->method('findById')->with(100)->willReturn($candidate);
		$this->deckClient->expects(self::never())->method('deleteBoard');

		$this->job->run(null);
	}

	public function testDeleteFailureStoresSafeRecoverableError(): void {
		$this->appConfig->method('getValueInt')->willReturn(30);

		$candidate = new SyncMap();
		$candidate->setId(100);
		$candidate->setStatus('archived');
		$candidate->setBoardId(501);
		$candidate->setArchivedAt(time() - (40 * 86400));

		$this->mapMapper->method('findEligibleForPurge')->with(self::anything(), 50)->willReturn([$candidate]);
		$this->mapMapper->method('findById')->with(100)->willReturn($candidate);
		$this->deckClient->method('deleteBoard')->with(501)->willThrowException(new \RuntimeException('raw deck token leaked'));
		$this->mapMapper->expects(self::once())
			->method('markError')
			->with(100, 'purge_failed');

		$this->job->run(null);
	}
}
