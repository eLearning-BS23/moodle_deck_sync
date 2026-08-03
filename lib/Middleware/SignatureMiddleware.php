<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Middleware;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Service\ConfigService;
use OCA\MoodleDeckSync\Service\WebhookEvent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\IUserSession;

final class SignatureMiddleware extends Middleware {
	private const REQUIRED_HEADERS = [
		'X-Moodle-Signature',
		'X-Moodle-Timestamp',
		'X-Moodle-Nonce',
		'X-Moodle-Event-Id',
		'X-Moodle-Instance',
	];
	private const TIMESTAMP_WINDOW_SECONDS = 300;
	private const NONCE_TTL_SECONDS = 300;
	private const RATE_LIMIT = 300;
	private const RATE_WINDOW_SECONDS = 60;
	private const CACHE_PREFIX = Application::APP_ID . ':security:';

	public function __construct(
		private readonly IRequest $request,
		private readonly ConfigService $config,
		private readonly ITimeFactory $timeFactory,
		private readonly ICacheFactory $cacheFactory,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		if (!$this->isIntegrationRoute()) {
			return;
		}

		$rawBody = file_get_contents('php://input');
		if ($rawBody === false) {
			$this->reject(Http::STATUS_BAD_REQUEST, 'body_unavailable');
		}

		if ($this->isWebhookRoute()) {
			$this->verify($rawBody);
			return;
		}

		if ($this->isHealthRoute()) {
			if ($this->userSession->isLoggedIn()) {
				$user = $this->userSession->getUser();
				if ($user !== null && $this->groupManager->isAdmin($user->getUID())) {
					return;
				}
			}
		}

		$this->authenticate($rawBody);
	}

	/**
	 * Authenticate exact request bytes before any body parsing.
	 *
	 * @return array<string, string> Trusted identity headers.
	 */
	public function authenticate(string $rawBody): array {
		$headers = $this->requiredHeaders();
		$instance = $headers['X-Moodle-Instance'];
		if (!in_array($instance, $this->config->allowedMoodleInstances(), true)) {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'instance_not_allowed');
		}

		$timestamp = filter_var($headers['X-Moodle-Timestamp'], FILTER_VALIDATE_INT);
		$now = $this->timeFactory->getTime();
		if (!is_int($timestamp) || abs($now - $timestamp) > self::TIMESTAMP_WINDOW_SECONDS) {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'timestamp_invalid');
		}

		$nonce = $headers['X-Moodle-Nonce'];
		$signature = $headers['X-Moodle-Signature'];
		$eventId = $headers['X-Moodle-Event-Id'];
		if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
			|| preg_match('/^sha256=[a-f0-9]{64}$/', $signature) !== 1
			|| preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $eventId) !== 1) {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'header_invalid');
		}

		$secret = $this->config->sharedSecret();
		if ($secret === '') {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'secret_unconfigured');
		}
		$expected = 'sha256=' . hash_hmac(
			'sha256',
			$headers['X-Moodle-Timestamp'] . '.' . $nonce . '.' . $rawBody,
			$secret,
		);
		if (!hash_equals($expected, $signature)) {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'signature_invalid');
		}

		$cache = $this->cacheFactory->createLocking(self::CACHE_PREFIX);
		$nonceKey = 'nonce:' . hash('sha256', $instance . "\0" . $nonce);
		if (!$cache->add($nonceKey, 1, self::NONCE_TTL_SECONDS)) {
			$this->reject(Http::STATUS_UNAUTHORIZED, 'nonce_replayed');
		}

		$this->enforceRateLimit($cache, $instance, $now);
		return $headers;
	}

	public function verify(string $rawBody): WebhookEvent {
		$headers = $this->authenticate($rawBody);
		$instance = $headers['X-Moodle-Instance'];
		$eventId = $headers['X-Moodle-Event-Id'];
		try {
			$body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->reject(Http::STATUS_BAD_REQUEST, 'json_invalid');
		}
		if (!is_array($body)) {
			$this->reject(Http::STATUS_BAD_REQUEST, 'json_invalid');
		}
		if (($body['instance'] ?? null) !== $instance || ($body['event_id'] ?? null) !== $eventId) {
			$this->reject(Http::STATUS_BAD_REQUEST, 'identity_mismatch');
		}

		try {
			return WebhookEvent::fromArray($body);
		} catch (\InvalidArgumentException) {
			$this->reject(Http::STATUS_BAD_REQUEST, 'event_invalid');
		}
	}

	#[\Override]
	public function afterException(
		Controller $controller,
		string $methodName,
		\Exception $exception,
	): Response {
		if (!$this->isIntegrationRoute()
			|| !$exception instanceof \RuntimeException
			|| !in_array($exception->getCode(), [
				Http::STATUS_BAD_REQUEST,
				Http::STATUS_UNAUTHORIZED,
				Http::STATUS_TOO_MANY_REQUESTS,
			], true)) {
			throw $exception;
		}

		$status = $exception->getCode();
		$headers = $status === Http::STATUS_TOO_MANY_REQUESTS
			? ['Retry-After' => (string)self::RATE_WINDOW_SECONDS]
			: [];
		$message = match ($status) {
			Http::STATUS_UNAUTHORIZED => 'Request authentication failed',
			Http::STATUS_TOO_MANY_REQUESTS => 'Rate limit exceeded',
			default => 'Invalid webhook request',
		};

		return new JSONResponse([
			'ocs' => [
				'meta' => [
					'status' => 'failure',
					'statuscode' => $status,
					'message' => $message,
				],
			],
		], $status, $headers);
	}

	/**
	 * @return array<string, string>
	 */
	private function requiredHeaders(): array {
		$headers = [];
		foreach (self::REQUIRED_HEADERS as $name) {
			$value = $this->request->getHeader($name);
			if ($value === '' || trim($value) !== $value) {
				$this->reject(Http::STATUS_UNAUTHORIZED, 'header_missing');
			}
			$headers[$name] = $value;
		}

		return $headers;
	}

	private function enforceRateLimit(IMemcache $cache, string $instance, int $now): void {
		$bucket = intdiv($now, self::RATE_WINDOW_SECONDS);
		$key = 'rate:' . hash('sha256', $instance) . ':' . $bucket;
		$cache->add($key, 0, self::RATE_WINDOW_SECONDS);
		$count = $cache->inc($key);
		if (!is_int($count) || $count > self::RATE_LIMIT) {
			$this->reject(Http::STATUS_TOO_MANY_REQUESTS, 'rate_limited');
		}
	}

	private function isIntegrationRoute(): bool {
		$path = $this->request->getPathInfo();
		if (!is_string($path)) {
			return false;
		}

		return preg_match(
			'#^/(?:ocs/v2\.php/)?apps/' . preg_quote(Application::APP_ID, '#') . '/api/v1/(?:webhook|health)$#',
			$path,
		) === 1;
	}

	private function isWebhookRoute(): bool {
		$path = $this->request->getPathInfo();
		return is_string($path) && str_ends_with($path, '/api/v1/webhook');
	}

	private function isHealthRoute(): bool {
		$path = $this->request->getPathInfo();
		return is_string($path) && str_ends_with($path, '/api/v1/health');
	}

	private function reject(int $status, string $safeCode): never {
		throw new \RuntimeException($safeCode, $status);
	}
}
