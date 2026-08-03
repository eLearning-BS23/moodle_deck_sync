<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Controller;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

final class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	public function getSettings(): DataResponse {
		$allowed = $this->appConfig->getValueArray(
			Application::APP_ID,
			'allowed_moodle_instances',
			[],
			true,
		);

		$botUsername = $this->appConfig->getValueString(
			Application::APP_ID,
			'bot_username',
			'',
			true,
		);

		$provisioningEnabled = $this->appConfig->getValueBool(
			Application::APP_ID,
			'provisioning_enabled',
			false,
			true,
		);

		$purgeAfterDays = $this->appConfig->getValueInt(
			Application::APP_ID,
			'purge_after_days',
			0,
			true,
		);

		return new DataResponse([
			'allowed_instances' => $allowed,
			'bot_username' => $botUsername,
			'provisioning_enabled' => $provisioningEnabled,
			'purge_after_days' => $purgeAfterDays,
			'auth_mode' => 'hmac',
		]);
	}

	public function updateSettings(): DataResponse {
		$allowedInstances = $this->request->getParam('allowed_instances', []);
		if (is_array($allowedInstances)) {
			$this->appConfig->setValueArray(
				Application::APP_ID,
				'allowed_moodle_instances',
				$allowedInstances,
			);
		}

		$sharedSecret = $this->request->getParam('shared_secret', null);
		if (is_string($sharedSecret) && $sharedSecret !== '') {
			$this->appConfig->setValueString(
				Application::APP_ID,
				'shared_secret',
				$sharedSecret,
			);
		}

		$botUsername = $this->request->getParam('bot_username', null);
		if (is_string($botUsername)) {
			$this->appConfig->setValueString(
				Application::APP_ID,
				'bot_username',
				$botUsername,
			);
		}

		$botAppPassword = $this->request->getParam('bot_app_password', null);
		if (is_string($botAppPassword) && $botAppPassword !== '') {
			$this->appConfig->setValueString(
				Application::APP_ID,
				'bot_app_password',
				$botAppPassword,
			);
		}

		$provisioningEnabled = $this->request->getParam('provisioning_enabled', null);
		if ($provisioningEnabled !== null) {
			$this->appConfig->setValueBool(
				Application::APP_ID,
				'provisioning_enabled',
				(bool)$provisioningEnabled,
			);
		}

		$purgeAfterDays = $this->request->getParam('purge_after_days', null);
		if ($purgeAfterDays !== null) {
			$this->appConfig->setValueInt(
				Application::APP_ID,
				'purge_after_days',
				(int)$purgeAfterDays,
			);
		}

		return new DataResponse(['status' => 'success']);
	}
}
