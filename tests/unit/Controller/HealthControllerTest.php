<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Controller;

use OCA\MoodleDeckSync\Controller\HealthController;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private HttpDeckClient&MockObject $deckClient;
	private IAppConfig&MockObject $appConfig;
	private HealthController $controller;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->deckClient = $this->createMock(HttpDeckClient::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->controller = new HealthController(
			'moodle_deck_sync',
			$this->request,
			$this->deckClient,
			$this->appConfig,
		);
	}

	public function testHealthReturnsOnlyApprovedKeys(): void {
		$this->appConfig->method('getValueString')->willReturn('test_value');

		$this->deckClient->method('createBoard')->willThrowException(new \RuntimeException('Probe only'));

		$response = $this->controller->getHealth();
		$data = $response->getData();

		self::assertIsArray($data);
		self::assertArrayHasKey('app_version', $data);
		self::assertArrayHasKey('deck_reachable', $data);
		self::assertArrayHasKey('bot_authenticated', $data);
		self::assertArrayHasKey('auth_mode', $data);

		self::assertSame('hmac', $data['auth_mode']);

		self::assertArrayNotHasKey('shared_secret', $data);
		self::assertArrayNotHasKey('bot_app_password', $data);
		self::assertArrayNotHasKey('bot_username', $data);
	}
}
