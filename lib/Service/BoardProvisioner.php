<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Service;

use OCA\MoodleDeckSync\Db\SyncMap;
use OCA\MoodleDeckSync\Db\SyncMapMapper;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCA\MoodleDeckSync\Notification\Notifier;

final class BoardProvisioner {
	public function __construct(
		private readonly SyncMapMapper $syncMapMapper,
		private readonly HttpDeckClient $deckClient,
		private readonly ProvisioningService $provisioningService,
		private readonly ConfigService $configService,
		private readonly Notifier $notifier,
	) {
	}

	public function provision(WebhookEvent $event): SyncResult {
		$map = $this->syncMapMapper->upsertPending(
			$event->instance(),
			$event->courseId(),
			$event->groupId(),
			$event->cmid(),
		);

		$boardId = $map->getBoardId();
		$isNewBoard = false;
		$title = $this->formatBoardTitle($event);

		if ($boardId === null) {
			$created = $this->deckClient->createBoard($title, '0082c9');
			$boardId = $created['id'];
			$isNewBoard = true;

			$defaultStacks = $this->configService->defaultStacks();
			foreach ($defaultStacks as $index => $stackTitle) {
				$this->deckClient->createStack($boardId, $stackTitle, $index);
			}

			$this->syncMapMapper->markActive($map->getId(), $boardId);
		}

		$boardUrl = $this->buildBoardUrl($boardId);
		$counts = $this->reconcileMembersForBoard($boardId, $event, $isNewBoard, $title);

		return SyncResult::applied($boardId, $boardUrl, $counts);
	}

	public function reconcileMember(WebhookEvent $event): SyncResult {
		$maps = $this->findTargetMaps($event);
		if (empty($maps)) {
			return SyncResult::skipped(null, null);
		}

		$firstBoardId = null;
		$firstBoardUrl = null;
		$totalCounts = ['resolved' => 0, 'provisioned' => 0, 'unresolved' => 0];

		foreach ($maps as $map) {
			$boardId = $map->getBoardId();
			if ($boardId === null || $map->getStatus() === 'archived' || $map->getStatus() === 'purged') {
				continue;
			}

			if ($firstBoardId === null) {
				$firstBoardId = $boardId;
				$firstBoardUrl = $this->buildBoardUrl($boardId);
			}

			$notifyBoardReady = $event->eventType() === 'group_member_added';
			$counts = $this->reconcileMembersForBoard(
				$boardId,
				$event,
				$notifyBoardReady,
				$this->formatBoardTitle($event),
			);
			$totalCounts['resolved'] += $counts['resolved'] ?? 0;
			$totalCounts['provisioned'] += $counts['provisioned'] ?? 0;
			$totalCounts['unresolved'] += $counts['unresolved'] ?? 0;
		}

		if ($firstBoardId === null) {
			return SyncResult::skipped(null, null);
		}

		return SyncResult::applied($firstBoardId, $firstBoardUrl, $totalCounts);
	}

	public function archive(WebhookEvent $event): SyncResult {
		$maps = $this->syncMapMapper->findAllByGroup(
			$event->instance(),
			$event->courseId(),
			$event->groupId(),
		);

		if (empty($maps)) {
			return SyncResult::skipped(null, null);
		}

		$archivedCount = 0;
		$firstBoardId = null;

		foreach ($maps as $map) {
			if ($map->getStatus() === 'archived' || $map->getStatus() === 'purged') {
				continue;
			}

			$boardId = $map->getBoardId();
			if ($boardId !== null) {
				$this->deckClient->archiveBoard($boardId);
				if ($firstBoardId === null) {
					$firstBoardId = $boardId;
				}
			}

			$this->syncMapMapper->markArchived($map->getId(), $event->occurredAt());
			$archivedCount++;
		}

		if ($archivedCount === 0) {
			return SyncResult::skipped(null, null);
		}

		return SyncResult::applied($firstBoardId, $firstBoardId !== null ? $this->buildBoardUrl($firstBoardId) : null, []);
	}

	/**
	 * @return list<SyncMap>
	 */
	private function findTargetMaps(WebhookEvent $event): array {
		if ($event->cmid() !== null) {
			$map = $this->syncMapMapper->findBySourceKey(
				$event->instance(),
				$event->courseId(),
				$event->groupId(),
				$event->cmid(),
			);
			if ($map !== null) {
				return [$map];
			}
		}

		return $this->syncMapMapper->findAllByGroup(
			$event->instance(),
			$event->courseId(),
			$event->groupId(),
		);
	}

	/**
	 * @return array{resolved:int,provisioned:int,unresolved:int}
	 */
	private function reconcileMembersForBoard(
		int $boardId,
		WebhookEvent $event,
		bool $notifyBoardReady = false,
		string $boardTitle = '',
	): array {
		$currentAclList = $this->deckClient->listAcl($boardId);
		$currentAcl = [];
		$duplicateAclIds = [];
		foreach ($currentAclList as $entry) {
			if (!isset($entry['participant'], $entry['id'])) {
				continue;
			}
			if (isset($currentAcl[$entry['participant']])) {
				$duplicateAclIds[] = (int)$entry['id'];
				continue;
			}
			$currentAcl[$entry['participant']] = $entry;
		}

		$membersToProcess = [];
		$payload = $event->payload();
		$hasDesiredState = isset($payload['members']) && is_array($payload['members']) && array_is_list($payload['members']);

		if (!$hasDesiredState && $event->eventType() === 'group_member_removed') {
			$member = $payload['member'] ?? null;
			if (is_array($member) && isset($member['email'])) {
				$res = $this->provisioningService->resolve((string)$member['email'], '', $event->eventId());
				if ($res['status'] !== 'unresolved' && $res['uid'] !== null) {
					$uid = $res['uid'];
					if (isset($currentAcl[$uid])) {
						$this->deckClient->removeAcl($boardId, (int)$currentAcl[$uid]['id']);
					}
				}
			}
			return ['resolved' => 0, 'provisioned' => 0, 'unresolved' => 0];
		}

		if ($hasDesiredState) {
			$membersToProcess = $payload['members'];
		} elseif ($event->eventType() === 'group_member_added') {
			$member = $payload['member'] ?? null;
			if (is_array($member)) {
				$membersToProcess[] = $member;
			}
		}

		$resolvedCount = 0;
		$provisionedCount = 0;
		$unresolvedCount = 0;
		$desiredAcl = [];

		foreach ($membersToProcess as $memberData) {
			if (!is_array($memberData) || !isset($memberData['email'])) {
				continue;
			}

			$email = (string)$memberData['email'];
			$name = (string)($memberData['name'] ?? '');
			$role = (string)($memberData['role'] ?? 'student');

			$res = $this->provisioningService->resolve($email, $name, $event->eventId());
			if ($res['status'] === 'unresolved' || $res['uid'] === null) {
				$unresolvedCount++;
				continue;
			}

			if ($res['status'] === 'created') {
				$provisionedCount++;
			} else {
				$resolvedCount++;
			}

			$uid = $res['uid'];
			$desiredPermissions = $this->permissionsForRole($role);
			$desiredAcl[$uid] = $desiredPermissions;

			if (!isset($currentAcl[$uid])) {
				$this->deckClient->addAcl($boardId, $uid, $desiredPermissions);
			} else {
				$existing = $currentAcl[$uid];
				if (
					$existing['permissionEdit'] !== $desiredPermissions['permissionEdit']
					|| $existing['permissionShare'] !== $desiredPermissions['permissionShare']
					|| $existing['permissionManage'] !== $desiredPermissions['permissionManage']
				) {
					$this->deckClient->updateAcl($boardId, (int)$existing['id'], $desiredPermissions);
				}
			}

			if ($notifyBoardReady) {
				$this->notifier->notifyBoardReady($uid, $boardId, $boardTitle);
			}
		}

		foreach ($duplicateAclIds as $aclId) {
			$this->deckClient->removeAcl($boardId, $aclId);
		}

		if ($hasDesiredState) {
			foreach ($currentAcl as $uid => $entry) {
				if (!isset($desiredAcl[$uid])) {
					$this->deckClient->removeAcl($boardId, (int)$entry['id']);
				}
			}
		}

		return [
			'resolved' => $resolvedCount,
			'provisioned' => $provisionedCount,
			'unresolved' => $unresolvedCount,
		];
	}

	/**
	 * @return array{permissionEdit:bool,permissionShare:bool,permissionManage:bool}
	 */
	private function permissionsForRole(string $role): array {
		if ($role === 'editingteacher' || $role === 'teacher' || $role === 'manager' || $role === 'owner') {
			return [
				'permissionEdit' => true,
				'permissionShare' => true,
				'permissionManage' => true,
			];
		}

		return [
			'permissionEdit' => true,
			'permissionShare' => false,
			'permissionManage' => false,
		];
	}

	private function formatBoardTitle(WebhookEvent $event): string {
		$template = $this->configService->boardNameTemplate();
		$courseName = $event->course()['shortname'] ?? 'Course';
		$groupName = $event->group()['name'] ?? 'Group';
		$assignmentTitle = $event->assignment()['title'] ?? 'Assignment';

		$title = str_replace(
			['{course}', '{group}', '{assignment}'],
			[$courseName, $groupName, $assignmentTitle],
			$template,
		);

		return substr($title, 0, 255);
	}

	private function buildBoardUrl(int $boardId): string {
		return '/apps/deck/#/board/' . $boardId;
	}
}
