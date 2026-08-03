<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Tests\Unit\AppInfo;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCA\MoodleDeckSync\Middleware\SignatureMiddleware;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase {
	public function testRouteManifestHasNextcloudSections(): void {
		/** @var mixed $routes */
		$routes = require __DIR__ . '/../../../appinfo/routes.php';

		self::assertIsArray($routes);
		self::assertSame(['ocs', 'routes'], array_keys($routes));
		self::assertIsArray($routes['ocs']);
		self::assertIsArray($routes['routes']);
	}

	public function testApplicationRegistersSignatureMiddlewareForThisAppOnly(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerMiddleware')
			->with(SignatureMiddleware::class, false);
		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		$application->register($context);
	}
}
