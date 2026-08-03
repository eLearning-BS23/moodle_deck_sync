<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Service;

use OCA\MoodleDeckSync\Service\ConfigService;
use OCA\MoodleDeckSync\Service\SyncResult;
use OCA\MoodleDeckSync\Service\WebhookEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class ContractServicesTest extends TestCase {
	public function testConfigReadsSecretAndFiltersAllowedInstances(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('configured-test-value');
		$appConfig->method('getValueArray')->willReturn([
			'https://moodle.local',
			'',
			42,
			'https://second-moodle.local',
			'https://moodle.local',
		]);
		$config = new ConfigService($appConfig);

		self::assertSame('configured-test-value', $config->sharedSecret());
		self::assertSame(
			['https://moodle.local', 'https://second-moodle.local'],
			$config->allowedMoodleInstances(),
		);
	}

	public function testWebhookEventRejectsAnUnknownEvent(): void {
		$this->expectException(\InvalidArgumentException::class);
		WebhookEvent::fromArray([
			'event' => 'not_supported',
			'event_id' => 'evt_invalid',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'C', 'fullname' => 'Course'],
			'payload' => [],
		]);
	}

	public function testWebhookEventAllowsDocumentedLocalHttpMoodleInstance(): void {
		$event = WebhookEvent::fromArray([
			'event' => 'course_module_created',
			'event_id' => 'evt_local_http',
			'occurred_at' => 1753180000,
			'instance' => 'http://localhost:8080',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Intro to CS'],
			'payload' => [
				'assignment' => ['cmid' => 88, 'title' => 'Final Project', 'duedate' => 1754380000],
				'group' => ['id' => 501],
				'deck_enabled' => true,
			],
		]);

		self::assertSame('http://localhost:8080', $event->instance());
	}

	public function testWebhookEventRejectsArbitraryPlainHttpMoodleInstance(): void {
		$this->expectException(\InvalidArgumentException::class);
		WebhookEvent::fromArray([
			'event' => 'course_module_created',
			'event_id' => 'evt_bad_http',
			'occurred_at' => 1753180000,
			'instance' => 'http://moodle.example.com',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Intro to CS'],
			'payload' => [
				'assignment' => ['cmid' => 88, 'title' => 'Final Project', 'duedate' => 1754380000],
				'group' => ['id' => 501],
				'deck_enabled' => true,
			],
		]);
	}

	public function testSyncResultFactoriesExposeOnlyApprovedResults(): void {
		$applied = SyncResult::applied(87, 'https://nextcloud.local/board/87', [
			'resolved' => 2,
			'provisioned' => 1,
			'unresolved' => 0,
		]);
		$skipped = SyncResult::skipped(87, null);
		$partial = SyncResult::partial(87, null, ['resolved' => 1], 'deck_unavailable');
		$failed = SyncResult::failed('invalid_state');

		self::assertSame('applied', $applied->result());
		self::assertSame(87, $applied->boardId());
		self::assertSame('https://nextcloud.local/board/87', $applied->boardUrl());
		self::assertSame(['resolved' => 2, 'provisioned' => 1, 'unresolved' => 0], $applied->memberCounts());
		self::assertSame('skipped', $skipped->result());
		self::assertSame('partial', $partial->result());
		self::assertSame(['resolved' => 1], $partial->memberCounts());
		self::assertSame('failed', $failed->result());
		self::assertNull($failed->boardId());
		self::assertNull($failed->boardUrl());
		self::assertSame([], $failed->memberCounts());
	}
}
