<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Service;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Db\UserLinkMapper;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;

final class ProvisioningService {
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly UserLinkMapper $userLinkMapper,
		private readonly IAppConfig $appConfig,
	) {
	}

	/**
	 * @return array{status:'matched'|'created'|'unresolved',uid:?string,reason:?string}
	 */
	public function resolve(string $email, string $displayName, string $eventId): array {
		$normalizedEmail = strtolower(trim($email));
		if (
			$normalizedEmail === ''
			|| strlen($normalizedEmail) > 255
			|| filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false
		) {
			return $this->unresolved($normalizedEmail, 'invalid_email');
		}

		$cached = $this->userLinkMapper->findByEmail($normalizedEmail);
		if ($cached !== null && $cached->getNcUid() !== null && $cached->getNcUid() !== '') {
			return [
				'status' => 'matched',
				'uid' => $cached->getNcUid(),
				'reason' => null,
			];
		}

		$matches = $this->exactMatches($normalizedEmail);
		if (count($matches) > 1) {
			return $this->unresolved($normalizedEmail, 'multiple_email_matches');
		}
		if (count($matches) === 1) {
			$user = reset($matches);
			$uid = $this->uid($user);
			if ($uid === null) {
				return $this->unresolved($normalizedEmail, 'invalid_user_match');
			}

			$this->userLinkMapper->upsertOutcome($normalizedEmail, $uid, false, 'matched');
			return [
				'status' => 'matched',
				'uid' => $uid,
				'reason' => null,
			];
		}

		if (!$this->provisioningEnabled()) {
			return $this->unresolved($normalizedEmail, 'provisioning_disabled');
		}
		if ($this->provisioningMode() !== 'create') {
			return $this->unresolved($normalizedEmail, 'unsupported_provisioning_mode');
		}

		return $this->create($normalizedEmail, trim($displayName));
	}

	/**
	 * @return array<string, IUser>
	 */
	private function exactMatches(string $email): array {
		$found = $this->userManager->getByEmail($email);
		if (!is_array($found)) {
			return [];
		}

		$matches = [];
		foreach ($found as $user) {
			if (!$user instanceof IUser) {
				continue;
			}
			$userEmail = $user->getEMailAddress();
			if (!is_string($userEmail) || strtolower(trim($userEmail)) !== $email) {
				continue;
			}
			$uid = $this->uid($user);
			if ($uid !== null) {
				$matches[$uid] = $user;
			}
		}
		ksort($matches, SORT_STRING);

		return $matches;
	}

	/**
	 * @return array{status:'created'|'unresolved',uid:?string,reason:?string}
	 */
	private function create(string $email, string $displayName): array {
		$uid = 'moodle-' . substr(hash('sha256', $email), 0, 32);

		try {
			$user = $this->userManager->get($uid);
			if (!$user instanceof IUser) {
				$password = bin2hex(random_bytes(32));
				try {
					$created = $this->userManager->createUser($uid, $password);
				} finally {
					$password = '';
				}
				if (!$created instanceof IUser) {
					return $this->unresolved($email, 'account_creation_failed');
				}
				$user = $created;
			}

			if ($displayName !== '') {
				$user->setDisplayName($displayName);
			}
			$user->setSystemEMailAddress($email);
		} catch (\Throwable) {
			return $this->unresolved($email, 'account_creation_failed');
		}

		$createdUid = $this->uid($user);
		if ($createdUid === null) {
			return $this->unresolved($email, 'account_creation_failed');
		}
		$this->userLinkMapper->upsertOutcome($email, $createdUid, true, 'created');

		return [
			'status' => 'created',
			'uid' => $createdUid,
			'reason' => null,
		];
	}

	/**
	 * @return array{status:'unresolved',uid:null,reason:string}
	 */
	private function unresolved(string $email, string $reason): array {
		if ($email !== '' && strlen($email) <= 255) {
			$this->userLinkMapper->upsertOutcome($email, null, false, 'unresolved');
		}

		return [
			'status' => 'unresolved',
			'uid' => null,
			'reason' => $reason,
		];
	}

	private function uid(IUser $user): ?string {
		$uid = $user->getUID();
		return is_string($uid) && $uid !== '' ? $uid : null;
	}

	private function provisioningEnabled(): bool {
		return $this->appConfig->getValueBool(
			Application::APP_ID,
			'provisioning_enabled',
			false,
			true,
		);
	}

	private function provisioningMode(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'provisioning_mode',
			'create',
			true,
		);
	}
}
