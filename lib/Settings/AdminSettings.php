<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Settings;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

final class AdminSettings implements ISettings {
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'admin',
			[
				'settingsUrl' => $this->urlGenerator->linkToRoute('moodle_deck_sync.settings.getSettings'),
			],
			TemplateResponse::RENDER_AS_BLANK
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}
}
