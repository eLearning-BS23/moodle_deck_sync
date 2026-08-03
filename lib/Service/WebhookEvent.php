<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Service;

final class WebhookEvent {
	private const EVENTS = [
		'course_module_created' => true,
		'group_created' => true,
		'group_deleted' => true,
		'group_member_added' => true,
		'group_member_removed' => true,
	];

	/**
	 * @param array{id:int,shortname:string,fullname:string} $course
	 * @param array<string, mixed> $payload
	 */
	private function __construct(
		private readonly string $event,
		private readonly string $eventId,
		private readonly string $instance,
		private readonly array $course,
		private readonly array $payload,
		private readonly int $occurredAt = 0,
	) {
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function fromArray(array $body): self {
		self::requireKeys($body, ['event', 'event_id', 'occurred_at', 'instance', 'course', 'payload']);

		if (!is_string($body['event']) || !isset(self::EVENTS[$body['event']])) {
			throw new \InvalidArgumentException('Unsupported webhook event');
		}
		if (!is_string($body['event_id']) || $body['event_id'] === '' || strlen($body['event_id']) > 64) {
			throw new \InvalidArgumentException('Invalid event identity');
		}
		if (!is_int($body['occurred_at']) || $body['occurred_at'] <= 0) {
			throw new \InvalidArgumentException('Invalid event timestamp');
		}
		if (!is_string($body['instance']) || !self::isAllowedInstanceUrl($body['instance'])) {
			throw new \InvalidArgumentException('Invalid Moodle instance');
		}
		if (!is_array($body['course']) || !is_array($body['payload'])) {
			throw new \InvalidArgumentException('Invalid webhook envelope');
		}

		$course = self::validatedCourse($body['course']);
		self::validatePayload($body['event'], $body['payload']);

		return new self(
			$body['event'],
			$body['event_id'],
			$body['instance'],
			$course,
			$body['payload'],
			$body['occurred_at'],
		);
	}

	public function event(): string {
		return $this->event;
	}

	public function eventType(): string {
		return $this->event;
	}

	public function eventId(): string {
		return $this->eventId;
	}

	public function instance(): string {
		return $this->instance;
	}

	/**
	 * @return array{id:int,shortname:string,fullname:string}
	 */
	public function course(): array {
		return $this->course;
	}

	public function courseId(): int {
		return $this->course['id'];
	}

	public function groupId(): int {
		return (int)($this->payload['group']['id'] ?? 0);
	}

	public function cmid(): ?int {
		return isset($this->payload['assignment']['cmid']) ? (int)$this->payload['assignment']['cmid'] : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function group(): array {
		$group = $this->payload['group'] ?? [];
		return is_array($group) ? $group : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assignment(): array {
		$assignment = $this->payload['assignment'] ?? [];
		return is_array($assignment) ? $assignment : [];
	}

	public function occurredAt(): int {
		return $this->occurredAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * @param array<string, mixed> $course
	 * @return array{id:int,shortname:string,fullname:string}
	 */
	private static function validatedCourse(array $course): array {
		self::requireKeys($course, ['id', 'shortname', 'fullname']);
		if (!is_int($course['id']) || $course['id'] <= 0
			|| !self::isNonEmptyString($course['shortname'])
			|| !self::isNonEmptyString($course['fullname'])) {
			throw new \InvalidArgumentException('Invalid course');
		}

		return [
			'id' => $course['id'],
			'shortname' => $course['shortname'],
			'fullname' => $course['fullname'],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function validatePayload(string $event, array $payload): void {
		switch ($event) {
			case 'group_created':
				self::requireKeys($payload, ['group', 'assignment', 'members']);
				self::validateGroup($payload['group'], true);
				self::validateAssignment($payload['assignment'], false);
				if (!is_array($payload['members']) || !array_is_list($payload['members'])) {
					throw new \InvalidArgumentException('Invalid members');
				}
				foreach ($payload['members'] as $member) {
					self::member($member, true);
				}
				break;

			case 'group_deleted':
				self::requireKeys($payload, ['group']);
				self::validateGroup($payload['group'], false);
				break;

			case 'group_member_added':
				self::requireKeys($payload, ['group', 'member']);
				self::validateGroup($payload['group'], false);
				self::member($payload['member'], true);
				break;

			case 'group_member_removed':
				self::requireKeys($payload, ['group', 'member']);
				self::validateGroup($payload['group'], false);
				self::member($payload['member'], false);
				break;

			case 'course_module_created':
				self::requireKeys($payload, ['assignment', 'group', 'deck_enabled']);
				self::validateAssignment($payload['assignment'], true);
				self::validateGroup($payload['group'], false);
				if ($payload['deck_enabled'] !== true) {
					throw new \InvalidArgumentException('Invalid Deck opt-in');
				}
				break;
		}
	}

	private static function validateGroup(mixed $group, bool $withDetails): void {
		if (!is_array($group)) {
			throw new \InvalidArgumentException('Invalid group');
		}
		self::requireKeys($group, $withDetails ? ['id', 'name', 'description'] : ['id']);
		if (!is_int($group['id']) || $group['id'] <= 0) {
			throw new \InvalidArgumentException('Invalid group');
		}
		if ($withDetails
			&& (!self::isNonEmptyString($group['name']) || !is_string($group['description']))) {
			throw new \InvalidArgumentException('Invalid group');
		}
	}

	private static function validateAssignment(mixed $assignment, bool $withDueDate): void {
		if (!is_array($assignment)) {
			throw new \InvalidArgumentException('Invalid assignment');
		}
		self::requireKeys($assignment, $withDueDate ? ['cmid', 'title', 'duedate'] : ['cmid', 'title']);
		if (!is_int($assignment['cmid']) || $assignment['cmid'] <= 0
			|| !self::isNonEmptyString($assignment['title'])) {
			throw new \InvalidArgumentException('Invalid assignment');
		}
		if ($withDueDate && (!is_int($assignment['duedate']) || $assignment['duedate'] < 0)) {
			throw new \InvalidArgumentException('Invalid assignment');
		}
	}

	private static function member(mixed $member, bool $withRole): void {
		if (!is_array($member)) {
			throw new \InvalidArgumentException('Invalid member');
		}
		self::requireKeys($member, $withRole ? ['userid', 'email', 'name', 'role'] : ['userid', 'email']);
		if (!is_int($member['userid']) || $member['userid'] <= 0
			|| !is_string($member['email'])
			|| filter_var($member['email'], FILTER_VALIDATE_EMAIL) === false) {
			throw new \InvalidArgumentException('Invalid member');
		}
		if (!$withRole) {
			return;
		}
		if (!self::isNonEmptyString($member['name'])
			|| !is_string($member['role'])
			|| !in_array($member['role'], ['manager', 'editingteacher', 'teacher', 'student'], true)) {
			throw new \InvalidArgumentException('Invalid member');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $keys
	 */
	private static function requireKeys(array $data, array $keys): void {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $data)) {
				throw new \InvalidArgumentException('Missing webhook field');
			}
		}
	}

	private static function isNonEmptyString(mixed $value): bool {
		return is_string($value) && $value !== '';
	}

	private static function isAllowedInstanceUrl(string $value): bool {
		if (strlen($value) > 255 || filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);
		if ($scheme === 'https') {
			return true;
		}
		if ($scheme !== 'http') {
			return false;
		}

		$host = parse_url($value, PHP_URL_HOST);
		return in_array($host, ['localhost', '127.0.0.1', 'moodle-web'], true);
	}
}
