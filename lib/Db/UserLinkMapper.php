<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<UserLink>
 */
class UserLinkMapper extends QBMapper {
	private const TABLE = 'moodle_deck_sync_users';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, UserLink::class);
	}

	public function findByEmail(string $email): ?UserLink {
		try {
			return $this->linkByEmail($email);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function upsertOutcome(
		string $email,
		?string $uid,
		bool $provisioned,
		string $mode,
	): UserLink {
		if (!in_array($mode, ['matched', 'created', 'unresolved'], true)) {
			throw new \InvalidArgumentException('Unsupported user provisioning outcome');
		}

		$now = time();
		$link = $this->findByEmail($email) ?? new UserLink();
		if ($link->getId() === null) {
			$link->setEmail($email);
			$link->setCreatedAt($now);
		}
		$link->setNcUid($uid);
		$link->setProvisioned($provisioned);
		$link->setProvisionMode($mode);
		$link->setUpdatedAt($now);

		return $this->saveLink($link);
	}

	protected function linkByEmail(string $email): UserLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR)));

		/** @var UserLink $link */
		$link = $this->findEntity($qb);
		return $link;
	}

	protected function saveLink(UserLink $link): UserLink {
		if ($link->getId() === null) {
			/** @var UserLink $inserted */
			$inserted = $this->insert($link);
			return $inserted;
		}

		/** @var UserLink $updated */
		$updated = $this->update($link);
		return $updated;
	}
}
