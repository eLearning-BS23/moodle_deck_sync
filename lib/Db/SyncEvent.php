<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getMoodleInstance()
 * @method void setMoodleInstance(string $moodleInstance)
 * @method string getEventId()
 * @method void setEventId(string $eventId)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string getResult()
 * @method void setResult(string $result)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SyncEvent extends Entity {
	protected string $moodleInstance = '';
	protected string $eventId = '';
	protected string $eventType = '';
	protected string $result = '';
	protected ?string $detail = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('moodleInstance', Types::STRING);
		$this->addType('eventId', Types::STRING);
		$this->addType('eventType', Types::STRING);
		$this->addType('result', Types::STRING);
		$this->addType('detail', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
