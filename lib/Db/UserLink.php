<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string|null getNcUid()
 * @method void setNcUid(?string $ncUid)
 * @method bool isProvisioned()
 * @method void setProvisioned(bool $provisioned)
 * @method string getProvisionMode()
 * @method void setProvisionMode(string $provisionMode)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class UserLink extends Entity {
	protected string $email = '';
	protected ?string $ncUid = null;
	protected bool $provisioned = false;
	protected string $provisionMode = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('email', Types::STRING);
		$this->addType('ncUid', Types::STRING);
		$this->addType('provisioned', Types::BOOLEAN);
		$this->addType('provisionMode', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
