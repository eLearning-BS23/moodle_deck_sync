<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Deck;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;

class HttpDeckClient implements DeckClient {
	private const API_PATH = '/index.php/apps/deck/api/v1.1';

	private readonly IClient $http;
	private readonly string $baseUrl;

	public function __construct(
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
	) {
		$this->http = $clientService->newClient();
		$configuredBaseUrl = trim($this->appConfig->getValueString(
			Application::APP_ID,
			'deck_api_base_url',
			'',
			true,
		));
		$this->baseUrl = rtrim($configuredBaseUrl !== '' ? $configuredBaseUrl : $urlGenerator->getAbsoluteURL(self::API_PATH), '/');
	}

	#[\Override]
	public function createBoard(string $title, string $color): array {
		$data = $this->request('POST', '/boards', [
			'title' => $title,
			'color' => $color,
		]);

		return [
			'id' => $this->requiredInt($data, 'id'),
			'title' => $this->requiredString($data, 'title'),
			'color' => $this->requiredString($data, 'color'),
		];
	}

	#[\Override]
	public function createStack(int $boardId, string $title, int $order): array {
		$data = $this->request('POST', '/boards/' . $boardId . '/stacks', [
			'title' => $title,
			'order' => $order,
		]);

		return $this->stack($data);
	}

	#[\Override]
	public function listStacks(int $boardId): array {
		$data = $this->request('GET', '/boards/' . $boardId . '/stacks');
		if (!array_is_list($data)) {
			throw $this->invalidResponse();
		}

		$stacks = [];
		foreach ($data as $stack) {
			if (!is_array($stack)) {
				throw $this->invalidResponse();
			}
			$stacks[] = $this->stack($stack);
		}

		return $stacks;
	}

	#[\Override]
	public function listAcl(int $boardId): array {
		$board = $this->request('GET', '/boards/' . $boardId);
		$this->requiredInt($board, 'id');
		$entries = $board['acl'] ?? null;
		if (!is_array($entries) || !array_is_list($entries)) {
			throw $this->invalidResponse();
		}

		$acl = [];
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				throw $this->invalidResponse();
			}
			$acl[] = $this->acl($entry);
		}

		return $acl;
	}

	#[\Override]
	public function addAcl(int $boardId, string $uid, array $permissions): int {
		$data = $this->request('POST', '/boards/' . $boardId . '/acl', [
			'type' => 0,
			'participant' => $uid,
			...$permissions,
		]);

		return $this->acl($data)['id'];
	}

	#[\Override]
	public function updateAcl(int $boardId, int $aclId, array $permissions): void {
		$data = $this->request('PUT', '/boards/' . $boardId . '/acl/' . $aclId, $permissions);
		$this->acl($data);
	}

	#[\Override]
	public function removeAcl(int $boardId, int $aclId): void {
		$data = $this->request('DELETE', '/boards/' . $boardId . '/acl/' . $aclId);
		$this->requiredInt($data, 'id');
	}

	#[\Override]
	public function archiveBoard(int $boardId): void {
		$board = $this->request('GET', '/boards/' . $boardId);
		$title = $this->requiredString($board, 'title');
		$color = $this->requiredString($board, 'color');

		$archived = $this->request('PUT', '/boards/' . $boardId, [
			'title' => $title,
			'color' => $color,
			'archived' => true,
		]);
		if (($archived['archived'] ?? null) !== true) {
			throw $this->invalidResponse();
		}
	}

	#[\Override]
	public function deleteBoard(int $boardId): void {
		$deleted = $this->request('DELETE', '/boards/' . $boardId);
		$this->requiredInt($deleted, 'id');
		$deletedAt = $deleted['deletedAt'] ?? null;
		if (!is_int($deletedAt) || $deletedAt <= 0) {
			throw $this->invalidResponse();
		}
	}

	/**
	 * @param array<string, bool|int|string>|null $body
	 * @return array<array-key, mixed>
	 */
	private function request(string $method, string $path, ?array $body = null): array {
		$options = $this->requestOptions();
		if ($body !== null) {
			$options['headers']['Content-Type'] = 'application/json';
			$options['body'] = json_encode($body, JSON_THROW_ON_ERROR);
		}

		try {
			$response = $this->http->request($method, $this->baseUrl . $path, $options);
		} catch (\Throwable $throwable) {
			try {
				$response = $this->http->getResponseFromThrowable($throwable);
			} catch (\Throwable) {
				throw new DeckRequestException('Deck request could not be completed', 0, true);
			}
		}

		return $this->decode($response);
	}

	/**
	 * @return array{
	 *     auth:array{string,string},
	 *     timeout:int,
	 *     headers:array{Accept:string}
	 * }
	 */
	private function requestOptions(): array {
		$username = $this->appConfig->getValueString(
			Application::APP_ID,
			'bot_username',
			'',
			true,
		);
		$appPassword = $this->appConfig->getValueString(
			Application::APP_ID,
			'bot_app_password',
			'',
			true,
		);
		if ($username === '' || $appPassword === '') {
			throw new DeckRequestException('Deck bot credentials are not configured', 0, false);
		}

		$timeout = $this->appConfig->getValueInt(
			Application::APP_ID,
			'request_timeout_seconds',
			10,
			true,
		);

		return [
			'auth' => [$username, $appPassword],
			'timeout' => $timeout > 0 ? $timeout : 10,
			'headers' => [
				'Accept' => 'application/json',
			],
		];
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function decode(IResponse $response): array {
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new DeckRequestException(
				'Deck request failed with HTTP ' . $status,
				$status,
				$status === 408 || $status === 429 || $status >= 500,
			);
		}

		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body)) {
			throw $this->invalidResponse($status);
		}

		try {
			$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw $this->invalidResponse($status);
		}
		if (!is_array($data)) {
			throw $this->invalidResponse($status);
		}

		return $data;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array{id:int,title:string,order:int}
	 */
	private function stack(array $data): array {
		return [
			'id' => $this->requiredInt($data, 'id'),
			'title' => $this->requiredString($data, 'title'),
			'order' => $this->requiredInt($data, 'order'),
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return array{
	 *     id:int,
	 *     participant:string,
	 *     permissionEdit:bool,
	 *     permissionShare:bool,
	 *     permissionManage:bool
	 * }
	 */
	private function acl(array $data): array {
		return [
			'id' => $this->requiredInt($data, 'id'),
			'participant' => $this->participantUid($data),
			'permissionEdit' => $this->requiredBool($data, 'permissionEdit'),
			'permissionShare' => $this->requiredBool($data, 'permissionShare'),
			'permissionManage' => $this->requiredBool($data, 'permissionManage'),
		];
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function participantUid(array $data): string {
		$participant = $data['participant'] ?? null;
		if (is_string($participant)) {
			return $participant;
		}
		if (is_array($participant)) {
			return $this->requiredString($participant, 'uid');
		}

		throw $this->invalidResponse();
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function requiredInt(array $data, string $key): int {
		$value = $data[$key] ?? null;
		if (!is_int($value)) {
			throw $this->invalidResponse();
		}

		return $value;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function requiredString(array $data, string $key): string {
		$value = $data[$key] ?? null;
		if (!is_string($value)) {
			throw $this->invalidResponse();
		}

		return $value;
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function requiredBool(array $data, string $key): bool {
		$value = $data[$key] ?? null;
		if (!is_bool($value)) {
			throw $this->invalidResponse();
		}

		return $value;
	}

	private function invalidResponse(int $status = 200): DeckRequestException {
		return new DeckRequestException('Deck returned an invalid response', $status, true);
	}
}
