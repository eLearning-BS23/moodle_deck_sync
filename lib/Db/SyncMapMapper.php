<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SyncMap>
 */
class SyncMapMapper extends QBMapper {
	private const TABLE = 'moodle_deck_sync_map';
	private const ACTIVE_STATUSES = ['pending' => true, 'active' => true, 'error' => true];
	private const ARCHIVE_STATUSES = ['active' => true, 'archived' => true, 'error' => true];
	private const PURGE_STATUSES = ['archived' => true, 'purged' => true];
	private const ERROR_LIMIT = 1024;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, SyncMap::class);
	}

	public function findBySourceKey(string $instance, int $courseId, int $groupId, ?int $cmid): ?SyncMap {
		try {
			return $this->mapBySourceKey($instance, $courseId, $groupId, $cmid);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findById(int $id): ?SyncMap {
		try {
			return $this->mapById($id);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<SyncMap>
	 */
	public function findAllByGroup(string $instance, int $courseId, int $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('moodle_instance', $qb->createNamedParameter($instance, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('moodle_course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('moodle_group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));

		/** @var list<SyncMap> $entities */
		$entities = $this->findEntities($qb);
		return $entities;
	}

	/**
	 * @return list<SyncMap>
	 */
	public function findEligibleForPurge(int $cutoffTimestamp, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('status', $qb->createNamedParameter('archived', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('archived_at', $qb->createNamedParameter($cutoffTimestamp, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('purged_at'))
			->andWhere($qb->expr()->isNotNull('board_id'))
			->setMaxResults($limit);

		/** @var list<SyncMap> $entities */
		$entities = $this->findEntities($qb);
		return $entities;
	}

	public function upsertPending(string $instance, int $courseId, int $groupId, ?int $cmid): SyncMap {
		$existing = $this->findBySourceKey($instance, $courseId, $groupId, $cmid);
		if ($existing !== null) {
			return $existing;
		}

		$now = time();
		$map = new SyncMap();
		$map->setMoodleInstance($instance);
		$map->setMoodleCourseId($courseId);
		$map->setMoodleGroupId($groupId);
		$map->setMoodleCmid($cmid);
		$map->setBoardId(null);
		$map->setStatus('pending');
		$map->setSafeError('');
		$map->setArchivedAt(null);
		$map->setPurgedAt(null);
		$map->setCreatedAt($now);
		$map->setUpdatedAt($now);

		return $this->saveMap($map);
	}

	public function markActive(int $id, int $boardId): void {
		$map = $this->mapById($id);
		if (!isset(self::ACTIVE_STATUSES[$map->getStatus()])) {
			throw new \RuntimeException('Map cannot transition to active');
		}

		$map->setBoardId($boardId);
		$map->setStatus('active');
		$map->setSafeError('');
		$map->setUpdatedAt(time());
		$this->saveMap($map);
	}

	public function markArchived(int $id, int $archivedAt): void {
		$map = $this->mapById($id);
		if (!isset(self::ARCHIVE_STATUSES[$map->getStatus()])) {
			throw new \RuntimeException('Map cannot transition to archived');
		}

		$map->setStatus('archived');
		$map->setArchivedAt($archivedAt);
		$map->setUpdatedAt(time());
		$this->saveMap($map);
	}

	public function markPurged(int $id, int $purgedAt): void {
		$map = $this->mapById($id);
		if (!isset(self::PURGE_STATUSES[$map->getStatus()])) {
			throw new \RuntimeException('Map cannot transition to purged');
		}

		$map->setStatus('purged');
		$map->setPurgedAt($purgedAt);
		$map->setUpdatedAt(time());
		$this->saveMap($map);
	}

	public function markError(int $id, string $safeerror): void {
		$map = $this->mapById($id);
		$map->setStatus('error');
		$map->setSafeError($this->boundedError($safeerror));
		$map->setUpdatedAt(time());
		$this->saveMap($map);
	}

	protected function mapBySourceKey(string $instance, int $courseId, int $groupId, ?int $cmid): SyncMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('moodle_instance', $qb->createNamedParameter($instance, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('moodle_course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('moodle_group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_INT)));

		if ($cmid === null) {
			$qb->andWhere($qb->expr()->isNull('moodle_cmid'));
		} else {
			$qb->andWhere($qb->expr()->eq('moodle_cmid', $qb->createNamedParameter($cmid, IQueryBuilder::PARAM_INT)));
		}

		/** @var SyncMap $map */
		$map = $this->findEntity($qb);
		return $map;
	}

	protected function mapById(int $id): SyncMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var SyncMap $map */
		$map = $this->findEntity($qb);
		return $map;
	}

	protected function saveMap(SyncMap $map): SyncMap {
		if ($map->getId() === null) {
			/** @var SyncMap $inserted */
			$inserted = $this->insert($map);
			return $inserted;
		}

		/** @var SyncMap $updated */
		$updated = $this->update($map);
		return $updated;
	}

	private function boundedError(string $safeerror): string {
		return substr($safeerror, 0, self::ERROR_LIMIT);
	}
}
