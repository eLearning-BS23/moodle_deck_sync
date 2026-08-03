<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Middleware;

use OCA\MoodleDeckSync\Middleware\SignatureMiddleware;
use OCA\MoodleDeckSync\Service\ConfigService;
use OCA\MoodleDeckSync\Service\WebhookEvent;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SignatureMiddlewareTest extends TestCase {
	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function requiredHeaderProvider(): iterable {
		foreach ([
			'X-Moodle-Signature',
			'X-Moodle-Timestamp',
			'X-Moodle-Nonce',
			'X-Moodle-Event-Id',
			'X-Moodle-Instance',
		] as $header) {
			yield $header => [$header];
		}
	}

	public function testEverySignedWebhookFixtureVerifiesFromExactRawBytes(): void {
		foreach ($this->signedWebhookFixtures() as $fixture) {
			$cache = new MemoryPlatformCache();
			$middleware = $this->middleware(
				$fixture['headers'],
				(int)$fixture['headers']['X-Moodle-Timestamp'],
				$cache,
				$fixture['secret'],
			);

			$event = $middleware->verify($fixture['raw']);

			self::assertInstanceOf(WebhookEvent::class, $event);
			self::assertSame($fixture['name'], $event->event());
			self::assertSame($fixture['headers']['X-Moodle-Event-Id'], $event->eventId());
			self::assertSame($fixture['headers']['X-Moodle-Instance'], $event->instance());
			self::assertArrayHasKey('id', $event->course());
			self::assertNotSame([], $event->payload());
		}
	}

	public function testEverySignedFixtureAuthenticatesFromExactRawBytes(): void {
		foreach ($this->allSignedFixtures() as $fixture) {
			$middleware = $this->middleware(
				$fixture['headers'],
				(int)$fixture['headers']['X-Moodle-Timestamp'],
				new MemoryPlatformCache(),
				$fixture['secret'],
			);

			$headers = $middleware->authenticate($fixture['raw']);

			self::assertSame($fixture['headers']['X-Moodle-Event-Id'], $headers['X-Moodle-Event-Id']);
		}
	}

	#[DataProvider('requiredHeaderProvider')]
	public function testMissingRequiredHeaderRejectsWithoutDispatch(string $missing): void {
		$fixture = $this->fixture('group_created');
		unset($fixture['headers'][$missing]);

		$this->assertRejectedWithoutDispatch($fixture, 401);
	}

	public function testUnknownInstanceRejectsWithoutDispatch(): void {
		$fixture = $this->fixture('group_created');
		$fixture['headers']['X-Moodle-Instance'] = 'https://unknown.invalid';

		$this->assertRejectedWithoutDispatch($fixture, 401);
	}

	public function testMalformedTimestampRejectsWithoutDispatch(): void {
		$fixture = $this->fixture('group_created');
		$fixture['headers']['X-Moodle-Timestamp'] = 'not-a-timestamp';

		$this->assertRejectedWithoutDispatch($fixture, 401);
	}

	public function testTimestampOutsideFiveMinuteWindowRejectsWithoutDispatch(): void {
		$fixture = $this->fixture('group_created');

		$this->assertRejectedWithoutDispatch(
			$fixture,
			401,
			(int)$fixture['headers']['X-Moodle-Timestamp'] + 301,
		);
	}

	public function testChangedRawBodyRejectsWithoutDispatch(): void {
		$fixture = $this->fixture('group_created');
		$fixture['raw'] .= ' ';

		$this->assertRejectedWithoutDispatch($fixture, 401);
	}

	public function testBadSignatureRejectsWithoutClaimingNonceOrDispatching(): void {
		$fixture = $this->fixture('group_created');
		$cache = new MemoryPlatformCache();
		$bad = $fixture;
		$bad['headers']['X-Moodle-Signature'] = 'sha256=' . str_repeat('0', 64);
		$middleware = $this->middleware(
			$bad['headers'],
			(int)$bad['headers']['X-Moodle-Timestamp'],
			$cache,
			$bad['secret'],
		);
		$dispatches = 0;

		try {
			$middleware->verify($bad['raw']);
			$dispatches++;
			self::fail('Expected bad signature to be rejected');
		} catch (\RuntimeException $exception) {
			self::assertSame(401, $exception->getCode());
		}

		self::assertSame(0, $dispatches);
		$valid = $this->middleware(
			$fixture['headers'],
			(int)$fixture['headers']['X-Moodle-Timestamp'],
			$cache,
			$fixture['secret'],
		);
		self::assertInstanceOf(WebhookEvent::class, $valid->verify($fixture['raw']));
	}

	public function testReplayedNonceRejectsWithoutSecondDispatch(): void {
		$fixture = $this->fixture('group_created');
		$cache = new MemoryPlatformCache();
		$middleware = $this->middleware(
			$fixture['headers'],
			(int)$fixture['headers']['X-Moodle-Timestamp'],
			$cache,
			$fixture['secret'],
		);
		$dispatches = 0;

		$middleware->verify($fixture['raw']);
		$dispatches++;

		try {
			$middleware->verify($fixture['raw']);
			$dispatches++;
			self::fail('Expected replay to be rejected');
		} catch (\RuntimeException $exception) {
			self::assertSame(401, $exception->getCode());
		}

		self::assertSame(1, $dispatches);
	}

	public function testHeaderBodyIdentityMismatchRejectsWithoutDispatch(): void {
		$fixture = $this->fixture('group_created');
		$fixture['headers']['X-Moodle-Event-Id'] = 'evt_different';

		$this->assertRejectedWithoutDispatch($fixture, 400);
	}

	public function testInvalidJsonRejectsWithoutDispatchAfterValidHmac(): void {
		$fixture = $this->fixture('group_created');
		$fixture['raw'] = '{"event":';
		$fixture['headers']['X-Moodle-Nonce'] = '11111111111111111111111111111111';
		$fixture['headers']['X-Moodle-Signature'] = $this->sign(
			$fixture['secret'],
			$fixture['headers'],
			$fixture['raw'],
		);

		$this->assertRejectedWithoutDispatch($fixture, 400);
	}

	public function testInvalidEventRejectsWithoutDispatchAfterValidHmac(): void {
		$fixture = $this->fixture('group_created');
		$body = json_decode($fixture['raw'], true, 512, JSON_THROW_ON_ERROR);
		$body['event'] = 'unsupported_event';
		$fixture['raw'] = json_encode(
			$body,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);
		$fixture['headers']['X-Moodle-Nonce'] = '22222222222222222222222222222222';
		$fixture['headers']['X-Moodle-Signature'] = $this->sign(
			$fixture['secret'],
			$fixture['headers'],
			$fixture['raw'],
		);

		$this->assertRejectedWithoutDispatch($fixture, 400);
	}

	public function testRateLimitIsExactlyThreeHundredPerInstancePerSixtySeconds(): void {
		$fixture = $this->fixture('group_created');
		$cache = new MemoryPlatformCache();
		$now = (int)$fixture['headers']['X-Moodle-Timestamp'];
		$dispatches = 0;

		for ($request = 1; $request <= 301; $request++) {
			$current = $fixture;
			$current['headers']['X-Moodle-Nonce'] = substr(hash('sha256', 'rate-' . $request), 0, 32);
			$current['headers']['X-Moodle-Signature'] = $this->sign(
				$current['secret'],
				$current['headers'],
				$current['raw'],
			);
			$middleware = $this->middleware($current['headers'], $now, $cache, $current['secret']);

			if ($request <= 300) {
				$middleware->verify($current['raw']);
				$dispatches++;
				continue;
			}

			try {
				$middleware->verify($current['raw']);
				$dispatches++;
				self::fail('Expected request 301 to be rate limited');
			} catch (\RuntimeException $exception) {
				self::assertSame(429, $exception->getCode());
				$response = $middleware->afterException(
					new TestController($this->request($current['headers'])),
					'receive',
					$exception,
				);
				self::assertSame(429, $response->getStatus());
				$headers = (
					new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers')
				)->getValue($response);
				self::assertSame('60', $headers['Retry-After']);
			}
		}

		self::assertSame(300, $dispatches);
	}

	public function testRateLimitRunsBeforeJsonParsing(): void {
		$fixture = $this->fixture('group_created');
		$cache = new MemoryPlatformCache();
		$now = (int)$fixture['headers']['X-Moodle-Timestamp'];

		for ($request = 1; $request <= 300; $request++) {
			$current = $fixture;
			$current['headers']['X-Moodle-Nonce'] = substr(hash('sha256', 'valid-' . $request), 0, 32);
			$current['headers']['X-Moodle-Signature'] = $this->sign(
				$current['secret'],
				$current['headers'],
				$current['raw'],
			);
			$this->middleware($current['headers'], $now, $cache, $current['secret'])->verify($current['raw']);
		}

		$invalid = $fixture;
		$invalid['raw'] = '{';
		$invalid['headers']['X-Moodle-Nonce'] = '33333333333333333333333333333333';
		$invalid['headers']['X-Moodle-Signature'] = $this->sign(
			$invalid['secret'],
			$invalid['headers'],
			$invalid['raw'],
		);

		$this->assertRejectedWithoutDispatch($invalid, 429, $now, $cache);
	}

	public function testReplayClaimRunsBeforeRateLimit(): void {
		$fixture = $this->fixture('group_created');
		$cache = new MemoryPlatformCache();
		$now = (int)$fixture['headers']['X-Moodle-Timestamp'];
		$middleware = $this->middleware($fixture['headers'], $now, $cache, $fixture['secret']);
		$middleware->verify($fixture['raw']);

		for ($request = 2; $request <= 300; $request++) {
			$current = $fixture;
			$current['headers']['X-Moodle-Nonce'] = substr(hash('sha256', 'replay-order-' . $request), 0, 32);
			$current['headers']['X-Moodle-Signature'] = $this->sign(
				$current['secret'],
				$current['headers'],
				$current['raw'],
			);
			$this->middleware($current['headers'], $now, $cache, $current['secret'])->verify($current['raw']);
		}

		$this->assertRejectedWithoutDispatch($fixture, 401, $now, $cache);
	}

	public function testMiddlewareBypassesNonIntegrationRoutes(): void {
		$fixture = $this->fixture('group_created');
		$request = $this->request($fixture['headers'], '/apps/moodle_deck_sync/settings');
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::never())->method('createLocking');
		$middleware = new SignatureMiddleware(
			$request,
			$this->config($fixture['secret']),
			$this->time((int)$fixture['headers']['X-Moodle-Timestamp']),
			$cacheFactory,
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\IGroupManager::class),
		);

		$middleware->beforeController(new TestController($request), 'save');
	}

	public function testMiddlewareBypassesAdminSettingsApiRoute(): void {
		$fixture = $this->fixture('group_created');
		$request = $this->request($fixture['headers'], '/apps/moodle_deck_sync/api/v1/settings');
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects(self::never())->method('createLocking');
		$middleware = new SignatureMiddleware(
			$request,
			$this->config($fixture['secret']),
			$this->time((int)$fixture['headers']['X-Moodle-Timestamp']),
			$cacheFactory,
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\IGroupManager::class),
		);

		$middleware->beforeController(new TestController($request), 'getSettings');
	}

	/**
	 * @param array<string, mixed> $fixture
	 */
	private function assertRejectedWithoutDispatch(
		array $fixture,
		int $expectedStatus,
		?int $now = null,
		?MemoryPlatformCache $cache = null,
	): void {
		$middleware = $this->middleware(
			$fixture['headers'],
			$now ?? (int)($fixture['headers']['X-Moodle-Timestamp'] ?? 1753180000),
			$cache ?? new MemoryPlatformCache(),
			$fixture['secret'],
		);
		$dispatches = 0;

		try {
			$middleware->verify($fixture['raw']);
			$dispatches++;
			self::fail('Expected request to be rejected');
		} catch (\RuntimeException $exception) {
			self::assertSame($expectedStatus, $exception->getCode());
		}

		self::assertSame(0, $dispatches);
	}

	public function testHealthRouteAllowsLoggedInAdminWithoutHmacHeaders(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn('/apps/moodle_deck_sync/api/v1/health');

		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$config = new ConfigService($appConfig);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('admin');

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('isLoggedIn')->willReturn(true);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(\OCP\IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$middleware = new SignatureMiddleware(
			$request,
			$config,
			$timeFactory,
			$cacheFactory,
			$userSession,
			$groupManager,
		);

		$controller = $this->createMock(\OCP\AppFramework\Controller::class);
		$middleware->beforeController($controller, 'getHealth');
		self::assertTrue(true);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function signedWebhookFixtures(): array {
		return array_values(array_filter(
			$this->allSignedFixtures(),
			static fn (array $fixture): bool => $fixture['request_body_file'] !== null,
		));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function allSignedFixtures(): array {
		$manifest = json_decode(
			file_get_contents($this->fixturesDir() . '/manifest.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$secret = trim(file_get_contents($this->fixturesDir() . '/secret.txt'));
		$fixtures = [];

		foreach ($manifest['fixtures'] as $fixture) {
			if (empty($fixture['headers']['X-Moodle-Signature'])) {
				continue;
			}
			$fixtures[] = [
				'name' => $fixture['name'],
				'headers' => $fixture['headers'],
				'raw' => $fixture['request_body_file'] === null
					? ''
					: file_get_contents($this->fixturesDir() . '/' . $fixture['request_body_file']),
				'request_body_file' => $fixture['request_body_file'],
				'secret' => $secret,
			];
		}

		return $fixtures;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fixture(string $name): array {
		foreach ($this->signedWebhookFixtures() as $fixture) {
			if ($fixture['name'] === $name) {
				return $fixture;
			}
		}

		throw new \RuntimeException('Fixture not found');
	}

	private function fixturesDir(): string {
		$directory = realpath(__DIR__ . '/../../../../../specs/02-integration/fixtures');
		if ($directory === false) {
			self::fail('Frozen contract fixtures are unavailable');
		}

		return $directory;
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function middleware(
		array $headers,
		int $now,
		MemoryPlatformCache $cache,
		string $secret,
	): SignatureMiddleware {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createLocking')->willReturn($cache);

		return new SignatureMiddleware(
			$this->request($headers),
			$this->config($secret),
			$this->time($now),
			$cacheFactory,
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\IGroupManager::class),
		);
	}

	private function config(string $secret): ConfigService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($secret);
		$appConfig->method('getValueArray')->willReturn(['https://moodle.local']);

		return new ConfigService($appConfig);
	}

	private function time(int $now): ITimeFactory&MockObject {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		return $time;
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function request(
		array $headers,
		string $path = '/apps/moodle_deck_sync/api/v1/webhook',
	): IRequest&MockObject {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $headers[$name] ?? '',
		);
		$request->method('getPathInfo')->willReturn($path);

		return $request;
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function sign(string $secret, array $headers, string $raw): string {
		return 'sha256=' . hash_hmac(
			'sha256',
			$headers['X-Moodle-Timestamp'] . '.' . $headers['X-Moodle-Nonce'] . '.' . $raw,
			$secret,
		);
	}
}

final class TestController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct('moodle_deck_sync', $request);
	}
}

final class MemoryPlatformCache implements IMemcache {
	/** @var array<string, mixed> */
	private array $values = [];

	#[\Override]
	public function get($key): mixed {
		return $this->values[$key] ?? null;
	}

	#[\Override]
	public function set($key, $value, $ttl = 0): bool {
		$this->values[$key] = $value;
		return true;
	}

	#[\Override]
	public function hasKey($key): bool {
		return array_key_exists($key, $this->values);
	}

	#[\Override]
	public function remove($key): bool {
		unset($this->values[$key]);
		return true;
	}

	#[\Override]
	public function clear($prefix = ''): bool {
		foreach (array_keys($this->values) as $key) {
			if ($prefix === '' || str_starts_with($key, $prefix)) {
				unset($this->values[$key]);
			}
		}
		return true;
	}

	#[\Override]
	public static function isAvailable(): bool {
		return true;
	}

	#[\Override]
	public function add($key, $value, $ttl = 0): bool {
		if (array_key_exists($key, $this->values)) {
			return false;
		}
		$this->values[$key] = $value;
		return true;
	}

	#[\Override]
	public function inc($key, $step = 1): int|bool {
		$current = $this->values[$key] ?? 0;
		if (!is_int($current)) {
			return false;
		}
		$this->values[$key] = $current + $step;
		return $this->values[$key];
	}

	#[\Override]
	public function dec($key, $step = 1): int|bool {
		$current = $this->values[$key] ?? null;
		if (!is_int($current)) {
			return false;
		}
		$this->values[$key] = $current - $step;
		return $this->values[$key];
	}

	#[\Override]
	public function cas($key, $old, $new): bool {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}
		$this->values[$key] = $new;
		return true;
	}

	#[\Override]
	public function cad($key, $old): bool {
		if (($this->values[$key] ?? null) !== $old) {
			return false;
		}
		unset($this->values[$key]);
		return true;
	}

	#[\Override]
	public function ncad(string $key, mixed $old): bool {
		if (!array_key_exists($key, $this->values) || $this->values[$key] === $old) {
			return false;
		}
		unset($this->values[$key]);
		return true;
	}
}
