<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Service;

use OCA\MoodleDeckSync\Db\SyncMap;
use OCA\MoodleDeckSync\Db\SyncMapMapper;
use OCA\MoodleDeckSync\Db\UserLink;
use OCA\MoodleDeckSync\Db\UserLinkMapper;
use OCA\MoodleDeckSync\Deck\HttpDeckClient;
use OCA\MoodleDeckSync\Notification\Notifier;
use OCA\MoodleDeckSync\Service\BoardProvisioner;
use OCA\MoodleDeckSync\Service\ConfigService;
use OCA\MoodleDeckSync\Service\ProvisioningService;
use OCA\MoodleDeckSync\Service\WebhookEvent;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MembershipReconciliationTest extends TestCase {
	private SyncMapMapper&MockObject $mapMapper;
	private HttpDeckClient&MockObject $deckClient;
	private UserLinkMapper&MockObject $userLinkMapper;
	private IUserManager&MockObject $userManager;
	private IAppConfig&MockObject $appConfig;
	private IManager&MockObject $notificationManager;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private BoardProvisioner $provisioner;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->mapMapper = $this->createMock(SyncMapMapper::class);
		$this->deckClient = $this->createMock(HttpDeckClient::class);
		$this->userLinkMapper = $this->createMock(UserLinkMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $routeName, array $parameters): string {
				self::assertSame('deck.page.indexBoard', $routeName);
				return 'https://nextcloud.local/index.php/apps/deck/board/' . $parameters['boardId'];
			},
		);

		$configService = new ConfigService($this->appConfig);

		$provisioningService = new ProvisioningService(
			$this->userManager,
			$this->userLinkMapper,
			$this->appConfig,
		);

		$this->provisioner = new BoardProvisioner(
			$this->mapMapper,
			$this->deckClient,
			$provisioningService,
			$configService,
			new Notifier($this->notificationManager, $this->l10n, $this->urlGenerator),
		);
	}

	public function testAddMissingMemberAddsAcl(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$this->deckClient->method('listAcl')->with(42)->willReturn([]);

		$this->deckClient->expects(self::once())
			->method('addAcl')
			->with(42, 'nc_student1', [
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			])
			->willReturn(101);

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_added',
			'event_id' => 'evt_add_1',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'student1@moodle.local',
					'name' => 'Student One',
					'role' => 'student',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);

		self::assertSame('applied', $result->result());
		self::assertSame(42, $result->boardId());
	}

	public function testAddMissingMemberNotifiesUserForExistingBoard(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$this->deckClient->method('listAcl')->with(42)->willReturn([]);
		$this->deckClient->method('addAcl')->with(42, 'nc_student1')->willReturn(101);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->expects(self::once())->method('setUser')->with('nc_student1')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->expects(self::once())->method('setObject')->with('board', '42')->willReturnSelf();
		$notification->expects(self::once())
			->method('setSubject')
			->with('board_ready', ['board' => 'CS101 - Group Alpha - Project 1'])
			->willReturnSelf();
		$notification->expects(self::once())->method('setLink')->with('https://nextcloud.local/index.php/apps/deck/board/42')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects(self::once())
			->method('notify')
			->with($notification);

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_added',
			'event_id' => 'evt_add_notify',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5, 'name' => 'Group Alpha'],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'student1@moodle.local',
					'name' => 'Student One',
					'role' => 'student',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);

		self::assertSame('applied', $result->result());
	}

	public function testAddExistingMemberWithSameRoleIsNoOp(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$this->deckClient->method('listAcl')->with(42)->willReturn([
			[
				'id' => 101,
				'participant' => 'nc_student1',
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			],
		]);

		$this->deckClient->expects(self::never())->method('addAcl');
		$this->deckClient->expects(self::never())->method('updateAcl');

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_added',
			'event_id' => 'evt_add_dup',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'student1@moodle.local',
					'name' => 'Student One',
					'role' => 'student',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);
		self::assertSame('applied', $result->result());
	}

	public function testRoleChangeUpdatesAcl(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_user1');
		$this->userLinkMapper->method('findByEmail')->with('teacher1@moodle.local')->willReturn($userLink);

		$this->deckClient->method('listAcl')->with(42)->willReturn([
			[
				'id' => 101,
				'participant' => 'nc_user1',
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			],
		]);

		$this->deckClient->expects(self::once())
			->method('updateAcl')
			->with(42, 101, [
				'permissionEdit' => true,
				'permissionShare' => true,
				'permissionManage' => true,
			]);

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_added',
			'event_id' => 'evt_role_change',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'teacher1@moodle.local',
					'name' => 'Teacher One',
					'role' => 'editingteacher',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);
		self::assertSame('applied', $result->result());
	}

	public function testRemovePresentMemberRemovesAcl(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$this->deckClient->method('listAcl')->with(42)->willReturn([
			[
				'id' => 101,
				'participant' => 'nc_student1',
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			],
		]);

		$this->deckClient->expects(self::once())
			->method('removeAcl')
			->with(42, 101);

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_removed',
			'event_id' => 'evt_rem_1',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'student1@moodle.local',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);
		self::assertSame('applied', $result->result());
	}

	public function testRemoveAbsentMemberIsNoOp(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);
		$this->deckClient->method('listAcl')->with(42)->willReturn([]);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$this->deckClient->expects(self::never())->method('removeAcl');

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_removed',
			'event_id' => 'evt_rem_absent',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'student1@moodle.local',
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);
		self::assertSame('applied', $result->result());
	}

	public function testGroupDeletionArchivesAllMappedBoards(): void {
		$map1 = new SyncMap();
		$map1->setId(10);
		$map1->setStatus('active');
		$map1->setBoardId(42);

		$map2 = new SyncMap();
		$map2->setId(11);
		$map2->setStatus('active');
		$map2->setBoardId(43);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map1, $map2]);

		$this->deckClient->expects(self::exactly(2))
			->method('archiveBoard')
			->willReturnCallback(function (int $boardId): void {
				self::assertContains($boardId, [42, 43]);
			});

		$this->mapMapper->expects(self::exactly(2))
			->method('markArchived');

		$event = WebhookEvent::fromArray([
			'event' => 'group_deleted',
			'event_id' => 'evt_group_del',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
			],
		]);

		$result = $this->provisioner->archive($event);
		self::assertSame('applied', $result->result());
	}

	public function testRepeatedGroupDeletionIsNoOp(): void {
		$map1 = new SyncMap();
		$map1->setId(10);
		$map1->setStatus('archived');
		$map1->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map1]);
		$this->deckClient->expects(self::never())->method('archiveBoard');

		$event = WebhookEvent::fromArray([
			'event' => 'group_deleted',
			'event_id' => 'evt_group_del_dup',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
			],
		]);

		$result = $this->provisioner->archive($event);
		self::assertSame('skipped', $result->result());
	}

	public function testFullMemberPayloadConvergesAclAndRemovesStaleEntries(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('active');
		$map->setBoardId(42);

		$this->mapMapper->method('findAllByGroup')->willReturn([$map]);

		$studentLink = new UserLink();
		$studentLink->setNcUid('nc_student_current');
		$this->userLinkMapper->method('findByEmail')->willReturnMap([
			['current@moodle.local', $studentLink],
		]);

		$this->deckClient->method('listAcl')->with(42)->willReturn([
			[
				'id' => 101,
				'participant' => 'nc_student_stale',
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			],
			[
				'id' => 102,
				'participant' => 'nc_student_current',
				'permissionEdit' => true,
				'permissionShare' => false,
				'permissionManage' => false,
			],
		]);

		$this->deckClient->expects(self::never())->method('addAcl');
		$this->deckClient->expects(self::never())->method('updateAcl');
		$this->deckClient->expects(self::once())
			->method('removeAcl')
			->with(42, 101);

		$event = WebhookEvent::fromArray([
			'event' => 'group_member_added',
			'event_id' => 'evt_out_of_order',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'member' => [
					'userid' => 101,
					'email' => 'current@moodle.local',
					'name' => 'Current Student',
					'role' => 'student',
				],
				'members' => [
					[
						'userid' => 101,
						'email' => 'current@moodle.local',
						'name' => 'Current Student',
						'role' => 'student',
					],
				],
			],
		]);

		$result = $this->provisioner->reconcileMember($event);

		self::assertSame('applied', $result->result());
	}

	public function testProvisionUsesConfiguredStacksAndBoardTitleTemplate(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('pending');
		$map->setBoardId(null);

		$this->mapMapper->method('upsertPending')->willReturn($map);
		$this->appConfig->method('getValueArray')->willReturn(['Backlog', 'Done']);
		$this->appConfig->method('getValueString')->willReturn('{assignment}: {group}');

		$this->deckClient->expects(self::once())
			->method('createBoard')
			->with('Project 1: Team Five', '0082c9')
			->willReturn(['id' => 42]);
		$this->deckClient->expects(self::exactly(2))
			->method('createStack')
			->willReturnCallback(static function (int $boardId, string $title, int $order): array {
				self::assertSame(42, $boardId);
				self::assertContains($title, ['Backlog', 'Done']);
				self::assertContains($order, [0, 1]);
				return ['id' => 100 + $order];
			});
		$this->mapMapper->expects(self::once())
			->method('markActive')
			->with(10, 42);
		$this->deckClient->method('listAcl')->with(42)->willReturn([]);

		$event = WebhookEvent::fromArray([
			'event' => 'group_created',
			'event_id' => 'evt_group_created',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5, 'name' => 'Team Five', 'description' => ''],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'members' => [],
			],
		]);

		$result = $this->provisioner->provision($event);

		self::assertSame('applied', $result->result());
		self::assertSame(42, $result->boardId());
	}

	public function testNewlyProvisionedBoardNotifiesResolvedMembersOnce(): void {
		$map = new SyncMap();
		$map->setId(10);
		$map->setStatus('pending');
		$map->setBoardId(null);

		$this->mapMapper->method('upsertPending')->willReturn($map);
		$this->appConfig->method('getValueArray')->willReturn(['To Do']);
		$this->appConfig->method('getValueString')->willReturn('{course} - {group} - {assignment}');

		$this->deckClient->method('createBoard')->willReturn(['id' => 42]);
		$this->deckClient->method('createStack')->willReturn(['id' => 100]);
		$this->deckClient->method('listAcl')->with(42)->willReturn([]);
		$this->deckClient->method('addAcl')->willReturn(101);

		$userLink = new UserLink();
		$userLink->setNcUid('nc_student1');
		$this->userLinkMapper->method('findByEmail')->with('student1@moodle.local')->willReturn($userLink);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->expects(self::once())->method('setUser')->with('nc_student1')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->expects(self::once())->method('setObject')->with('board', '42')->willReturnSelf();
		$notification->expects(self::once())
			->method('setSubject')
			->with('board_ready', ['board' => 'CS101 - Group Alpha - Project 1'])
			->willReturnSelf();
		$notification->expects(self::once())->method('setLink')->with('https://nextcloud.local/index.php/apps/deck/board/42')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects(self::once())
			->method('notify')
			->with($notification);

		$event = WebhookEvent::fromArray([
			'event' => 'group_created',
			'event_id' => 'evt_group_created_notify',
			'occurred_at' => 1753180000,
			'instance' => 'https://moodle.local',
			'course' => ['id' => 12, 'shortname' => 'CS101', 'fullname' => 'Computer Science'],
			'payload' => [
				'group' => ['id' => 5, 'name' => 'Group Alpha', 'description' => ''],
				'assignment' => ['cmid' => 88, 'title' => 'Project 1'],
				'members' => [
					[
						'userid' => 101,
						'email' => 'student1@moodle.local',
						'name' => 'Student One',
						'role' => 'student',
					],
				],
			],
		]);

		$result = $this->provisioner->provision($event);

		self::assertSame('applied', $result->result());
	}
}
