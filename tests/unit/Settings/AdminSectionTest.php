<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Settings;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Settings\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminSectionTest extends TestCase {
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private AdminSection $section;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->section = new AdminSection($this->l10n, $this->urlGenerator);
	}

	public function testImplementsIIconSection(): void {
		self::assertInstanceOf(IIconSection::class, $this->section);
	}

	public function testGetID(): void {
		self::assertSame(Application::APP_ID, $this->section->getID());
	}

	public function testGetName(): void {
		$this->l10n->expects(self::once())
			->method('t')
			->with('Moodle Deck Sync')
			->willReturn('Moodle Deck Sync');

		self::assertSame('Moodle Deck Sync', $this->section->getName());
	}

	public function testGetPriority(): void {
		self::assertSame(80, $this->section->getPriority());
	}

	public function testGetIcon(): void {
		$this->urlGenerator->expects(self::once())
			->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps-extra/moodle_deck_sync/img/app.svg');

		self::assertSame('/apps-extra/moodle_deck_sync/img/app.svg', $this->section->getIcon());
	}
}
