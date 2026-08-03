<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Settings;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection {
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Moodle Deck Sync');
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}

	#[\Override]
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
