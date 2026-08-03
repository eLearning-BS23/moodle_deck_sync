<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Controller;

use OCA\MoodleDeckSync\Controller\SettingsController;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IAppConfig&MockObject $appConfig;
	private SettingsController $controller;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->controller = new SettingsController(
			'moodle_deck_sync',
			$this->request,
			$this->appConfig,
		);
	}

	public function testGetSettingsNeverReturnsSecretsOrAppPassword(): void {
		$this->appConfig->method('getValueString')->willReturn('some_configured_value');
		$this->appConfig->expects(self::once())
			->method('getValueArray')
			->with('moodle_deck_sync', 'allowed_moodle_instances', [], true)
			->willReturn(['https://moodle.local']);
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->appConfig->method('getValueInt')->willReturn(0);

		$response = $this->controller->getSettings();
		$data = $response->getData();

		self::assertIsArray($data);
		self::assertArrayHasKey('allowed_instances', $data);
		self::assertArrayHasKey('bot_username', $data);
		self::assertArrayHasKey('provisioning_enabled', $data);
		self::assertArrayHasKey('purge_after_days', $data);
		self::assertArrayHasKey('auth_mode', $data);

		self::assertSame('hmac', $data['auth_mode']);

		self::assertArrayNotHasKey('shared_secret', $data);
		self::assertArrayNotHasKey('bot_app_password', $data);
	}

	public function testUpdateSettingsSavesAllowedInstancesAndSecrets(): void {
		$this->appConfig->expects(self::once())
			->method('setValueArray')
			->with('moodle_deck_sync', 'allowed_moodle_instances', ['https://moodle.local']);
		$this->appConfig->expects(self::exactly(3))->method('setValueString');

		$this->request->method('getParam')->willReturnMap([
			['allowed_instances', [], ['https://moodle.local']],
			['shared_secret', null, 'new_secret_12345'],
			['bot_username', null, 'bot_user'],
			['bot_app_password', null, 'new_app_password'],
			['provisioning_enabled', null, false],
			['purge_after_days', null, 0],
		]);

		$response = $this->controller->updateSettings();
		self::assertSame(200, $response->getStatus());
	}
}
