<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260731000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['moodle_deck_sync_map', 'moodle_deck_sync_events', 'moodle_deck_sync_users'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}

			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('updated_at')) {
				$table->addColumn('updated_at', Types::BIGINT, [
					'notnull' => true,
					'length' => 20,
					'unsigned' => true,
					'default' => 0,
				]);
			}
		}

		if ($schema->hasTable('moodle_deck_sync_events')) {
			$table = $schema->getTable('moodle_deck_sync_events');
			if (!$table->hasColumn('detail')) {
				$table->addColumn('detail', Types::TEXT, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
