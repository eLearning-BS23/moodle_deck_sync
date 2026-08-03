<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Settings;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Settings\AdminSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private AdminSettings $settings;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->settings = new AdminSettings($this->l10n, $this->urlGenerator);
	}

	public function testImplementsISettings(): void {
		self::assertInstanceOf(ISettings::class, $this->settings);
	}

	public function testGetSection(): void {
		self::assertSame(Application::APP_ID, $this->settings->getSection());
	}

	public function testGetPriority(): void {
		self::assertSame(50, $this->settings->getPriority());
	}

	public function testGetForm(): void {
		$this->urlGenerator->expects(self::once())
			->method('linkToRoute')
			->with('moodle_deck_sync.settings.getSettings')
			->willReturn('/index.php/apps/moodle_deck_sync/api/v1/admin/settings');

		$response = $this->settings->getForm();
		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame(Application::APP_ID, $response->getApp());
		self::assertSame('admin', $response->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_BLANK, $response->getRenderAs());
		self::assertSame(
			['settingsUrl' => '/index.php/apps/moodle_deck_sync/api/v1/admin/settings'],
			$response->getParams()
		);
	}
}
