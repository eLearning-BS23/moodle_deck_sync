<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getMoodleInstance()
 * @method void setMoodleInstance(string $moodleInstance)
 * @method int getMoodleCourseId()
 * @method void setMoodleCourseId(int $moodleCourseId)
 * @method int getMoodleGroupId()
 * @method void setMoodleGroupId(int $moodleGroupId)
 * @method int|null getMoodleCmid()
 * @method void setMoodleCmid(?int $moodleCmid)
 * @method int|null getBoardId()
 * @method void setBoardId(?int $boardId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getSafeError()
 * @method void setSafeError(?string $safeError)
 * @method int|null getArchivedAt()
 * @method void setArchivedAt(?int $archivedAt)
 * @method int|null getPurgedAt()
 * @method void setPurgedAt(?int $purgedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SyncMap extends Entity {
	protected string $moodleInstance = '';
	protected int $moodleCourseId = 0;
	protected int $moodleGroupId = 0;
	protected ?int $moodleCmid = null;
	protected ?int $boardId = null;
	protected string $status = '';
	protected ?string $safeError = '';
	protected ?int $archivedAt = null;
	protected ?int $purgedAt = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('moodleInstance', Types::STRING);
		$this->addType('moodleCourseId', Types::BIGINT);
		$this->addType('moodleGroupId', Types::BIGINT);
		$this->addType('moodleCmid', Types::BIGINT);
		$this->addType('boardId', Types::BIGINT);
		$this->addType('status', Types::STRING);
		$this->addType('safeError', Types::STRING);
		$this->addType('archivedAt', Types::BIGINT);
		$this->addType('purgedAt', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
