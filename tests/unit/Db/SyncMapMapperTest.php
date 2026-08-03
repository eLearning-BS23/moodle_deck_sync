<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Db;

use OCA\MoodleDeckSync\Db\SyncMap;
use OCA\MoodleDeckSync\Db\SyncMapMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SyncMapMapperTest extends TestCase {
	public function testUpsertPendingUsesApprovedSourceKey(): void {
		$mapper = new MemorySyncMapMapper($this->createMock(IDBConnection::class));

		$first = $mapper->upsertPending('https://moodle.local', 12, 501, 77);
		$second = $mapper->upsertPending('https://moodle.local', 12, 501, 77);

		self::assertSame($first->getId(), $second->getId());
		self::assertSame('pending', $second->getStatus());
		self::assertSame($first->getId(), $mapper->findBySourceKey('https://moodle.local', 12, 501, 77)->getId());
	}

	public function testApprovedLifecycleTransitions(): void {
		$mapper = new MemorySyncMapMapper($this->createMock(IDBConnection::class));
		$map = $mapper->upsertPending('https://moodle.local', 12, 501, 77);

		$mapper->markActive($map->getId(), 87);
		$active = $mapper->findBySourceKey('https://moodle.local', 12, 501, 77);
		self::assertSame('active', $active->getStatus());
		self::assertSame(87, $active->getBoardId());

		$mapper->markArchived($map->getId(), 1753180000);
		$archived = $mapper->findBySourceKey('https://moodle.local', 12, 501, 77);
		self::assertSame('archived', $archived->getStatus());
		self::assertSame(1753180000, $archived->getArchivedAt());

		$mapper->markPurged($map->getId(), 1753181000);
		$purged = $mapper->findBySourceKey('https://moodle.local', 12, 501, 77);
		self::assertSame('purged', $purged->getStatus());
		self::assertSame(1753181000, $purged->getPurgedAt());
	}

	public function testDirectActiveToPurgedIsRejected(): void {
		$mapper = new MemorySyncMapMapper($this->createMock(IDBConnection::class));
		$map = $mapper->upsertPending('https://moodle.local', 12, 501, 77);
		$mapper->markActive($map->getId(), 87);

		$this->expectException(\RuntimeException::class);
		$mapper->markPurged($map->getId(), 1753181000);
	}
}

final class MemorySyncMapMapper extends SyncMapMapper {
	/** @var array<int, SyncMap> */
	private array $maps = [];
	private int $nextId = 1;

	#[\Override]
	protected function mapBySourceKey(string $instance, int $courseId, int $groupId, ?int $cmid): SyncMap {
		foreach ($this->maps as $map) {
			if (
				$map->getMoodleInstance() === $instance
				&& $map->getMoodleCourseId() === $courseId
				&& $map->getMoodleGroupId() === $groupId
				&& $map->getMoodleCmid() === $cmid
			) {
				return $map;
			}
		}

		throw new DoesNotExistException('map not found');
	}

	#[\Override]
	protected function mapById(int $id): SyncMap {
		if (!isset($this->maps[$id])) {
			throw new DoesNotExistException('map not found');
		}

		return $this->maps[$id];
	}

	#[\Override]
	protected function saveMap(SyncMap $map): SyncMap {
		if ($map->getId() === null) {
			$map->setId($this->nextId++);
		}
		$this->maps[$map->getId()] = $map;

		return $map;
	}
}
