<?php

declare(strict_types=1);

return [
	'ocs' => [],
	'routes' => [
		['name' => 'health#getHealth', 'url' => '/api/v1/health', 'verb' => 'GET'],
		['name' => 'settings#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'settings#updateSettings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
		['name' => 'webhook#receive', 'url' => '/api/v1/webhook', 'verb' => 'POST'],
	],
];
