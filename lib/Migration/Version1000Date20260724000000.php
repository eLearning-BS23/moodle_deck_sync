<?php

declare(strict_types=1);

namespace OCA\MoodleDeckSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260724000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('moodle_deck_sync_map')) {
			$table = $schema->createTable('moodle_deck_sync_map');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('moodle_instance', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('moodle_course_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('moodle_group_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('moodle_cmid', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'pending',
			]);
			$table->addColumn('safe_error', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('archived_at', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('purged_at', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['moodle_instance', 'moodle_course_id', 'moodle_group_id', 'moodle_cmid'], 'mds_map_source_uix');
			$table->addIndex(['moodle_group_id'], 'mds_map_group_idx');
			$table->addIndex(['status'], 'mds_map_status_idx');
		}

		if (!$schema->hasTable('moodle_deck_sync_events')) {
			$table = $schema->createTable('moodle_deck_sync_events');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('moodle_instance', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('event_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('event_type', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('result', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'processing',
			]);
			$table->addColumn('detail', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['moodle_instance', 'event_id'], 'mds_events_event_uix');
			$table->addIndex(['result'], 'mds_events_result_idx');
		}

		if (!$schema->hasTable('moodle_deck_sync_users')) {
			$table = $schema->createTable('moodle_deck_sync_users');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('email', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('nc_uid', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('provisioned', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$table->addColumn('provision_mode', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'unresolved',
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['email'], 'mds_users_email_idx');
		}

		return $schema;
	}
}
