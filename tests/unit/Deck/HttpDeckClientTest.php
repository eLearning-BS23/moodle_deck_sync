<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Deck;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Deck\DeckRequestException;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HttpDeckClientTest extends TestCase {
	private const BASE_URL = 'https://nextcloud.local/index.php/apps/deck/api/v1.1';

	private IClient&MockObject $http;

	/** @var list<IResponse|\Throwable> */
	private array $responses = [];

	/** @var list<array{method:string,url:string,options:array<string,mixed>}> */
	private array $requests = [];

	private HttpDeckClient $client;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->http = $this->createMock(IClient::class);
		$this->http->method('request')->willReturnCallback(
			function (string $method, string $url, array $options): IResponse {
				$this->requests[] = [
					'method' => $method,
					'url' => $url,
					'options' => $options,
				];

				$response = array_shift($this->responses);
				if ($response instanceof \Throwable) {
					throw $response;
				}
				if (!$response instanceof IResponse) {
					throw new \LogicException('No fake Deck response was queued');
				}

				return $response;
			},
		);
		$this->http->method('getResponseFromThrowable')
			->willThrowException(new \RuntimeException('No HTTP response is available'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->http);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nextcloud.local' . $path,
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default, bool $lazy): string {
				self::assertSame(Application::APP_ID, $appId);
				self::assertTrue($lazy);

				return match ($key) {
					'bot_username' => 'moodle-deck-bot',
					'bot_app_password' => 'test-app-password',
					default => $default,
				};
			},
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $appId, string $key, int $default, bool $lazy): int {
				self::assertSame(Application::APP_ID, $appId);
				self::assertSame('request_timeout_seconds', $key);
				self::assertSame(10, $default);
				self::assertTrue($lazy);
				return 10;
			},
		);

		$this->client = new HttpDeckClient($clientService, $urlGenerator, $appConfig);
	}

	public function testBoardCreateArchiveAndSoftDeleteUseCapturedShapes(): void {
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
			'archived' => false,
		]);
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
			'archived' => false,
			'acl' => [],
		]);
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
			'archived' => true,
		]);
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'archived' => true,
			'deletedAt' => 1700000000,
		]);

		self::assertSame(
			['id' => 7001, 'title' => 'Algorithms - Group A', 'color' => '0082c9'],
			$this->client->createBoard('Algorithms - Group A', '0082c9'),
		);
		$this->client->archiveBoard(7001);
		$this->client->deleteBoard(7001);

		$this->assertRequest(0, 'POST', '/boards', [
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
		]);
		$this->assertRequest(1, 'GET', '/boards/7001');
		$this->assertRequest(2, 'PUT', '/boards/7001', [
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
			'archived' => true,
		]);
		$this->assertRequest(3, 'DELETE', '/boards/7001');
	}

	public function testStackCreateAndListUseCapturedShapes(): void {
		$this->queueJson(200, [
			'id' => 7101,
			'boardId' => 7001,
			'title' => 'To Do',
			'order' => 0,
		]);
		$this->queueJson(200, [
			[
				'id' => 7101,
				'boardId' => 7001,
				'title' => 'To Do',
				'order' => 0,
			],
		]);

		self::assertSame(
			['id' => 7101, 'title' => 'To Do', 'order' => 0],
			$this->client->createStack(7001, 'To Do', 0),
		);
		self::assertSame(
			[['id' => 7101, 'title' => 'To Do', 'order' => 0]],
			$this->client->listStacks(7001),
		);

		$this->assertRequest(0, 'POST', '/boards/7001/stacks', [
			'title' => 'To Do',
			'order' => 0,
		]);
		$this->assertRequest(1, 'GET', '/boards/7001/stacks');
	}

	public function testAclOperationsReadBoardAndUseCapturedShapes(): void {
		$permissions = [
			'permissionEdit' => true,
			'permissionShare' => false,
			'permissionManage' => false,
		];
		$ownerPermissions = [
			'permissionEdit' => true,
			'permissionShare' => true,
			'permissionManage' => true,
		];
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
			'acl' => [[
				'id' => 7201,
				'boardId' => 7001,
				'participant' => [
					'primaryKey' => 'student-1',
					'uid' => 'student-1',
					'displayname' => 'Student One',
					'type' => 0,
				],
				'type' => 0,
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			]],
		]);
		$this->queueJson(200, [
			'id' => 7202,
			'boardId' => 7001,
			'participant' => [
				'primaryKey' => 'teacher-1',
				'uid' => 'teacher-1',
				'displayname' => 'Teacher One',
				'type' => 0,
			],
			'type' => 0,
			...$ownerPermissions,
		]);
		$this->queueJson(200, [
			'id' => 7201,
			'boardId' => 7001,
			'participant' => 'student-1',
			'type' => 0,
			...$ownerPermissions,
		]);
		$this->queueJson(200, [
			'id' => 7201,
			'boardId' => 7001,
			'participant' => 'student-1',
			'type' => 0,
		]);

		self::assertSame([[
			'id' => 7201,
			'participant' => 'student-1',
			...$permissions,
		]], $this->client->listAcl(7001));
		self::assertSame(7202, $this->client->addAcl(7001, 'teacher-1', $ownerPermissions));
		$this->client->updateAcl(7001, 7201, $ownerPermissions);
		$this->client->removeAcl(7001, 7201);

		$this->assertRequest(0, 'GET', '/boards/7001');
		$this->assertRequest(1, 'POST', '/boards/7001/acl', [
			'type' => 0,
			'participant' => 'teacher-1',
			...$ownerPermissions,
		]);
		$this->assertRequest(2, 'PUT', '/boards/7001/acl/7201', $ownerPermissions);
		$this->assertRequest(3, 'DELETE', '/boards/7001/acl/7201');
	}

	/**
	 * @return iterable<string, array{int,bool}>
	 */
	public static function httpFailureProvider(): iterable {
		yield 'authentication failure is terminal' => [401, false];
		yield 'missing board is terminal' => [404, false];
		yield 'server failure is retryable' => [503, true];
	}

	#[DataProvider('httpFailureProvider')]
	public function testHttpFailuresAreClassifiedWithoutResponseDisclosure(int $status, bool $retryable): void {
		$this->queueJson($status, ['message' => 'sensitive upstream detail']);

		try {
			$this->client->createBoard('Algorithms - Group A', '0082c9');
			self::fail('Expected the Deck request to fail');
		} catch (DeckRequestException $exception) {
			self::assertSame($status, $exception->httpStatus());
			self::assertSame($retryable, $exception->isRetryable());
			self::assertStringNotContainsString('sensitive upstream detail', $exception->getMessage());
		}
	}

	public function testTimeoutIsRetryableAndDoesNotExposeTransportDetails(): void {
		$this->responses[] = new \RuntimeException('transport detail with credentials');

		try {
			$this->client->createBoard('Algorithms - Group A', '0082c9');
			self::fail('Expected the Deck request to time out');
		} catch (DeckRequestException $exception) {
			self::assertSame(0, $exception->httpStatus());
			self::assertTrue($exception->isRetryable());
			self::assertStringNotContainsString('credentials', $exception->getMessage());
		}
	}

	public function testMalformedJsonIsAResponseFailure(): void {
		$this->responses[] = $this->response(200, '{not-json');

		$this->expectException(DeckRequestException::class);
		try {
			$this->client->createBoard('Algorithms - Group A', '0082c9');
		} catch (DeckRequestException $exception) {
			self::assertSame(200, $exception->httpStatus());
			self::assertTrue($exception->isRetryable());
			throw $exception;
		}
	}

	public function testMissingRequiredFieldsAreAResponseFailure(): void {
		$this->queueJson(200, [
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
		]);

		$this->expectException(DeckRequestException::class);
		try {
			$this->client->createBoard('Algorithms - Group A', '0082c9');
		} catch (DeckRequestException $exception) {
			self::assertSame(200, $exception->httpStatus());
			self::assertTrue($exception->isRetryable());
			throw $exception;
		}
	}

	public function testConfiguredDeckApiBaseUrlOverridesGeneratedPublicUrl(): void {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->http);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects(self::never())->method('getAbsoluteURL');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default, bool $lazy): string {
				self::assertSame(Application::APP_ID, $appId);
				self::assertTrue($lazy);

				return match ($key) {
					'deck_api_base_url' => 'http://localhost/index.php/apps/deck/api/v1.1',
					'bot_username' => 'moodle-deck-bot',
					'bot_app_password' => 'test-app-password',
					default => $default,
				};
			},
		);
		$appConfig->method('getValueInt')->willReturn(10);

		$client = new HttpDeckClient($clientService, $urlGenerator, $appConfig);
		$this->queueJson(200, [
			'id' => 7001,
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
		]);

		$client->createBoard('Algorithms - Group A', '0082c9');

		$this->assertRequest(0, 'POST', '/boards', [
			'title' => 'Algorithms - Group A',
			'color' => '0082c9',
		], 'http://localhost/index.php/apps/deck/api/v1.1');
	}

	private function queueJson(int $status, array $body): void {
		$this->responses[] = $this->response(
			$status,
			json_encode($body, JSON_THROW_ON_ERROR),
		);
	}

	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	private function assertRequest(int $index, string $method, string $path, ?array $body = null, string $baseUrl = self::BASE_URL): void {
		$request = $this->requests[$index];
		self::assertSame($method, $request['method']);
		self::assertSame($baseUrl . $path, $request['url']);
		self::assertSame(['moodle-deck-bot', 'test-app-password'], $request['options']['auth']);
		self::assertSame(10, $request['options']['timeout']);
		self::assertSame('application/json', $request['options']['headers']['Accept']);
		self::assertArrayNotHasKey('OCS-APIRequest', $request['options']['headers']);

		if ($body === null) {
			self::assertArrayNotHasKey('body', $request['options']);
			return;
		}

		self::assertSame('application/json', $request['options']['headers']['Content-Type']);
		self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), $request['options']['body']);
	}
}
