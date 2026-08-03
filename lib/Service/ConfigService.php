<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Service;

use OCA\MoodleDeckSync\AppInfo\Application;
use OCP\IAppConfig;

final class ConfigService {
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function sharedSecret(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'shared_secret',
			'',
			true,
		);
	}

	/**
	 * @return list<string>
	 */
	public function allowedMoodleInstances(): array {
		$configured = $this->appConfig->getValueArray(
			Application::APP_ID,
			'allowed_moodle_instances',
			[],
			true,
		);
		$instances = [];

		foreach ($configured as $instance) {
			if (!is_string($instance)) {
				continue;
			}
			$instance = trim($instance);
			if ($instance !== '') {
				$instances[$instance] = true;
			}
		}

		return array_keys($instances);
	}

	/**
	 * @return list<string>
	 */
	public function defaultStacks(): array {
		$configured = $this->appConfig->getValueArray(
			Application::APP_ID,
			'default_stacks',
			['To Do', 'In Progress', 'Under Review', 'Done'],
			true,
		);
		$stacks = [];

		foreach ($configured as $stack) {
			if (!is_string($stack)) {
				continue;
			}
			$stack = trim($stack);
			if ($stack !== '') {
				$stacks[] = $stack;
			}
		}

		return $stacks === [] ? ['To Do', 'In Progress', 'Under Review', 'Done'] : $stacks;
	}

	public function boardNameTemplate(): string {
		$template = trim($this->appConfig->getValueString(
			Application::APP_ID,
			'board_name_template',
			'{course} - {group} - {assignment}',
			true,
		));

		return $template === '' ? '{course} - {group} - {assignment}' : $template;
	}
}
