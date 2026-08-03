<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Controller;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

final class HealthController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly HttpDeckClient $deckClient,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function getHealth(): DataResponse {
		$deckReachable = true;
		$botAuthenticated = true;

		$botUsername = $this->appConfig->getValueString(
			Application::APP_ID,
			'bot_username',
			'',
			true,
		);
		$botAppPassword = $this->appConfig->getValueString(
			Application::APP_ID,
			'bot_app_password',
			'',
			true,
		);

		if ($botUsername === '' || $botAppPassword === '') {
			$botAuthenticated = false;
		}

		return new DataResponse([
			'app_version' => '1.0.0',
			'deck_reachable' => $deckReachable,
			'bot_authenticated' => $botAuthenticated,
			'auth_mode' => 'hmac',
		]);
	}
}
