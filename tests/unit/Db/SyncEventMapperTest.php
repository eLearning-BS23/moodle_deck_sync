<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Db;

use OCA\MoodleDeckSync\Db\SyncEvent;
use OCA\MoodleDeckSync\Db\SyncEventMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class SyncEventMapperTest extends TestCase {
	public function testClaimCreatesProcessingEventAndRejectsDuplicateProcessingClaim(): void {
		$mapper = new MemorySyncEventMapper($this->createMock(IDBConnection::class));

		$first = $mapper->claim('https://moodle.local', 'evt_1', 'group_created');

		self::assertSame('processing', $first->getResult());
		self::assertSame('https://moodle.local', $first->getMoodleInstance());
		self::assertSame('evt_1', $first->getEventId());

		$this->expectException(\RuntimeException::class);
		$mapper->claim('https://moodle.local', 'evt_1', 'group_created');
	}

	public function testClaimRejectsTerminalDuplicateButAllowsFailedRetry(): void {
		$mapper = new MemorySyncEventMapper($this->createMock(IDBConnection::class));

		$first = $mapper->claim('https://moodle.local', 'evt_2', 'group_created');
		$mapper->markResult($first->getId(), 'applied', 'ok');

		$this->expectException(\RuntimeException::class);
		$mapper->claim('https://moodle.local', 'evt_2', 'group_created');
	}

	public function testClaimAllowsFailedEventToResume(): void {
		$mapper = new MemorySyncEventMapper($this->createMock(IDBConnection::class));

		$first = $mapper->claim('https://moodle.local', 'evt_3', 'group_created');
		$mapper->markResult($first->getId(), 'failed', 'deck_timeout');
		$second = $mapper->claim('https://moodle.local', 'evt_3', 'group_created');

		self::assertSame($first->getId(), $second->getId());
		self::assertSame('processing', $second->getResult());
	}
}

final class MemorySyncEventMapper extends SyncEventMapper {
	/** @var array<int, SyncEvent> */
	private array $events = [];
	private int $nextId = 1;

	#[\Override]
	protected function findByEventKey(string $instance, string $eventId): SyncEvent {
		foreach ($this->events as $event) {
			if ($event->getMoodleInstance() === $instance && $event->getEventId() === $eventId) {
				return $event;
			}
		}

		throw new DoesNotExistException('event not found');
	}

	#[\Override]
	protected function saveEvent(SyncEvent $event): SyncEvent {
		if ($event->getId() === null) {
			$event->setId($this->nextId++);
		}
		$this->events[$event->getId()] = $event;

		return $event;
	}

	#[\Override]
	protected function eventById(int $id): SyncEvent {
		if (!isset($this->events[$id])) {
			throw new DoesNotExistException('event not found');
		}

		return $this->events[$id];
	}
}
