<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Deck;

final class DeckRequestException extends \RuntimeException {
	public function __construct(
		string $message,
		private readonly int $httpStatus,
		private readonly bool $retryable,
	) {
		parent::__construct($message);
	}

	public function httpStatus(): int {
		return $this->httpStatus;
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}
}
