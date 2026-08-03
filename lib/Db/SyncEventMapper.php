<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SyncEvent>
 */
class SyncEventMapper extends QBMapper {
	private const TABLE = 'moodle_deck_sync_events';
	private const RECLAIMABLE = ['failed' => true, 'partial' => true];
	private const RESULTS = ['processing' => true, 'applied' => true, 'partial' => true, 'failed' => true];
	private const DETAIL_LIMIT = 1024;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, SyncEvent::class);
	}

	public function claim(string $instance, string $eventId, string $type): SyncEvent {
		try {
			$existing = $this->findByEventKey($instance, $eventId);
		} catch (DoesNotExistException) {
			return $this->insertNewClaim($instance, $eventId, $type);
		}

		if (!isset(self::RECLAIMABLE[$existing->getResult()])) {
			throw new \RuntimeException('Event has already been claimed');
		}

		$existing->setEventType($type);
		$existing->setResult('processing');
		$existing->setDetail('');
		$existing->setUpdatedAt(time());

		return $this->saveEvent($existing);
	}

	public function markResult(int $id, string $result, string $detail): void {
		if (!isset(self::RESULTS[$result])) {
			throw new \InvalidArgumentException('Unsupported sync event result');
		}

		$event = $this->eventById($id);
		$event->setResult($result);
		$event->setDetail($this->boundedDetail($detail));
		$event->setUpdatedAt(time());
		$this->saveEvent($event);
	}

	protected function findByEventKey(string $instance, string $eventId): SyncEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('moodle_instance', $qb->createNamedParameter($instance, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('event_id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_STR)));

		/** @var SyncEvent $event */
		$event = $this->findEntity($qb);
		return $event;
	}

	protected function eventById(int $id): SyncEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var SyncEvent $event */
		$event = $this->findEntity($qb);
		return $event;
	}

	protected function saveEvent(SyncEvent $event): SyncEvent {
		if ($event->getId() === null) {
			/** @var SyncEvent $inserted */
			$inserted = $this->insert($event);
			return $inserted;
		}

		/** @var SyncEvent $updated */
		$updated = $this->update($event);
		return $updated;
	}

	private function insertNewClaim(string $instance, string $eventId, string $type): SyncEvent {
		$now = time();
		$event = new SyncEvent();
		$event->setMoodleInstance($instance);
		$event->setEventId($eventId);
		$event->setEventType($type);
		$event->setResult('processing');
		$event->setDetail('');
		$event->setCreatedAt($now);
		$event->setUpdatedAt($now);

		try {
			return $this->saveEvent($event);
		} catch (Exception $exception) {
			if ($exception->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $exception;
			}
			$existing = $this->findByEventKey($instance, $eventId);
			if (!isset(self::RECLAIMABLE[$existing->getResult()])) {
				throw new \RuntimeException('Event has already been claimed');
			}
			$existing->setEventType($type);
			$existing->setResult('processing');
			$existing->setDetail('');
			$existing->setUpdatedAt(time());
			return $this->saveEvent($existing);
		}
	}

	private function boundedDetail(string $detail): string {
		return substr($detail, 0, self::DETAIL_LIMIT);
	}
}
