<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\Service;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Db\UserLink;
use OCA\MoodleDeckSync\Db\UserLinkMapper;
use OCA\MoodleDeckSync\Service\ProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProvisioningServiceTest extends TestCase {
	public function testCachedLinkUsesNormalizedEmailWithoutAnotherLookup(): void {
		$mapper = $this->mapper();
		$mapper->upsertOutcome('student@example.com', 'student-1', false, 'matched');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::never())->method('getByEmail');
		$userManager->expects(self::never())->method('createUser');

		$result = $this->service($mapper, $userManager, false)->resolve(
			' Student@Example.COM ',
			'Student One',
			'evt-cached',
		);

		self::assertSame([
			'status' => 'matched',
			'uid' => 'student-1',
			'reason' => null,
		], $result);
	}

	public function testOneExactNormalizedEmailMatchIsPersisted(): void {
		$mapper = $this->mapper();
		$user = $this->user('student-1', 'Student@Example.com');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::once())
			->method('getByEmail')
			->with('student@example.com')
			->willReturn([$user]);
		$userManager->expects(self::never())->method('createUser');

		$result = $this->service($mapper, $userManager, false)->resolve(
			' STUDENT@example.com ',
			'Student One',
			'evt-match',
		);

		self::assertSame([
			'status' => 'matched',
			'uid' => 'student-1',
			'reason' => null,
		], $result);
		$link = $mapper->findByEmail('student@example.com');
		self::assertSame('student-1', $link?->getNcUid());
		self::assertSame('matched', $link?->getProvisionMode());
		self::assertFalse($link?->isProvisioned());
	}

	public function testMultipleExactEmailMatchesRemainUnresolved(): void {
		$mapper = $this->mapper();
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([
			$this->user('student-2', 'student@example.com'),
			$this->user('student-1', 'STUDENT@example.com'),
		]);
		$userManager->expects(self::never())->method('createUser');

		$result = $this->service($mapper, $userManager, true)->resolve(
			'student@example.com',
			'Student One',
			'evt-ambiguous',
		);

		self::assertSame([
			'status' => 'unresolved',
			'uid' => null,
			'reason' => 'multiple_email_matches',
		], $result);
		self::assertSame('unresolved', $mapper->findByEmail('student@example.com')?->getProvisionMode());
	}

	public function testProvisioningDisabledPreventsEveryCreateCall(): void {
		$mapper = $this->mapper();
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([]);
		$userManager->expects(self::never())->method('createUser');

		$result = $this->service($mapper, $userManager, false)->resolve(
			'missing@example.com',
			'Missing Student',
			'evt-disabled',
		);

		self::assertSame([
			'status' => 'unresolved',
			'uid' => null,
			'reason' => 'provisioning_disabled',
		], $result);
	}

	public function testGuardedCreationUsesDeterministicUidAndReturnsNoCredential(): void {
		$mapper = $this->mapper();
		$created = $this->createMock(IUser::class);
		$created->method('getUID')->willReturn('moodle-f0030501023327437b06e5c6f87df787');
		$created->expects(self::once())->method('setDisplayName')->with('New Student')->willReturn(true);
		$created->expects(self::once())->method('setSystemEMailAddress')->with('new@example.com');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([]);
		$userManager->method('get')->willReturn(null);
		$userManager->expects(self::once())
			->method('createUser')
			->with(
				'moodle-f0030501023327437b06e5c6f87df787',
				self::callback(static fn (mixed $password): bool => is_string($password) && strlen($password) === 64),
			)
			->willReturn($created);

		$result = $this->service($mapper, $userManager, true)->resolve(
			'NEW@example.com',
			'New Student',
			'evt-create',
		);

		self::assertSame([
			'status' => 'created',
			'uid' => 'moodle-f0030501023327437b06e5c6f87df787',
			'reason' => null,
		], $result);
		self::assertSame(['status', 'uid', 'reason'], array_keys($result));
		$link = $mapper->findByEmail('new@example.com');
		self::assertSame('created', $link?->getProvisionMode());
		self::assertTrue($link?->isProvisioned());
	}

	public function testCreateFailureIsRedactedAndPersistedAsUnresolved(): void {
		$mapper = $this->mapper();
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([]);
		$userManager->method('get')->willReturn(null);
		$userManager->method('createUser')
			->willThrowException(new \RuntimeException('backend detail with generated credential'));

		$result = $this->service($mapper, $userManager, true)->resolve(
			'failed@example.com',
			'Failed Student',
			'evt-create-failed',
		);

		self::assertSame([
			'status' => 'unresolved',
			'uid' => null,
			'reason' => 'account_creation_failed',
		], $result);
		self::assertStringNotContainsString('credential', (string)$result['reason']);
		self::assertSame('unresolved', $mapper->findByEmail('failed@example.com')?->getProvisionMode());
	}

	public function testRepeatedResolutionCreatesOnlyOnceAndUsesCachedUid(): void {
		$mapper = $this->mapper();
		$created = $this->createMock(IUser::class);
		$created->method('getUID')->willReturn('moodle-539979aba91bea71c377ae4a9f15490e');
		$created->method('setDisplayName')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::once())->method('getByEmail')->willReturn([]);
		$userManager->expects(self::once())->method('get')->willReturn(null);
		$userManager->expects(self::once())->method('createUser')->willReturn($created);

		$service = $this->service($mapper, $userManager, true);
		$first = $service->resolve('repeat@example.com', 'Repeat Student', 'evt-repeat-1');
		$second = $service->resolve('repeat@example.com', 'Repeat Student', 'evt-repeat-2');

		self::assertSame('created', $first['status']);
		self::assertSame('matched', $second['status']);
		self::assertSame($first['uid'], $second['uid']);
	}

	private function mapper(): MemoryUserLinkMapper {
		return new MemoryUserLinkMapper($this->createMock(IDBConnection::class));
	}

	private function user(string $uid, string $email): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getEMailAddress')->willReturn($email);

		return $user;
	}

	private function service(
		UserLinkMapper $mapper,
		IUserManager $userManager,
		bool $provisioningEnabled,
	): ProvisioningService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static function (string $appId, string $key, bool $default, bool $lazy) use ($provisioningEnabled): bool {
				self::assertSame(Application::APP_ID, $appId);
				self::assertSame('provisioning_enabled', $key);
				self::assertFalse($default);
				self::assertTrue($lazy);
				return $provisioningEnabled;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default, bool $lazy): string {
				self::assertSame(Application::APP_ID, $appId);
				self::assertSame('provisioning_mode', $key);
				self::assertSame('create', $default);
				self::assertTrue($lazy);
				return 'create';
			},
		);

		return new ProvisioningService($userManager, $mapper, $appConfig);
	}
}

final class MemoryUserLinkMapper extends UserLinkMapper {
	/** @var array<string, UserLink> */
	private array $links = [];
	private int $nextId = 1;

	#[\Override]
	protected function linkByEmail(string $email): UserLink {
		if (!isset($this->links[$email])) {
			throw new DoesNotExistException('user link not found');
		}

		return $this->links[$email];
	}

	#[\Override]
	protected function saveLink(UserLink $link): UserLink {
		if ($link->getId() === null) {
			$link->setId($this->nextId++);
		}
		$this->links[$link->getEmail()] = $link;

		return $link;
	}
}
