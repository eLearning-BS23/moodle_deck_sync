<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Service;

final class SyncResult {
	/**
	 * @param array<string, int> $memberCounts
	 */
	private function __construct(
		private readonly string $result,
		private readonly ?int $boardId,
		private readonly ?string $boardUrl,
		private readonly array $memberCounts,
		private readonly ?string $reason,
	) {
	}

	/**
	 * @param array<string, int> $memberCounts
	 */
	public static function applied(?int $boardId, ?string $boardUrl, array $memberCounts): self {
		return new self('applied', $boardId, $boardUrl, $memberCounts, null);
	}

	public static function skipped(?int $boardId, ?string $boardUrl): self {
		return new self('skipped', $boardId, $boardUrl, [], null);
	}

	/**
	 * @param array<string, int> $memberCounts
	 */
	public static function partial(
		?int $boardId,
		?string $boardUrl,
		array $memberCounts,
		string $reason,
	): self {
		return new self('partial', $boardId, $boardUrl, $memberCounts, $reason);
	}

	public static function failed(string $reason): self {
		return new self('failed', null, null, [], $reason);
	}

	public function result(): string {
		return $this->result;
	}

	public function boardId(): ?int {
		return $this->boardId;
	}

	public function boardUrl(): ?string {
		return $this->boardUrl;
	}

	public function safeError(): ?string {
		return $this->reason;
	}

	/**
	 * @return array<string, int>
	 */
	public function memberCounts(): array {
		return $this->memberCounts;
	}
}
